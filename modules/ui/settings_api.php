<?php
/**
 * UI Settings API — AJAX endpoint for saving/loading view configurations.
 * POST /modules/ui/settings_api.php
 *
 * Actions:
 *   save_prefs     — save user preferences for a module
 *   reset_prefs    — reset user preferences for a module
 *   save_preset    — save named preset
 *   update_preset  — update existing preset
 *   delete_preset  — delete a preset
 *   list_presets   — list presets for a module
 *   load_preset    — load a specific preset by id
 *   get_prices     — get product prices
 *   save_prices    — save product prices (admin/manager)
 *   get_config     — get merged config for a module
 *   save_role      — save role-level settings (admin only)
 *   save_warehouse — save warehouse-level settings (admin/manager)
 *   price_types    — list all active price types
 */

require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
Auth::requireLogin();

if (!is_ajax() && !is_post()) {
    json_response(['error' => 'Invalid request'], 400);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── Get merged config ─────────────────────────────────────────
    case 'get_config': {
        $module      = sanitize($_POST['module'] ?? $_GET['module'] ?? '');
        $warehouseId = (int)($_POST['warehouse_id'] ?? $_GET['warehouse_id'] ?? 0);
        if (!$module) json_response(['error' => 'module required'], 400);
        json_response(['ok' => true, 'config' => UISettings::get($module, $warehouseId ?: null)]);
    }

    // ── Save user preferences ─────────────────────────────────────
    case 'save_prefs': {
        $module   = sanitize($_POST['module'] ?? '');
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        if (!$module) json_response(['error' => 'module required'], 400);
        UISettings::save($module, $settings ?: []);
        json_response(['ok' => true]);
    }

    // ── Reset user preferences ────────────────────────────────────
    case 'reset_prefs': {
        $module = sanitize($_POST['module'] ?? '');
        if (!$module) json_response(['error' => 'module required'], 400);
        UISettings::resetUserPrefs($module);
        json_response(['ok' => true, 'defaults' => UISettings::get($module)]);
    }

    // ── List presets ──────────────────────────────────────────────
    case 'list_presets': {
        $module = sanitize($_POST['module'] ?? $_GET['module'] ?? '');
        if (!$module) json_response(['error' => 'module required'], 400);
        json_response(['ok' => true, 'presets' => UISettings::listPresets($module)]);
    }

    // ── Load a specific preset ────────────────────────────────────
    case 'load_preset': {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) json_response(['error' => 'id required'], 400);
        $row = Database::row('SELECT * FROM ui_presets WHERE id = ?', [$id]);
        if (!$row) json_response(['error' => 'not found'], 404);
        $row['settings'] = json_decode($row['settings_json'], true);
        json_response(['ok' => true, 'preset' => $row]);
    }

    // ── Save new preset ───────────────────────────────────────────
    case 'save_preset': {
        $name      = sanitize($_POST['name'] ?? '');
        $module    = sanitize($_POST['module'] ?? '');
        $scopeType = $_POST['scope_type'] ?? 'user';
        $scopeId   = (int)($_POST['scope_id'] ?? 0) ?: null;
        $isDefault = !empty($_POST['is_default']);
        $settings  = json_decode($_POST['settings'] ?? '{}', true);

        if (!$name || !$module) json_response(['error' => 'name and module required'], 400);

        // Permission checks for non-user scopes
        if ($scopeType !== 'user' && !Auth::can('ui_settings')) {
            json_response(['error' => __('auth_no_permission')], 403);
        }

        $id = UISettings::savePreset($name, $module, $settings ?: [], $scopeType, $scopeId, $isDefault);
        json_response(['ok' => true, 'id' => $id]);
    }

    // ── Update existing preset ────────────────────────────────────
    case 'update_preset': {
        $id       = (int)($_POST['id'] ?? 0);
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        $name     = sanitize($_POST['name'] ?? '') ?: null;
        if (!$id) json_response(['error' => 'id required'], 400);

        // Check ownership
        $row = Database::row('SELECT * FROM ui_presets WHERE id = ?', [$id]);
        if (!$row) json_response(['error' => 'not found'], 404);
        if ($row['scope_type'] !== 'user' && !Auth::can('ui_settings')) {
            json_response(['error' => __('auth_no_permission')], 403);
        }
        if ($row['scope_type'] === 'user' && $row['scope_id'] != Auth::id()) {
            json_response(['error' => __('auth_no_permission')], 403);
        }

        UISettings::updatePreset($id, $settings ?: [], $name);
        json_response(['ok' => true]);
    }

    // ── Delete preset ─────────────────────────────────────────────
    case 'delete_preset': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(['error' => 'id required'], 400);

        $row = Database::row('SELECT * FROM ui_presets WHERE id = ?', [$id]);
        if (!$row) json_response(['error' => 'not found'], 404);
        if ($row['scope_type'] === 'system') {
            json_response(['error' => __('auth_no_permission')], 403);
        }
        if ($row['scope_type'] === 'user' && $row['scope_id'] != Auth::id() && !Auth::can('ui_settings')) {
            json_response(['error' => __('auth_no_permission')], 403);
        }
        if (in_array($row['scope_type'], ['role','warehouse']) && !Auth::can('ui_settings')) {
            json_response(['error' => __('auth_no_permission')], 403);
        }

        UISettings::deletePreset($id);
        json_response(['ok' => true]);
    }

    // ── Get price types ───────────────────────────────────────────
    case 'price_types': {
        $context = sanitize($_GET['context'] ?? $_POST['context'] ?? 'products');
        $all     = !empty($_GET['all']) || !empty($_POST['all']);
        $types   = $all ? UISettings::allActivePriceTypes() : UISettings::visiblePriceTypes($context);
        json_response(['ok' => true, 'price_types' => $types]);
    }

    // ── Get product prices ────────────────────────────────────────
    case 'get_prices': {
        $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        if (!$productId) json_response(['error' => 'product_id required'], 400);
        json_response(['ok' => true, 'prices' => UISettings::productPrices($productId)]);
    }

    // ── Save product prices ───────────────────────────────────────
    case 'save_prices': {
        if (!Auth::can('products')) json_response(['error' => __('auth_no_permission')], 403);
        $productId = (int)($_POST['product_id'] ?? 0);
        $prices    = json_decode($_POST['prices'] ?? '{}', true);
        if (!$productId || !is_array($prices)) json_response(['error' => 'invalid data'], 400);
        UISettings::saveProductPrices($productId, $prices);
        json_response(['ok' => true]);
    }

    // ── Save role settings (admin only) ───────────────────────────
    case 'save_role': {
        Auth::requirePerm('ui_settings');
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $module   = sanitize($_POST['module'] ?? '');
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        if (!$roleId || !$module) json_response(['error' => 'role_id and module required'], 400);
        UISettings::saveRoleSettings($roleId, $module, $settings ?: []);
        json_response(['ok' => true]);
    }

    // ── Save warehouse settings ───────────────────────────────────
    case 'save_warehouse': {
        if (!Auth::can('ui_settings') && !Auth::can('settings')) {
            json_response(['error' => __('auth_no_permission')], 403);
        }
        $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
        $module      = sanitize($_POST['module'] ?? '');
        $settings    = json_decode($_POST['settings'] ?? '{}', true);
        if (!$warehouseId || !$module) json_response(['error' => 'warehouse_id and module required'], 400);
        UISettings::saveWarehouseSettings($warehouseId, $module, $settings ?: []);
        json_response(['ok' => true]);
    }

    default:
        json_response(['error' => 'Unknown action'], 400);
}
