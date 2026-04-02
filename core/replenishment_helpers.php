<?php
/**
 * Product replenishment / restock need helpers.
 */

function replenishment_has_product_column(string $column): bool
{
    return shift_schema_has_column('products', $column);
}

function replenishment_schema_ready(): bool
{
    return replenishment_has_product_column('replenishment_class')
        && replenishment_has_product_column('target_stock_qty');
}

function replenishment_product_select_sql(string $alias = 'p'): string
{
    $parts = [];
    $parts[] = replenishment_has_product_column('replenishment_class')
        ? "{$alias}.replenishment_class"
        : "'C' AS replenishment_class";
    $parts[] = replenishment_has_product_column('target_stock_qty')
        ? "{$alias}.target_stock_qty"
        : "0 AS target_stock_qty";
    $parts[] = replenishment_has_product_column('target_stock_display_unit_code')
        ? "{$alias}.target_stock_display_unit_code"
        : "'' AS target_stock_display_unit_code";

    return implode(', ', $parts);
}

function replenishment_class_normalize(?string $value): string
{
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['A', 'B', 'C'], true) ? $value : 'C';
}

function replenishment_class_rank(?string $value): int
{
    return match (replenishment_class_normalize($value)) {
        'A' => 1,
        'B' => 2,
        default => 3,
    };
}

function replenishment_class_meta(?string $value): array
{
    $class = replenishment_class_normalize($value);

    return match ($class) {
        'A' => [
            'class' => 'A',
            'label' => __('repl_class_a'),
            'short_label' => 'A',
            'description' => __('repl_class_a_desc'),
            'badge_class' => 'danger',
            'card_border' => 'rgba(248,81,73,.45)',
            'card_bg' => 'rgba(248,81,73,.08)',
        ],
        'B' => [
            'class' => 'B',
            'label' => __('repl_class_b'),
            'short_label' => 'B',
            'description' => __('repl_class_b_desc'),
            'badge_class' => 'warning',
            'card_border' => 'rgba(210,153,34,.45)',
            'card_bg' => 'rgba(210,153,34,.10)',
        ],
        default => [
            'class' => 'C',
            'label' => __('repl_class_c'),
            'short_label' => 'C',
            'description' => __('repl_class_c_desc'),
            'badge_class' => 'info',
            'card_border' => 'rgba(88,166,255,.35)',
            'card_bg' => 'rgba(88,166,255,.08)',
        ],
    };
}

function replenishment_class_options(): array
{
    return [
        'A' => __('repl_class_a'),
        'B' => __('repl_class_b'),
        'C' => __('repl_class_c'),
    ];
}

function replenishment_class_badge(?string $value): string
{
    $meta = replenishment_class_meta($value);
    return '<span class="badge badge-' . e($meta['badge_class']) . '">' . e($meta['short_label']) . ' · ' . e($meta['label']) . '</span>';
}

function product_target_stock_data(array $product, ?array $units = null): array
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

    $baseQty = max(0.0, (float)($product['target_stock_qty'] ?? 0));
    $baseUnitRow = product_resolve_unit($units, $baseUnit, $baseUnit);
    $preferredDisplayUnitCode = (string)($product['target_stock_display_unit_code'] ?? '');
    if ($preferredDisplayUnitCode === '') {
        $preferredDisplayUnitCode = (string)($product['min_stock_display_unit_code'] ?? '');
    }
    $displayUnit = product_resolve_unit($units, $baseUnit, $preferredDisplayUnitCode);

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

function product_replenishment_state(array $product, ?float $currentStockQty = null, ?array $units = null): array
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

    $resolvedCurrentQty = $currentStockQty;
    if ($resolvedCurrentQty === null) {
        $resolvedCurrentQty = ($productId > 0)
            ? InventoryService::getTotalStock($productId)
            : (float)($product['stock_qty'] ?? 0);
    }
    $currentQty = stock_qty_round(max(0.0, (float)$resolvedCurrentQty));
    $minStock = product_min_stock_data($product, $units);
    $targetStock = product_target_stock_data($product, $units);
    $classMeta = replenishment_class_meta($product['replenishment_class'] ?? 'C');

    $participates = (float)$minStock['base_qty'] > 0;
    $isOut = $currentQty <= 0.0;
    $isBelowMin = $participates && $currentQty <= (float)$minStock['base_qty'];
    $qtyToOrder = (float)$targetStock['base_qty'] > 0
        ? max(0.0, stock_qty_round((float)$targetStock['base_qty'] - $currentQty))
        : 0.0;
    $orderUnitCode = (string)($targetStock['display_unit_code'] ?: $minStock['display_unit_code'] ?: $baseUnit);
    $orderUnit = product_resolve_unit($units, $baseUnit, $orderUnitCode);
    $qtyToOrderDisplay = product_qty_from_base_unit($qtyToOrder, $units, $baseUnit, (string)$orderUnit['unit_code']);

    $status = $isOut ? 'out' : ($isBelowMin ? 'needs' : 'ok');
    $statusLabel = match ($status) {
        'out' => __('out_of_stock'),
        'needs' => __('repl_below_min'),
        default => __('in_stock'),
    };
    $statusBadgeClass = match ($status) {
        'out' => 'danger',
        'needs' => $classMeta['badge_class'],
        default => 'success',
    };

    return [
        'class' => $classMeta['class'],
        'class_label' => $classMeta['label'],
        'class_description' => $classMeta['description'],
        'class_badge_class' => $classMeta['badge_class'],
        'class_short_label' => $classMeta['short_label'],
        'class_meta' => $classMeta,
        'participates' => $participates,
        'current_stock_qty' => $currentQty,
        'current_stock_text' => product_stock_breakdown($currentQty, $units, $baseUnit),
        'min_stock' => $minStock,
        'target_stock' => $targetStock,
        'is_out_of_stock' => $isOut,
        'is_below_min_stock' => $isBelowMin,
        'status' => $status,
        'status_label' => $statusLabel,
        'status_badge_class' => $statusBadgeClass,
        'qty_to_order' => $qtyToOrder,
        'qty_to_order_display' => $qtyToOrderDisplay,
        'qty_to_order_text' => $qtyToOrder > 0
            ? product_unit_qty_text($qtyToOrderDisplay, $orderUnit)
            : '—',
    ];
}

function replenishment_status_badge(array $state): string
{
    return '<span class="badge badge-' . e($state['status_badge_class'] ?? 'secondary') . '">' . e($state['status_label'] ?? __('lbl_status')) . '</span>';
}

function replenishment_compare_states(array $left, array $right): int
{
    $leftRank = replenishment_class_rank($left['class'] ?? 'C');
    $rightRank = replenishment_class_rank($right['class'] ?? 'C');
    if ($leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }

    $leftCurrent = (float)($left['current_stock_qty'] ?? 0);
    $rightCurrent = (float)($right['current_stock_qty'] ?? 0);
    if ($leftCurrent !== $rightCurrent) {
        return $leftCurrent <=> $rightCurrent;
    }

    $leftName = (string)($left['product_name'] ?? '');
    $rightName = (string)($right['product_name'] ?? '');
    return strcasecmp($leftName, $rightName);
}
