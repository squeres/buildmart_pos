ALTER TABLE `inventory_movements`
  MODIFY `type` ENUM('receipt','sale','return','adjustment','inventory','writeoff','transfer') NOT NULL;

INSERT INTO `settings` (`key`, `value`, `label`, `group`, `type`)
SELECT 'allow_negative_stock', '1', 'Allow Negative Stock', 'pos', 'boolean'
WHERE NOT EXISTS (
  SELECT 1 FROM `settings` WHERE `key` = 'allow_negative_stock'
);
