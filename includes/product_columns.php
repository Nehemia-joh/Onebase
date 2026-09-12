<?php
declare(strict_types=1);

/**
 * Makes sure the extended product columns (category/unit/multi-tier
 * wholesale/expiry/VAT) exist — added on the fly for installs that
 * predate them. Safe to call on every request; idempotent.
 *
 * @return string[] columns it could not add (should normally be empty)
 */
function ensureProductColumns(PDO $db): array
{
    $need = [
        'category'    => "VARCHAR(100) DEFAULT NULL AFTER `description`",
        'unit'        => "VARCHAR(30) DEFAULT NULL AFTER `category`",
        'ws1_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `wholesale_price`",
        'ws2_price'   => "DECIMAL(15,2) DEFAULT NULL AFTER `ws1_unit`",
        'ws2_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `ws2_price`",
        'ws3_price'   => "DECIMAL(15,2) DEFAULT NULL AFTER `ws2_unit`",
        'ws3_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `ws3_price`",
        'expiry_date' => "DATE DEFAULT NULL AFTER `min_stock_alert`",
        'vat_status'  => "ENUM('INCL','EXCL') DEFAULT NULL AFTER `expiry_date`",
    ];
    $stmt = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
    $have = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $failed = [];
    foreach ($need as $col => $ddl) {
        if (in_array(strtolower($col), $have, true)) continue;
        try {
            $db->exec("ALTER TABLE `products` ADD COLUMN `$col` $ddl");
        } catch (PDOException) {
            $failed[] = $col;
        }
    }
    return $failed;
}
