<?php
declare(strict_types=1);

/**
 * Shift lifecycle service.
 */
final class ShiftService
{
    /**
     * Get current open shift for a user.
     */
    public static function getOpenShiftForUser(int $userId, bool $forUpdate = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $sql = "SELECT * FROM shifts WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1"
            . ($forUpdate ? ' FOR UPDATE' : '');

        return Database::row($sql, [$userId]);
    }

    /**
     * Validate that selling is currently allowed and return the open shift.
     */
    public static function requireShiftForSale(int $userId): ?array
    {
        if (setting('shifts_required', '1') !== '1') {
            return null;
        }

        $openShift = self::getOpenShiftForUser($userId);
        if (!$openShift) {
            throw new AppServiceException(_r('pos_no_shift'), 'shift_not_open');
        }

        $saleState = shift_can_sell_now($openShift);
        if (empty($saleState['ok'])) {
            $payload = $saleState;
            if (!empty($payload['allowed_until']) && $payload['allowed_until'] instanceof DateTimeImmutable) {
                $payload['allowed_until_label'] = date_fmt($payload['allowed_until']->format('Y-m-d H:i:s'));
                unset($payload['allowed_until']);
            }
            if (!empty($openShift['id'])) {
                $payload['close_url'] = url('modules/shifts/close.php?id=' . (int)$openShift['id']);
            }
            throw new AppServiceException(
                (string)$saleState['message'],
                (string)($saleState['code'] ?? 'shift_sales_blocked'),
                $payload
            );
        }

        return $openShift;
    }

    /**
     * Open a new shift or return the already open shift.
     *
     * @return array{shift_id:int,already_open:bool,message:string}
     */
    public static function openShift(int $userId, float $openingCash, string $notes = ''): array
    {
        return Database::transaction(function () use ($userId, $openingCash, $notes): array {
            $openGuard = shift_can_open_now($userId);
            if (empty($openGuard['ok'])) {
                if (($openGuard['code'] ?? '') === 'already_open') {
                    return [
                        'shift_id' => (int)($openGuard['shift']['id'] ?? 0),
                        'already_open' => true,
                        'message' => (string)$openGuard['message'],
                    ];
                }

                throw new AppServiceException(
                    (string)$openGuard['message'],
                    (string)($openGuard['code'] ?? 'shift_open_denied'),
                    $openGuard
                );
            }

            $shiftId = Database::insert(
                "INSERT INTO shifts (user_id, opening_cash, notes, status, opened_at)
                 VALUES (?, ?, ?, 'open', NOW())",
                [$userId, max(0.0, $openingCash), $notes]
            );

            return [
                'shift_id' => (int)$shiftId,
                'already_open' => false,
                'message' => _r('shift_opened'),
            ];
        });
    }

    /**
     * Close an open shift.
     *
     * @return array{expected_cash:float,cash_difference:float}
     */
    public static function closeShift(int $shiftId, int $actorUserId, float $closingCash, string $notes = '', bool $allowAnyUser = false): array
    {
        return Database::transaction(function () use ($shiftId, $actorUserId, $closingCash, $notes, $allowAnyUser): array {
            $shift = Database::row(
                "SELECT * FROM shifts WHERE id = ? FOR UPDATE",
                [$shiftId]
            );

            if (!$shift) {
                throw new AppServiceException(_r('err_not_found'), 'shift_not_found');
            }
            if (($shift['status'] ?? '') !== 'open') {
                throw new AppServiceException(_r('err_not_found'), 'shift_already_closed');
            }
            if (!$allowAnyUser && (int)$shift['user_id'] !== $actorUserId) {
                throw new AppServiceException(_r('auth_no_permission'), 'auth_no_permission');
            }

            $cashSales = (float)Database::value(
                "SELECT COALESCE(SUM(p.amount), 0)
                 FROM payments p
                 JOIN sales s ON s.id = p.sale_id
                 WHERE s.shift_id = ? AND s.status = 'completed' AND p.method = 'cash'",
                [$shiftId]
            );
            $cashReturns = (float)Database::value(
                "SELECT COALESCE(SUM(r.total), 0)
                 FROM returns r
                 JOIN sales s ON s.id = r.sale_id
                 WHERE s.shift_id = ? AND r.refund_method = 'cash'",
                [$shiftId]
            );

            $expectedCash = (float)$shift['opening_cash'] + $cashSales - $cashReturns;
            $closingCash = max(0.0, $closingCash);
            $diff = $closingCash - $expectedCash;

            Database::exec(
                "UPDATE shifts
                 SET status = 'closed',
                     closed_at = NOW(),
                     closing_cash = ?,
                     expected_cash = ?,
                     cash_difference = ?,
                     notes = ?
                 WHERE id = ? AND status = 'open'",
                [$closingCash, $expectedCash, $diff, $notes, $shiftId]
            );

            return [
                'expected_cash' => $expectedCash,
                'cash_difference' => $diff,
            ];
        });
    }
}
