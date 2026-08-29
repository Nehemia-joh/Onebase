<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$branchId = cleanInt($_GET['branch_id'] ?? 0);
if (!$branchId) { json_out([]); }

$stmt = getDB()->prepare(
    'SELECT p.id, p.name, p.sku, p.barcode,
            COALESCE(s.retail_price_override, p.retail_price) as retail_price,
            COALESCE(s.wholesale_price_override, p.wholesale_price) as wholesale_price,
            COALESCE(s.quantity, 0) as qty
     FROM products p
     LEFT JOIN stock s ON s.product_id = p.id AND s.branch_id = ?
     WHERE p.is_active = 1
     ORDER BY p.name'
);
$stmt->execute([$branchId]);
json_out($stmt->fetchAll());
