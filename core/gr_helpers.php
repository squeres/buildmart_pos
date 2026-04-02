<?php
/**
 * Goods Receipt + Acceptance Module — Helper Functions
 * core/gr_helpers.php
 */

/**
 * Generate the next goods receipt document number.
 * Format: GR-YYMMDD-NNN
 */
function gr_next_doc_no(): string
{
    $prefix = 'GR-' . date('ymd') . '-';
    $last   = Database::value(
        "SELECT doc_no FROM goods_receipts WHERE doc_no LIKE ? ORDER BY id DESC LIMIT 1",
        [$prefix . '%']
    );
    $n = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $prefix . str_pad($n, 3, '0', STR_PAD_LEFT);
}

/**
 * Status badge for goods receipt document.
 * Statuses: draft | pending_acceptance | accepted | cancelled
 */
function gr_status_badge(string $status): string
{
    $map = [
        'draft'              => ['secondary', __('gr_status_draft')],
        'pending_acceptance' => ['warning',   __('gr_status_pending')],
        'accepted'           => ['success',   __('gr_status_accepted')],
        'cancelled'          => ['danger',    __('gr_status_cancelled')],
        // legacy alias kept for safety
        'posted'             => ['success',   __('gr_status_accepted')],
    ];
    [$cls, $lbl] = $map[$status] ?? ['secondary', e($status)];
    return '<span class="badge badge-' . $cls . '">' . $lbl . '</span>';
}

/**
 * Count of receipts currently waiting for acceptance.
 * Used for the notification badge in the sidebar.
 */
function gr_pending_count(): int
{
    static $count = null;
    if ($count === null) {
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM goods_receipts WHERE status = 'pending_acceptance'"
        );
    }
    return $count;
}
