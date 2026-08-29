<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$productId = cleanInt($_GET['product_id'] ?? 0);
$branchId  = cleanInt($_GET['branch_id'] ?? 0);
$qty       = cleanInt($_GET['qty'] ?? 1);

if (!$productId || !$branchId) { json_out(['error' => 'Missing parameters'], 400); }

$stmt = getDB()->prepare('SELECT s.quantity, p.name, p.min_stock_alert FROM stock s JOIN products p ON p.id=s.product_id WHERE s.product_id=? AND s.branch_id=?');
$stmt->execute([$productId, $branchId]);
$row = $stmt->fetch();

if (!$row) { json_out(['available' => false, 'quantity' => 0, 'message' => 'Product not stocked in this branch']); }

$available = (int)$row['quantity'] >= $qty;
json_out([
    'available' => $available,
    'quantity'  => (int)$row['quantity'],
    'requested' => $qty,
    'product'   => $row['name'],
    'low_stock' => (int)$row['quantity'] <= (int)$row['min_stock_alert'],
    'message'   => $available ? 'Stock available' : "Only {$row['quantity']} units available",
]);
