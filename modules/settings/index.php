<?php
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../views/partials/icons.php';
Auth::requireLogin();
Auth::requirePerm('settings');

$pageTitle   = __('set_title');
$breadcrumbs = [[$pageTitle, null]];

$requiredSettings = [
    ['timezone', 'Asia/Almaty', 'Timezone', 'general', 'select'],
    ['currency_code', 'KZT', 'Currency Code', 'general', 'select'],
    ['allow_negative_stock', '1', 'Allow Negative Stock', 'pos', 'boolean'],
    ['currency_symbol', '₸', 'Currency Symbol', 'general', 'text'],
    ['store_open_time', '08:30', 'Store Open Time', 'shifts', 'text'],
    ['store_close_time', '21:00', 'Store Close Time', 'shifts', 'text'],
    ['shift_close_grace_minutes', '15', 'Shift Close Grace (minutes)', 'shifts', 'number'],
    ['shift_extension_enabled', '1', 'Enable Shift Extensions', 'shifts', 'boolean'],
    ['shift_extension_max_minutes', '120', 'Shift Extension Max (minutes)', 'shifts', 'number'],
    ['shift_extension_default_options', '15,30,45,60', 'Shift Extension Options', 'shifts', 'text'],
];

foreach ($requiredSettings as [$key, $value, $label, $group, $type]) {
    Database::exec(
        "INSERT INTO settings (`key`, value, label, `group`, `type`)
         SELECT ?, ?, ?, ?, ?
         WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = ?)",
        [$key, $value, $label, $group, $type, $key]
    );
}

if (is_post()) {
    if (!csrf_verify()) {
        flash_error(_r('err_csrf'));
        redirect($_SERVER['REQUEST_URI']);
    }

    $keys = Database::all("SELECT `key`,`type` FROM settings");
    $selectedCurrency = normalize_currency_code($_POST['currency_code'] ?? current_currency_code());
    $selectedTimezone = $_POST['timezone'] ?? current_timezone();
    if (!in_array($selectedTimezone, timezone_identifiers_list(), true)) {
        $selectedTimezone = current_timezone();
    }

    foreach ($keys as $row) {
        $key = (string)$row['key'];

        if ($key === 'currency_symbol') {
            $value = currency_symbol($selectedCurrency);
        } elseif ($key === 'currency_code') {
            $value = $selectedCurrency;
        } elseif ($key === 'timezone') {
            $value = $selectedTimezone;
        } elseif ($key === 'default_language') {
            $value = DEFAULT_LANG;
        } elseif (in_array($key, ['store_open_time', 'store_close_time'], true)) {
            $value = shift_normalize_time_value($_POST[$key] ?? '', $key === 'store_open_time' ? '08:30' : '21:00');
        } elseif ($key === 'shift_extension_default_options') {
            $value = shift_extension_default_options_string($_POST[$key] ?? '', '15,30,45,60');
        } elseif (in_array($key, ['shift_close_grace_minutes', 'shift_extension_max_minutes'], true)) {
            $value = (string)max(0, (int)($_POST[$key] ?? 0));
        } else {
            $value = $row['type'] === 'boolean'
                ? (isset($_POST[$key]) ? '1' : '0')
                : sanitize($_POST[$key] ?? '');
        }

        Database::exec("UPDATE settings SET value=? WHERE `key`=?", [$value, $key]);
    }

    flash_success(_r('set_saved'));
    redirect($_SERVER['REQUEST_URI']);
}

$settings = Database::all("SELECT * FROM settings ORDER BY `group`,`id`");
$grouped  = [];
foreach ($settings as $setting) {
    if (($setting['key'] ?? '') === 'default_language') {
        continue;
    }
    $grouped[$setting['group']][] = $setting;
}

include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="page-header"><h1 class="page-heading"><?= __('set_title') ?></h1></div>

<form method="POST" style="max-width:720px">
  <?= csrf_field() ?>
  <?php foreach ($grouped as $group => $rows): ?>
  <div class="card mb-3">
    <div class="card-header"><span class="card-title"><?= setting_group_label((string)$group) ?></span></div>
    <div class="card-body">
      <?php foreach ($rows as $setting): ?>
      <?php if ($setting['key'] === 'currency_symbol') continue; ?>
      <div class="form-group">
        <label class="form-label" for="<?= e($setting['key']) ?>"><?= setting_label($setting) ?></label>
        <?php if ($setting['type'] === 'boolean'): ?>
          <label class="form-check">
            <input type="checkbox" id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" value="1" <?= $setting['value'] == '1' ? 'checked' : '' ?>>
            <span class="form-check-label"><?= __('lbl_yes') ?></span>
          </label>
        <?php elseif ($setting['type'] === 'textarea'): ?>
          <textarea id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" class="form-control" rows="3"><?= e($setting['value'] ?? '') ?></textarea>
        <?php elseif ($setting['type'] === 'select' && $setting['key'] === 'currency_code'): ?>
          <select id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" class="form-control" style="max-width:280px">
            <?php foreach (currency_options() as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= normalize_currency_code($setting['value']) === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($setting['type'] === 'select' && $setting['key'] === 'timezone'): ?>
          <select id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" class="form-control">
            <?php foreach (timezone_options() as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= ($setting['value'] ?: current_timezone()) === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($setting['type'] === 'number'): ?>
          <input type="number" id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" class="form-control mono" value="<?= e($setting['value'] ?? '') ?>" style="max-width:150px">
        <?php else: ?>
          <input type="text" id="<?= e($setting['key']) ?>" name="<?= e($setting['key']) ?>" class="form-control" value="<?= e($setting['value'] ?? '') ?>">
        <?php endif; ?>
        <?php $hintKey = 'set_hint_' . (string)$setting['key']; ?>
        <?php if (Lang::has($hintKey)): ?>
          <div class="form-hint"><?= __($hintKey) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <button type="submit" class="btn btn-primary btn-lg">
    <?= feather_icon('save', 17) ?> <?= __('btn_save') ?>
  </button>
</form>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script>feather.replace();</script>
