<?php
/**
 * Lang — PHP multilingual support.
 *
 * HOW IT WORKS
 * ─────────────
 * Each language is a PHP file in /lang/ that returns a flat associative
 * array of string keys → translated strings.
 *
 *   Lang::init()           — Called once in bootstrap; resolves active lang.
 *   Lang::set('ru')        — Override current language.
 *   Lang::get('key')       — Return translated string.
 *   __('key')              — Global shorthand (HTML-escaped).
 *   _r('key')              — Global shorthand (raw, not escaped).
 *   __('key', ['n'=>5])    — Simple :placeholder substitution.
 *
 * LANGUAGE RESOLUTION ORDER
 *   1. logged-in user's explicit profile preference
 *   2. $_SESSION['lang'] (guest/login-page selection)
 *   3. DEFAULT_LANG constant
 */
class Lang
{
    private static string $current = DEFAULT_LANG;
    private static array  $strings = [];
    private static array  $fallbackStrings = [];
    private static array  $missingKeys = [];

    /** Resolve and load language, handle switch request. */
    public static function init(): void
    {
        $requestedLang = self::normalizeCode($_GET['lang'] ?? null);

        if ($requestedLang !== null) {
            $lang = $requestedLang;
            $_SESSION['lang'] = $lang;

            // Redirect to clean URL
            $clean = strtok($_SERVER['REQUEST_URI'], '?');
            $qs    = $_GET;
            unset($qs['lang']);
            redirect((string)$clean . ($qs ? '?' . http_build_query($qs) : ''));
        }

        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null;
        self::set(self::resolvePreferredCode($user, $_SESSION['lang'] ?? null));
    }

    public static function set(string $lang): void
    {
        self::$current = self::normalizeCode($lang) ?? DEFAULT_LANG;
        self::$missingKeys = [];

        self::$fallbackStrings = [];
        $fallbackFile = ROOT_PATH . '/lang/en.php';
        if (file_exists($fallbackFile)) {
            self::$fallbackStrings = require $fallbackFile;
        }

        $file = ROOT_PATH . '/lang/' . self::$current . '.php';
        self::$strings = file_exists($file) ? require $file : [];
    }

    public static function get(string $key, array $replace = []): string
    {
        if (array_key_exists($key, self::$strings)) {
            $str = self::$strings[$key];
        } elseif (self::$current !== 'en' && array_key_exists($key, self::$fallbackStrings)) {
            self::$missingKeys[$key] = true;
            $str = self::$fallbackStrings[$key];
        } else {
            self::$missingKeys[$key] = true;
            $str = $key;
        }

        foreach ($replace as $k => $v) {
            $str = str_replace(':' . $k, $v, $str);
        }
        return $str;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$strings) || array_key_exists($key, self::$fallbackStrings);
    }

    public static function current(): string { return self::$current; }
    public static function isRu(): bool       { return self::$current === 'ru'; }

    public static function all(): array { return SUPPORTED_LANGS; }

    public static function normalizeCode(?string $lang): ?string
    {
        $lang = strtolower(trim((string)$lang));
        return $lang !== '' && array_key_exists($lang, SUPPORTED_LANGS) ? $lang : null;
    }

    public static function hasExplicitUserPreference(?array $user): bool
    {
        if (!is_array($user) || self::normalizeCode($user['language'] ?? null) === null) {
            return false;
        }

        if (!array_key_exists('language_set_at', $user)) {
            return true;
        }

        return !empty($user['language_set_at']);
    }

    public static function resolvePreferredCode(?array $user = null, ?string $sessionLang = null): string
    {
        if (self::hasExplicitUserPreference($user)) {
            return self::normalizeCode($user['language'] ?? null) ?? DEFAULT_LANG;
        }

        return self::normalizeCode($sessionLang) ?? DEFAULT_LANG;
    }

    public static function missingKeys(): array
    {
        return array_keys(self::$missingKeys);
    }
}

/** HTML-escaped translation */
function __(string $key, array $replace = []): string
{
    return htmlspecialchars(Lang::get($key, $replace), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Raw (un-escaped) translation — use only for safe content */
function _r(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}
