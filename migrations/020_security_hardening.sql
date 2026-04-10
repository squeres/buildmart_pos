-- Migration 020: Security hardening — login rate limiting + instant permission refresh

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`         ENUM('password','pin') NOT NULL,
  `identifier`   VARCHAR(255) NOT NULL,
  `ip`           VARCHAR(45) NOT NULL DEFAULT '',
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lookup` (`type`, `identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MySQL 8: нет IF NOT EXISTS для ADD COLUMN, применяйте только на новой БД
ALTER TABLE `users`
  ADD COLUMN `permissions_updated_at` DATETIME NULL DEFAULT NULL;
