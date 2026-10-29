<?php
/**
 * PayU hosted checkout.
 *   GET  payu.php?order=ID&t=SIG        payment page for an unpaid order ("Pay now")
 *   GET  payu.php?order=ID&t=SIG&go=1   records a new payment attempt and sends the browser to PayU
 *   POST from PayU (success and failure URL)  checks PayU's hash, then updates the attempt and the order
 * The PayU return does not start a session: PayU posts from its own site, and starting a session there
 * would replace the visitor's session cookie.
 */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['txnid'], $_POST['hash'], $_POST['status'])) {
    $p = $_POST;
    $cfg = payu_config();
    $txnid = (string) $p['txnid'];
    $t = row("SELECT * FROM payu_transactions WHERE txnid = ? AND source = 'website'", [$txnid]);
    $valid = $cfg['salt'] !== '' && (string) ($p['key'] ?? '') === $cfg['key']
        && hash_equals(payu_response_hash($p, $cfg['salt']), strtolower((string) $p['hash']));
    $o = $t ? row('SELECT * FROM orders WHERE id = ?', [(int) $t['order_id']]) : null;
    if (!$t || !$valid || !$o) {
        error_log('PayU return rejected for txnid ' . substr($txnid, 0, 60) . ($t ? ' (hash or key mismatch)' : ' (unknown txnid)'));
        redirect(url('order-status?error=verify'));
    }
    $status = (string) $p['status'];
    $detail = (string) ($p['unmappedstatus'] ?? '');
    $result = payu_result($status) ?: (payu_result($detail) ?: 'pending');
    if ($t['result'] !== 'success' || $result === 'success') {
        $set = ['status' => str_cut($status, 40), 'status_detail' => str_cut($detail, 40), 'result' => $result,
            'mode' => str_cut((string) ($p['mode'] ?? ''), 30), 'bank_name' => str_cut((string) ($p['issuing_bank'] ?? ($p['bankcode'] ?? '')), 100),
            'bank_ref_no' => str_cut((string) ($p['bank_ref_num'] ?? ''), 100), 'card_type' => str_cut((string) ($p['card_type'] ?? ''), 40),
            'card_last4' => substr((string) preg_replace('/\D/', '', (string) ($p['cardnum'] ?? '')), -4), 'error_code' => str_cut((string) ($p['error'] ?? ''), 20),
            'error_message' => str_cut((string) ($p['error_Message'] ?? ''), 255), 'raw_data' => json_encode(array_diff_key($p, ['hash' => 1]), JSON_UNESCAPED_UNICODE)];
        if (num2($p['amount'] ?? 0) !== num2($t['amount'])) {
            // PayU reports the amount actually paid; storing it makes the order show as underpaid instead of paid.
            $set['amount'] = num2($p['amount'] ?? 0);
            $set['error_message'] = str_cut('PayU amount differs from the order. ' . $set['error_message'], 255);
        }
        if ($result === 'success') {
            $set['success_at'] = now();
        }
        $pid = (string) preg_replace('/\D/', '', (string) ($p['mihpayid'] ?? ''));
        if ($pid !== '' && !val('SELECT 1 FROM payu_transactions WHERE mihpayid = ? AND id <> ?', [$pid, (int) $t['id']])) {
            $set['mihpayid'] = $pid;
        }
        update_row('payu_transactions', $set, 'id = ?', [(int) $t['id']]);
        if (order_recalc((int) $o['id'])) {
            order_email_paid((int) $o['id']);
        }
    }
    redirect(url(order_view_link($o['order_number'])));
}

store_boot();
$id = (int) ($_GET['order'] ?? 0);
$o = $id ? row("SELECT * FROM orders WHERE id = ? AND source = 'website'", [$id]) : null;
if (!$o || !sign_ok('pay|' . $o['id'] . '|' . $o['order_number'], (string) ($_GET['t'] ?? ''))) {
    require __DIR__ . '/404.php';
    exit;
}
if (!in_array($o['payment_status'], ['pending', 'failed'], true) || $o['order_status'] === 'cancelled') {
    redirect(url(order_view_link($o['order_number'])));
}
if (!payu_ready()) {
    page_start('Payment', ['noindex' => true]);
    echo '<section class="section"><div class="wrap"><div class="status-card is-wait"><div class="status-icon">' . icon('hourglass') . '</div><h1>Online payment is not available right now</h1><p>Your order ' . e($o['order_number'])
        . ' is saved. Please contact us to complete the payment.</p></div></div></section>';
    page_end();
    exit;
}

if (!empty($_GET['go'])) {
    $cfg = payu_config();
    $txnid = 'W' . $o['id'] . 'T' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
    $first = trim((string) preg_replace('/[^A-Za-z ]/', '', explode(' ', trim($o['customer_name']))[0]));
    $f = ['key' => $cfg['key'], 'txnid' => $txnid, 'amount' => num2($o['total']), 'productinfo' => 'Order ' . $o['order_number'],
        'firstname' => $first !== '' ? substr($first, 0, 60) : 'Customer', 'email' => $o['email'], 'phone' => $o['phone'],
        'surl' => abs_url('payu.php'), 'furl' => abs_url('payu.php'), 'udf1' => $o['order_number'], 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => ''];
    $f['hash'] = payu_request_hash($f, $cfg['salt']);
    insert_row('payu_transactions', ['source' => 'website', 'txnid' => $txnid, 'order_id' => (int) $o['id'], 'customer_id' => $o['customer_id'],
        'status' => 'initiated', 'result' => 'pending', 'amount' => num2($o['total']), 'productinfo' => $f['productinfo'], 'firstname' => $f['firstname'],
        'email' => $o['email'], 'phone' => $o['phone'], 'udf1' => $o['order_number'], 'added_on' => now(), 'created_at' => now()]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $back = url(order_pay_link($o));
    ?><!doctype html>
<html lang="en-IN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Connecting to PayU</title>
<style>body{margin:0;display:grid;place-items:center;min-height:100vh;font:17px/1.6 system-ui,sans-serif;color:#2a2018;background:#f4f5f1;text-align:center;padding:20px}button{font:inherit;padding:10px 20px;border:0;border-radius:6px;background:#2f6a3b;color:#fff;cursor:pointer}</style>
</head><body>
<form method="post" action="<?= e(payu_endpoint()) ?>" id="payu">
  <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
  <p><strong>Taking you to PayU to pay <?= money($o['total']) ?> for order <?= e($o['order_number']) ?>.</strong><br>Please do not refresh or close this page.</p>
  <button type="submit">Continue to payment</button>
</form>
<script>
history.replaceState(null, '', <?= json_encode($back) ?>);
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
document.getElementById('payu').submit();
</script>
</body></html>
<?php
    exit;
}

page_start('Pay for order ' . $o['order_number'], ['noindex' => true]);
?>
<section class="section"><div class="wrap">
  <div class="status-card is-wait">
    <div class="status-icon"><?= icon('lock') ?></div>
    <h1>Pay for order <?= e($o['order_number']) ?></h1>
    <p><?= $o['payment_status'] === 'failed' ? 'Your last payment attempt did not go through. No money was taken for it.' : 'Your order is saved and waiting for payment.' ?> Pay <?= money($o['total']) ?> securely by UPI, card or net banking.</p>
    <div class="status-actions">
      <a class="btn" href="<?= e(url(order_pay_link($o, true))) ?>">Pay <?= money($o['total']) ?> with PayU</a>
      <a class="btn btn-ghost" href="<?= e(url(order_view_link($o['order_number']))) ?>">View order</a>
    </div>
  </div>
</div></section>
<?php page_end();
