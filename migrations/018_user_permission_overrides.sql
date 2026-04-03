CREATE TABLE IF NOT EXISTS `user_permission_overrides` (
  `user_id` INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(80) NOT NULL,
  `mode` ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `permission_key`),
  CONSTRAINT `fk_user_permission_overrides_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
