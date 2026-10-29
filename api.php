<?php
/** JSON endpoints for assets/js/site.js: read the cart, add, change, remove, set loose kilos. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
store_boot();

$action = (string) ($_GET['action'] ?? '');
if ($action === 'cart') {
    json_out(['ok' => true, 'cart' => cart_json()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Use POST.'], 405);
}
if (!csrf_ok()) {
    json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 419);
}
switch ($action) {
    case 'add':
        $err = cart_add((int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 1));
        break;
    case 'set':
        $err = cart_set((string) ($_POST['key'] ?? ''), (int) ($_POST['qty'] ?? 0));
        break;
    case 'remove':
        $err = cart_set((string) ($_POST['key'] ?? ''), 0);
        break;
    case 'loose':
        $err = cart_set('loose', max(1, (int) ($_POST['kg'] ?? 0)));
        break;
    default:
        json_out(['ok' => false, 'message' => 'Unknown action.'], 404);
}
json_out(['ok' => $err === '', 'message' => $err, 'cart' => cart_json()], $err === '' ? 200 : 422);
