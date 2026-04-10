<?php
/**
 * UISettings — Configuration-driven UI customization system.
 *
 * Loads and merges settings at 4 levels (priority low→high):
 *   1. system  — global defaults stored in ui_presets (scope_type = 'system')
 *   2. role    — per-role overrides  (scope_type = 'role',      scope_id = role_id)
 *   3. warehouse — per-store overrides (scope_type = 'warehouse', scope_id = wh_id)
 *   4. user    — personal last-saved state in user_preferences
 *
 * Usage:
 *   $cfg = UISettings::get('products');           // merged config
 *   $cfg = UISettings::get('pos', $warehouseId);  // with warehouse override
 *   UISettings::save('pos', $data);               // save user prefs
 *   UISettings::menu();                           // merged sidebar config
 */
class UISettings
{
    // ── Static cache for request lifetime ─────────────────────────
    private static array $cache = [];

    // ── Module definitions (default schema for each module) ───────
    private static array $moduleDefaults = [
        'sidebar' => [
            'items'  => ['dashboard','pos','products','categories','inventory','inventory_count',
                         'receipts','acceptance','transfers','sales','customers',
                         'shifts','reports','suppliers','warehouses','users','settings'],
            'hidden' => [],
            'pinned' => ['dashboard'],
        ],
        'products' => [
            'view_mode'      => 'table',
            'columns'        => ['name','sku','category','purchase','retail','stock','status','actions'],
            'columns_order'  => ['name','sku','category','purchase','retail','stock','status','actions'],
            'sort_by'        => 'name',
            'sort_dir'       => 'asc',
            'per_page'       => 30,
            'group_by_category' => 0,
            'filters'        => ['search'=>'','category_id'=>'','status'=>'','stock_filter'=>''],
        ],
        'pos' => [
            'view_mode'           => 'cards',
            'show_categories_bar' => 1,
            'show_search'         => 1,
            'show_stock'          => 1,
            'show_sku'            => 0,
            'price_type'          => 'retail',
            'price_types_visible' => ['retail'],
            'large_touch'         => 0,
            'columns_list'        => ['name','price','stock','add_btn'],
        ],
        'inventory' => [
            'view_mode'      => 'table',
            'stock_display'  => 'summarized',
            'columns'        => ['name','sku','category','warehouse','stock','min_stock','status','actions'],
            'sort_by'        => 'name',
            'sort_dir'       => 'asc',
            'filters'        => ['search'=>'','category_id'=>'','warehouse_id'=>'','stock_filter'=>''],
        ],
        'sales' => [
            'columns'  => ['receipt_no','date','cashier','customer','total','status','payment_method','actions'],
            'sort_by'  => 'created_at',
            'sort_dir' => 'desc',
            'filters'  => ['search'=>'','date_from'=>'','date_to'=>'','status'=>'','cashier_id'=>'','payment_method'=>''],
        ],
        'receipts' => [
            'columns'  => ['doc_no','date','supplier','warehouse','amount','status','created_by','actions'],
            'sort_by'  => 'created_at',
            'sort_dir' => 'desc',
            'filters'  => ['search'=>'','date_from'=>'','date_to'=>'','supplier_id'=>'','warehouse_id'=>'','status'=>''],
        ],
        'transfers' => [
            'columns'  => ['doc_no','date','from_wh','to_wh','items_count','total_qty','status','created_by','actions'],
            'sort_by'  => 'created_at',
            'sort_dir' => 'desc',
            'filters'  => ['status'=>'','from_wh'=>'','to_wh'=>'','date_from'=>'','date_to'=>''],
        ],
        'customers' => [
            'columns'  => ['name','phone','company','discount','total_spent','purchase_count','last_visit','actions'],
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'filters'  => ['search'=>''],
        ],
        'acceptance' => [
            'columns'  => ['doc_no','date','supplier','warehouse','status','user','items_count','amount','actions'],
            'sort_by'  => 'created_at',
            'sort_dir' => 'desc',
            'filters'  => ['search'=>'','status'=>'','supplier_id'=>'','warehouse_id'=>'','date_from'=>'','date_to'=>''],
        ],
        'users' => [
            'columns'  => ['name','email','role','language','last_login','status','actions'],
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'filters'  => ['role_id'=>'','status'=>''],
        ],
        'shifts' => [
            'columns'  => ['cashier','opened_at','closed_at','opening_cash','sales_amount','returns_amount','ops_count','status','warehouse','actions'],
            'sort_by'  => 'opened_at',
            'sort_dir' => 'desc',
            'filters'  => ['date_from'=>'','date_to'=>'','cashier_id'=>'','status'=>'','warehouse_id'=>''],
        ],
        'suppliers' => [
            'columns'  => ['name','contact','phone','email','inn','docs_count','status','actions'],
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'filters'  => ['search'=>'','status'=>''],
        ],
        'warehouses' => [
            'columns'  => ['name','address','docs_count','status','actions'],
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'filters'  => ['status'=>''],
        ],
        'categories' => [
            'columns'  => ['name','product_count','status','actions'],
            'sort_by'  => 'name',
            'sort_dir' => 'asc',
            'filters'  => ['search'=>'','status'=>''],
        ],
        'reports' => [
            'default_period' => 'today',
            'widgets'        => ['revenue','profit','sales_count','avg_receipt','top_products','category_chart'],
            'charts'         => ['revenue_chart','category_pie'],
            'default_warehouse_id' => null,
        ],
        'dashboard' => [
            'widgets' => ['revenue_today','sales_today','low_stock','recent_sales','best_sellers','quick_actions'],
            'period'  => 'today',
            'warehouse_id' => null,
        ],
        'global' => [
            'visible_prices'        => ['retail'],
            'can_see_purchase_price' => 0,
            'can_see_profit'         => 0,
        ],
    ];

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Get merged settings for a module.
     * Priority: system → role → warehouse → user
     *
     * @param  string   $module       Module name (products, pos, sidebar, …)
     * @param  int|null $warehouseId  Override warehouse (uses session default if null)
     * @return array
     */
    public static function get(string $module, ?int $warehouseId = null): array
    {
        $user        = Auth::user();
        $roleId      = (int)($user['role_id'] ?? 0);
        $userId      = (int)($user['id'] ?? 0);
        $warehouseId = $warehouseId ?? (int)($user['default_warehouse_id'] ?? 0);

        $cacheKey = "{$module}:{$roleId}:{$warehouseId}:{$userId}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Start from PHP defaults
        $merged = self::$moduleDefaults[$module] ?? [];

        // 1. System preset (is_default = 1, scope_type = system)
        $sys = self::loadPreset($module, 'system', null);
        if ($sys) $merged = self::deepMerge($merged, $sys);

        // 2. Role preset
        if ($roleId) {
            $role = self::loadPreset($module, 'role', $roleId);
            if ($role) $merged = self::deepMerge($merged, $role);
        }

        // 3. Warehouse preset
        if ($warehouseId) {
            $wh = self::loadWarehouseSettings($module, $warehouseId);
            if ($wh) $merged = self::deepMerge($merged, $wh);
        }

        // 4. User preferences (highest priority)
        if ($userId) {
            $up = self::loadUserPrefs($module, $userId);
            if ($up) $merged = self::deepMerge($merged, $up);
        }

        self::$cache[$cacheKey] = $merged;
        return $merged;
    }

