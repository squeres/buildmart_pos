<?php
/**
 * NAVIGATION ADDITIONS FOR views/layouts/header.php
 * ===================================================
 * Find the section in your header.php where inventory links are listed
 * (look for nav_inventory). Add the block below either before or after
 * the Inventory nav-link.
 *
 * The code below is a SNIPPET — do not replace the whole header.php,
 * just add these nav-link entries in the appropriate sidebar section.
 *
 * Typical placement: after the Inventory nav-link group.
 */

/*
<div class="nav-divider"></div>

<!-- Goods Receipts section -->
<?php if (Auth::can('inventory')): ?>
<a href="<?= url('modules/receipts/') ?>"
   class="nav-link <?= (strpos($_SERVER['REQUEST_URI'], '/modules/receipts') !== false) ? 'active' : '' ?>">
  <span class="nav-icon"><?= feather_icon('truck', 16) ?></span>
  <?= __('nav_receipts') ?>
</a>
<a href="<?= url('modules/suppliers/') ?>"
   class="nav-link <?= (strpos($_SERVER['REQUEST_URI'], '/modules/suppliers') !== false) ? 'active' : '' ?>">
  <span class="nav-icon"><?= feather_icon('users', 16) ?></span>
  <?= __('nav_suppliers') ?>
</a>
<a href="<?= url('modules/warehouses/') ?>"
   class="nav-link <?= (strpos($_SERVER['REQUEST_URI'], '/modules/warehouses') !== false) ? 'active' : '' ?>">
  <span class="nav-icon"><?= feather_icon('archive', 16) ?></span>
  <?= __('nav_warehouses') ?>
</a>
<?php endif; ?>

<!-- Template settings link (admin only) -->
<?php if (Auth::can('settings')): ?>
<a href="<?= url('modules/receipts/settings.php') ?>"
   class="nav-link <?= (strpos($_SERVER['REQUEST_URI'], '/modules/receipts/settings') !== false) ? 'active' : '' ?>">
  <span class="nav-icon"><?= feather_icon('file-text', 16) ?></span>
  <?= __('gr_settings_title') ?>
</a>
<?php endif; ?>
*/
