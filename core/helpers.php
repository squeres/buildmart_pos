<?php
/**
 * Global helper functions.
 */

// ── Output & security ─────────────────────────────────────────────

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $token);
}

// ── HTTP ──────────────────────────────────────────────────────────

function redirect(string $path, int $code = 302): never
{
    $url = (str_starts_with($path, 'http') || str_starts_with($path, '/'))
        ? $path
        : BASE_URL . '/' . ltrim($path, '/');

    if (!str_starts_with($url, 'http')) {
        $url = BASE_URL . $url;
    }

    header('Location: ' . $url, true, $code);
    exit;
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function current_url(): string
{
    return (isset($_SERVER['HTTPS']) ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . ($_SERVER['REQUEST_URI'] ?? '/');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Flash messages ────────────────────────────────────────────────

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_success(string $msg): void { flash('success', $msg); }
function flash_error(string $msg): void   { flash('error',   $msg); }
function flash_warning(string $msg): void { flash('warning', $msg); }
function flash_info(string $msg): void    { flash('info',    $msg); }

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ── Formatting ────────────────────────────────────────────────────

function money(float $amount, bool $withSymbol = true): string
{
    $sym = currency_symbol();
    $fmt = number_format($amount, 2, '.', ' ');
    return $withSymbol ? $fmt . ' ' . e($sym) : $fmt;
}

function normalize_currency_code(string $value): string
{
    $value = strtoupper(trim($value));
    $map = [
        '$'      => 'USD',
        'USD'    => 'USD',
        'DOLLAR' => 'USD',
        'ДОЛЛАР' => 'USD',
        '₽'      => 'RUB',
        'RUB'    => 'RUB',
        'РУБ'    => 'RUB',
        'РУБЛЬ'  => 'RUB',
        'ТЕНГЕ'  => 'KZT',
        'ТГ'     => 'KZT',
        '₸'      => 'KZT',
        'KZT'    => 'KZT',
        '€'      => 'EUR',
        'EUR'    => 'EUR',
        'EURO'   => 'EUR',
        'ЕВРО'   => 'EUR',
    ];
    return $map[$value] ?? 'KZT';
}

function currency_catalog(): array
{
    return [
        'USD' => ['symbol' => '$', 'label' => __('currency_usd'), 'name' => __('currency_name_usd')],
        'RUB' => ['symbol' => '₽', 'label' => __('currency_rub'), 'name' => __('currency_name_rub')],
        'KZT' => ['symbol' => '₸', 'label' => __('currency_kzt'), 'name' => __('currency_name_kzt')],
        'EUR' => ['symbol' => '€', 'label' => __('currency_eur'), 'name' => __('currency_name_eur')],
    ];
}

function current_currency_code(): string
{
    return normalize_currency_code(setting('currency_code', 'KZT'));
}

function currency_symbol(?string $code = null): string
{
    $catalog = currency_catalog();
    $code = $code ? normalize_currency_code($code) : current_currency_code();
    return $catalog[$code]['symbol'] ?? setting('currency_symbol', '₸');
}

function currency_name(?string $code = null): string
{
    $catalog = currency_catalog();
    $code = $code ? normalize_currency_code($code) : current_currency_code();
    return $catalog[$code]['name'] ?? __('currency_name_kzt');
}

function currency_options(): array
{
    $options = [];
    foreach (currency_catalog() as $code => $meta) {
        $options[$code] = $meta['label'];
    }
    return $options;
}

function qty_display(float $qty, string $unit): string
{
    // Suppress trailing zeros for whole quantities
    return $qty == intval($qty)
        ? number_format($qty, 0, '.', ' ') . ' ' . unit_label($unit)
        : rtrim(rtrim(number_format($qty, 3, '.', ' '), '0'), '.') . ' ' . unit_label($unit);
}

function unit_label(string $unit): string
{
    $map = [
        'pcs'  => __('unit_pcs'),
        'kg'   => __('unit_kg'),
        'g'    => __('unit_g'),
        't'    => __('unit_t'),
        'l'    => __('unit_l'),
        'ml'   => __('unit_ml'),
        'm'    => __('unit_m'),
        'm2'   => __('unit_m2'),
        'm3'   => __('unit_m3'),
        'pack' => __('unit_pack'),
        'roll' => __('unit_roll'),
        'bag'  => __('unit_bag'),
        'box'  => __('unit_box'),
        'pair' => __('unit_pair'),
        'set'  => __('unit_set'),
        'pallet' => 'Pallet',
    ];
    return $map[$unit] ?? e($unit);
}

function unit_options(): array
{
    return [
        'pcs'  => _r('unit_pcs'),
        'kg'   => _r('unit_kg'),
        'g'    => _r('unit_g'),
        't'    => _r('unit_t'),
        'l'    => _r('unit_l'),
        'ml'   => _r('unit_ml'),
        'm'    => _r('unit_m'),
        'm2'   => _r('unit_m2'),
        'm3'   => _r('unit_m3'),
        'pack' => _r('unit_pack'),
        'roll' => _r('unit_roll'),
        'bag'  => _r('unit_bag'),
        'box'  => _r('unit_box'),
        'pair' => _r('unit_pair'),
        'set'  => _r('unit_set'),
        'pallet' => 'Pallet',
    ];
}

function unit_preset_fallback_rows(): array
{
    return [
        ['unit_code' => 'korobka', 'unit_label' => 'Коробка'],
        ['unit_code' => 'upakovka', 'unit_label' => 'Упаковка'],
        ['unit_code' => 'shtuk', 'unit_label' => 'Штук'],
    ];
}

function unit_preset_rows(bool $includeInactive = false): array
{
    static $cache = [];
    $cacheKey = $includeInactive ? 'all' : 'active';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $sql = "SELECT id, unit_code, unit_label, sort_order, is_active
                FROM unit_presets";
        $params = [];
        if (!$includeInactive) {
            $sql .= " WHERE is_active=1";
        }
        $sql .= " ORDER BY sort_order, unit_label, id";
        $rows = Database::all($sql, $params);
        if ($rows) {
            return $cache[$cacheKey] = $rows;
        }
    } catch (Throwable $e) {
        // Fall back to built-in defaults until the migration is present.
    }

    return $cache[$cacheKey] = unit_preset_fallback_rows();
}

function unit_preset_options(): array
{
    $options = [];
    foreach (unit_preset_rows() as $row) {
        $options[(string)$row['unit_label']] = (string)$row['unit_label'];
    }
    return $options;
}

function unit_preset_exists(string $label): bool
{
    $label = trim($label);
    if ($label === '') {
        return false;
    }

    $targetLower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    foreach (unit_preset_rows(true) as $row) {
        $rowLower = function_exists('mb_strtolower') ? mb_strtolower((string)$row['unit_label'], 'UTF-8') : strtolower((string)$row['unit_label']);
        if ($rowLower === $targetLower) {
            return true;
        }
    }
    return false;
}

function create_unit_preset(string $label): array
{
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Unit label is required');
    }

    $targetLower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    foreach (unit_preset_rows(true) as $row) {
        $rowLower = function_exists('mb_strtolower') ? mb_strtolower((string)$row['unit_label'], 'UTF-8') : strtolower((string)$row['unit_label']);
        if ($rowLower === $targetLower) {
            return $row;
        }
    }

    $codeBase = product_unit_code($label);
    $code = $codeBase !== '' ? $codeBase : 'unit';

    try {
        $sortOrder = (int)(Database::value("SELECT COALESCE(MAX(sort_order), 0) FROM unit_presets") ?? 0) + 10;
        $suffix = 1;
        while (Database::value("SELECT id FROM unit_presets WHERE unit_code=?", [$code])) {
            $suffix++;
            $code = $codeBase !== '' ? ($codeBase . '_' . $suffix) : ('unit_' . $suffix);
        }

        $id = Database::insert(
            "INSERT INTO unit_presets (unit_code, unit_label, sort_order, is_active) VALUES (?,?,?,1)",
            [$code, $label, $sortOrder]
        );
    } catch (Throwable $e) {
        foreach (unit_preset_rows(true) as $row) {
            $rowLower = function_exists('mb_strtolower') ? mb_strtolower((string)$row['unit_label'], 'UTF-8') : strtolower((string)$row['unit_label']);
            if ($rowLower === $targetLower) {
                return $row;
            }
        }
        throw $e;
    }

    return [
        'id' => $id,
        'unit_code' => $code,
        'unit_label' => $label,
        'sort_order' => $sortOrder,
        'is_active' => 1,
    ];
}

function unit_storage_code_from_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return 'pcs';
    }

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);

    $map = [
        'коробка' => 'box',
        'кор.' => 'box',
        'ящик' => 'box',
        'упаковка' => 'pack',
        'упак.' => 'pack',
        'пачка' => 'pack',
        'штук' => 'pcs',
        'шт' => 'pcs',
        'шт.' => 'pcs',
        'штука' => 'pcs',
        'мешок' => 'bag',
        'меш.' => 'bag',
        'палета' => 'pallet',
        'паллет' => 'pallet',
        'рулон' => 'roll',
        'рул.' => 'roll',
        'комплект' => 'set',
        'компл.' => 'set',
        'пара' => 'pair',
        'кг' => 'kg',
        'килограмм' => 'kg',
        'г' => 'g',
        'гр' => 'g',
        'гр.' => 'g',
        'л' => 'l',
        'литр' => 'l',
        'мл' => 'ml',
        'м' => 'm',
        'м2' => 'm2',
        'м³' => 'm3',
        'м3' => 'm3',
        'т' => 't',
    ];

    return $map[$normalized] ?? 'pcs';
}

