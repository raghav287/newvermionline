<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$t = row('SELECT t.*, o.order_number FROM payu_transactions t LEFT JOIN orders o ON o.id = t.order_id WHERE t.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$t) {
    flash('That transaction does not exist.', 'err');
    redirect(admin_url('payments.php'));
}
$raw = json_decode((string) $t['raw_data'], true);
$fields = ['Transaction ID (txnid)' => $t['txnid'], 'PayU ID (mihpayid)' => $t['mihpayid'], 'PayU status' => $t['status'], 'Second status column' => $t['status_detail'],
    'Amount' => money($t['amount']), 'Payment mode' => $t['mode'], 'Bank' => $t['bank_name'], 'Bank reference' => $t['bank_ref_no'], 'UPI app' => $t['upi_app'],
    'Card' => trim($t['card_type'] . ($t['card_last4'] !== '' ? ' ending ' . $t['card_last4'] : '')), 'Error' => trim($t['error_code'] . ' ' . $t['error_message']),
    'Product info' => $t['productinfo'], 'Customer' => trim($t['firstname'] . ' ' . $t['lastname']), 'Email' => $t['email'], 'Phone' => $t['phone'],
    'Address' => trim(implode(', ', array_filter([$t['address_line1'], $t['address_line2'], $t['city'], $t['state'], $t['zipcode'], $t['country']]))),
    'Date' => fmt_date($t['added_on']), 'Success at' => fmt_date($t['success_at']), 'Settlement amount' => $t['settlement_amount'] !== null ? money($t['settlement_amount']) : '',
    'Settlement date' => $t['settlement_date'] ? fmt_date($t['settlement_date']) : '', 'Settlement UTR' => $t['utr'], 'udf1' => $t['udf1'], 'udf5' => $t['udf5']];

admin_header('PayU transaction', 'payments');
?>
<p class="crumbs"><a href="<?= e(admin_url('payments.php')) ?>">PayU payments</a></p>
<div class="head-badges"><?= result_badge($t['result']) ?> <?= badge($t['source'] === 'website' ? 'Website checkout' : 'PayU import #' . (int) $t['import_batch_id'], $t['source'] === 'website' ? 'web' : 'imp') ?>
  <?= $t['order_id'] ? '<a href="' . e(admin_url('order.php?id=' . (int) $t['order_id'])) . '">Order ' . e($t['order_number']) . '</a>' : '<span class="muted">Not part of an order: no payment in its group succeeded.</span>' ?></div>
<div class="two">
  <section class="panel">
    <h2>Summary</h2>
    <dl class="meta"><?php foreach ($fields as $k => $v): if ($v === null || $v === '' || $v === '—') continue; ?><div><dt><?= e($k) ?></dt><dd class="<?= in_array($k, ['Transaction ID (txnid)', 'PayU ID (mihpayid)', 'Bank reference', 'Settlement UTR', 'udf5'], true) ? 'mono' : '' ?>"><?= e($v) ?></dd></div><?php endforeach; ?></dl>
  </section>
  <section class="panel">
    <h2><?= $t['source'] === 'website' ? 'PayU response, as received' : 'Original row from the PayU file' ?></h2>
    <?php if (!is_array($raw) || !$raw): ?><p class="muted">Nothing received from PayU yet for this attempt.</p><?php else: ?>
    <div class="tw raw"><table class="tbl tbl-tight"><tbody><?php foreach ($raw as $k => $v): ?><tr><th><?= e($k) ?></th><td class="mono"><?= $v === '' ? '<span class="muted">empty</span>' : e(is_scalar($v) ? (string) $v : json_encode($v)) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
</div>
<?php admin_footer();
