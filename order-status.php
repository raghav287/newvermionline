<?php
/** Order result page reached after PayU, from emails and from the account page (signed link). */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$num = (string) ($_GET['o'] ?? '');
$o = ($num !== '' && sign_ok('view|' . $num, (string) ($_GET['t'] ?? ''))) ? row("SELECT * FROM orders WHERE order_number = ? AND source = 'website'", [$num]) : null;
page_start($o ? 'Order ' . $o['order_number'] : 'Order status', ['noindex' => true]);
echo '<section class="section"><div class="wrap">';
if (!$o) {
    $verify = ($_GET['error'] ?? '') === 'verify';
    echo '<div class="status-card is-' . ($verify ? 'bad' : 'wait') . '"><div class="status-icon">' . icon($verify ? 'alert' : 'search') . '</div>'
        . '<h1>' . ($verify ? 'We could not confirm this payment' : 'Order not found') . '</h1><p>'
        . ($verify ? 'If money was deducted, it will be matched to your order or refunded. Send us your order number and we will check right away.'
            : 'Open the link from your order email, or look up the order with its number and your email.')
        . '</p><div class="status-actions"><a class="btn" href="' . e(url('track-order')) . '">Track an order</a><a class="btn btn-ghost" href="' . e(url('contact')) . '">Contact us</a></div></div>';
} else {
    $items = order_items((int) $o['id']);
    $ps = $o['payment_status'];
    if ($o['order_status'] === 'cancelled') {
        $tone = 'bad'; $title = 'This order was cancelled'; $msg = 'Any amount paid is refunded under our refund policy.';
    } elseif (in_array($ps, ['paid', 'refunded', 'partially_refunded'], true)) {
        $tone = 'ok'; $title = $ps === 'paid' ? 'Payment received' : 'Payment refunded';
        $msg = $ps === 'paid' ? 'Thank you. Your order is confirmed and will be dispatched within ' . e(setting('dispatch_time', '7-8 days')) . '.' : 'This payment has been refunded.';
    } elseif ($ps === 'failed') {
        $tone = 'bad'; $title = 'Payment did not go through'; $msg = 'No money was taken for this attempt. You can try again. If money was deducted, it is usually reversed within 5 to 7 working days.';
    } else {
        $tone = 'wait'; $title = 'Waiting for payment'; $msg = 'We have not received a payment confirmation from PayU yet. If you completed the payment, refresh this page in a minute.';
    }
    ?>
    <div class="status-card is-<?= $tone ?>">
      <div class="status-icon"><?= icon($tone === 'ok' ? 'check' : ($tone === 'bad' ? 'alert' : 'hourglass')) ?></div>
      <h1><?= e($title) ?></h1>
      <p><?= $msg ?></p>
      <dl class="kv">
        <div><dt>Order number</dt><dd><?= e($o['order_number']) ?></dd></div>
        <div><dt>Placed on</dt><dd><?= e(fmt_date($o['placed_at'])) ?></dd></div>
        <div><dt>Payment</dt><dd><?= e(PAYMENT_STATUSES[$ps] ?? $ps) ?> (PayU)</dd></div>
        <div><dt>Total</dt><dd><?= money($o['total']) ?></dd></div>
      </dl>
      <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?><tr><td><?= e($it['product_name']) ?><?= $it['size_label'] !== '' ? '<br><span class="hint">' . e($it['size_label']) . '</span>' : '' ?></td><td><?= (int) $it['quantity'] ?><?= $it['unit'] === 'kg' ? ' kg' : '' ?></td><td><?= money($it['line_total']) ?></td></tr><?php endforeach; ?>
          <?php if ((float) $o['discount'] > 0): ?><tr><td colspan="2"><?= e($o['offer_note'] ?: 'Discount') ?></td><td>-<?= money($o['discount']) ?></td></tr><?php endif; ?>
          <tr><td colspan="2">Shipping</td><td><?= (float) $o['shipping_fee'] > 0 ? money($o['shipping_fee']) : 'Free' ?></td></tr>
        </tbody>
      </table></div>
      <p style="margin-top:16px"><strong>Delivering to</strong><br><?= e($o['customer_name']) ?>, <?= e(trim($o['address_line1'] . ', ' . $o['address_line2'], ', ')) ?>, <?= e($o['city']) ?>, <?= e($o['state']) ?> <?= e($o['pincode']) ?></p>
      <div class="status-actions">
        <?php if (in_array($ps, ['pending', 'failed'], true) && $o['order_status'] !== 'cancelled'): ?><a class="btn" href="<?= e(url(order_pay_link($o))) ?>">Pay <?= money($o['total']) ?> now</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('track-order?order=' . rawurlencode($o['order_number']) . '&email=' . rawurlencode($o['email']))) ?>">Track this order</a>
        <a class="btn btn-ghost" href="<?= e(url('shop')) ?>">Continue shopping</a>
      </div>
    </div>
    <?php
}
echo '</div></section>';
page_end();
