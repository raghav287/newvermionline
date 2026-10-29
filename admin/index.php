<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$sum = function ($source) {
    return row("SELECT COUNT(*) AS n,
        COALESCE(SUM(payment_status IN ('paid', 'partially_refunded')), 0) AS paid_n,
        COALESCE(SUM(CASE WHEN payment_status IN ('paid', 'partially_refunded') THEN total END), 0) AS paid_total,
        COALESCE(SUM(payment_status = 'pending'), 0) AS pending_n
        FROM orders WHERE source = ?", [$source]);
};
$web = $sum('website');
$imp = $sum('payu_import');
$tx = row("SELECT COUNT(*) AS n, COALESCE(SUM(result = 'success'), 0) AS ok, COALESCE(SUM(result = 'failure'), 0) AS bad,
    COALESCE(SUM(result = 'pending'), 0) AS pend, COALESCE(SUM(order_id IS NULL AND result = 'failure'), 0) AS attempts FROM payu_transactions");
$refunds = (float) val('SELECT COALESCE(SUM(amount), 0) FROM payu_refunds');
$toShip = (int) val("SELECT COUNT(*) FROM orders WHERE source = 'website' AND payment_status = 'paid' AND order_status = 'processing'");
$unmatched = (int) val("SELECT COUNT(*) FROM order_items WHERE match_status = 'unmatched'");
$noItems = (int) val("SELECT COUNT(*) FROM orders o WHERE o.source = 'payu_import' AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)");
$unread = (int) val('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0');
$recent = rows('SELECT * FROM orders ORDER BY placed_at DESC, id DESC LIMIT 8');
$batches = rows('SELECT * FROM import_batches ORDER BY id DESC LIMIT 5');
$rate = (int) $tx['n'] ? round((int) $tx['ok'] / (int) $tx['n'] * 100) : 0;

admin_header('Dashboard', 'dashboard');
if (!payu_ready()) {
    echo '<p class="note note-warn">PayU keys are not set, so customers cannot pay at checkout yet. Add them in <a href="' . e(admin_url('settings.php#payu')) . '">Settings</a>.</p>';
}
?>
<section class="cards">
  <article class="card"><h2>Website orders</h2><p class="big"><?= (int) $web['n'] ?></p><p><?= (int) $web['paid_n'] ?> paid, <?= money($web['paid_total']) ?> received. <?= (int) $web['pending_n'] ?> awaiting payment.</p><a href="<?= e(admin_url('orders.php?source=website')) ?>">See website orders</a></article>
  <article class="card"><h2>Imported PayU orders</h2><p class="big"><?= (int) $imp['n'] ?></p><p><?= (int) $imp['paid_n'] ?> paid, <?= money($imp['paid_total']) ?> received.</p><a href="<?= e(admin_url('orders.php?source=payu_import')) ?>">See imported orders</a></article>
  <article class="card"><h2>PayU transactions</h2><p class="big"><?= (int) $tx['n'] ?></p><p><?= (int) $tx['ok'] ?> successful (<?= $rate ?>%), <?= (int) $tx['bad'] ?> failed, <?= (int) $tx['pend'] ?> pending.</p><a href="<?= e(admin_url('payments.php')) ?>">See all payments</a></article>
  <article class="card"><h2>Refunds recorded</h2><p class="big"><?= money($refunds) ?></p><p>Refunds are made in the PayU dashboard and recorded on the order.</p></article>
</section>

<section class="panel">
  <h2>Needs attention</h2>
  <ul class="todo">
    <li><a href="<?= e(admin_url('orders.php?source=website&payment=paid&status=processing')) ?>"><?= $toShip ?> paid website order<?= $toShip === 1 ? '' : 's' ?> to dispatch</a></li>
    <li><a href="<?= e(admin_url('messages.php')) ?>"><?= $unread ?> unread message<?= $unread === 1 ? '' : 's' ?></a></li>
    <li><a href="<?= e(admin_url('tools.php#checks')) ?>"><?= $unmatched ?> imported item<?= $unmatched === 1 ? '' : 's' ?> not matched to a product</a></li>
    <li><a href="<?= e(admin_url('orders.php?source=payu_import')) ?>"><?= $noItems ?> imported order<?= $noItems === 1 ? '' : 's' ?> without product details in the PayU file</a></li>
    <li><a href="<?= e(admin_url('payments.php?linked=no&result=failure')) ?>"><?= (int) $tx['attempts'] ?> failed payment attempt<?= (int) $tx['attempts'] === 1 ? '' : 's' ?> with no order</a></li>
  </ul>
</section>

<div class="two">
  <section class="panel">
    <h2>Latest orders</h2>
    <?php if (!$recent): ?><p class="muted">No orders yet.</p><?php else: ?>
    <div class="tw"><table class="tbl">
      <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Payment</th></tr></thead>
      <tbody><?php foreach ($recent as $o): ?><tr><td><a href="<?= e(admin_url('order.php?id=' . (int) $o['id'])) ?>"><?= e($o['order_number']) ?></a><br><span class="muted"><?= e(fmt_date($o['placed_at'])) ?></span></td>
        <td><?= e($o['customer_name'] ?: $o['email']) ?><br><?= source_badge($o['source']) ?></td><td><?= money($o['total']) ?></td><td><?= pay_badge($o['payment_status']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Latest PayU imports</h2>
    <?php if (!$batches): ?><p class="muted">No PayU file imported yet. <a href="<?= e(admin_url('import.php')) ?>">Import one</a>.</p><?php else: ?>
    <div class="tw"><table class="tbl">
      <thead><tr><th>File</th><th>Imported</th><th>Problems</th><th>Orders</th></tr></thead>
      <tbody><?php foreach ($batches as $b): ?><tr><td><a href="<?= e(admin_url('import.php?batch=' . (int) $b['id'])) ?>"><?= e($b['file_name']) ?></a><br><span class="muted">#<?= (int) $b['id'] ?>, <?= e(fmt_date($b['created_at'])) ?>, <?= e($b['status']) ?></span></td>
        <td><?= (int) $b['imported_rows'] ?></td><td><?= (int) $b['failed_rows'] ?></td><td><?= (int) $b['orders_created'] ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>
<?php admin_footer();
