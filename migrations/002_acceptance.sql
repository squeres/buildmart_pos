SET NAMES utf8mb4;
SET foreign_key_checks = 0;

ALTER TABLE `goods_receipts`
  MODIFY COLUMN `status`
    ENUM('draft','posted','pending_acceptance','accepted','cancelled')
    NOT NULL DEFAULT 'draft';

UPDATE `goods_receipts`
SET `status` = 'pending_acceptance'
WHERE `status` = 'posted';

ALTER TABLE `goods_receipts`
  ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `posted_at`,
  ADD COLUMN `accepted_by_user` INT UNSIGNED DEFAULT NULL AFTER `accepted_at`;

ALTER TABLE `goods_receipt_items`
  ADD COLUMN `sale_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`,
  ADD COLUMN `accepted_qty` DECIMAL(14,3) DEFAULT NULL AFTER `sale_price`;

ALTER TABLE `goods_receipts`
  ADD CONSTRAINT `fk_gr_accepted_user`
  FOREIGN KEY (`accepted_by_user`) REFERENCES `users`(`id`)
  ON DELETE SET NULL;

ALTER TABLE `goods_receipts`
  MODIFY COLUMN `status`
    ENUM('draft','pending_acceptance','accepted','cancelled')
    NOT NULL DEFAULT 'draft';

SET foreign_key_checks = 1;