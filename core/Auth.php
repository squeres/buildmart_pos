<?php
/**
 * Auth — session-based authentication and RBAC.
 */
class Auth
{
    /** Check if user is logged in. */
    public static function check(): bool
    {
        return !empty($_SESSION['user']['id']);
    }

    /** Get current user array. */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /** Get current user ID. */
    public static function id(): int
    {
        return (int)($_SESSION['user']['id'] ?? 0);
    }

    /** Get current user's role slug. */
    public static function role(): string
    {
        return $_SESSION['user']['role_slug'] ?? '';
    }

    public static function mustChangePassword(): bool
    {
        return !empty($_SESSION['user']['must_change_password']);
    }

    /** Check if current user has a permission. */
    public static function can(string $perm): bool
    {
        $perms = $_SESSION['user']['permissions'] ?? [];
        return !empty($perms['all']) || !empty($perms[$perm]);
    }

    /** Check whether current user is manager or admin. */
    public static function isManagerOrAdmin(): bool
    {
        return self::can('all') || self::role() === 'manager';
    }

    /** Require login — redirect to login if not authenticated. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            redirect('/modules/auth/login.php');
        }
    }

    /** Require specific permission — 403 if missing. */
    public static function requirePerm(string $perm): void
    {
        self::requireLogin();
        if (!self::can($perm)) {
            http_response_code(403);
            include ROOT_PATH . '/views/partials/403.php';
            exit;
        }
    }

    /** Require manager or admin role. */
    public static function requireManagerOrAdmin(): void
    {
        self::requireLogin();
        if (!self::isManagerOrAdmin()) {
            http_response_code(403);
            include ROOT_PATH . '/views/partials/403.php';
            exit;
        }
    }

    /** Attempt login with email + password. Returns bool. */
    public static function attempt(string $email, string $password): bool
    {
        $user = Database::row(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = ? AND u.is_active = 1
             LIMIT 1',
            [trim($email)]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        self::_startSession($user);
        return true;
    }

    /** Attempt login with PIN (for quick cashier login). */
    public static function attemptPin(string $pin): bool
    {
        if (strlen(trim($pin)) < 4) return false;

        $user = Database::row(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.pin = ? AND u.is_active = 1
             LIMIT 1',
            [trim($pin)]
        );

        if (!$user) return false;
        self::_startSession($user);
        return true;
    }

    /** Destroy session and log out. */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        redirect('/modules/auth/login.php');
    }

    // ── Internal ──────────────────────────────────────────────────

    private static function _startSession(array $user): void
    {
        session_regenerate_id(true);

        $perms = json_decode($user['permissions'] ?? '{}', true) ?? [];

        $_SESSION['user'] = [
            'id'                   => (int)$user['id'],
            'name'                 => $user['name'],
            'email'                => $user['email'],
            'role_id'              => (int)$user['role_id'],
            'role_slug'            => $user['role_slug'],
            'role_name'            => $user['role_name'],
            'permissions'          => $perms,
            'language'             => $user['language'] ?? DEFAULT_LANG,
            'default_warehouse_id' => (int)($user['default_warehouse_id'] ?? 1),
            'must_change_password' => !empty($user['must_change_password']),
        ];

        // Sync session lang
        $_SESSION['lang'] = $user['language'] ?? DEFAULT_LANG;

        // Update last login and presence when the presence schema is available.
        if (function_exists('shift_schema_has_column') && shift_schema_has_column('users', 'last_seen_at')) {
            Database::exec('UPDATE users SET last_login = NOW(), last_seen_at = NOW() WHERE id = ?', [$user['id']]);
        } else {
            Database::exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
        }
    }

    public static function clearMustChangePassword(): void
    {
        if (!empty($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = false;
        }
    }
}
