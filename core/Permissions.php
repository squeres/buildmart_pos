<?php
/**
 * Permission catalog and helpers for per-user overrides.
 */

function permission_groups(): array
{
    static $groups = null;
    if ($groups !== null) {
        return $groups;
    }

    return $groups = [
        [
            'group_key' => 'nav_dashboard',
            'items' => [
                ['key' => 'dashboard', 'label_key' => 'perm_access'],
            ],
        ],
        [
            'group_key' => 'nav_pos',
            'items' => [
                ['key' => 'pos', 'label_key' => 'perm_access'],
                ['key' => 'pos.sell', 'label_key' => 'perm_sell'],
            ],
        ],
        [
            'group_key' => 'nav_products',
            'items' => [
                ['key' => 'products', 'label_key' => 'perm_access'],
                ['key' => 'products.create', 'label_key' => 'perm_create'],
                ['key' => 'products.edit', 'label_key' => 'perm_edit'],
                ['key' => 'products.delete', 'label_key' => 'perm_delete'],
                ['key' => 'products.import', 'label_key' => 'perm_import'],
                ['key' => 'products.export', 'label_key' => 'perm_export'],
            ],
        ],
        [
            'group_key' => 'nav_categories',
            'items' => [
                ['key' => 'categories', 'label_key' => 'perm_access'],
                ['key' => 'categories.manage', 'label_key' => 'perm_manage'],
            ],
        ],
        [
            'group_key' => 'nav_inventory',
            'items' => [
                ['key' => 'inventory', 'label_key' => 'perm_access'],
                ['key' => 'inventory.receive', 'label_key' => 'perm_receive'],
                ['key' => 'inventory.adjust', 'label_key' => 'perm_adjust'],
                ['key' => 'inventory.writeoff', 'label_key' => 'perm_writeoff'],
            ],
        ],
        [
            'group_key' => 'nav_receipts',
            'items' => [
                ['key' => 'receipts', 'label_key' => 'perm_access'],
                ['key' => 'receipts.create', 'label_key' => 'perm_create'],
                ['key' => 'receipts.edit', 'label_key' => 'perm_edit'],
                ['key' => 'receipts.post', 'label_key' => 'perm_post'],
                ['key' => 'receipts.cancel', 'label_key' => 'perm_cancel'],
                ['key' => 'receipts.export', 'label_key' => 'perm_export'],
            ],
        ],
        [
            'group_key' => 'nav_acceptance',
            'items' => [
                ['key' => 'acceptance', 'label_key' => 'perm_access'],
                ['key' => 'acceptance.process', 'label_key' => 'perm_process'],
                ['key' => 'acceptance.accept', 'label_key' => 'perm_accept'],
            ],
        ],
        [
            'group_key' => 'nav_suppliers',
            'items' => [
                ['key' => 'suppliers', 'label_key' => 'perm_access'],
                ['key' => 'suppliers.manage', 'label_key' => 'perm_manage'],
            ],
        ],
        [
            'group_key' => 'nav_warehouses',
            'items' => [
                ['key' => 'warehouses', 'label_key' => 'perm_access'],
                ['key' => 'warehouses.manage', 'label_key' => 'perm_manage'],
            ],
        ],
        [
            'group_key' => 'nav_transfers',
            'items' => [
                ['key' => 'transfers', 'label_key' => 'perm_access'],
                ['key' => 'transfers.create', 'label_key' => 'perm_create'],
            ],
        ],
        [
            'group_key' => 'nav_customers',
            'items' => [
                ['key' => 'customers', 'label_key' => 'perm_access'],
                ['key' => 'customers.create', 'label_key' => 'perm_create'],
                ['key' => 'customers.edit', 'label_key' => 'perm_edit'],
                ['key' => 'customers.delete', 'label_key' => 'perm_delete'],
            ],
        ],
        [
            'group_key' => 'nav_shifts',
            'items' => [
                ['key' => 'shifts', 'label_key' => 'perm_access'],
                ['key' => 'shifts.open', 'label_key' => 'perm_open'],
                ['key' => 'shifts.close', 'label_key' => 'perm_close'],
                ['key' => 'shifts.extend', 'label_key' => 'perm_extend'],
            ],
        ],
        [
            'group_key' => 'nav_sales',
            'items' => [
                ['key' => 'sales', 'label_key' => 'perm_access'],
                ['key' => 'sales.void', 'label_key' => 'perm_void'],
                ['key' => 'sales.invoice', 'label_key' => 'perm_invoice'],
            ],
        ],
        [
            'group_key' => 'nav_reports',
            'items' => [
                ['key' => 'reports', 'label_key' => 'perm_access'],
            ],
        ],
    ];
}