function product_unit_code(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }

    $code = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    $code = preg_replace('/\s+/u', '_', $code);
    return trim((string)$code, '_');
}

function product_unit_label_text(array $unit): string
{
    $label = trim((string)($unit['unit_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    return unit_label((string)($unit['unit_code'] ?? ''));
}

function product_unit_allows_fractional(array $unit, array $units, bool $isWeighable = false): bool
{
    $ratio = (float)($unit['ratio_to_base'] ?? 1.0);
    $maxRatio = 1.0;
    foreach ($units as $candidate) {
        $maxRatio = max($maxRatio, (float)($candidate['ratio_to_base'] ?? 1.0));
    }

    if (abs($ratio - $maxRatio) > 0.000001) {
        return false;
    }

    if ($isWeighable) {
        return true;
    }

    $code = (string)($unit['unit_code'] ?? '');
    $label = product_unit_label_text($unit);
    $normalizedCode = function_exists('mb_strtolower') ? mb_strtolower($code, 'UTF-8') : strtolower($code);
    $normalizedLabel = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

    return in_array($normalizedCode, ['kg', 'g', 'l', 'ml', 'm', 'm2', 'm3'], true)
        || str_contains($normalizedCode, 'weight')
        || str_contains($normalizedCode, 'вес')
        || str_contains($normalizedLabel, 'weight')
        || str_contains($normalizedLabel, 'вес');
}

function product_units(int $productId, ?string $baseUnit = null): array
{
    static $cache = [];
    $cacheKey = $productId . '|' . ($baseUnit ?? '');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rows = [];
    try {
        $rows = Database::all(
            "SELECT product_id, unit_code, unit_label, ratio_to_base, sort_order, is_default
             FROM product_units
             WHERE product_id=?
             ORDER BY sort_order ASC, ratio_to_base ASC, id ASC",
            [$productId]
        );
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!$rows) {
        if ($baseUnit === null) {
            $baseUnit = (string)(Database::value("SELECT unit FROM products WHERE id=?", [$productId]) ?: 'pcs');
        }
        $rows = [[
            'product_id' => $productId,
            'unit_code' => $baseUnit,
            'unit_label' => unit_label($baseUnit),
            'ratio_to_base' => 1.0,
            'sort_order' => 0,
            'is_default' => 1,
        ]];
    }

    return $cache[$cacheKey] = $rows;
}

function product_unit_map(int $productId, ?string $baseUnit = null): array
{
    $map = [];
    foreach (product_units($productId, $baseUnit) as $unit) {
        $map[(string)$unit['unit_code']] = $unit;
    }
    return $map;
}

function generate_product_barcode(): string
{
    do {
        $barcode = '20' . date('ymd') . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    } while (Database::value("SELECT id FROM products WHERE barcode=?", [$barcode]));

    return $barcode;
}

function normalize_product_units(array $rows, string $baseUnit, ?string $defaultUnitCode = null, ?string $baseUnitLabel = null): array
{
    $baseUnitLabel = trim((string)$baseUnitLabel);
    $normalized = [[
        'unit_code' => $baseUnit,
        'unit_label' => $baseUnitLabel !== '' ? $baseUnitLabel : unit_label($baseUnit),
        'ratio_to_base' => 1.0,
        'sort_order' => 0,
        'is_default' => !$defaultUnitCode || $defaultUnitCode === $baseUnit ? 1 : 0,
    ]];
    $seen = [$baseUnit => true];
    $sort = 10;

    foreach ($rows as $row) {
        $label = trim((string)($row['unit_label'] ?? ''));
        $ratio = (float)($row['ratio_to_base'] ?? 0);
        if ($label === '' || $ratio <= 0) {
            continue;
        }

        $code = product_unit_code($label);
        if ($code === '' || isset($seen[$code])) {
            continue;
        }

        $normalized[] = [
            'unit_code' => $code,
            'unit_label' => $label,
            'ratio_to_base' => $ratio,
            'sort_order' => $sort,
            'is_default' => $defaultUnitCode === $code ? 1 : 0,
        ];
        $seen[$code] = true;
        $sort += 10;
    }

    if (!array_filter($normalized, static fn($row) => !empty($row['is_default']))) {
        $normalized[0]['is_default'] = 1;
    }

    return $normalized;
}

function save_product_units(int $productId, string $baseUnit, array $rows, ?string $defaultUnitCode = null, ?string $baseUnitLabel = null): void
{
    $normalized = normalize_product_units($rows, $baseUnit, $defaultUnitCode, $baseUnitLabel);
    Database::exec("DELETE FROM product_units WHERE product_id=?", [$productId]);
    foreach ($normalized as $unit) {
        Database::insert(
            "INSERT INTO product_units (product_id, unit_code, unit_label, ratio_to_base, sort_order, is_default)
             VALUES (?,?,?,?,?,?)",
            [
                $productId,
                $unit['unit_code'],
                $unit['unit_label'],
                $unit['ratio_to_base'],
                $unit['sort_order'],
                $unit['is_default'],
            ]
        );
    }
}

function product_unit_price_overrides(int $productId): array
{
    static $cache = [];
    if (isset($cache[$productId])) {
        return $cache[$productId];
    }

    $map = [];
    try {
        $rows = Database::all(
            "SELECT pup.unit_code, pt.code AS price_type_code, pup.price
             FROM product_unit_prices pup
             JOIN price_types pt ON pt.id = pup.price_type_id
             WHERE pup.product_id = ?
             ORDER BY pup.unit_code, pt.sort_order",
            [$productId]
        );
        foreach ($rows as $row) {
            $map[(string)$row['unit_code']][(string)$row['price_type_code']] = (float)$row['price'];
        }
    } catch (Throwable $e) {
        $map = [];
    }

    return $cache[$productId] = $map;
}

function derive_unit_price(float $referencePrice, float $referenceRatio, float $targetRatio): float
{
    $referenceRatio = $referenceRatio > 0 ? $referenceRatio : 1.0;
    $targetRatio = $targetRatio > 0 ? $targetRatio : 1.0;
    return round($referencePrice * ($referenceRatio / $targetRatio), 2);
}

function price_relative_diff(float $left, float $right): float
{
    return abs($left - $right) / max(1.0, abs($right));
}

function stock_qty_round(float $value): float
{
    return round($value, 6);
}

function product_reference_price_data(
    int $productId,
    string $priceTypeCode,
    float $fallbackPrice,
    ?array $units = null,
    ?array $overrides = null
): array {
    $units = array_values($units ?? product_units($productId));
    $overrides = $overrides ?? product_unit_price_overrides($productId);
    $defaultUnit = $units[0] ?? ['unit_code' => '', 'ratio_to_base' => 1.0];
    foreach ($units as $unit) {
        if (!empty($unit['is_default'])) {
            $defaultUnit = $unit;
            break;
        }
    }
    $defaultCode = (string)($defaultUnit['unit_code'] ?? '');
    $defaultRatio = (float)($defaultUnit['ratio_to_base'] ?? 1.0);

    $rootUnit = $units[0] ?? $defaultUnit;
    foreach ($units as $unit) {
        if ((float)($unit['ratio_to_base'] ?? 1.0) < (float)($rootUnit['ratio_to_base'] ?? 1.0)) {
            $rootUnit = $unit;
        }
    }

    $rootCode = (string)($rootUnit['unit_code'] ?? $defaultCode);
    $rootRatio = (float)($rootUnit['ratio_to_base'] ?? 1.0);
    $defaultOverride = isset($overrides[$defaultCode][$priceTypeCode])
        ? (float)$overrides[$defaultCode][$priceTypeCode]
        : null;
    $rootOverride = isset($overrides[$rootCode][$priceTypeCode])
        ? (float)$overrides[$rootCode][$priceTypeCode]
        : null;
    $rootOverridePlausible = $rootOverride !== null
        && $rootOverride > 0
        && ($defaultOverride === null || $rootOverride > $defaultOverride);

    $rootExpected = null;
    if ($rootOverridePlausible) {
        $rootExpected = derive_unit_price($rootOverride, $rootRatio, $defaultRatio);
    }

    $resolved = $fallbackPrice > 0 ? $fallbackPrice : 0.0;
    if ($defaultOverride !== null && $defaultOverride > 0 && $rootExpected !== null && $rootExpected > 0) {
        $defaultLooksLegacy = $defaultOverride < ($rootExpected * 0.5) || $defaultOverride > ($rootExpected * 2.0);
        if ($defaultLooksLegacy) {
            if ($resolved <= 0 || price_relative_diff($resolved, $rootExpected) > 0.15) {
                $resolved = $rootExpected;
            }
        } else {
            $resolved = price_relative_diff($defaultOverride, $rootExpected) <= price_relative_diff($resolved, $rootExpected)
                ? $defaultOverride
                : $resolved;
        }
    } elseif ($defaultOverride !== null && $defaultOverride > 0 && $resolved <= 0) {
        $resolved = $defaultOverride;
    } elseif ($resolved <= 0 && $rootExpected !== null) {
        $resolved = $rootExpected;
    }

    return [
        'unit_code' => $defaultCode,
        'ratio' => $defaultRatio > 0 ? $defaultRatio : 1.0,
        'price' => $resolved > 0 ? $resolved : 0.0,
        'root_code' => $rootCode,
        'root_ratio' => $rootRatio > 0 ? $rootRatio : 1.0,
        'root_expected' => $rootExpected,
    ];
}

function product_unit_override_looks_legacy(float $overridePrice, float $legacyPrice, float $derivedPrice): bool
{
    return price_relative_diff($overridePrice, $legacyPrice) <= 0.05
        && price_relative_diff($overridePrice, $derivedPrice) > 0.15;
}

function product_unit_price(int $productId, string $unitCode, string $priceTypeCode, float $basePrice, ?array $units = null, ?array $overrides = null): float
{
    $units = $units ?? product_units($productId);
    $overrides = $overrides ?? product_unit_price_overrides($productId);

    $unitMap = [];
    foreach ($units as $unit) {
        $unitMap[(string)$unit['unit_code']] = $unit;
    }

    $reference = product_reference_price_data($productId, $priceTypeCode, $basePrice, $units, $overrides);
    $referencePrice = (float)$reference['price'];
    $referenceRatio = (float)$reference['ratio'];
    $targetRatio = (float)($unitMap[$unitCode]['ratio_to_base'] ?? 1.0);
    $derivedPrice = derive_unit_price($referencePrice, $referenceRatio, $targetRatio);

    if (isset($overrides[$unitCode][$priceTypeCode])) {
        $overridePrice = (float)$overrides[$unitCode][$priceTypeCode];
        if ($unitCode === (string)$reference['unit_code']) {
            if ($referencePrice <= 0 || price_relative_diff($overridePrice, $referencePrice) <= 0.15) {
                return $overridePrice;
            }
            return $referencePrice;
        }

        $legacyPrice = round($referencePrice / max(1.0, $targetRatio), 2);
        $looksImpossibleLarge = $targetRatio < $referenceRatio && $overridePrice <= $referencePrice;
        $looksImpossibleSmall = $targetRatio > $referenceRatio && $overridePrice >= $referencePrice;
        if (
            !$looksImpossibleLarge
            && !$looksImpossibleSmall
            && !product_unit_override_looks_legacy($overridePrice, $legacyPrice, $derivedPrice)
        ) {
            return $overridePrice;
        }
    }

    return $derivedPrice;
}

function product_unit_price_rows(array $units, array $priceTypes, array $basePrices, array $overrides = []): array
{
    $rows = [];
    $referenceCache = [];
    foreach (array_values($units) as $idx => $unit) {
        $unitCode = (string)$unit['unit_code'];
        $row = [
            'unit_code' => $unitCode,
            'unit_label' => product_unit_label_text($unit),
            'ratio_to_base' => (float)$unit['ratio_to_base'],
            'prices' => [],
            'manual' => [],
        ];
        foreach ($priceTypes as $priceType) {
            $code = (string)$priceType['code'];
            $basePrice = (float)($basePrices[$code] ?? 0);
            $referenceCache[$code] = $referenceCache[$code] ?? product_reference_price_data(0, $code, $basePrice, $units, $overrides);
            $reference = $referenceCache[$code];
            $row['prices'][$code] = product_unit_price(0, $unitCode, $code, $basePrice, $units, $overrides);

            $manual = false;
            if (isset($overrides[$unitCode][$code])) {
                $overridePrice = (float)$overrides[$unitCode][$code];
                if ($unitCode === (string)$reference['unit_code']) {
                    $manual = $reference['price'] <= 0 || price_relative_diff($overridePrice, (float)$reference['price']) <= 0.15;
                } else {
                    $legacyPrice = round((float)$reference['price'] / max(1.0, (float)$unit['ratio_to_base']), 2);
                    $manual = !product_unit_override_looks_legacy($overridePrice, $legacyPrice, (float)$row['prices'][$code]);
                }
            }
            $row['manual'][$code] = $manual;
        }
        $rows[] = $row;
    }
    return $rows;
}

function save_product_unit_prices(int $productId, array $units, array $priceRows, array $fallbackBasePrices = []): void
{
    try {
        $types = Database::all('SELECT id, code FROM price_types WHERE is_active = 1');
        Database::exec('DELETE FROM product_unit_prices WHERE product_id=?', [$productId]);
    } catch (Throwable $e) {
        return;
    }

    $typeMap = array_column($types, 'id', 'code');
    $priceRows = array_values($priceRows);
    $units = array_values($units);
    $defaultUnit = $units[0] ?? ['unit_code' => '', 'ratio_to_base' => 1];
    foreach ($units as $unit) {
        if (!empty($unit['is_default'])) {
            $defaultUnit = $unit;
            break;
        }
    }
    $defaultRatio = (float)($defaultUnit['ratio_to_base'] ?? 1.0);

    foreach ($units as $idx => $unit) {
        $unitCode = (string)$unit['unit_code'];
        $row = $priceRows[$idx] ?? [];
        foreach ($typeMap as $code => $typeId) {
            $rawValue = $row[$code] ?? null;
            if ($rawValue === '' || $rawValue === null) {
                if (!array_key_exists($code, $fallbackBasePrices)) {
                    continue;
                }
                $rawValue = derive_unit_price(
                    (float)$fallbackBasePrices[$code],
                    $defaultRatio,
                    (float)$unit['ratio_to_base']
                );
            }
            $price = sanitize_float($rawValue);
            Database::insert(
                'INSERT INTO product_unit_prices (product_id, unit_code, price_type_id, price) VALUES (?,?,?,?)',
                [$productId, $unitCode, $typeId, $price]
            );
        }
    }
}

function product_default_unit(int $productId, ?string $baseUnit = null): array
{
    $units = product_units($productId, $baseUnit);
    foreach ($units as $unit) {
        if (!empty($unit['is_default'])) {
            return $unit;
        }
    }
    return $units[0];
}

function product_resolve_unit(array $units, string $baseUnit, ?string $unitCode = null): array
{
    $units = array_values($units);
    if (!$units) {
        return [
            'unit_code' => $baseUnit,
            'unit_label' => unit_label($baseUnit),
            'ratio_to_base' => 1.0,
            'sort_order' => 0,
            'is_default' => 1,
        ];
    }

    $unitCode = trim((string)$unitCode);
    if ($unitCode !== '') {
        foreach ($units as $unit) {
            if ((string)($unit['unit_code'] ?? '') === $unitCode) {
                return $unit;
            }
        }
    }

    foreach ($units as $unit) {
        if (!empty($unit['is_default'])) {
            return $unit;
        }
    }

    foreach ($units as $unit) {
        if ((string)($unit['unit_code'] ?? '') === $baseUnit) {
            return $unit;
        }
    }

    return $units[0];
}

function product_qty_from_base_unit(float $baseQty, array $units, string $baseUnit, ?string $unitCode = null): float
{
    $unit = product_resolve_unit($units, $baseUnit, $unitCode);
    $ratio = (float)($unit['ratio_to_base'] ?? 1.0);
    return stock_qty_round($baseQty * max(1.0, $ratio));
}

function product_qty_to_base_unit(float $qty, array $units, string $baseUnit, ?string $unitCode = null): float
{
    $unit = product_resolve_unit($units, $baseUnit, $unitCode);
    $ratio = (float)($unit['ratio_to_base'] ?? 1.0);
    return stock_qty_round($qty / max(1.0, $ratio));
}

function product_unit_qty_text(float $qty, array $unit): string
{
    return fmtQty(stock_qty_round($qty)) . ' ' . product_unit_label_text($unit);
}

function product_formatted_qty_in_unit(float $baseQty, array $units, string $baseUnit, ?string $unitCode = null): string
{
    $unit = product_resolve_unit($units, $baseUnit, $unitCode);
    $qty = product_qty_from_base_unit($baseQty, $units, $baseUnit, (string)$unit['unit_code']);
    return product_unit_qty_text($qty, $unit);
}

function product_min_stock_data(array $product, ?array $units = null): array
{
    $baseUnit = (string)($product['unit'] ?? 'pcs');
    $productId = (int)($product['id'] ?? 0);
    $units = $units ?? ($productId > 0 ? product_units($productId, $baseUnit) : [[
        'unit_code' => $baseUnit,
        'unit_label' => unit_label($baseUnit),
        'ratio_to_base' => 1.0,
        'sort_order' => 0,
        'is_default' => 1,
    ]]);

    $baseQty = max(0.0, (float)($product['min_stock_qty'] ?? 0));
    $baseUnitRow = product_resolve_unit($units, $baseUnit, $baseUnit);
    $displayUnit = product_resolve_unit(
        $units,
        $baseUnit,
        (string)($product['min_stock_display_unit_code'] ?? '')
    );

    $displayQty = product_qty_from_base_unit(
        $baseQty,
        $units,
        $baseUnit,
        (string)$displayUnit['unit_code']
    );

    $baseText = product_unit_qty_text($baseQty, $baseUnitRow);
    $displayText = product_unit_qty_text($displayQty, $displayUnit);
    $fullText = (string)$displayUnit['unit_code'] === (string)$baseUnitRow['unit_code']
        ? $displayText
        : ($displayText . ' (' . $baseText . ')');

    return [
        'base_qty' => $baseQty,
        'base_unit_code' => (string)$baseUnitRow['unit_code'],
        'base_unit_label' => product_unit_label_text($baseUnitRow),
        'base_text' => $baseText,
        'display_qty' => $displayQty,
        'display_unit_code' => (string)$displayUnit['unit_code'],
        'display_unit_label' => product_unit_label_text($displayUnit),
        'display_text' => $displayText,
        'full_text' => $fullText,
    ];
}

function product_stock_breakdown(float $baseQty, array $units, string $baseUnit): string
{
    if ($baseQty <= 0) {
        return qty_display(0, $baseUnit);
    }

    usort($units, static fn($a, $b) => (float)$a['ratio_to_base'] <=> (float)$b['ratio_to_base']);
    $maxRatio = 1.0;
    foreach ($units as $unit) {
        $maxRatio = max($maxRatio, (float)$unit['ratio_to_base']);
    }

    $remainingSmallest = stock_qty_round($baseQty * $maxRatio);
    if (abs($remainingSmallest - round($remainingSmallest)) < 0.001) {
        $remainingSmallest = round($remainingSmallest);
    }
    $parts = [];

    foreach ($units as $unit) {
        $ratio = (float)$unit['ratio_to_base'];
        if ($ratio <= 0) {
            continue;
        }
        $unitSmallestFactor = $maxRatio / $ratio;
        if ($unitSmallestFactor <= 0) {
            continue;
        }

        $qty = $ratio === $maxRatio
            ? stock_qty_round($remainingSmallest / $unitSmallestFactor)
            : floor(($remainingSmallest + 0.000001) / $unitSmallestFactor);

        if (abs($qty - round($qty)) < 0.001) {
            $qty = round($qty);
        }

        if ($qty <= 0) {
            continue;
        }

        $remainingSmallest = stock_qty_round($remainingSmallest - ($qty * $unitSmallestFactor));
        if (abs($remainingSmallest - round($remainingSmallest)) < 0.001) {
            $remainingSmallest = round($remainingSmallest);
        }
        $parts[] = (($qty == intval($qty))
            ? number_format((float)$qty, 0, '.', ' ')
            : rtrim(rtrim(number_format((float)$qty, 3, '.', ' '), '0'), '.'))
            . ' ' . product_unit_label_text($unit);
    }

    return $parts ? implode(' + ', $parts) : qty_display($baseQty, $baseUnit);
}

function date_fmt(string $datetime, string $format = 'd.m.Y H:i'): string
{
    if (!$datetime) {
        return '—';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return e($datetime);
    }

    return localize_date_output(date($format, $timestamp));
}

function date_now(string $format = 'd.m.Y H:i'): string
{
    return localize_date_output(date($format));
}

function localize_date_output(string $formatted): string
{
    if (!Lang::isRu()) {
        return $formatted;
    }

    static $replacements = [
        'Monday' => 'Понедельник',
        'Tuesday' => 'Вторник',
        'Wednesday' => 'Среда',
        'Thursday' => 'Четверг',
        'Friday' => 'Пятница',
        'Saturday' => 'Суббота',
        'Sunday' => 'Воскресенье',
        'Mon' => 'Пн',
        'Tue' => 'Вт',
        'Wed' => 'Ср',
        'Thu' => 'Чт',
        'Fri' => 'Пт',
        'Sat' => 'Сб',
        'Sun' => 'Вс',
        'January' => 'января',
        'February' => 'февраля',
        'March' => 'марта',
        'April' => 'апреля',
        'May' => 'мая',
        'June' => 'июня',
        'July' => 'июля',
        'August' => 'августа',
        'September' => 'сентября',
        'October' => 'октября',
        'November' => 'ноября',
        'December' => 'декабря',
        'Jan' => 'янв',
        'Feb' => 'фев',
        'Mar' => 'мар',
        'Apr' => 'апр',
        'Jun' => 'июн',
        'Jul' => 'июл',
        'Aug' => 'авг',
        'Sep' => 'сен',
        'Oct' => 'окт',
        'Nov' => 'ноя',
        'Dec' => 'дек',
    ];

    return strtr($formatted, $replacements);
}

function current_timezone(): string
{
    $timezone = setting('timezone', getenv('TZ') ?: 'Asia/Almaty');
    return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Almaty';
}

function timezone_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $now = new DateTimeImmutable('now');
    $options = [];
    foreach (timezone_identifiers_list() as $timezone) {
        $tz = new DateTimeZone($timezone);
        $offset = $tz->getOffset($now);
        $sign = $offset >= 0 ? '+' : '-';
        $hours = str_pad((string)floor(abs($offset) / 3600), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string)((abs($offset) % 3600) / 60), 2, '0', STR_PAD_LEFT);
        $options[$timezone] = sprintf('(UTC%s%s:%s) %s', $sign, $hours, $minutes, str_replace('_', ' ', $timezone));
    }

    asort($options, SORT_NATURAL);
    return $options;
}

// ── Settings ─────────────────────────────────────────────────────

function setting(string $key, mixed $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = Database::row('SELECT value FROM settings WHERE `key` = ? LIMIT 1', [$key]);
        $cache[$key] = $row !== null ? (string)$row['value'] : null;
    }
    return $cache[$key] ?? $default;
}

