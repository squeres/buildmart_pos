ALTER TABLE `users`
  ADD COLUMN `last_seen_at` DATETIME DEFAULT NULL AFTER `last_login`,
  ADD KEY `idx_last_seen_at` (`last_seen_at`);
