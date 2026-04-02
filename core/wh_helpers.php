<?php
/**
 * core/wh_helpers.php
 * Multi-warehouse helper functions.
 * Loaded in bootstrap.php after existing helpers.
 */

/**
 * Return warehouse IDs accessible to the current user.
 * Admin → all active warehouses.
 * Others → only assigned warehouses (intersection with active).
 */
function user_warehouse_ids(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    if (Auth::can('all')) {
        $rows = Database::all("SELECT id FROM warehouses WHERE is_active=1 ORDER BY name");
        $cache = array_column($rows, 'id');
    } else {
        $rows = Database::all(
            "SELECT w.id FROM warehouses w
             JOIN warehouse_user_access a ON a.warehouse_id = w.id
             WHERE a.user_id = ? AND w.is_active = 1
             ORDER BY w.name",
            [Auth::id()]
        );
        $cache = array_column($rows, 'id');
    }
    return $cache;
}

/**
 * Return full warehouse rows accessible to the current user.
 */
function user_warehouses(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    if (Auth::can('all')) {
        $cache = Database::all("SELECT * FROM warehouses WHERE is_active=1 ORDER BY name");
    } else {
        $cache = Database::all(
            "SELECT w.* FROM warehouses w
             JOIN warehouse_user_access a ON a.warehouse_id = w.id
             WHERE a.user_id = ? AND w.is_active = 1
             ORDER BY w.name",
            [Auth::id()]
        );
    }
    return $cache;
}

/**
 * Check if current user has access to a specific warehouse.
 */
function user_can_access_warehouse(int $warehouseId): bool
{
    if (Auth::can('all')) return true;
    return in_array($warehouseId, user_warehouse_ids());
}

/**
 * Require access to a warehouse for the current user.
 */
function require_warehouse_access(int $warehouseId, string $redirectPath): void
{
    if ($warehouseId > 0 && user_can_access_warehouse($warehouseId)) {
        return;
    }

    flash_error(_r('auth_no_permission'));
    redirect($redirectPath);
}

/**
 * Get default warehouse ID for current user.
 * Falls back to first accessible, then 1.
 */
function user_default_warehouse_id(): int
{
    $user = Auth::user();
    $default = (int)($user['default_warehouse_id'] ?? 1);
    $accessible = user_warehouse_ids();

    if (in_array($default, $accessible)) return $default;
    return $accessible[0] ?? 1;
}

/**
 * Get stock qty for a product on a specific warehouse.
 * stock_balances is the source of truth.
 */
function get_stock_qty(int $productId, int $warehouseId): float
{
    return InventoryService::getAvailableStock($productId, $warehouseId);
}

/**
 * Get total stock qty for a product across all warehouses.
 */
function get_total_stock_qty(int $productId): float
{
    return InventoryService::getTotalStock($productId);
}

/**
 * Update stock balance for a product in a warehouse.
 * Creates the row if it doesn't exist.
 * Returns [qty_before, qty_after].
 */
function update_stock_balance(int $productId, int $warehouseId, float $delta): array
{
    if ($delta > 0) {
        return InventoryService::restoreStock($productId, $warehouseId, $delta);
    }
    if ($delta < 0) {
        return InventoryService::deductStock($productId, $warehouseId, abs($delta));
    }

    $current = InventoryService::getAvailableStock($productId, $warehouseId, true);
    return [$current, $current];
}

/**
 * Set an exact stock balance for a product in a warehouse.
 * Returns [qty_before, qty_after].
 */
function set_stock_balance(int $productId, int $warehouseId, float $qty): array
{
    return InventoryService::setStock($productId, $warehouseId, $qty);
}

/**
 * Generate a unique transfer document number.
 */
function generate_transfer_no(): string
{
    $base = 'TRF-' . date('ymd') . '-';
    $last = Database::value(
        "SELECT doc_no FROM stock_transfers WHERE doc_no LIKE ? ORDER BY id DESC LIMIT 1",
        [$base . '%']
    );
    $seq = $last ? ((int)substr($last, -3) + 1) : 1;
    return $base . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

/**
 * Get or set the current warehouse selector value for the session.
 * 0 means “all warehouses” and is only allowed for users who can switch warehouses.
 */
function selected_warehouse_id(?int $setId = null): int
{
    if ($setId !== null) {
        if ($setId === 0 && Auth::can('inventory')) {
            $_SESSION['pos_warehouse_id'] = 0;
        } elseif ($setId > 0 && user_can_access_warehouse($setId)) {
            $_SESSION['pos_warehouse_id'] = $setId;
        }
    }

    $stored = (int)($_SESSION['pos_warehouse_id'] ?? -1);
    if ($stored === 0 && Auth::can('inventory')) {
        return 0;
    }
    if ($stored > 0 && user_can_access_warehouse($stored)) {
        return $stored;
    }

    $default = user_default_warehouse_id();
    $_SESSION['pos_warehouse_id'] = $default;
    return $default;
}

/**
 * Get or set the active POS warehouse for current session.
 * POS screens must always operate on one concrete warehouse, so if the
 * selector is set to “all warehouses” we silently fall back to default.
 */
function pos_warehouse_id(?int $setId = null): int
{
    if ($setId !== null) {
        selected_warehouse_id($setId);
    }

    $selected = selected_warehouse_id();
    if ($selected > 0) {
        return $selected;
    }

    return user_default_warehouse_id();
}

/**
 * Whether the global selector is currently set to all accessible warehouses.
 */
function is_all_warehouses_selected(): bool
{
    return selected_warehouse_id() === 0;
}