function setting_group_label(string $group): string
{
    foreach (['set_group_' . $group, 'set_' . $group] as $key) {
        if (Lang::has($key)) {
            return __($key);
        }
    }

    return e(humanize_key($group));
}

function setting_label(array $setting): string
{
    $key = (string)($setting['key'] ?? '');
    $translationKeys = [
        'set_key_' . $key,
        'set_' . $key,
    ];

    if (str_starts_with($key, 'gr_label_')) {
        $translationKeys[] = 'gr_set_lbl_' . substr($key, strlen('gr_label_'));
    } elseif (str_starts_with($key, 'gr_')) {
        $translationKeys[] = 'gr_set_' . substr($key, 3);
    }

    foreach ($translationKeys as $translationKey) {
        if (Lang::has($translationKey)) {
            return __($translationKey);
        }
    }

    $fallback = trim((string)($setting['label'] ?? ''));
    if ($fallback !== '') {
        return e($fallback);
    }

    return e(humanize_key($key));
}

function humanize_key(string $value): string
{
    $value = trim(str_replace(['_', '-'], ' ', $value));
    return $value === '' ? '' : ucwords($value);
}

// ── Product helpers ───────────────────────────────────────────────

function product_name(array $p): string
{
    return Lang::isRu() && !empty($p['name_ru']) ? $p['name_ru'] : $p['name_en'];
}

