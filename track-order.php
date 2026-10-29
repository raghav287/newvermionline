<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$num = trim(str_cut((string) ($_GET['order'] ?? ''), 40));
$email = strtolower(trim(str_cut((string) ($_GET['email'] ?? ''), 190)));
$o = null;
$error = '';
if ($num !== '' || $email !== '') {
    if (throttle_blocked('track', '', 20, 20)) {
        $error = 'Too many lookups. Please wait a few minutes and try again.';
    } else {
        throttle_hit('track');
        $o = row("SELECT * FROM orders WHERE order_number = ? AND email = ? AND source = 'website'", [$num, $email]);
        if (!$o) {
            $error = 'We could not find an order with that number and email. Check both and try again.';
        }
    }
}
$steps = ['pending' => 'Order placed', 'processing' => 'Packed', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
page_start('Track your order', ['active' => 'track']);
page_head('Track your order', ['Track order' => null], 'Enter the order number from your confirmation and the email you ordered with.');
?>
<section class="section">
  <div class="wrap narrow">
    <form method="get" action="<?= e(url('track-order')) ?>" class="panel">
      <div class="field"><label for="order">Order number</label><input class="input" id="order" name="order" required placeholder="ORD-261029-7K3Q" value="<?= e($num) ?>"></div>
      <div class="field"><label for="temail">Email</label><input class="input" id="temail" name="email" type="email" required value="<?= e($email) ?>"></div>
      <button class="btn" type="submit">Track order</button>
    </form>
    <?php if ($error): ?><p class="notice notice-err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($o):
        $reached = array_search($o['order_status'], array_keys($steps), true);
        if ($o['payment_status'] !== 'paid' && $o['order_status'] === 'pending') {
            $reached = false;
        }
    ?>
      <div class="panel">
        <h2>Order <?= e($o['order_number']) ?></h2>
        <?php if ($o['order_status'] === 'cancelled'): ?>
          <p class="notice notice-err">This order was cancelled.</p>
        <?php elseif (in_array($o['payment_status'], ['pending', 'failed'], true)): ?>
          <p class="notice notice-info">This order is waiting for payment. <a href="<?= e(url(order_pay_link($o))) ?>">Pay <?= money($o['total']) ?> now</a>.</p>
        <?php else: ?>
          <ol class="timeline"><?php $i = 0; foreach ($steps as $label): ?><li class="<?= $reached !== false && $i <= $reached ? 'done' : '' ?>"><?= e($label) ?></li><?php $i++; endforeach; ?></ol>
        <?php endif; ?>
        <dl class="kv">
          <div><dt>Placed on</dt><dd><?= e(fmt_date($o['placed_at'], false)) ?></dd></div>
          <div><dt>Payment</dt><dd><?= e(PAYMENT_STATUSES[$o['payment_status']] ?? $o['payment_status']) ?></dd></div>
        </dl>
        <div class="table-wrap"><table class="table"><tbody>
          <?php foreach (order_items((int) $o['id']) as $it): ?><tr><td><?= e($it['product_name']) ?></td><td><?= (int) $it['quantity'] ?><?= $it['unit'] === 'kg' ? ' kg' : '' ?></td><td><?= money($it['line_total']) ?></td></tr><?php endforeach; ?>
          <tr><th scope="row" colspan="2">Total</th><td><strong><?= money($o['total']) ?></strong></td></tr>
        </tbody></table></div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php page_end();
