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
        $user = AuthService::attemptPassword($email, $password);
        if (!$user) {
            return false;
        }

        $user = self::syncLoginLanguagePreference($user);
        self::syncUserSession($user, true);
        return true;
    }

    /** Attempt login with PIN (for quick cashier login). */
    public static function attemptPin(string $pin): bool
    {
        $user = AuthService::attemptPin($pin);
        if (!$user) {
            return false;
        }
        $user = self::syncLoginLanguagePreference($user);
        self::syncUserSession($user, true);
        return true;
    }

    public static function refreshCurrentUser(): void
    {
        if (!self::check()) {
            return;
        }

        $user = AuthService::getUserForSession(self::id());
        if ($user) {
            self::syncUserSession($user, false);
        }
    }

    /** Destroy session and log out. */
    public static function logout(): void
    {
        $flash = $_SESSION['flash'] ?? [];
        $lang = Lang::normalizeCode($_SESSION['lang'] ?? null) ?? DEFAULT_LANG;

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        session_start();
        session_regenerate_id(true);

        if ($flash) {
            $_SESSION['flash'] = $flash;
        }
        $_SESSION['lang'] = $lang;

        redirect('/modules/auth/login.php');
    }

    // ── Internal ──────────────────────────────────────────────────

    private static function syncUserSession(array $user, bool $regenerateSessionId = false): void
    {
        if ($regenerateSessionId) {
            session_regenerate_id(true);
        }

        $perms = json_decode($user['permissions'] ?? '{}', true) ?? [];
        $profileLanguage = Lang::normalizeCode($user['language'] ?? null) ?? DEFAULT_LANG;
        $effectiveLanguage = Lang::resolvePreferredCode($user, $_SESSION['lang'] ?? null);

        $_SESSION['user'] = [
            'id'                   => (int)$user['id'],
            'name'                 => $user['name'],
            'email'                => $user['email'],
            'phone'                => $user['phone'] ?? null,
            'role_id'              => (int)$user['role_id'],
            'role_slug'            => $user['role_slug'],
            'role_name'            => $user['role_name'],
            'permissions'          => $perms,
            'language'             => $profileLanguage,
            'language_set_at'      => $user['language_set_at'] ?? null,
            'default_warehouse_id' => (int)($user['default_warehouse_id'] ?? 1),
            'must_change_password' => !empty($user['must_change_password']),
        ];

        // Sync session lang
        $_SESSION['lang'] = $effectiveLanguage;

        if ($regenerateSessionId) {
            // Update login audit only on actual sign-in.
            if (function_exists('shift_schema_has_column') && shift_schema_has_column('users', 'last_seen_at')) {
                Database::exec('UPDATE users SET last_login = NOW(), last_seen_at = NOW() WHERE id = ?', [$user['id']]);
            } else {
                Database::exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
            }
        }
    }

    private static function syncLoginLanguagePreference(array $user): array
    {
        $selectedLanguage = Lang::normalizeCode($_SESSION['lang'] ?? null);
        if ($selectedLanguage === null || empty($user['id'])) {
            return $user;
        }

        $hasLanguageSetAt = function_exists('shift_schema_has_column') && shift_schema_has_column('users', 'language_set_at');
        $currentLanguage = Lang::normalizeCode($user['language'] ?? null) ?? DEFAULT_LANG;
        $hasExplicitLanguage = !$hasLanguageSetAt || !empty($user['language_set_at']);

        if ($currentLanguage === $selectedLanguage && $hasExplicitLanguage) {
            return $user;
        }

        if ($hasLanguageSetAt) {
            Database::exec(
                'UPDATE users SET language = ?, language_set_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$selectedLanguage, (int)$user['id']]
            );
            $user['language_set_at'] = date('Y-m-d H:i:s');
        } else {
            Database::exec(
                'UPDATE users SET language = ?, updated_at = NOW() WHERE id = ?',
                [$selectedLanguage, (int)$user['id']]
            );
        }

        $user['language'] = $selectedLanguage;

        return $user;
    }

    public static function clearMustChangePassword(): void
    {
        if (!empty($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = false;
        }
    }
}
