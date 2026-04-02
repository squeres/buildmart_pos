<?php
/**
 * Shift/business-hours helpers.
 */

function shift_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    return $timezone = new DateTimeZone(current_timezone());
}

function shift_now(string $time = 'now'): DateTimeImmutable
{
    return new DateTimeImmutable($time, shift_timezone());
}

function shift_datetime(?string $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, shift_timezone());
    } catch (Throwable $e) {
        return null;
    }
}

function shift_normalize_time_value(mixed $value, string $fallback = '08:30'): string
{
    $fallback = preg_match('/^\d{2}:\d{2}$/', $fallback) ? $fallback : '08:30';
    $value = trim((string)$value);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
        return $fallback;
    }

    $hours = (int)$matches[1];
    $minutes = (int)$matches[2];
    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return $fallback;
    }

    return sprintf('%02d:%02d', $hours, $minutes);
}

function shift_setting_time(string $key, string $fallback): string
{
    return shift_normalize_time_value(setting($key, $fallback), $fallback);
}

function shift_setting_int(string $key, int $default, int $min = 0, int $max = 1440): int
{
    $value = (int)setting($key, (string)$default);
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function shift_extension_default_options_string(mixed $value, string $fallback = '15,30,45,60'): string
{
    $parts = preg_split('/\s*,\s*/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $options = [];
    foreach ($parts as $part) {
        $minutes = (int)$part;
        if ($minutes > 0 && $minutes <= 720) {
            $options[] = $minutes;
        }
    }

    $options = array_values(array_unique($options));
    sort($options);

    if (!$options) {
        return $fallback;
    }

    return implode(',', $options);
}

function shift_extension_default_options(): array
{
    $value = shift_extension_default_options_string(
        setting('shift_extension_default_options', '15,30,45,60'),
        '15,30,45,60'
    );

    return array_map('intval', explode(',', $value));
}

function shift_extension_max_minutes(): int
{
    return shift_setting_int('shift_extension_max_minutes', 120, 0, 720);
}

function shift_schema_has_table(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
        return $cache[$table] = false;
    }

    try {
        return $cache[$table] = (bool)Database::row(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function shift_schema_has_column(string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
        return $cache[$cacheKey] = false;
    }

    try {
        return $cache[$cacheKey] = (bool)Database::row(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $column]
        );
    } catch (Throwable $e) {
        return $cache[$cacheKey] = false;
    }
}

function shift_extension_schema_ready(): bool
{
    return shift_schema_has_table('shift_extension_requests')
        && shift_schema_has_column('shifts', 'extended_until')
        && shift_schema_has_column('shifts', 'extension_approved_by');
}

function shift_extension_feature_available(): bool
{
    return shift_extension_schema_ready() && setting('shift_extension_enabled', '1') === '1';
}

function shift_minutes_from_hhmm(string $hhmm): int
{
    [$hours, $minutes] = array_map('intval', explode(':', shift_normalize_time_value($hhmm, '00:00')));
    return ($hours * 60) + $minutes;
}

function shift_store_schedule_for_moment(?DateTimeImmutable $moment = null): array
{
    $moment = $moment ?: shift_now();
    $openTime = shift_setting_time('store_open_time', '08:30');
    $closeTime = shift_setting_time('store_close_time', '21:00');
    $graceMinutes = shift_setting_int('shift_close_grace_minutes', 15, 0, 180);

    $openMinutes = shift_minutes_from_hhmm($openTime);
    $closeMinutes = shift_minutes_from_hhmm($closeTime);
    $isOvernight = $closeMinutes < $openMinutes;

    $startDate = $moment->setTime(0, 0);
    if ($isOvernight) {
        $currentMinutes = ((int)$moment->format('H') * 60) + (int)$moment->format('i');
        if ($currentMinutes < $closeMinutes) {
            $startDate = $startDate->modify('-1 day');
        }
    }

    $openAt = $startDate->setTime(intdiv($openMinutes, 60), $openMinutes % 60);
    $closeDate = $isOvernight ? $startDate->modify('+1 day') : $startDate;
    $closeAt = $closeDate->setTime(intdiv($closeMinutes, 60), $closeMinutes % 60);
    $allowedUntil = $closeAt->modify('+' . $graceMinutes . ' minutes');

    return [
        'open_time' => $openTime,
        'close_time' => $closeTime,
        'grace_minutes' => $graceMinutes,
        'overnight' => $isOvernight,
        'open_at' => $openAt,
        'close_at' => $closeAt,
        'allowed_until' => $allowedUntil,
    ];
}

function shift_store_schedule_for_shift(array $shift): array
{
    $openedAt = shift_datetime((string)($shift['opened_at'] ?? '')) ?: shift_now();
    return shift_store_schedule_for_moment($openedAt);
}

function shift_base_allowed_until(array $shift): DateTimeImmutable
{
    return shift_store_schedule_for_shift($shift)['allowed_until'];
}

function shift_extension_max_deadline(array $shift): DateTimeImmutable
{
    return shift_base_allowed_until($shift)->modify('+' . shift_extension_max_minutes() . ' minutes');
}

function shift_valid_extended_until(array $shift): ?DateTimeImmutable
{
    $extendedUntil = shift_datetime($shift['extended_until'] ?? null);
    if (!$extendedUntil) {
        return null;
    }

    $baseAllowedUntil = shift_base_allowed_until($shift);
    if ($extendedUntil <= $baseAllowedUntil) {
        return null;
    }

    $maxDeadline = shift_extension_max_deadline($shift);
    if ($extendedUntil > $maxDeadline) {
        return $maxDeadline;
    }

    return $extendedUntil;
}

function shift_allowed_until(array $shift): DateTimeImmutable
{
    $allowedUntil = shift_base_allowed_until($shift);
    $extendedUntil = shift_valid_extended_until($shift);

    if ($extendedUntil && $extendedUntil > $allowedUntil) {
        return $extendedUntil;
    }

    return $allowedUntil;
}

function shift_effective_closed_at(array $shift, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $allowedUntil = shift_allowed_until($shift);
    $closedAt = shift_datetime($shift['closed_at'] ?? null);
    if ($closedAt) {
        return $closedAt < $allowedUntil ? $closedAt : $allowedUntil;
    }

    $now = $now ?: shift_now();
    return $now < $allowedUntil ? $now : $allowedUntil;
}

function shift_worked_seconds(array $shift, ?DateTimeImmutable $now = null): int
{
    $openedAt = shift_datetime((string)($shift['opened_at'] ?? '')) ?: ($now ?: shift_now());
    $effectiveClosedAt = shift_effective_closed_at($shift, $now);

    return max(0, $effectiveClosedAt->getTimestamp() - $openedAt->getTimestamp());
}

function shift_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours . ' ' . _r('shift_hours_short') . ' ' . $minutes . ' ' . _r('shift_minutes_short');
}

function shift_is_expired(array $shift, ?DateTimeImmutable $now = null): bool
{
    if (($shift['status'] ?? 'open') !== 'open') {
        return false;
    }

    $now = $now ?: shift_now();
    return $now > shift_allowed_until($shift);
}

function shift_is_frozen_open(array $shift, ?DateTimeImmutable $now = null): bool
{
    return ($shift['status'] ?? '') === 'open' && shift_is_expired($shift, $now);
}

function shift_pending_extension_request(int $shiftId): ?array
{
    if (!shift_extension_schema_ready() || $shiftId <= 0) {
        return null;
    }

    return Database::row(
        "SELECT *
         FROM shift_extension_requests
         WHERE shift_id = ? AND status = 'pending'
         ORDER BY requested_at DESC, id DESC
         LIMIT 1",
        [$shiftId]
    );
}

function shift_total_approved_minutes(int $shiftId): int
{
    if (!shift_extension_schema_ready() || $shiftId <= 0) {
        return 0;
    }

    return (int)Database::value(
        "SELECT COALESCE(SUM(approved_minutes), 0)
         FROM shift_extension_requests
         WHERE shift_id = ? AND status = 'approved'",
        [$shiftId]
    );
}

function shift_remaining_extension_minutes(array $shift): int
{
    $shiftId = (int)($shift['id'] ?? 0);
    if ($shiftId <= 0) {
        return 0;
    }

    return max(0, shift_extension_max_minutes() - shift_total_approved_minutes($shiftId));
}

function shift_expire_stale_extension_requests(?DateTimeImmutable $now = null): void
{
    if (!shift_extension_schema_ready()) {
        return;
    }

    $now = $now ?: shift_now();
    $pendingRequests = Database::all(
        "SELECT ser.id,
                s.id AS shift_id,
                s.opened_at,
                s.closed_at,
                s.extended_until,
                s.status
         FROM shift_extension_requests ser
         JOIN shifts s ON s.id = ser.shift_id
         WHERE ser.status = 'pending'"
    );

    foreach ($pendingRequests as $request) {
        $expire = !empty($request['closed_at']) || ($request['status'] ?? '') !== 'open';
        if (!$expire && $now > shift_extension_max_deadline($request)) {
            $expire = true;
        }

        if ($expire) {
            Database::exec(
                "UPDATE shift_extension_requests
                 SET status = 'expired'
                 WHERE id = ? AND status = 'pending'",
                [(int)$request['id']]
            );
        }
    }
}

function shift_request_options_for_remaining(int $remainingMinutes): array
{
    $options = [];
    foreach (shift_extension_default_options() as $minutes) {
        if ($minutes > 0 && $minutes <= $remainingMinutes) {
            $options[] = $minutes;
        }
    }

    if (!$options && $remainingMinutes > 0) {
        $options[] = $remainingMinutes;
    }

    return array_values(array_unique($options));
}

function shift_can_request_extension(array $shift, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: shift_now();
    $shiftId = (int)($shift['id'] ?? 0);

    if (!shift_extension_feature_available()) {
        return ['ok' => false, 'message' => _r('shift_extension_not_available'), 'code' => 'shift_extension_unavailable'];
    }
    if (($shift['status'] ?? 'open') !== 'open' || $shiftId <= 0) {
        return ['ok' => false, 'message' => _r('shift_extension_not_available'), 'code' => 'shift_extension_unavailable'];
    }
    if (!shift_is_expired($shift, $now)) {
        return ['ok' => false, 'message' => _r('shift_extension_not_needed'), 'code' => 'shift_extension_not_needed'];
    }

    $maxDeadline = shift_extension_max_deadline($shift);
    if ($now > $maxDeadline) {
        return [
            'ok' => false,
            'message' => _r('shift_close_expired_first', ['date' => date_fmt((string)$shift['opened_at'])]),
            'code' => 'shift_expired_close_required',
        ];
    }

    shift_expire_stale_extension_requests($now);
    if (shift_pending_extension_request($shiftId)) {
        return ['ok' => false, 'message' => _r('shift_extension_pending'), 'code' => 'shift_extension_pending'];
    }

    $remainingMinutes = shift_remaining_extension_minutes($shift);
    if ($remainingMinutes <= 0) {
        return ['ok' => false, 'message' => _r('shift_extension_limit_reached'), 'code' => 'shift_extension_limit_reached'];
    }

    return [
        'ok' => true,
        'message' => _r('shift_sales_extension_required'),
        'remaining_minutes' => $remainingMinutes,
        'request_options' => shift_request_options_for_remaining($remainingMinutes),
        'max_deadline' => $maxDeadline,
    ];
}

function shift_extension_deadline_after_approval(array $shift, int $approvedMinutes, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now = $now ?: shift_now();
    $baseline = shift_allowed_until($shift);
    if ($now > $baseline) {
        $baseline = $now;
    }

    $deadline = $baseline->modify('+' . max(0, $approvedMinutes) . ' minutes');
    $maxDeadline = shift_extension_max_deadline($shift);

    return $deadline > $maxDeadline ? $maxDeadline : $deadline;
}

function shift_can_extend_directly(array $shift, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: shift_now();
    if (!shift_extension_feature_available()) {
        return ['ok' => false, 'message' => _r('shift_extension_not_available')];
    }
    if (($shift['status'] ?? '') !== 'open' || !empty($shift['closed_at'])) {
        return ['ok' => false, 'message' => _r('shift_extension_not_available')];
    }

    $maxDeadline = shift_extension_max_deadline($shift);
    if ($now > $maxDeadline) {
        return [
            'ok' => false,
            'message' => _r('shift_close_expired_first', ['date' => date_fmt((string)$shift['opened_at'])]),
        ];
    }

    $remainingMinutes = shift_remaining_extension_minutes($shift);
    if ($remainingMinutes <= 0) {
        return ['ok' => false, 'message' => _r('shift_extension_limit_reached')];
    }

    return [
        'ok' => true,
        'remaining_minutes' => $remainingMinutes,
        'request_options' => shift_request_options_for_remaining($remainingMinutes),
        'allowed_until' => shift_allowed_until($shift),
        'max_deadline' => $maxDeadline,
    ];
}

function shift_grant_direct_extension(
    array $shift,
    int $approvedBy,
    int $approvedMinutes,
    ?string $reason = null,
    ?DateTimeImmutable $now = null
): array {
    $now = $now ?: shift_now();
    $state = shift_can_extend_directly($shift, $now);
    if (empty($state['ok'])) {
        throw new RuntimeException((string)($state['message'] ?? _r('shift_extension_not_available')));
    }

    $requestedMinutes = max(0, $approvedMinutes);
    if ($requestedMinutes <= 0) {
        throw new RuntimeException(_r('err_validation'));
    }
    if ($requestedMinutes > (int)$state['remaining_minutes']) {
        throw new RuntimeException(_r('shift_extension_limit_reached'));
    }

    $deadline = shift_extension_deadline_after_approval($shift, $requestedMinutes, $now);
    $baseline = shift_allowed_until($shift);
    if ($now > $baseline) {
        $baseline = $now;
    }

    $actualApprovedMinutes = max(0, (int)floor(($deadline->getTimestamp() - $baseline->getTimestamp()) / 60));
    if ($actualApprovedMinutes <= 0) {
        throw new RuntimeException(_r('shift_extension_limit_reached'));
    }

    Database::beginTransaction();
    try {
        $requestId = Database::insert(
            "INSERT INTO shift_extension_requests (
                shift_id,
                cashier_id,
                requested_at,
                requested_minutes,
                reason,
                status,
                approved_by,
                approved_minutes,
                approved_at,
                expires_at,
                created_at
             ) VALUES (?, ?, NOW(), ?, ?, 'approved', ?, ?, NOW(), ?, NOW())",
            [
                (int)$shift['id'],
                (int)$shift['user_id'],
                $requestedMinutes,
                $reason ?: _r('shift_extension_direct_reason_default'),
                $approvedBy,
                $actualApprovedMinutes,
                $deadline->format('Y-m-d H:i:s'),
            ]
        );

        Database::exec(
            "UPDATE shifts
             SET extended_until = ?,
                 extension_approved_by = ?
             WHERE id = ? AND status = 'open'",
            [
                $deadline->format('Y-m-d H:i:s'),
                $approvedBy,
                (int)$shift['id'],
            ]
        );

        Database::exec(
            "UPDATE shift_extension_requests
             SET status = 'expired'
             WHERE shift_id = ? AND status = 'pending'",
            [(int)$shift['id']]
        );

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        throw $e;
    }

    return [
        'request_id' => $requestId,
        'expires_at' => $deadline,
        'approved_minutes' => $actualApprovedMinutes,
    ];
}