    /**
     * Get sidebar/menu config with visibility applied.
     */
    public static function menu(?int $warehouseId = null): array
    {
        return self::get('sidebar', $warehouseId);
    }

    /**
     * Save user preferences for a module.
     */
    public static function save(string $module, array $settings): bool
    {
        $userId = Auth::id();
        if (!$userId) return false;

        $json = json_encode($settings, JSON_UNESCAPED_UNICODE);

        $existing = Database::row(
            'SELECT id FROM user_preferences WHERE user_id = ? AND module = ? LIMIT 1',
            [$userId, $module]
        );

        if ($existing) {
            Database::exec(
                'UPDATE user_preferences SET settings_json = ?, updated_at = NOW() WHERE id = ?',
                [$json, $existing['id']]
            );
        } else {
            Database::exec(
                'INSERT INTO user_preferences (user_id, module, settings_json) VALUES (?, ?, ?)',
                [$userId, $module, $json]
            );
        }

        // Bust cache
        self::bustCache($module);
        return true;
    }

    /**
     * Save a named preset (user, role, warehouse, system scope).
     */
    public static function savePreset(
        string $name,
        string $module,
        array  $settings,
        string $scopeType = 'user',
        ?int   $scopeId   = null,
        bool   $isDefault = false
    ): int {
        $json    = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $creator = Auth::id() ?: null;

        // If marking as default, unset previous defaults for same scope
        if ($isDefault) {
            Database::exec(
                'UPDATE ui_presets SET is_default = 0
                 WHERE module = ? AND scope_type = ? AND scope_id <=> ?',
                [$module, $scopeType, $scopeId]
            );
        }

        $id = Database::insert(
            'INSERT INTO ui_presets (name, module, scope_type, scope_id, is_default, settings_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $module, $scopeType, $scopeId, $isDefault ? 1 : 0, $json, $creator]
        );

        self::bustCache($module);
        return $id;
    }