function category_name(array $c): string
{
    return Lang::isRu() && !empty($c['name_ru']) ? $c['name_ru'] : $c['name_en'];
}

function customer_type_normalize(?string $type): string
{
    $type = strtolower(trim((string)$type));
    return $type === 'legal' ? 'legal' : 'individual';
}

function customer_type_label(string $type): string
{
    return __(customer_type_normalize($type) === 'legal' ? 'cust_type_legal' : 'cust_type_individual');
}

function customer_is_legal(array $customer): bool
{
    return customer_type_normalize((string)($customer['customer_type'] ?? 'individual')) === 'legal';
}

function sale_customer_snapshot(array $sale, ?array $customer = null): array
{
    $type = customer_type_normalize(
        (string)($sale['customer_type_snapshot'] ?? ($customer['customer_type'] ?? 'individual'))
    );

    $name = trim((string)($sale['customer_name_snapshot'] ?? ($customer['name'] ?? ($sale['customer_name'] ?? ''))));
    $company = trim((string)($sale['customer_company_snapshot'] ?? ($customer['company'] ?? '')));
    $iinBin = trim((string)($sale['customer_iin_bin_snapshot'] ?? ($customer['inn'] ?? '')));
    $address = trim((string)($sale['customer_address_snapshot'] ?? ($customer['address'] ?? '')));
    $phone = trim((string)($sale['customer_phone_snapshot'] ?? ($customer['phone'] ?? ($sale['customer_phone'] ?? ''))));
    $email = trim((string)($sale['customer_email_snapshot'] ?? ($customer['email'] ?? '')));

    return [
        'type' => $type,
        'name' => $name,
        'company' => $company,
        'iin_bin' => $iinBin,
        'address' => $address,
        'phone' => $phone,
        'email' => $email,
        'display_name' => $company !== '' ? $company : ($name !== '' ? $name : __('pos_walk_in')),
    ];
}

