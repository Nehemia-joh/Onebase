<?php
/**
 * Standalone database reset tool — deliberately outside the normal
 * login/role system so it works with no session. Protected instead by a
 * long random token in the URL (shown below); anyone without that exact
 * token gets nothing. Wipes every product, sale, and purchase order —
 * unconditional, includes inactive products. Irreversible.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$token = getSetting('db_reset_token');
if (!$token) {
    $token = bin2hex(random_bytes(24));
    saveSetting('db_reset_token', $token);
}

$given = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals($token, $given)) {
    http_response_code(403);
    die('Forbidden — missing or invalid token.');
}

$db = getDB();
$message = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['confirm_text'] ?? '') !== 'WIPE ALL DATA') {
        $error = 'Type WIPE ALL DATA exactly to confirm.';
    } else {
        $db->beginTransaction();
        try {
            $salesCount    = (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn();
            $posCount      = (int)$db->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
            $productsCount = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();

            $db->exec('DELETE FROM sales');
            $db->exec('DELETE FROM purchase_orders');
            $db->exec('DELETE FROM products');

            $db->commit();
            try { logActivity('data_wiped', "Full wipe via database_reset.php: $productsCount product(s), $salesCount sale(s), $posCount purchase order(s)"); } catch (Throwable) {}
            $message = "Wiped: $productsCount product(s), $salesCount sale(s), $posCount purchase order(s) deleted.";
        } catch (Throwable $e) {
            $db->rollBack();
            $error = 'Wipe failed and was rolled back: ' . $e->getMessage();
        }
    }
}

$productsNow = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$salesNow    = (int)$db->query('SELECT COUNT(*) FROM sales')->fetchColumn();
$posNow      = (int)$db->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Reset</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-8">
  <h1 class="text-xl font-bold text-red-600 mb-1"><i class="fas fa-skull-crossbones"></i> Database Reset</h1>
  <p class="text-sm text-gray-500 mb-6">No login required — protected only by this page's secret token.</p>

  <?php if ($message): ?>
  <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-5 text-sm text-green-800"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="bg-gray-50 rounded-lg p-4 mb-5 text-sm text-gray-600">
    <p>Products: <strong><?= $productsNow ?></strong></p>
    <p>Sales: <strong><?= $salesNow ?></strong></p>
    <p>Purchase Orders: <strong><?= $posNow ?></strong></p>
  </div>

  <form method="POST">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <label class="block text-sm font-medium text-gray-700 mb-1">Type <strong>WIPE ALL DATA</strong> to confirm</label>
    <input type="text" id="confirmText" name="confirm_text" autocomplete="off"
           class="w-full border rounded-lg px-3 py-2 text-sm mb-4"
           oninput="document.getElementById('wipeBtn').disabled = (this.value !== 'WIPE ALL DATA')">
    <button type="submit" id="wipeBtn" disabled
            class="w-full bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-lg transition-colors">
      <i class="fas fa-skull-crossbones"></i> Wipe All Products, Sales &amp; Purchases
    </button>
  </form>

  <p class="text-xs text-gray-400 mt-5">Bookmark this exact URL (with its token) — regenerating the token elsewhere will invalidate it.</p>
</div>
</body>
</html>
