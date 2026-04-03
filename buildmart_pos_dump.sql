-- MySQL dump 10.13  Distrib 8.0.45, for Win64 (x86_64)
--
-- Host: localhost    Database: buildmart_pos
-- ------------------------------------------------------
-- Server version	8.0.45

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` smallint unsigned DEFAULT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ru` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'box',
  `color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#607D8B',
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,NULL,'Электрика','Электрика','box','#007bff',0,1),(2,NULL,'Сухие смеси','Сухие смеси','box','#fff700',0,1),(3,NULL,'Расходные материалы','Расходные материалы','box','#607d8b',0,1);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inn` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tax ID',
  `customer_type` enum('retail','legal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail',
  `address` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `total_spent` decimal(16,2) NOT NULL DEFAULT '0.00',
  `visits` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` (`id`,`name`,`phone`,`email`,`company`,`inn`,`customer_type`,`address`,`notes`,`discount_pct`,`total_spent`,`visits`,`created_at`,`updated_at`) VALUES (1,'Покупатель','',NULL,NULL,NULL,'retail',NULL,NULL,0.00,0.00,0,'2026-03-12 19:07:44','2026-03-12 19:07:44');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `goods_receipt_items`
--

DROP TABLE IF EXISTS `goods_receipt_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipt_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL COMMENT 'NULL = custom/non-catalogue item',
  `name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Snapshot of product name at time of save',
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `qty` decimal(14,3) NOT NULL DEFAULT '1.000',
  `unit_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `unit_prices_json` longtext COLLATE utf8mb4_unicode_ci,
  `sale_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `sale_prices_json` longtext COLLATE utf8mb4_unicode_ci,
  `accepted_qty` decimal(14,3) DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'VAT % per line',
  `line_total` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'qty * unit_price (excl VAT)',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_receipt` (`receipt_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_gri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gri_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `goods_receipt_items`
--

LOCK TABLES `goods_receipt_items` WRITE;
/*!40000 ALTER TABLE `goods_receipt_items` DISABLE KEYS */;
INSERT INTO `goods_receipt_items` VALUES (2,1,5,'Саморезы 3 x 50','упаковка',80.000,400.00,'{\"box\":16000,\"упаковка\":400,\"штук\":4}',600.00,'{\"box\":26000,\"упаковка\":600,\"штук\":10}',80.000,12.00,32000.00,'',0),(3,2,5,'Саморезы 3 x 50','упаковка',20.000,400.00,'{\"box\":16000,\"упаковка\":400,\"штук\":4}',600.00,'{\"box\":26000,\"упаковка\":600,\"штук\":10}',20.000,12.00,8000.00,'',0),(4,3,6,'Лампочка 100W','pcs',50.000,500.00,'{\"pcs\":500}',850.00,'{\"pcs\":850}',50.000,12.00,25000.00,'',0),(5,4,5,'Саморезы 3 x 50','упаковка',10.000,400.00,'{\"box\":16000,\"упаковка\":400,\"штук\":4}',600.00,'{\"box\":20000,\"упаковка\":600,\"штук\":10}',10.000,12.00,4000.00,'',0),(6,4,7,'Брус 5 x 10','pcs',20.000,800.00,'{\"pcs\":800}',1100.00,'{\"pcs\":1100}',20.000,12.00,16000.00,'',1);
/*!40000 ALTER TABLE `goods_receipt_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `goods_receipts`
--

DROP TABLE IF EXISTS `goods_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `doc_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable document number (GR-250101-001)',
  `doc_date` date NOT NULL,
  `supplier_id` int unsigned DEFAULT NULL,
  `warehouse_id` smallint unsigned NOT NULL DEFAULT '1',
  `status` enum('draft','pending_acceptance','accepted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `accepted_by` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Name of person who accepted delivery',
  `delivered_by` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Driver / courier name',
  `supplier_doc_no` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Supplier invoice / waybill number',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `posted_by` int unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_by_user` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_doc_no` (`doc_no`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_status` (`status`),
  KEY `idx_doc_date` (`doc_date`),
  KEY `idx_created_by` (`created_by`),
  KEY `fk_gr_posted` (`posted_by`),
  KEY `fk_gr_cancelled` (`cancelled_by`),
  KEY `fk_gr_accepted_user` (`accepted_by_user`),
  CONSTRAINT `fk_gr_accepted_user` FOREIGN KEY (`accepted_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_cancelled` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_gr_posted` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `goods_receipts`
--

LOCK TABLES `goods_receipts` WRITE;
/*!40000 ALTER TABLE `goods_receipts` DISABLE KEYS */;
INSERT INTO `goods_receipts` VALUES (1,'GR-260317-001','2026-03-17',4,1,'accepted','Исмаилхаджиев Б. М.','Роман','',32000.00,3840.00,35840.00,'',5,5,'2026-03-17 22:29:42','2026-03-17 22:30:37',5,NULL,NULL,'2026-03-17 17:17:03','2026-03-17 17:30:37'),(2,'GR-260317-002','2026-03-17',4,1,'accepted','Исмаилхаджиев Б. М.','Роман','',8000.00,960.00,8960.00,'',5,5,'2026-03-17 23:02:24','2026-03-17 23:54:04',5,NULL,NULL,'2026-03-17 18:02:24','2026-03-17 18:54:04'),(3,'GR-260317-003','2026-03-17',1,1,'accepted','Исмаилхаджиев Б. М.','Роман','12312',25000.00,3000.00,28000.00,'',5,5,'2026-03-17 23:32:01','2026-03-17 23:54:34',5,NULL,NULL,'2026-03-17 18:32:01','2026-03-17 18:54:34'),(4,'GR-260317-004','2026-03-17',4,1,'accepted','Исмаилхаджиев Б. М.','Роман','12312',20000.00,2400.00,22400.00,'',5,5,'2026-03-17 23:59:58','2026-03-18 00:01:42',5,NULL,NULL,'2026-03-17 18:59:58','2026-03-17 19:01:42');
/*!40000 ALTER TABLE `goods_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_movements`
--

DROP TABLE IF EXISTS `inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_movements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `warehouse_id` smallint unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `type` enum('receipt','sale','return','adjustment','writeoff','transfer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty_change` decimal(14,6) NOT NULL,
  `qty_before` decimal(14,6) NOT NULL,
  `qty_after` decimal(14,6) NOT NULL,
  `unit_cost` decimal(14,2) DEFAULT NULL,
  `reference_id` int unsigned DEFAULT NULL COMMENT 'sale_id / return_id / etc.',
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_type` (`type`),
  KEY `idx_created` (`created_at`),
  KEY `fk_inv_user` (`user_id`),
  KEY `fk_inv_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_inv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_inv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_inv_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_movements`
--

LOCK TABLES `inventory_movements` WRITE;
/*!40000 ALTER TABLE `inventory_movements` DISABLE KEYS */;
INSERT INTO `inventory_movements` VALUES (1,5,1,5,'receipt',2.000000,0.000000,2.000000,16000.00,1,'receipt_accept','Acceptance of GR#GR-260317-001','2026-03-17 22:30:37'),(2,5,1,5,'receipt',0.500000,2.000000,2.500000,16000.00,2,'receipt_accept','Acceptance of GR#GR-260317-002','2026-03-17 23:54:04'),(3,6,1,5,'receipt',50.000000,0.000000,50.000000,500.00,3,'receipt_accept','Acceptance of GR#GR-260317-003','2026-03-17 23:54:34'),(4,5,1,5,'receipt',0.250000,2.500000,2.750000,16000.00,4,'receipt_accept','Acceptance of GR#GR-260317-004','2026-03-18 00:01:42'),(5,7,1,5,'receipt',20.000000,0.000000,20.000000,800.00,4,'receipt_accept','Acceptance of GR#GR-260317-004','2026-03-18 00:01:42'),(6,7,1,5,'sale',-4.000000,20.000000,16.000000,NULL,1,'sale',NULL,'2026-03-18 00:30:02'),(7,6,1,5,'sale',-1.000000,50.000000,49.000000,NULL,1,'sale',NULL,'2026-03-18 00:30:02'),(8,5,1,5,'sale',-0.125000,2.750000,2.625000,NULL,1,'sale',NULL,'2026-03-18 00:30:02'),(9,5,1,5,'sale',-0.002500,2.625000,2.622500,NULL,2,'sale',NULL,'2026-03-18 21:29:17'),(10,7,1,5,'sale',-6.000000,16.000000,10.000000,NULL,2,'sale',NULL,'2026-03-18 21:29:17'),(12,5,1,5,'sale',-1.000000,2.622500,1.622500,NULL,4,'sale',NULL,'2026-03-19 02:48:46'),(13,5,1,5,'sale',-0.125000,1.622500,1.497500,NULL,4,'sale',NULL,'2026-03-19 02:48:46'),(14,5,1,5,'sale',-0.125000,1.497500,1.372500,NULL,5,'sale',NULL,'2026-03-19 02:49:23'),(15,5,1,5,'sale',-0.001250,1.372500,1.371250,NULL,5,'sale',NULL,'2026-03-19 02:49:23'),(16,5,1,5,'sale',-1.000000,1.371250,0.371250,NULL,6,'sale',NULL,'2026-03-19 02:54:09'),(17,5,1,5,'sale',-0.125000,0.371250,0.246250,NULL,6,'sale',NULL,'2026-03-19 02:54:09');
/*!40000 ALTER TABLE `inventory_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `method` enum('cash','card','transfer','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `amount` decimal(12,2) NOT NULL,
  `cash_given` decimal(12,2) DEFAULT NULL COMMENT 'For cash payments: amount given by customer',
  `change_given` decimal(12,2) DEFAULT NULL COMMENT 'Change returned',
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Card auth / transfer ref',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  CONSTRAINT `fk_payment_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,'card',8610.00,0.00,0.00,NULL,'2026-03-18 00:30:02'),(2,2,'card',6712.00,0.00,0.00,NULL,'2026-03-18 21:29:17'),(4,4,'card',25760.00,0.00,0.00,NULL,'2026-03-19 02:48:46'),(5,5,'card',3416.00,0.00,0.00,NULL,'2026-03-19 02:49:23'),(6,6,'card',25760.00,0.00,0.00,NULL,'2026-03-19 02:54:09');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_types`
--

DROP TABLE IF EXISTS `price_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ru` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` tinyint unsigned NOT NULL DEFAULT '10',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `visible_in_pos` tinyint(1) NOT NULL DEFAULT '1',
  `visible_in_products` tinyint(1) NOT NULL DEFAULT '1',
  `visible_in_receipts` tinyint(1) NOT NULL DEFAULT '0',
  `color_hex` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'badge color e.g. #f59e0b',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_types`
--

LOCK TABLES `price_types` WRITE;
/*!40000 ALTER TABLE `price_types` DISABLE KEYS */;
INSERT INTO `price_types` VALUES (1,'retail','Retail Price','Розничная',1,1,1,1,1,0,'#10b981','2026-03-14 23:40:29'),(2,'wholesale1','Wholesale 1','Опт 1',2,1,0,1,1,0,'#3b82f6','2026-03-14 23:40:29'),(3,'wholesale2','Wholesale 2','Опт 2',3,1,0,1,1,0,'#8b5cf6','2026-03-14 23:40:29'),(4,'wholesale3','Wholesale 3','Опт 3',4,1,0,1,1,0,'#f59e0b','2026-03-14 23:40:29'),(5,'purchase','Purchase Cost','Закупочная',5,1,0,0,1,0,'#ef4444','2026-03-14 23:40:29');
/*!40000 ALTER TABLE `price_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_prices`
--

DROP TABLE IF EXISTS `product_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_prices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `price_type_id` tinyint unsigned NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KZT',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_pricetype` (`product_id`,`price_type_id`),
  KEY `idx_pp_product` (`product_id`),
  KEY `fk_pp_pricetype` (`price_type_id`),
  CONSTRAINT `fk_pp_pricetype` FOREIGN KEY (`price_type_id`) REFERENCES `price_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_prices`
--

LOCK TABLES `product_prices` WRITE;
/*!40000 ALTER TABLE `product_prices` DISABLE KEYS */;
INSERT INTO `product_prices` VALUES (50,5,5,400.00,'KZT','2026-03-17 19:27:04'),(51,5,1,600.00,'KZT','2026-03-17 19:27:04'),(52,6,5,500.00,'KZT','2026-03-17 18:54:34'),(53,6,1,850.00,'KZT','2026-03-17 18:54:34'),(54,7,5,800.00,'KZT','2026-03-17 19:01:42'),(55,7,1,1100.00,'KZT','2026-03-17 19:01:42'),(56,5,2,0.00,'KZT','2026-03-17 19:27:04'),(57,5,3,0.00,'KZT','2026-03-17 19:27:04'),(58,5,4,0.00,'KZT','2026-03-17 19:27:04');
/*!40000 ALTER TABLE `product_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_unit_prices`
--

DROP TABLE IF EXISTS `product_unit_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_unit_prices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `unit_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_type_id` tinyint unsigned NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit_price` (`product_id`,`unit_code`,`price_type_id`),
  KEY `idx_product_unit_prices_product` (`product_id`),
  KEY `idx_product_unit_prices_type` (`price_type_id`),
  CONSTRAINT `fk_product_unit_prices_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_unit_prices_type` FOREIGN KEY (`price_type_id`) REFERENCES `price_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_unit_prices`
--

LOCK TABLES `product_unit_prices` WRITE;
/*!40000 ALTER TABLE `product_unit_prices` DISABLE KEYS */;
INSERT INTO `product_unit_prices` VALUES (29,6,'pcs',1,850.00,'2026-03-17 23:54:34','2026-03-17 23:54:34'),(30,6,'pcs',5,500.00,'2026-03-17 23:54:34','2026-03-17 23:54:34'),(39,7,'pcs',1,1100.00,'2026-03-18 00:01:42','2026-03-18 00:01:42'),(40,7,'pcs',5,800.00,'2026-03-18 00:01:42','2026-03-18 00:01:42'),(56,5,'box',1,20000.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(57,5,'box',2,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(58,5,'box',3,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(59,5,'box',4,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(60,5,'box',5,16000.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(61,5,'упаковка',1,600.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(62,5,'упаковка',2,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(63,5,'упаковка',3,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(64,5,'упаковка',4,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(65,5,'упаковка',5,400.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(66,5,'штук',1,10.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(67,5,'штук',2,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(68,5,'штук',3,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(69,5,'штук',4,0.00,'2026-03-18 00:27:04','2026-03-18 00:27:04'),(70,5,'штук',5,4.00,'2026-03-18 00:27:04','2026-03-18 00:27:04');
/*!40000 ALTER TABLE `product_unit_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_units`
--

DROP TABLE IF EXISTS `product_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_units` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `unit_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ratio_to_base` decimal(14,3) NOT NULL DEFAULT '1.000',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit` (`product_id`,`unit_code`),
  KEY `idx_product_units_product` (`product_id`),
  CONSTRAINT `fk_product_units_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_units`
--

LOCK TABLES `product_units` WRITE;
/*!40000 ALTER TABLE `product_units` DISABLE KEYS */;
INSERT INTO `product_units` VALUES (9,6,'pcs','Штук',1.000,0,1,'2026-03-17 23:31:25'),(10,7,'pcs','Штук',1.000,0,1,'2026-03-17 23:56:39'),(14,5,'box','Коробка',1.000,0,0,'2026-03-18 00:27:04'),(15,5,'упаковка','Упаковка',40.000,10,1,'2026-03-18 00:27:04'),(16,5,'штук','Штук',4000.000,20,0,'2026-03-18 00:27:04');
/*!40000 ALTER TABLE `product_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` smallint unsigned NOT NULL,
  `name_en` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ru` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sku` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_en` text COLLATE utf8mb4_unicode_ci,
  `description_ru` text COLLATE utf8mb4_unicode_ci,
  `unit` enum('pcs','kg','g','t','l','ml','m','m2','m3','pack','roll','bag','box','pair','set','pallet') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `sale_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `cost_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'VAT percent',
  `stock_qty` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `min_stock_qty` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allow_discount` tinyint(1) NOT NULL DEFAULT '1',
  `is_weighable` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `idx_sku` (`sku`),
  KEY `idx_barcode` (`barcode`),
  KEY `idx_category` (`category_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_product_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (5,3,'Саморезы 3 x 50','Саморезы 3 x 50','AUTO-3CB6B7BE','2026031719598','','','','box',600.00,400.00,12.00,0.246250,0.000000,NULL,1,0,1,'2026-03-17 17:16:36','2026-03-18 21:54:09'),(6,1,'Лампочка 100W','Лампочка 100W','AUTO-4FB21B0F','2026031717413',NULL,NULL,NULL,'pcs',850.00,500.00,0.00,49.000000,0.000000,NULL,1,0,0,'2026-03-17 18:31:25','2026-03-17 19:30:24'),(7,3,'Брус 5 x 10','Брус 5 x 10','AUTO-37397B7D','2026031789544',NULL,NULL,NULL,'pcs',1100.00,800.00,0.00,10.000000,0.000000,NULL,1,0,1,'2026-03-17 18:56:39','2026-03-18 21:48:58');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `return_items`
--

DROP TABLE IF EXISTS `return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `return_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int unsigned NOT NULL,
  `sale_item_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`),
  KEY `fk_retitem_product` (`product_id`),
  CONSTRAINT `fk_retitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_retitem_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `return_items`
--

LOCK TABLES `return_items` WRITE;
/*!40000 ALTER TABLE `return_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `return_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `returns`
--

DROP TABLE IF EXISTS `returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `returns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `return_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `refund_method` enum('cash','card','store_credit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_no` (`return_no`),
  KEY `idx_sale` (`sale_id`),
  KEY `fk_return_user` (`user_id`),
  CONSTRAINT `fk_return_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `fk_return_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `returns`
--

LOCK TABLES `returns` WRITE;
/*!40000 ALTER TABLE `returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_price_visibility`
--

DROP TABLE IF EXISTS `role_price_visibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_price_visibility` (
  `role_id` tinyint unsigned NOT NULL,
  `price_type_id` tinyint unsigned NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '1',
  `can_edit` tinyint(1) NOT NULL DEFAULT '0',
  `in_pos` tinyint(1) NOT NULL DEFAULT '1',
  `in_products` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`role_id`,`price_type_id`),
  KEY `fk_rpv_pt` (`price_type_id`),
  CONSTRAINT `fk_rpv_pt` FOREIGN KEY (`price_type_id`) REFERENCES `price_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rpv_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_price_visibility`
--

LOCK TABLES `role_price_visibility` WRITE;
/*!40000 ALTER TABLE `role_price_visibility` DISABLE KEYS */;
INSERT INTO `role_price_visibility` VALUES (1,1,1,1,1,1),(1,2,1,1,1,1),(1,3,1,1,1,1),(1,4,1,1,1,1),(1,5,1,1,0,1),(2,1,1,1,1,1),(2,2,1,1,1,1),(2,3,1,1,1,1),(2,4,1,1,1,1),(2,5,1,1,0,1),(3,1,1,0,1,1),(3,2,0,0,0,0),(3,3,0,0,0,0),(3,4,0,0,0,0),(3,5,0,0,0,0);
/*!40000 ALTER TABLE `role_price_visibility` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_ui_settings`
--

DROP TABLE IF EXISTS `role_ui_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_ui_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` tinyint unsigned NOT NULL,
  `module` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_json` json NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_id`,`module`),
  CONSTRAINT `fk_rus_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_ui_settings`
--

LOCK TABLES `role_ui_settings` WRITE;
/*!40000 ALTER TABLE `role_ui_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_ui_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permissions` json NOT NULL DEFAULT (_utf8mb4'{}'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Administrator','{\"all\": true}'),(2,'manager','Manager','{\"pos\": true, \"sales\": true, \"shifts\": true, \"reports\": true, \"returns\": true, \"products\": true, \"customers\": true, \"dashboard\": true, \"inventory\": true, \"transfers\": true, \"categories\": true, \"ui_settings\": true, \"price_types_view\": true}'),(3,'cashier','Cashier','{\"pos\": true, \"sales\": true, \"shifts\": true, \"returns\": true, \"customers\": true, \"dashboard\": true}');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Snapshot at time of sale',
  `product_sku` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_item_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
INSERT INTO `sale_items` VALUES (1,1,7,'Брус 5 x 10','AUTO-37397B7D','pcs',4.000,1100.00,0.00,0.00,0.00,0.00,4400.00),(2,1,6,'Лампочка 100W','AUTO-4FB21B0F','pcs',1.000,850.00,0.00,0.00,0.00,0.00,850.00),(3,1,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','упаковка',5.000,600.00,0.00,0.00,12.00,360.00,3360.00),(4,2,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','штук',10.000,10.00,0.00,0.00,12.00,12.00,112.00),(5,2,7,'Брус 5 x 10','AUTO-37397B7D','pcs',6.000,1100.00,0.00,0.00,0.00,0.00,6600.00),(7,4,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','box',1.000,20000.00,0.00,0.00,12.00,2400.00,22400.00),(8,4,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','упаковка',5.000,600.00,0.00,0.00,12.00,360.00,3360.00),(9,5,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','упаковка',5.000,600.00,0.00,0.00,12.00,360.00,3360.00),(10,5,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','штук',5.000,10.00,0.00,0.00,12.00,6.00,56.00),(11,6,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','box',1.000,20000.00,0.00,0.00,12.00,2400.00,22400.00),(12,6,5,'Саморезы 3 x 50','AUTO-3CB6B7BE','упаковка',5.000,600.00,0.00,0.00,12.00,360.00,3360.00);
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `warehouse_id` smallint unsigned DEFAULT '1',
  `customer_id` int unsigned NOT NULL DEFAULT '1',
  `customer_type_snapshot` enum('retail','legal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail',
  `customer_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_company_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_iin_bin_snapshot` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_address_snapshot` text COLLATE utf8mb4_unicode_ci,
  `customer_phone_snapshot` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('completed','voided','refunded','partial_refund') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  UNIQUE KEY `idx_receipt` (`receipt_no`),
  KEY `idx_shift` (`shift_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_created` (`created_at`),
  KEY `fk_sales_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_sale_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sale_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sale_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sales_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` (`id`,`shift_id`,`user_id`,`warehouse_id`,`customer_id`,`customer_type_snapshot`,`customer_name_snapshot`,`customer_company_snapshot`,`customer_iin_bin_snapshot`,`customer_address_snapshot`,`customer_phone_snapshot`,`customer_email_snapshot`,`receipt_no`,`subtotal`,`discount_amount`,`tax_amount`,`total`,`notes`,`status`,`created_at`) VALUES (1,1,5,1,1,'retail','Покупатель',NULL,NULL,NULL,NULL,NULL,'RCP-260318-45E79',8250.00,0.00,360.00,8610.00,'','completed','2026-03-18 00:30:02'),(2,1,5,1,1,'retail','Покупатель',NULL,NULL,NULL,NULL,NULL,'RCP-260318-80931',6700.00,0.00,12.00,6712.00,'','completed','2026-03-18 21:29:17'),(4,1,5,1,1,'retail','Покупатель',NULL,NULL,NULL,NULL,NULL,'RCP-260319-26E9C',23000.00,0.00,2760.00,25760.00,'','completed','2026-03-19 02:48:46'),(5,1,5,1,1,'retail','Покупатель',NULL,NULL,NULL,NULL,NULL,'RCP-260319-9B5CA',3050.00,0.00,366.00,3416.00,'','completed','2026-03-19 02:49:23'),(6,1,5,1,1,'retail','Покупатель',NULL,NULL,NULL,NULL,NULL,'RCP-260319-BAAC9',23000.00,0.00,2760.00,25760.00,'','completed','2026-03-19 02:54:09');
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `type` enum('text','number','boolean','select','textarea','color') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'store_name','MAXСтрой Сыганак','Store Name','store','text'),(2,'store_address','г. Астана, Сыганак 16/1','Store Address','store','textarea'),(3,'store_phone','+7 (708) 168-82-85','Phone','store','text'),(4,'store_email','info@buildmart.local','Email','store','text'),(5,'store_inn','7700000000','IIN/BIN','store','text'),(6,'currency_symbol','?','Currency Symbol','general','text'),(7,'currency_code','KZT','Currency Code','general','select'),(8,'default_tax_rate','20','Default VAT Rate (%)','general','number'),(9,'default_language','ru','Default Language','general','select'),(10,'receipt_header','Спасибо за покупку!','Receipt Header Text','receipt','text'),(11,'receipt_footer','Товар надлежащего качества обмену и возврату не подлежит в течение 14 дней','Receipt Footer','receipt','textarea'),(12,'receipt_show_logo','0','Show Logo on Receipt','receipt','boolean'),(13,'low_stock_email','squere@susanoo.ru','Low Stock Alert Email','alerts','text'),(14,'shifts_required','1','Require Shift to Make Sales','pos','boolean'),(15,'gr_org_name','ИП \"БЕН ТРЕЙД\"','Organization Name','gr_template','text'),(16,'gr_org_inn','-','IIN / BIN / Tax Number','gr_template','text'),(17,'gr_org_address','г. Астана, ул. Сыганак 16/1','Organization Address','gr_template','textarea'),(18,'gr_doc_title','ТОВАРНАЯ НАКЛАДНАЯ','Document Title','gr_template','text'),(19,'gr_header_note','','Header Note','gr_template','textarea'),(20,'gr_footer_note','Товар получен в полном объёме, претензий нет.','Footer Note','gr_template','textarea'),(21,'gr_label_warehouse','Склад','Warehouse Label','gr_template','text'),(22,'gr_label_supplier','Поставщик','Supplier Label','gr_template','text'),(23,'gr_label_accepted_by','Принял','Accepted By Label','gr_template','text'),(24,'gr_label_delivered_by','Сдал','Delivered By Label','gr_template','text'),(35,'timezone','Asia/Almaty','Timezone','general','select');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shifts`
--

DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shifts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `opening_cash` decimal(12,2) NOT NULL DEFAULT '0.00',
  `closing_cash` decimal(12,2) DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `cash_difference` decimal(12,2) DEFAULT NULL,
  `total_sales` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_returns` decimal(14,2) NOT NULL DEFAULT '0.00',
  `transaction_count` int unsigned NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_shift_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shifts`
--

LOCK TABLES `shifts` WRITE;
/*!40000 ALTER TABLE `shifts` DISABLE KEYS */;
INSERT INTO `shifts` VALUES (1,5,'2026-03-18 00:02:33','2026-03-19 03:41:00',0.00,0.00,0.00,0.00,71358.00,0.00,6,'','closed');
/*!40000 ALTER TABLE `shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_balances`
--

DROP TABLE IF EXISTS `stock_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_balances` (
  `product_id` int unsigned NOT NULL,
  `warehouse_id` smallint unsigned NOT NULL,
  `qty` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`,`warehouse_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_sb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sb_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_balances`
--

LOCK TABLES `stock_balances` WRITE;
/*!40000 ALTER TABLE `stock_balances` DISABLE KEYS */;
INSERT INTO `stock_balances` VALUES (5,1,0.246250,'2026-03-18 21:54:09'),(6,1,49.000000,'2026-03-17 19:30:02'),(7,1,10.000000,'2026-03-18 21:48:58');
/*!40000 ALTER TABLE `stock_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_transfer_items`
--

DROP TABLE IF EXISTS `stock_transfer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Snapshot at time of save',
  `unit` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `unit_label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `qty_base` decimal(14,6) NOT NULL DEFAULT '0.000000',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_transfer` (`transfer_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_sti_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_transfer_items`
--

LOCK TABLES `stock_transfer_items` WRITE;
/*!40000 ALTER TABLE `stock_transfer_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_transfer_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_transfers`
--

DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `doc_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. TRF-250613-001',
  `doc_date` date NOT NULL,
  `from_warehouse_id` smallint unsigned NOT NULL,
  `to_warehouse_id` smallint unsigned NOT NULL,
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `posted_by` int unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_no` (`doc_no`),
  KEY `idx_from_wh` (`from_warehouse_id`),
  KEY `idx_to_wh` (`to_warehouse_id`),
  KEY `idx_status` (`status`),
  KEY `idx_doc_date` (`doc_date`),
  KEY `idx_created_by` (`created_by`),
  KEY `fk_st_posted` (`posted_by`),
  KEY `fk_st_cancelled` (`cancelled_by`),
  CONSTRAINT `fk_st_cancelled` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_st_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_st_from_wh` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_st_posted` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_st_to_wh` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_transfers`
--

LOCK TABLES `stock_transfers` WRITE;
/*!40000 ALTER TABLE `stock_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Contact person name',
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inn` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tax ID / BIN / IIN',
  `bank_details` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`(50)),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'Мир электрики','Игорь','+7(702)705-47-42','worldofelectrition@gmail.com','Республика 23/2','123456789101','Каспи перевод','',1,'2026-03-16 17:15:30','2026-03-16 17:15:30'),(2,'Сухие смеси','Максим','+77081688285',NULL,'Республика 22/2','123456789101',NULL,NULL,1,'2026-03-16 20:15:53','2026-03-16 20:15:53'),(3,'Сухие смеси','Максим','+77081688285',NULL,'','123456789101',NULL,NULL,1,'2026-03-16 20:33:30','2026-03-16 20:33:30'),(4,'Строймир','Марина','+77070410609',NULL,'','123456789101',NULL,NULL,1,'2026-03-17 17:29:26','2026-03-17 17:29:26');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ui_presets`
--

DROP TABLE IF EXISTS `ui_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ui_presets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'products, pos, inventory, sales, etc.',
  `scope_type` enum('system','role','warehouse','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `scope_id` int unsigned DEFAULT NULL COMMENT 'role_id / warehouse_id / user_id',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `settings_json` json NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_preset_scope` (`module`,`scope_type`,`scope_id`),
  KEY `idx_preset_default` (`module`,`scope_type`,`scope_id`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ui_presets`
--

LOCK TABLES `ui_presets` WRITE;
/*!40000 ALTER TABLE `ui_presets` DISABLE KEYS */;
/*!40000 ALTER TABLE `ui_presets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unit_presets`
--

DROP TABLE IF EXISTS `unit_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unit_presets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_unit_presets_code` (`unit_code`),
  UNIQUE KEY `uniq_unit_presets_label` (`unit_label`),
  KEY `idx_unit_presets_active_sort` (`is_active`,`sort_order`,`unit_label`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unit_presets`
--

LOCK TABLES `unit_presets` WRITE;
/*!40000 ALTER TABLE `unit_presets` DISABLE KEYS */;
INSERT INTO `unit_presets` VALUES (1,'korobka','Коробка',10,1,'2026-03-17 20:58:51','2026-03-17 20:58:51'),(2,'upakovka','Упаковка',20,1,'2026-03-17 20:58:51','2026-03-17 20:58:51'),(3,'shtuk','Штук',30,1,'2026-03-17 20:58:51','2026-03-17 20:58:51');
/*!40000 ALTER TABLE `unit_presets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_preferences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `module` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_json` json NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_module` (`user_id`,`module`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_preferences`
--

LOCK TABLES `user_preferences` WRITE;
/*!40000 ALTER TABLE `user_preferences` DISABLE KEYS */;
INSERT INTO `user_preferences` VALUES (1,5,'products','{\"columns\": [\"name\", \"sku\", \"category\", \"unit\", \"stock\", \"status\", \"price_retail\", \"price_wholesale1\", \"price_purchase\", \"actions\"], \"filters\": {\"search\": \"\", \"status\": \"\", \"category_id\": \"\"}, \"sort_by\": \"name\", \"per_page\": 30, \"sort_dir\": \"asc\", \"view_mode\": \"table\", \"columns_order\": [\"name\", \"sku\", \"category\", \"unit\", \"stock\", \"status\", \"price_retail\", \"price_wholesale1\", \"price_wholesale2\", \"price_wholesale3\", \"price_purchase\", \"actions\"], \"group_by_category\": 0}','2026-03-16 21:09:44');
/*!40000 ALTER TABLE `user_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` tinyint unsigned NOT NULL DEFAULT '3',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pin` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Quick PIN for shift login',
  `phone` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` char(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ru',
  `language_set_at` datetime DEFAULT NULL,
  `default_warehouse_id` smallint unsigned DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (5,1,'Primary Admin','admin@buildmart.local','$2y$12$W1KKWl7FdovYIdqQIxbN2utuDsTTETnN6X/lMk89.ZNVEQe2cRZfy','',NULL,'ru',NULL,1,1,0,'2026-03-19 02:48:01','2026-03-16 16:34:29','2026-03-18 21:48:01');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_ui_settings`
--

DROP TABLE IF EXISTS `warehouse_ui_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_ui_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` smallint unsigned NOT NULL,
  `module` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_json` json NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_module` (`warehouse_id`,`module`),
  CONSTRAINT `fk_wus_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_ui_settings`
--

LOCK TABLES `warehouse_ui_settings` WRITE;
/*!40000 ALTER TABLE `warehouse_ui_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `warehouse_ui_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_user_access`
--

DROP TABLE IF EXISTS `warehouse_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_user_access` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `warehouse_id` smallint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wh` (`user_id`,`warehouse_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_wua_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wua_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_user_access`
--

LOCK TABLES `warehouse_user_access` WRITE;
/*!40000 ALTER TABLE `warehouse_user_access` DISABLE KEYS */;
INSERT INTO `warehouse_user_access` VALUES (14,5,1);
/*!40000 ALTER TABLE `warehouse_user_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES (1,'WH001','Шыгыс','Сыганак 16/1','',1,'2026-03-13 10:42:58'),(3,NULL,'Туран','Туран 43','',1,'2026-03-16 16:41:59');
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-19 14:11:21