    /**
     * Update a named preset.
     */
    public static function updatePreset(int $presetId, array $settings, ?string $name = null): bool
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $set  = 'settings_json = ?, updated_at = NOW()';
        $params = [$json];

        if ($name !== null) {
            $set = 'name = ?, ' . $set;
            array_unshift($params, $name);
        }
        $params[] = $presetId;

        Database::exec("UPDATE ui_presets SET {$set} WHERE id = ?", $params);
        self::bustCache('');
        return true;
    }

    /**
     * Delete a preset.
     */
    public static function deletePreset(int $presetId): bool
    {
        Database::exec('DELETE FROM ui_presets WHERE id = ?', [$presetId]);
        self::bustCache('');
        return true;
    }

    /**
     * List presets for a module visible to current user.
     * Returns: system + role + warehouse + user's own presets.
     */
    public static function listPresets(string $module): array
    {
        $user        = Auth::user();
        $roleId      = (int)($user['role_id'] ?? 0);
        $userId      = (int)($user['id'] ?? 0);
        $warehouseId = (int)($user['default_warehouse_id'] ?? 0);

        return Database::all(
            "SELECT p.*, u.name AS creator_name
             FROM ui_presets p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.module = ?
               AND (
                 (p.scope_type = 'system')
                 OR (p.scope_type = 'role'      AND p.scope_id = ?)
                 OR (p.scope_type = 'warehouse' AND p.scope_id = ?)
                 OR (p.scope_type = 'user'      AND p.scope_id = ?)
               )
             ORDER BY p.scope_type DESC, p.is_default DESC, p.name ASC",
            [$module, $roleId, $warehouseId, $userId]
        );
    }

    /**
     * Get visible price types for the current user/role.
     */
    public static function visiblePriceTypes(string $context = 'products'): array
    {
        $user   = Auth::user();
        $roleId = (int)($user['role_id'] ?? 0);

        if (!$roleId) {
            return self::allActivePriceTypes();
        }

        $col = ($context === 'pos') ? 'rpv.in_pos' : 'rpv.in_products';

        $rows = Database::all(
            "SELECT pt.id, pt.code, pt.name_en, pt.name_ru, pt.sort_order,
                    pt.color_hex, pt.is_default, rpv.can_edit,
                    rpv.can_view, rpv.in_pos, rpv.in_products
             FROM price_types pt
             JOIN role_price_visibility rpv ON rpv.price_type_id = pt.id
             WHERE rpv.role_id = ?
               AND rpv.can_view = 1
               AND {$col} = 1
               AND pt.is_active = 1
             ORDER BY pt.sort_order",
            [$roleId]
        );

        if (empty($rows)) {
            // Fallback: retail only
            return Database::all(
                'SELECT id, code, name_en, name_ru, sort_order, color_hex, is_default
                 FROM price_types WHERE code = "retail" AND is_active = 1 LIMIT 1'
            );
        }

        return $rows;
    }

    /**
     * Check if current user can see a specific price type in given context.
     */
    public static function canSeePriceType(string $code, string $context = 'products'): bool
    {
        static $visible = [];
        if (!isset($visible[$context])) {
            $visible[$context] = array_column(self::visiblePriceTypes($context), 'code');
        }
        return in_array($code, $visible[$context], true);
    }

    /**
     * Get the default price type code for current user/context.
     */
    public static function defaultPriceType(string $context = 'pos'): string
    {
        $cfg = self::get($context);
        return $cfg['price_type'] ?? 'retail';
    }

    /**
     * Reset user preferences for a module to inherited defaults.
     */
    public static function resetUserPrefs(string $module): void
    {
        $userId = Auth::id();
        if (!$userId) return;
        Database::exec(
            'DELETE FROM user_preferences WHERE user_id = ? AND module = ?',
            [$userId, $module]
        );
        self::bustCache($module);
    }

    /**
     * Save role-level settings for a module (admin only).
     */
    public static function saveRoleSettings(int $roleId, string $module, array $settings): bool
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $existing = Database::row(
            'SELECT id FROM role_ui_settings WHERE role_id = ? AND module = ? LIMIT 1',
            [$roleId, $module]
        );
        if ($existing) {
            Database::exec(
                'UPDATE role_ui_settings SET settings_json = ?, updated_at = NOW() WHERE id = ?',
                [$json, $existing['id']]
            );
        } else {
            Database::exec(
                'INSERT INTO role_ui_settings (role_id, module, settings_json) VALUES (?, ?, ?)',
                [$roleId, $module, $json]
            );
        }
        self::bustCache($module);
        return true;
    }

    /**
     * Save warehouse-level settings for a module (admin/manager).
     */
    public static function saveWarehouseSettings(int $warehouseId, string $module, array $settings): bool
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $existing = Database::row(
            'SELECT id FROM warehouse_ui_settings WHERE warehouse_id = ? AND module = ? LIMIT 1',
            [$warehouseId, $module]
        );
        if ($existing) {
            Database::exec(
                'UPDATE warehouse_ui_settings SET settings_json = ?, updated_at = NOW() WHERE id = ?',
                [$json, $existing['id']]
            );
        } else {
            Database::exec(
                'INSERT INTO warehouse_ui_settings (warehouse_id, module, settings_json) VALUES (?, ?, ?)',
                [$warehouseId, $module, $json]
            );
        }
        self::bustCache($module);
        return true;
    }

    /**
     * Get all active price types (no role filter).
     */
    public static function allActivePriceTypes(): array
    {
        return Database::all(
            'SELECT * FROM price_types WHERE is_active = 1 ORDER BY sort_order'
        );
    }

    /**
     * Get a single price type by code.
     */
    public static function priceType(string $code): ?array
    {
        return Database::row(
            'SELECT * FROM price_types WHERE code = ? AND is_active = 1 LIMIT 1',
            [$code]
        );
    }

    /**
     * Return price type label in current language.
     */
    public static function priceTypeName(array $pt): string
    {
        return unified_name_value($pt['name_ru'] ?? null, $pt['name_en'] ?? null);
    }

    /**
     * Get all prices for a product, keyed by price_type code.
     * Returns: ['retail' => 1500.00, 'wholesale1' => 1200.00, ...]
     */
    public static function productPrices(int $productId): array
    {
        $rows = Database::all(
            'SELECT pt.code, pp.price
             FROM product_prices pp
             JOIN price_types pt ON pt.id = pp.price_type_id
             WHERE pp.product_id = ?
             ORDER BY pt.sort_order',
            [$productId]
        );
        $prices = array_column($rows, 'price', 'code');
        $base = Database::row(
            'SELECT sale_price, cost_price FROM products WHERE id = ? LIMIT 1',
            [$productId]
        );
        if ($base) {
            $prices['retail'] = $prices['retail'] ?? (float)$base['sale_price'];
            $prices['purchase'] = $prices['purchase'] ?? (float)$base['cost_price'];
        }
        return $prices;
    }

    public static function productPricesMap(array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }

        $in = implode(',', array_fill(0, count($productIds), '?'));
        $rows = Database::all(
            "SELECT pp.product_id, pt.code, pp.price
             FROM product_prices pp
             JOIN price_types pt ON pt.id = pp.price_type_id
             WHERE pp.product_id IN ($in)
             ORDER BY pt.sort_order",
            $productIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id']][$row['code']] = (float)$row['price'];
        }

        $bases = Database::all(
            "SELECT id, sale_price, cost_price FROM products WHERE id IN ($in)",
            $productIds
        );
        foreach ($bases as $base) {
            $productId = (int)$base['id'];
            $map[$productId]['retail'] = $map[$productId]['retail'] ?? (float)$base['sale_price'];
            $map[$productId]['purchase'] = $map[$productId]['purchase'] ?? (float)$base['cost_price'];
        }

        return $map;
    }

    /**
     * Save all prices for a product in one call.
     * $prices: ['retail' => 1500, 'wholesale1' => 1200, ...]
     */
    public static function saveProductPrices(int $productId, array $prices): void
    {
        $types = Database::all('SELECT id, code FROM price_types WHERE is_active = 1');
        $typeMap = array_column($types, 'id', 'code');

        foreach ($prices as $code => $price) {
            $price = (float)$price;
            if (!isset($typeMap[$code])) continue;

            $typeId = $typeMap[$code];
            $existing = Database::row(
                'SELECT id FROM product_prices WHERE product_id = ? AND price_type_id = ? LIMIT 1',
                [$productId, $typeId]
            );
            if ($existing) {
                Database::exec(
                    'UPDATE product_prices SET price = ?, updated_at = NOW() WHERE id = ?',
                    [$price, $existing['id']]
                );
            } else {
                Database::exec(
                    'INSERT INTO product_prices (product_id, price_type_id, price) VALUES (?, ?, ?)',
                    [$productId, $typeId, $price]
                );
            }
        }
    }

    /**
     * Get the effective price for a product using the user's default price type.
     * Falls back to retail, then to first available.
     */
    public static function effectivePrice(int $productId, ?string $priceTypeCode = null): float
    {
        $code = $priceTypeCode ?? self::defaultPriceType('pos');
        $row  = Database::row(
            'SELECT pp.price FROM product_prices pp
             JOIN price_types pt ON pt.id = pp.price_type_id
             WHERE pp.product_id = ? AND pt.code = ? LIMIT 1',
            [$productId, $code]
        );
        if ($row) return (float)$row['price'];

        if ($code === 'purchase') {
            $base = Database::row(
                'SELECT cost_price FROM products WHERE id = ? LIMIT 1',
                [$productId]
            );
            return $base ? (float)$base['cost_price'] : 0.0;
        }

        // Fallback to retail
        if ($code !== 'retail') return self::effectivePrice($productId, 'retail');

        $base = Database::row(
            'SELECT sale_price, cost_price FROM products WHERE id = ? LIMIT 1',
            [$productId]
        );
        if (!$base) {
            return 0.0;
        }

        if ($code === 'purchase') {
            return (float)$base['cost_price'];
        }

        return (float)$base['sale_price'];
    }

    // ── Internal helpers ───────────────────────────────────────────

    private static function loadPreset(string $module, string $scopeType, ?int $scopeId): ?array
    {
        $row = Database::row(
            'SELECT settings_json FROM ui_presets
             WHERE module = ? AND scope_type = ? AND scope_id <=> ? AND is_default = 1
             ORDER BY updated_at DESC LIMIT 1',
            [$module, $scopeType, $scopeId]
        );
        if (!$row) return null;
        return json_decode($row['settings_json'], true) ?: null;
    }

    private static function loadUserPrefs(string $module, int $userId): ?array
    {
        $row = Database::row(
            'SELECT settings_json FROM user_preferences WHERE user_id = ? AND module = ? LIMIT 1',
            [$userId, $module]
        );
        if (!$row) return null;
        return json_decode($row['settings_json'], true) ?: null;
    }

    private static function loadWarehouseSettings(string $module, int $warehouseId): ?array
    {
        $row = Database::row(
            'SELECT settings_json FROM warehouse_ui_settings WHERE warehouse_id = ? AND module = ? LIMIT 1',
            [$warehouseId, $module]
        );
        if (!$row) return null;
        return json_decode($row['settings_json'], true) ?: null;
    }

    /**
     * Deep-merge $override into $base. Arrays are replaced, not combined.
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value)) {
                // Associative: recurse
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                // Scalar or indexed array: replace
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function bustCache(string $module): void
    {
        if ($module === '') {
            self::$cache = [];
        } else {
            foreach (array_keys(self::$cache) as $k) {
                if (str_starts_with($k, $module . ':')) {
                    unset(self::$cache[$k]);
                }
            }
        }
    }
}