function sale_is_legal(array $sale, ?array $customer = null): bool
{
    return sale_customer_snapshot($sale, $customer)['type'] === 'legal';
}

function sale_document_urls(int $saleId, string $customerType = 'individual'): array
{
    return [
        'receipt_url' => url('modules/pos/receipt.php?id=' . $saleId),
        'fiscal_receipt_url' => null,
        'invoice_url' => null,
    ];
}

function business_entity_snapshot(array $entity): array
{
    return [
        'name' => trim((string)($entity['name'] ?? '')),
        'legal_name' => trim((string)($entity['legal_name'] ?? '')),
        'iin_bin' => trim((string)($entity['iin_bin'] ?? '')),
        'address' => trim((string)($entity['address'] ?? '')),
        'phone' => trim((string)($entity['phone'] ?? '')),
        'email' => trim((string)($entity['email'] ?? '')),
        'responsible_name' => trim((string)($entity['responsible_name'] ?? '')),
        'responsible_position' => trim((string)($entity['responsible_position'] ?? '')),
        'released_by_name' => trim((string)($entity['released_by_name'] ?? '')),
        'chief_accountant_name' => trim((string)($entity['chief_accountant_name'] ?? '')),
    ];
}

function sale_invoice_urls(int $invoiceId): array
{
    return [
        'view_url' => url('modules/sale_invoices/view.php?id=' . $invoiceId),
        'print_url' => url('modules/sale_invoices/print.php?id=' . $invoiceId),
        'excel_url' => url('modules/sale_invoices/export_excel.php?id=' . $invoiceId),
    ];
}

