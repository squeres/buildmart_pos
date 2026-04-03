<?php
declare(strict_types=1);

/**
 * Authentication business logic.
 */
final class AuthService
{
    public const PASSWORD_MIN_LENGTH = 8;
    public const PIN_MIN_LENGTH = 4;
    public const PIN_MAX_LENGTH = 6;
    public const PIN_LOGIN_MAX_ATTEMPTS = 5;
    public const PIN_LOGIN_LOCK_SECONDS = 60;

    public static function getUserForSession(int $userId): ?array
    {
        $user = Database::row(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1',
            [$userId]
        );

        if ($user) {
            $user['permission_overrides'] = self::permissionOverrideMapForUser((int)$user['id']);
        }

        return $user;
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

        foreach (self::usersWithPins(0, true) as $user) {
            if (!self::pinMatchesUser($pin, $user)) {
                continue;
            }

            $plainPin = trim((string)($user['pin'] ?? ''));
            if ($plainPin !== '' && self::hasPinHashColumn()) {
                $newHash = password_hash($pin, PASSWORD_DEFAULT);
                Database::exec(
                    'UPDATE users SET pin_hash = ?, pin = NULL, updated_at = NOW() WHERE id = ?',
                    [$newHash, (int)$user['id']]
                );
            }

            return self::getUserForSession((int)$user['id']);
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

    public static function permissionOverridesTableReady(): bool
    {
        return function_exists('shift_schema_has_table') && shift_schema_has_table('user_permission_overrides');
    }

    public static function permissionOverrideModesForUser(int $userId): array
    {
        if ($userId <= 0 || !self::permissionOverridesTableReady()) {
            return [];
        }

        $rows = Database::all(
            'SELECT permission_key, mode
             FROM user_permission_overrides
             WHERE user_id = ?
             ORDER BY permission_key',
            [$userId]
        );

        $modes = [];
        foreach ($rows as $row) {
            $permissionKey = (string)($row['permission_key'] ?? '');
            if (!permission_is_known($permissionKey)) {
                continue;
            }
            $mode = permission_normalize_mode($row['mode'] ?? '');
            if ($mode === 'inherit') {
                continue;
            }
            $modes[$permissionKey] = $mode;
        }

        return $modes;
    }

    public static function permissionOverrideMapForUser(int $userId): array
    {
        $map = [];
        foreach (self::permissionOverrideModesForUser($userId) as $permissionKey => $mode) {
            $resolved = permission_mode_to_bool($mode);
            if ($resolved === null) {
                continue;
            }
            $map[$permissionKey] = $resolved;
        }

        return $map;
    }

    public static function savePermissionOverrideModes(int $userId, array $modes): void
    {
        if ($userId <= 0 || !self::permissionOverridesTableReady()) {
            return;
        }

        $normalizedModes = [];
        foreach ($modes as $permissionKey => $mode) {
            $permissionKey = trim((string)$permissionKey);
            if (!permission_is_known($permissionKey)) {
                continue;
            }

            $normalizedMode = permission_normalize_mode($mode);
            if ($normalizedMode === 'inherit') {
                continue;
            }

            $normalizedModes[$permissionKey] = $normalizedMode;
        }

        Database::transaction(static function () use ($userId, $normalizedModes): void {
            Database::exec('DELETE FROM user_permission_overrides WHERE user_id = ?', [$userId]);

            foreach ($normalizedModes as $permissionKey => $mode) {
                Database::insert(
                    'INSERT INTO user_permission_overrides (user_id, permission_key, mode) VALUES (?, ?, ?)',
                    [$userId, $permissionKey, $mode]
                );
            }
        });
    }

    public static function assertPinAvailable(?string $pin, int $excludeUserId = 0): void
    {
        $normalized = self::normalizePinForStorage($pin);
        if ($normalized === null) {
            return;
        }

        foreach (self::usersWithPins($excludeUserId, false) as $user) {
            if (self::pinMatchesUser($normalized, $user)) {
                throw new AppServiceException(_r('auth_pin_taken'), 'pin_taken');
            }
        }
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

    public static function changePin(
        int $userId,
        string $currentPassword,
        string $newPin,
        string $confirmPin,
        bool $requireCurrentPassword = true
    ): void {
        $selectPinHash = self::hasPinHashColumn() ? ', pin_hash' : ', NULL AS pin_hash';
        $user = Database::row(
            'SELECT id, password, pin' . $selectPinHash . ' FROM users WHERE id=? LIMIT 1',
            [$userId]
        );

        if (!$user) {
            throw new AppServiceException(_r('err_not_found'), 'user_not_found');
        }

        if ($requireCurrentPassword && !password_verify($currentPassword, (string)$user['password'])) {
            throw new AppServiceException(_r('auth_current_password_invalid'), 'current_password_invalid');
        }

        $normalizedPin = self::normalizePinForStorage($newPin);
        $normalizedConfirmPin = self::normalizePinForStorage($confirmPin);

        if ($normalizedPin !== $normalizedConfirmPin) {
            throw new AppServiceException(_r('profile_pin_mismatch'), 'pin_mismatch');
        }

        if (self::pinMatchesUser($normalizedPin, $user)) {
            throw new AppServiceException(_r('profile_pin_same'), 'pin_same');
        }

        self::assertPinAvailable($normalizedPin, $userId);
        $storage = self::preparePinStorage($normalizedPin);

        if (self::hasPinHashColumn()) {
            Database::exec(
                'UPDATE users SET pin=?, pin_hash=?, updated_at=NOW() WHERE id=?',
                [$storage['pin'], $storage['pin_hash'], $userId]
            );
            return;
        }

        Database::exec(
            'UPDATE users SET pin=?, updated_at=NOW() WHERE id=?',
            [$storage['pin'], $userId]
        );
    }

    private static function normalizePinForComparison(string $pin): ?string
    {
        $pin = preg_replace('/\D+/', '', trim($pin)) ?? '';
        if ($pin === '' || strlen($pin) < self::PIN_MIN_LENGTH || strlen($pin) > self::PIN_MAX_LENGTH) {
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

        if (strlen($pin) < self::PIN_MIN_LENGTH || strlen($pin) > self::PIN_MAX_LENGTH) {
            throw new AppServiceException(_r('auth_pin_invalid'), 'invalid_pin');
        }

        return $pin;
    }

    /**
     * @return list<array{id:int,pin:?string,pin_hash:?string}>
     */
    private static function usersWithPins(int $excludeUserId = 0, bool $onlyActive = false): array
    {
        $hasPinHash = self::hasPinHashColumn();
        $conditions = [
            '((u.pin IS NOT NULL AND u.pin <> \'\')' . ($hasPinHash ? ' OR (u.pin_hash IS NOT NULL AND u.pin_hash <> \'\')' : '') . ')',
        ];
        $params = [];
        $selectPinHash = $hasPinHash ? 'u.pin_hash' : 'NULL AS pin_hash';

        if ($onlyActive) {
            $conditions[] = 'u.is_active = 1';
        }

        if ($excludeUserId > 0) {
            $conditions[] = 'u.id <> ?';
            $params[] = $excludeUserId;
        }

        return Database::all(
            'SELECT u.id, u.pin, ' . $selectPinHash . '
             FROM users u
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY u.id ASC',
            $params
        );
    }

    private static function pinMatchesUser(string $pin, array $user): bool
    {
        $plainPin = trim((string)($user['pin'] ?? ''));
        $pinHash = (string)($user['pin_hash'] ?? '');

        if ($pinHash !== '' && password_verify($pin, $pinHash)) {
            return true;
        }

        return $plainPin !== '' && hash_equals($plainPin, $pin);
    }
}
