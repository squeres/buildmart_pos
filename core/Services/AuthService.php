<?php
declare(strict_types=1);

/**
 * Authentication business logic.
 */
final class AuthService
{
    public const PASSWORD_MIN_LENGTH = 8;

    public static function getUserForSession(int $userId): ?array
    {
        return Database::row(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1',
            [$userId]
        );
    }

    /**
     * Attempt sign-in with email and password.
     */
    public static function attemptPassword(string $email, string $password): ?array
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            return null;
        }

        $user = Database::row(
            'SELECT id, password, is_active
             FROM users
             WHERE email = ?
             LIMIT 1',
            [$email]
        );

        if (!$user || !password_verify($password, (string)$user['password'])) {
            return null;
        }

        if (empty($user['is_active'])) {
            return null;
        }

        return self::getUserForSession((int)$user['id']);
    }

    /**
     * Attempt quick sign-in with PIN.
     */
    public static function attemptPin(string $pin): ?array
    {
        $pin = self::normalizePinForComparison($pin);
        if ($pin === null) {
            return null;
        }

        $hasPinHash = self::hasPinHashColumn();
        $users = Database::all(
            'SELECT u.id, u.pin, u.pin_hash
             FROM users u
             WHERE u.is_active = 1
               AND ((u.pin IS NOT NULL AND u.pin <> \'\')' . ($hasPinHash ? ' OR (u.pin_hash IS NOT NULL AND u.pin_hash <> \'\')' : '') . ')
             ORDER BY u.id ASC'
        );

        foreach ($users as $user) {
            $plainPin = trim((string)($user['pin'] ?? ''));
            $pinHash = (string)($user['pin_hash'] ?? '');

            if ($pinHash !== '' && password_verify($pin, $pinHash)) {
                if ($plainPin !== '') {
                    Database::exec('UPDATE users SET pin = NULL, updated_at = NOW() WHERE id = ?', [(int)$user['id']]);
                    $user['pin'] = null;
                }
                return self::getUserForSession((int)$user['id']);
            }

            if ($plainPin !== '' && hash_equals($plainPin, $pin)) {
                if ($hasPinHash) {
                    $newHash = password_hash($pin, PASSWORD_DEFAULT);
                    Database::exec(
                        'UPDATE users SET pin_hash = ?, pin = NULL, updated_at = NOW() WHERE id = ?',
                        [$newHash, (int)$user['id']]
                    );
                    $user['pin_hash'] = $newHash;
                    $user['pin'] = null;
                }
                return self::getUserForSession((int)$user['id']);
            }
        }

        return null;
    }

    /**
     * Prepare secure PIN storage columns for create/update.
     *
     * @return array{pin:?string,pin_hash:?string}
     */
    public static function preparePinStorage(?string $pin): array
    {
        $normalized = self::normalizePinForStorage($pin);
        if ($normalized === null) {
            return ['pin' => null, 'pin_hash' => null];
        }

        if (self::hasPinHashColumn()) {
            return [
                'pin' => null,
                'pin_hash' => password_hash($normalized, PASSWORD_DEFAULT),
            ];
        }

        return [
            'pin' => $normalized,
            'pin_hash' => null,
        ];
    }

    public static function hasPinHashColumn(): bool
    {
        return function_exists('shift_schema_has_column') && shift_schema_has_column('users', 'pin_hash');
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmPassword,
        bool $requireCurrentPassword = true
    ): void {
        $user = Database::row('SELECT id, password FROM users WHERE id=? LIMIT 1', [$userId]);

        if (!$user) {
            throw new AppServiceException(_r('err_not_found'), 'user_not_found');
        }

        if ($requireCurrentPassword && !password_verify($currentPassword, (string)$user['password'])) {
            throw new AppServiceException(_r('auth_current_password_invalid'), 'current_password_invalid');
        }

        if (mb_strlen($newPassword, 'UTF-8') < self::PASSWORD_MIN_LENGTH) {
            throw new AppServiceException(
                _r('auth_new_password_short', ['min' => (string)self::PASSWORD_MIN_LENGTH]),
                'password_too_short'
            );
        }

        if ($newPassword !== $confirmPassword) {
            throw new AppServiceException(_r('usr_pass_mismatch'), 'password_mismatch');
        }

        if (password_verify($newPassword, (string)$user['password'])) {
            throw new AppServiceException(_r('auth_new_password_same'), 'password_same');
        }

        Database::exec(
            'UPDATE users SET password=?, must_change_password=0, updated_at=NOW() WHERE id=?',
            [self::hashPassword($newPassword), $userId]
        );
    }

    private static function normalizePinForComparison(string $pin): ?string
    {
        $pin = preg_replace('/\D+/', '', trim($pin)) ?? '';
        if ($pin === '' || strlen($pin) < 4 || strlen($pin) > 6) {
            return null;
        }

        return $pin;
    }

    private static function normalizePinForStorage(?string $pin): ?string
    {
        $pin = preg_replace('/\D+/', '', trim((string)$pin)) ?? '';
        if ($pin === '') {
            return null;
        }

        if (strlen($pin) < 4 || strlen($pin) > 6) {
            throw new AppServiceException(_r('auth_pin_invalid'), 'invalid_pin');
        }

        return $pin;
    }
}
