  </main><!-- /page-content -->
</div><!-- /main-wrap -->

<meta name="csrf-token" content="<?= csrf_token() ?>">
<script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
<script src="<?= url('assets/js/app.js') ?>"></script>
<script>
window._uiSettingsApiUrl = '<?= url('modules/ui/settings_api.php') ?>';
window._uiStrings = {
  configureView:       '<?= addslashes(__('ui_configure_view')) ?>',
  tabColumns:          '<?= addslashes(__('ui_tab_columns')) ?>',
  tabFilters:          '<?= addslashes(__('ui_tab_filters')) ?>',
  tabSort:             '<?= addslashes(__('ui_tab_sort')) ?>',
  tabView:             '<?= addslashes(__('ui_tab_view')) ?>',
  tabPresets:          '<?= addslashes(__('ui_tab_presets')) ?>',
  viewMode:            '<?= addslashes(__('ui_view_mode')) ?>',
  sortBy:              '<?= addslashes(__('ui_sort_by')) ?>',
  perPage:             '<?= addslashes(__('ui_per_page')) ?>',
  dragToReorder:       '<?= addslashes(__('ui_drag_to_reorder')) ?>',
  save:                '<?= addslashes(__('btn_save')) ?>',
  apply:               '<?= addslashes(__('btn_apply')) ?>',
  restoreDefaults:     '<?= addslashes(__('ui_restore_defaults')) ?>',
  saveAsPreset:        '<?= addslashes(__('ui_save_preset')) ?>',
  presetNamePh:        '<?= addslashes(__('ui_preset_name_ph')) ?>',
  noPresets:           '<?= addslashes(__('ui_no_presets')) ?>',
  confirmReset:        '<?= addslashes(__('ui_confirm_reset')) ?>',
  confirmDeletePreset: '<?= addslashes(__('ui_confirm_delete_preset')) ?>',
};
</script>
<script src="<?= url('assets/js/ui-settings.js') ?>"></script>
<?php if (!empty($extraJs)) echo $extraJs; ?>
<script>
  if (typeof feather !== 'undefined') feather.replace();
</script>
</body>
</html>