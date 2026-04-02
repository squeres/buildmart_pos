ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `pin_hash` VARCHAR(255) NULL
  COMMENT 'Secure PIN hash for quick login'
  AFTER `pin`;

ALTER TABLE `users`
  MODIFY COLUMN `pin` VARCHAR(10) NULL
  COMMENT 'Legacy plain PIN, auto-migrated to pin_hash on first successful PIN login';

ALTER TABLE `products`
  MODIFY COLUMN `stock_qty` DECIMAL(14,6) NOT NULL DEFAULT 0.000000
  COMMENT 'DEPRECATED legacy cache; use stock_balances as the source of truth';
