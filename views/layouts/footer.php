</main><!-- /page-content -->
</div><!-- /main-wrap -->

<button
  type="button"
  class="mobile-scroll-top-btn"
  id="mobileScrollTopBtn"
  aria-label="<?= __('ui_back_to_top') ?>"
  title="<?= __('ui_back_to_top') ?>"
  hidden
>
  <?= feather_icon('arrow-up', 18) ?>
</button>

<meta name="csrf-token" content="<?= csrf_token() ?>">
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script src="<?= url('assets/js/app.js') ?>"></script>
<script>
window.APP_LAYOUT_META = <?= json_for_html([
  'activeMenuKey' => (string)($activeMenuKey ?? ''),
]) ?>;
window._uiSettingsApiUrl = <?= json_for_html(url('modules/ui/settings_api.php')) ?>;
window._uiStrings = <?= json_for_html([
  'configureView' => __('ui_configure_view'),
  'configureMenu' => __('ui_configure_menu'),
  'tabColumns' => __('ui_tab_columns'),
  'tabFilters' => __('ui_tab_filters'),
  'tabSort' => __('ui_tab_sort'),
  'tabView' => __('ui_tab_view'),
  'tabPresets' => __('ui_tab_presets'),
  'viewMode' => __('ui_view_mode'),
  'sortBy' => __('ui_sort_by'),
  'perPage' => __('ui_per_page'),
  'dragToReorder' => __('ui_drag_to_reorder'),
  'save' => __('btn_save'),
  'apply' => __('btn_apply'),
  'restoreDefaults' => __('ui_restore_defaults'),
  'saveAsPreset' => __('ui_save_preset'),
  'presetNamePh' => __('ui_preset_name_ph'),
  'noPresets' => __('ui_no_presets'),
  'confirmReset' => __('ui_confirm_reset'),
  'confirmDeletePreset' => __('ui_confirm_delete_preset'),
  'deleteLabel' => __('btn_delete'),
  'sortAsc' => __('ui_sort_asc'),
  'sortDesc' => __('ui_sort_desc'),
  'errorSavingMenu' => __('ui_error_saving_menu'),
  'errorSavingSettings' => __('ui_error_saving_settings'),
]) ?>;
window._confirmModalStrings = <?= json_for_html([
  'confirm' => __('btn_confirm'),
  'cancel' => __('btn_cancel'),
]) ?>;
window.PRODUCT_CAMERA_I18N = <?= json_for_html([
  'title' => __('camera_scan_title'),
  'hint' => __('camera_scan_hint'),
  'close' => __('btn_cancel'),
  'scanning' => __('camera_scan_scanning'),
  'unsupported' => __('camera_scan_unsupported'),
  'unavailable' => __('camera_scan_unavailable'),
  'denied' => __('camera_scan_denied'),
  'failed' => __('camera_scan_failed'),
]) ?>;
window.PRODUCT_CAMERA_CONFIG = <?= json_for_html([
  'fallbackLibUrl' => url('assets/js/vendor/zxing-browser.min.js'),
]) ?>;
</script>
<script src="<?= url('assets/js/ui-settings.js') ?>"></script>
<script src="<?= url('assets/js/product-camera.js') ?>"></script>
<?php if (!empty($extraJs)) echo $extraJs; ?>
<script>
  if (typeof feather !== 'undefined') feather.replace();
</script>
</body>
</html>
