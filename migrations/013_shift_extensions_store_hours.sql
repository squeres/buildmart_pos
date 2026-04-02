ALTER TABLE `shifts`
  ADD COLUMN `extended_until` DATETIME NULL DEFAULT NULL AFTER `closed_at`,
  ADD COLUMN `extension_approved_by` INT UNSIGNED NULL DEFAULT NULL AFTER `extended_until`,
  ADD KEY `idx_user_status_opened` (`user_id`, `status`, `opened_at`),
  ADD KEY `idx_opened_at` (`opened_at`),
  ADD CONSTRAINT `fk_shift_ext_shift_approved_by`
    FOREIGN KEY (`extension_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE `shift_extension_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shift_id` INT UNSIGNED NOT NULL,
  `cashier_id` INT UNSIGNED NOT NULL,
  `requested_at` DATETIME NOT NULL,
  `requested_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_minutes` SMALLINT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shift_status_requested` (`shift_id`, `status`, `requested_at`),
  KEY `idx_cashier_status_requested` (`cashier_id`, `status`, `requested_at`),
  CONSTRAINT `fk_shift_extension_shift`
    FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shift_extension_cashier`
    FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shift_ext_request_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'store_open_time', '08:30', 'Store Open Time', 'shifts', 'text'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'store_open_time');

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'store_close_time', '21:00', 'Store Close Time', 'shifts', 'text'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'store_close_time');

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'shift_close_grace_minutes', '15', 'Shift Close Grace (minutes)', 'shifts', 'number'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'shift_close_grace_minutes');

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'shift_extension_enabled', '1', 'Enable Shift Extensions', 'shifts', 'boolean'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'shift_extension_enabled');

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'shift_extension_max_minutes', '120', 'Shift Extension Max (minutes)', 'shifts', 'number'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'shift_extension_max_minutes');

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'shift_extension_default_options', '15,30,45,60', 'Shift Extension Options', 'shifts', 'text'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'shift_extension_default_options');
