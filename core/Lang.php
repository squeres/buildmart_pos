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
 *   1. ?lang=xx  query param  →  persists to session + user DB row
 *   2. $_SESSION['lang']
 *   3. logged-in user's DB preference
 *   4. DEFAULT_LANG constant
 */
class Lang
{
    private static string $current = DEFAULT_LANG;
    private static array  $strings = [];

    /** Resolve and load language, handle switch request. */
    public static function init(): void
    {
        // Handle explicit switch
        if (!empty($_GET['lang']) && array_key_exists($_GET['lang'], SUPPORTED_LANGS)) {
            $lang = $_GET['lang'];
            $_SESSION['lang'] = $lang;

            // Save to DB for logged-in users
            if (!empty($_SESSION['user']['id'])) {
                Database::exec('UPDATE users SET language=? WHERE id=?',
                    [$lang, $_SESSION['user']['id']]);
                $_SESSION['user']['language'] = $lang;
            }

            // Redirect to clean URL
            $clean = strtok($_SERVER['REQUEST_URI'], '?');
            $qs    = $_GET;
            unset($qs['lang']);
            header('Location: ' . $clean . ($qs ? '?' . http_build_query($qs) : ''));
            exit;
        }

        // Resolve priority
        $lang = $_SESSION['lang']
            ?? $_SESSION['user']['language']
            ?? DEFAULT_LANG;

        self::set(array_key_exists($lang, SUPPORTED_LANGS) ? $lang : DEFAULT_LANG);
    }

    public static function set(string $lang): void
    {
        self::$current = $lang;
        $file = ROOT_PATH . '/lang/' . $lang . '.php';
        self::$strings = file_exists($file) ? require $file : [];

        // Merge fallback (English) for missing keys
        if ($lang !== 'en') {
            $fb = ROOT_PATH . '/lang/en.php';
            if (file_exists($fb)) {
                self::$strings = array_merge(require $fb, self::$strings);
            }
        }
    }

    public static function get(string $key, array $replace = []): string
    {
        $str = self::$strings[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $str = str_replace(':' . $k, $v, $str);
        }
        return $str;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$strings);
    }

    public static function current(): string { return self::$current; }
    public static function isRu(): bool       { return self::$current === 'ru'; }

    public static function all(): array { return SUPPORTED_LANGS; }
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
