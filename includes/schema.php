<?php
declare(strict_types=1);

/**
 * Canonical database schema — used by the first-run installer to build a
 * fresh database from nothing. Every statement is CREATE TABLE IF NOT
 * EXISTS, so running it again is always safe (never drops or alters data).
 *
 * @return string[] table name => CREATE TABLE statement
 */
function busybaseSchema(): array
{
    return [
        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(50) NOT NULL,
            `email` VARCHAR(100) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(100) NOT NULL,
            `phone` VARCHAR(20) DEFAULT NULL,
            `role` ENUM('super_admin','zone_manager','branch_manager','cashier','stock_controller') NOT NULL DEFAULT 'cashier',
            `branch_id` INT DEFAULT NULL,
            `zone_id` INT DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT '1',
            `last_login` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            UNIQUE KEY `email` (`email`),
            KEY `idx_branch` (`branch_id`),
            KEY `idx_zone` (`zone_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'zones' => "CREATE TABLE IF NOT EXISTS `zones` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT,
            `manager_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `fk_zone_manager` (`manager_id`),
            CONSTRAINT `fk_zone_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'branches' => "CREATE TABLE IF NOT EXISTS `branches` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `zone_id` INT DEFAULT NULL,
            `manager_id` INT DEFAULT NULL,
            `address` TEXT,
            `phone` VARCHAR(20) DEFAULT NULL,
            `email` VARCHAR(100) DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT '1',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_zone` (`zone_id`),
            KEY `fk_branch_manager` (`manager_id`),
            CONSTRAINT `fk_branch_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_branch_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'activity_logs' => "CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT DEFAULT NULL,
            `action` VARCHAR(100) NOT NULL,
            `description` TEXT,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'currency_settings' => "CREATE TABLE IF NOT EXISTS `currency_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `currency_code` VARCHAR(10) NOT NULL DEFAULT 'TSh',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT 'TSh',
            `currency_name` VARCHAR(50) NOT NULL DEFAULT 'Tanzanian Shilling',
            `decimal_places` INT DEFAULT '0',
            `thousands_separator` VARCHAR(5) DEFAULT ',',
            `decimal_separator` VARCHAR(5) DEFAULT '.',
            `symbol_position` ENUM('before','after') DEFAULT 'before',
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
            `key_name` VARCHAR(50) NOT NULL,
            `value` TEXT,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`key_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'customers' => "CREATE TABLE IF NOT EXISTS `customers` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `phone` VARCHAR(20) DEFAULT NULL,
            `email` VARCHAR(100) DEFAULT NULL,
            `type` ENUM('retail','wholesale','regular') DEFAULT 'retail',
            `total_purchases` DECIMAL(15,2) DEFAULT '0.00',
            `last_purchase_date` DATE DEFAULT NULL,
            `notes` TEXT,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'suppliers' => "CREATE TABLE IF NOT EXISTS `suppliers` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `company_name` VARCHAR(150) DEFAULT NULL,
            `contact_person` VARCHAR(100) DEFAULT NULL,
            `phone` VARCHAR(20) DEFAULT NULL,
            `email` VARCHAR(100) DEFAULT NULL,
            `address` TEXT,
            `tax_id` VARCHAR(50) DEFAULT NULL,
            `payment_terms` VARCHAR(100) DEFAULT NULL,
            `status` ENUM('active','inactive') DEFAULT 'active',
            `notes` TEXT,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'products' => "CREATE TABLE IF NOT EXISTS `products` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sku` VARCHAR(50) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `barcode` VARCHAR(100) DEFAULT NULL,
            `description` TEXT,
            `category` VARCHAR(100) DEFAULT NULL,
            `unit` VARCHAR(30) DEFAULT NULL,
            `cost_price` DECIMAL(15,2) DEFAULT '0.00',
            `wholesale_price` DECIMAL(15,2) DEFAULT '0.00',
            `ws1_unit` VARCHAR(30) DEFAULT NULL,
            `ws2_price` DECIMAL(15,2) DEFAULT NULL,
            `ws2_unit` VARCHAR(30) DEFAULT NULL,
            `ws3_price` DECIMAL(15,2) DEFAULT NULL,
            `ws3_unit` VARCHAR(30) DEFAULT NULL,
            `retail_price` DECIMAL(15,2) DEFAULT '0.00',
            `min_stock_alert` INT DEFAULT '5',
            `expiry_date` DATE DEFAULT NULL,
            `vat_status` ENUM('INCL','EXCL') DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT '1',
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `sku` (`sku`),
            KEY `created_by` (`created_by`),
            FULLTEXT KEY `ft_product_search` (`name`,`sku`),
            CONSTRAINT `products_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'product_suppliers' => "CREATE TABLE IF NOT EXISTS `product_suppliers` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `supplier_id` INT NOT NULL,
            `supplier_sku` VARCHAR(50) DEFAULT NULL,
            `cost_price` DECIMAL(15,2) DEFAULT NULL,
            `lead_time_days` INT DEFAULT '0',
            `is_preferred` TINYINT(1) DEFAULT '0',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_product_supplier` (`product_id`,`supplier_id`),
            KEY `supplier_id` (`supplier_id`),
            CONSTRAINT `product_suppliers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `product_suppliers_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'stock' => "CREATE TABLE IF NOT EXISTS `stock` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `branch_id` INT NOT NULL,
            `quantity` INT DEFAULT '0',
            `cost_price_override` DECIMAL(15,2) DEFAULT NULL,
            `wholesale_price_override` DECIMAL(15,2) DEFAULT NULL,
            `retail_price_override` DECIMAL(15,2) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_product_branch` (`product_id`,`branch_id`),
            KEY `branch_id` (`branch_id`),
            CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `stock_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'stock_adjustments' => "CREATE TABLE IF NOT EXISTS `stock_adjustments` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `branch_id` INT NOT NULL,
            `adjustment_type` ENUM('add','subtract','set','transfer_in','transfer_out') NOT NULL,
            `quantity_before` INT NOT NULL,
            `quantity_changed` INT NOT NULL,
            `quantity_after` INT NOT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `reference` VARCHAR(100) DEFAULT NULL,
            `user_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_product_branch` (`product_id`,`branch_id`),
            KEY `branch_id` (`branch_id`),
            KEY `user_id` (`user_id`),
            CONSTRAINT `stock_adjustments_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `stock_adjustments_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
            CONSTRAINT `stock_adjustments_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'purchase_orders' => "CREATE TABLE IF NOT EXISTS `purchase_orders` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `po_number` VARCHAR(50) NOT NULL,
            `supplier_id` INT NOT NULL,
            `branch_id` INT NOT NULL,
            `status` ENUM('draft','pending','approved','ordered','received','cancelled') DEFAULT 'draft',
            `subtotal` DECIMAL(15,2) DEFAULT '0.00',
            `tax` DECIMAL(15,2) DEFAULT '0.00',
            `shipping` DECIMAL(15,2) DEFAULT '0.00',
            `discount` DECIMAL(15,2) DEFAULT '0.00',
            `total` DECIMAL(15,2) DEFAULT '0.00',
            `notes` TEXT,
            `expected_date` DATE DEFAULT NULL,
            `received_date` DATE DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `approved_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `po_number` (`po_number`),
            KEY `idx_supplier` (`supplier_id`),
            KEY `idx_branch` (`branch_id`),
            KEY `idx_status` (`status`),
            KEY `created_by` (`created_by`),
            KEY `approved_by` (`approved_by`),
            CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `purchase_orders_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'purchase_order_items' => "CREATE TABLE IF NOT EXISTS `purchase_order_items` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `po_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `product_name` VARCHAR(200) NOT NULL,
            `sku` VARCHAR(50) NOT NULL,
            `quantity_ordered` INT NOT NULL,
            `quantity_received` INT DEFAULT '0',
            `unit_cost` DECIMAL(15,2) NOT NULL,
            `total_cost` DECIMAL(15,2) NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `po_id` (`po_id`),
            KEY `product_id` (`product_id`),
            CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'po_receiving_log' => "CREATE TABLE IF NOT EXISTS `po_receiving_log` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `po_id` INT NOT NULL,
            `po_item_id` INT NOT NULL,
            `quantity_received` INT NOT NULL,
            `received_by` INT DEFAULT NULL,
            `notes` TEXT,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `po_id` (`po_id`),
            KEY `po_item_id` (`po_item_id`),
            KEY `received_by` (`received_by`),
            CONSTRAINT `po_receiving_log_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `po_receiving_log_ibfk_2` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE CASCADE,
            CONSTRAINT `po_receiving_log_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sales' => "CREATE TABLE IF NOT EXISTS `sales` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `invoice_no` VARCHAR(50) NOT NULL,
            `branch_id` INT NOT NULL,
            `customer_id` INT DEFAULT NULL,
            `customer_name` VARCHAR(100) DEFAULT NULL,
            `customer_type` ENUM('retail','wholesale') DEFAULT 'retail',
            `subtotal` DECIMAL(15,2) DEFAULT '0.00',
            `discount` DECIMAL(15,2) DEFAULT '0.00',
            `tax` DECIMAL(15,2) DEFAULT '0.00',
            `total` DECIMAL(15,2) DEFAULT '0.00',
            `payment_method` ENUM('cash','card','mobile_money','credit') DEFAULT 'cash',
            `payment_status` ENUM('paid','pending','partial') DEFAULT 'paid',
            `amount_paid` DECIMAL(15,2) DEFAULT '0.00',
            `notes` TEXT,
            `cashier_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_no` (`invoice_no`),
            KEY `idx_branch_date` (`branch_id`,`created_at`),
            KEY `idx_customer` (`customer_id`),
            KEY `cashier_id` (`cashier_id`),
            CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
            CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sale_items' => "CREATE TABLE IF NOT EXISTS `sale_items` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sale_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `product_name` VARCHAR(200) NOT NULL,
            `sku` VARCHAR(50) NOT NULL,
            `quantity` INT NOT NULL,
            `unit_price` DECIMAL(15,2) NOT NULL,
            `price_type` ENUM('retail','wholesale') DEFAULT 'retail',
            `total_price` DECIMAL(15,2) NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `sale_id` (`sale_id`),
            KEY `product_id` (`product_id`),
            CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
            CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sale_payments' => "CREATE TABLE IF NOT EXISTS `sale_payments` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sale_id` INT NOT NULL,
            `amount` DECIMAL(15,2) NOT NULL,
            `method` ENUM('cash','card','mobile_money','return_credit') DEFAULT 'cash',
            `notes` VARCHAR(255) DEFAULT NULL,
            `received_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_sale` (`sale_id`),
            KEY `received_by` (`received_by`),
            CONSTRAINT `sale_payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
            CONSTRAINT `sale_payments_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sale_returns' => "CREATE TABLE IF NOT EXISTS `sale_returns` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `return_no` VARCHAR(50) NOT NULL,
            `sale_id` INT NOT NULL,
            `branch_id` INT NOT NULL,
            `total_refund` DECIMAL(15,2) DEFAULT '0.00',
            `refund_method` ENUM('cash','card','mobile_money','deduct_balance') DEFAULT 'cash',
            `reason` VARCHAR(255) DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `return_no` (`return_no`),
            KEY `idx_sale` (`sale_id`),
            KEY `idx_branch_date` (`branch_id`,`created_at`),
            KEY `created_by` (`created_by`),
            CONSTRAINT `sale_returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
            CONSTRAINT `sale_returns_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `sale_returns_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'sale_return_items' => "CREATE TABLE IF NOT EXISTS `sale_return_items` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `return_id` INT NOT NULL,
            `sale_item_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `product_name` VARCHAR(200) DEFAULT NULL,
            `sku` VARCHAR(50) DEFAULT NULL,
            `quantity` INT NOT NULL,
            `unit_price` DECIMAL(15,2) DEFAULT '0.00',
            `total_price` DECIMAL(15,2) DEFAULT '0.00',
            PRIMARY KEY (`id`),
            KEY `idx_return` (`return_id`),
            KEY `idx_sale_item` (`sale_item_id`),
            CONSTRAINT `sale_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'failed_logins' => "CREATE TABLE IF NOT EXISTS `failed_logins` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(100) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `attempted_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'rate_limits' => "CREATE TABLE IF NOT EXISTS `rate_limits` (
            `key_name` VARCHAR(150) NOT NULL,
            `attempts` INT DEFAULT '1',
            `last_attempt` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`key_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'backup_logs' => "CREATE TABLE IF NOT EXISTS `backup_logs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `success` TINYINT(1) NOT NULL,
            `message` TEXT,
            `tables_count` INT DEFAULT NULL,
            `rows_count` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}
