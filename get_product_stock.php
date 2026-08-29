<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$productId = cleanInt($_GET['product_id'] ?? 0);
$branchId  = cleanInt($_GET['branch_id'] ?? 0);

if (!$productId) { json_out(['error' => 'Product ID required'], 400); }

$db = getDB();
if ($branchId) {
    $stmt = $db->prepare('SELECT s.quantity, b.name as branch_name FROM stock s JOIN branches b ON b.id=s.branch_id WHERE s.product_id=? AND s.branch_id=?');
    $stmt->execute([$productId, $branchId]);
    $row = $stmt->fetch();
    json_out($row ?: ['quantity' => 0, 'branch_name' => '']);
} else {
    $stmt = $db->prepare('SELECT s.quantity, b.id as branch_id, b.name as branch_name FROM stock s JOIN branches b ON b.id=s.branch_id WHERE s.product_id=? ORDER BY b.name');
    $stmt->execute([$productId]);
    json_out($stmt->fetchAll());
}