function sale_invoice_for_sale(int $saleId): ?array
{
    try {
        $row = Database::row(
            "SELECT si.*, be.name AS business_entity_name
             FROM sale_invoices si
             LEFT JOIN business_entities be ON be.id = si.business_entity_id
             WHERE si.sale_id = ?
             LIMIT 1",
            [$saleId]
        );
    } catch (Throwable $e) {
        return null;
    }

    return $row ?: null;
}
// ── Pagination ────────────────────────────────────────────────────

function paginate(int $total, int $page, int $perPage = ITEMS_PER_PAGE): array
{
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return compact('total', 'page', 'pages', 'perPage', 'offset');
}

// ── Receipt / ID generation ───────────────────────────────────────

function generate_receipt_no(): string
{
    return 'RCP-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

function generate_return_no(): string
{
    return 'RTN-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

// ── Stock status ──────────────────────────────────────────────────

function stock_status(float $qty, float $min): string
{
    if ($qty <= 0)         return 'out';
    if ($min > 0 && $qty <= $min) return 'low';
    return 'ok';
}

function stock_badge(float $qty, float $min): string
{
    $s = stock_status($qty, $min);
    $map = [
        'out' => ['danger',  __('out_of_stock')],
        'low' => ['warning', __('low_stock')],
        'ok'  => ['success', __('in_stock')],
    ];
    [$cls, $lbl] = $map[$s];
    return '<span class="badge badge-' . $cls . '">' . $lbl . '</span>';
}

// ── Sanitize ──────────────────────────────────────────────────────

function sanitize(mixed $v): string
{
    return trim(strip_tags((string)$v));
}

function sanitize_float(mixed $v): float
{
    return (float)filter_var(str_replace(',', '.', (string)$v), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

// ── Movement type label ───────────────────────────────────────────

function movement_label(string $type): string
{
    $map = [
        'receipt'    => __('mv_receipt'),
        'sale'       => __('mv_sale'),
        'return'     => __('mv_return'),
        'adjustment' => __('mv_adjustment'),
        'writeoff'   => __('mv_writeoff'),
        'transfer'   => __('mv_transfer'),
    ];
    return $map[$type] ?? e($type);
}

function movement_badge_class(string $type): string
{
    return match($type) {
        'receipt'    => 'success',
        'sale'       => 'info',
        'return'     => 'warning',
        'adjustment' => 'secondary',
        'writeoff'   => 'danger',
        default      => 'secondary',
    };
}
function fmtQty(float $n): string {
    if ((float)(int)$n === (float)$n) {
        return (string)(int)$n;
    }
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}
// ── Transfer helpers ──────────────────────────────────────────────

function transfer_status_badge(string $status, string $size = ''): string
{
    $map = [
        'draft'     => ['secondary', 'tr_status_draft'],
        'posted'    => ['success',   'tr_status_posted'],
        'cancelled' => ['danger',    'tr_status_cancelled'],
    ];
    [$cls, $key] = $map[$status] ?? ['secondary', $status];
    $style = $size === 'lg' ? ' style="font-size:13px;padding:5px 12px"' : '';
    return '<span class="badge badge-' . $cls . '"' . $style . '>' . __($key) . '</span>';
}