function permission_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = [];
    foreach (permission_groups() as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = (string)$item['key'];
        }
    }

    return $keys;
}

function permission_allowed_key_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (permission_keys() as $key) {
        $map[$key] = true;
    }

    return $map;
}

function permission_is_known(string $key): bool
{
    return isset(permission_allowed_key_map()[$key]);
}

function permission_label(string $key): string
{
    foreach (permission_groups() as $group) {
        foreach ($group['items'] as $item) {
            if ((string)$item['key'] === $key) {
                return __((string)$item['label_key']);
            }
        }
    }

    return e($key);
}

function permission_override_modes(): array
{
    return [
        'inherit' => __('usr_permission_inherit'),
        'allow' => __('usr_permission_allow'),
        'deny' => __('usr_permission_deny'),
    ];
}

function permission_normalize_mode(mixed $mode): string
{
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['allow', 'deny'], true) ? $mode : 'inherit';
}

function permission_mode_to_bool(string $mode): ?bool
{
    return match (permission_normalize_mode($mode)) {
        'allow' => true,
        'deny' => false,
        default => null,
    };
}

function permission_role_supports_custom_overrides(array $role): bool
{
    $permissions = json_decode((string)($role['permissions'] ?? '{}'), true) ?: [];
    return empty($permissions['all']);
}

function permission_legacy_parent_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    return $map = [
        'receipts' => 'inventory',
        'acceptance' => 'inventory',
        'suppliers' => 'inventory',
        'warehouses' => 'inventory',
    ];
}

function permission_legacy_parent(string $permission): ?string
{
    return permission_legacy_parent_map()[$permission] ?? null;
}

function permission_resolve_for_map(array $basePermissions, array $overrides, string $permission): bool
{
    if (!empty($basePermissions['all'])) {
        return true;
    }

    if (array_key_exists($permission, $overrides)) {
        return (bool)$overrides[$permission];
    }

    if (array_key_exists($permission, $basePermissions)) {
        return !empty($basePermissions[$permission]);
    }

    $legacyParent = permission_legacy_parent($permission);
    if ($legacyParent !== null) {
        if (array_key_exists($legacyParent, $overrides)) {
            return (bool)$overrides[$legacyParent];
        }

        if (array_key_exists($legacyParent, $basePermissions)) {
            return !empty($basePermissions[$legacyParent]);
        }
    }

    if (str_contains($permission, '.')) {
        $modulePermission = strstr($permission, '.', true);
        if ($modulePermission !== false) {
            if (array_key_exists($modulePermission, $overrides)) {
                return (bool)$overrides[$modulePermission];
            }

            if (array_key_exists($modulePermission, $basePermissions)) {
                return !empty($basePermissions[$modulePermission]);
            }

            $legacyParent = permission_legacy_parent($modulePermission);
            if ($legacyParent !== null) {
                if (array_key_exists($legacyParent, $overrides)) {
                    return (bool)$overrides[$legacyParent];
                }

                if (array_key_exists($legacyParent, $basePermissions)) {
                    return !empty($basePermissions[$legacyParent]);
                }
            }
        }
    }

    return false;
}