function shift_can_sell_now(array $shift, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: shift_now();
    if (($shift['status'] ?? 'open') !== 'open') {
        return ['ok' => false, 'message' => _r('pos_no_shift')];
    }

    if (!shift_is_expired($shift, $now)) {
        return [
            'ok' => true,
            'allowed_until' => shift_allowed_until($shift),
        ];
    }

    $requestState = shift_can_request_extension($shift, $now);
    if (!empty($requestState['ok'])) {
        return [
            'ok' => false,
            'message' => $requestState['message'],
            'code' => 'shift_extension_required',
            'request_options' => $requestState['request_options'],
            'remaining_minutes' => $requestState['remaining_minutes'],
            'allowed_until' => shift_allowed_until($shift),
        ];
    }

    return [
        'ok' => false,
        'message' => $requestState['message'] ?? _r('shift_close_expired_first', ['date' => date_fmt((string)$shift['opened_at'])]),
        'code' => $requestState['code'] ?? 'shift_expired_close_required',
        'allowed_until' => shift_allowed_until($shift),
    ];
}

function shift_can_open_now(int $userId, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: shift_now();
    $isPrivilegedUser = Auth::check() && Auth::id() === $userId && Auth::isManagerOrAdmin();
    $existingShift = Database::row(
        "SELECT *
         FROM shifts
         WHERE user_id = ? AND status = 'open'
         ORDER BY opened_at ASC
         LIMIT 1",
        [$userId]
    );

    if ($existingShift) {
        if (shift_is_frozen_open($existingShift, $now)) {
            return [
                'ok' => false,
                'message' => _r('shift_close_expired_first', ['date' => date_fmt((string)$existingShift['opened_at'])]),
                'code' => 'expired_previous_shift',
                'shift' => $existingShift,
            ];
        }

        return [
            'ok' => false,
            'message' => _r('shift_already_open'),
            'code' => 'already_open',
            'shift' => $existingShift,
        ];
    }

    if ($isPrivilegedUser) {
        return ['ok' => true];
    }

    $schedule = shift_store_schedule_for_moment($now);
    if ($now < $schedule['open_at']) {
        return [
            'ok' => false,
            'message' => _r('shift_open_too_early'),
            'code' => 'before_store_open',
            'schedule' => $schedule,
        ];
    }
    if ($now > $schedule['allowed_until']) {
        return [
            'ok' => false,
            'message' => _r('shift_store_closed_no_open'),
            'code' => 'after_store_close',
            'schedule' => $schedule,
        ];
    }

    return ['ok' => true, 'schedule' => $schedule];
}

function shift_report_closed_label(array $shift, ?DateTimeImmutable $now = null): string
{
    if (!empty($shift['closed_at'])) {
        return date_fmt((string)$shift['closed_at']);
    }

    if (shift_is_expired($shift, $now)) {
        return _r('shift_report_overdue_open');
    }

    return _r('shift_report_open');
}
