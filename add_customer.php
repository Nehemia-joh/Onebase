<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'Method not allowed'], 405); }
csrf_verify();

$name  = clean($_POST['name'] ?? '');
$phone = clean($_POST['phone'] ?? '');
$email = filter_var(clean($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
$type  = in_array($_POST['type'] ?? '', ['retail','wholesale','regular']) ? $_POST['type'] : 'retail';

if (!$name) { json_out(['error' => 'Customer name is required'], 422); }

$db = getDB();
$db->prepare('INSERT INTO customers (name, phone, email, type, created_by) VALUES (?,?,?,?,?)')
    ->execute([$name, $phone, $email, $type, $_SESSION['user_id'] ?? null]);

$id = (int)$db->lastInsertId();
logActivity('customer_added', "Added customer: $name");
json_out(['success' => true, 'id' => $id, 'name' => $name]);
