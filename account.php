<?php
/** Customer account: website orders, profile and address, password. Orders imported from PayU are not shown here. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$me = current_customer();
if (!$me) {
    redirect(url('sign-in?next=account'));
}
$tab = in_array($_GET['tab'] ?? '', ['orders', 'profile', 'password'], true) ? $_GET['tab'] : 'orders';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if (!csrf_ok()) {
        $err = 'Your session expired. Please try again.';
    } elseif ($do === 'profile') {
        $f = [];
        foreach (['name' => 120, 'address_line1' => 255, 'address_line2' => 255, 'city' => 80, 'pincode' => 6] as $k => $max) {
            $f[$k] = trim(str_cut((string) ($_POST[$k] ?? ''), $max));
        }
        $f['phone'] = clean_phone((string) ($_POST['phone'] ?? ''));
        $f['state'] = in_array($_POST['state'] ?? '', INDIAN_STATES, true) ? $_POST['state'] : '';
        if (strlen($f['name']) < 2) {
            $err = 'Enter your name.';
        } elseif ($f['phone'] === '') {
            $err = 'Enter a valid 10-digit mobile number.';
        } elseif ($f['pincode'] !== '' && !preg_match('/^[1-9][0-9]{5}$/', $f['pincode'])) {
            $err = 'Enter a valid 6-digit PIN code.';
        } else {
            update_row('customers', $f, 'id = ?', [(int) $me['id']]);
            flash('Your details are saved.');
            redirect(url('account?tab=profile'));
        }
        $tab = 'profile';
    } elseif ($do === 'password') {
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), (string) $me['password_hash'])) {
            $err = 'Your current password is not correct.';
        } elseif (strlen($new) < 8) {
            $err = 'Use at least 8 characters for your new password.';
        } elseif ($new !== (string) ($_POST['new2'] ?? '')) {
            $err = 'The two new passwords do not match.';
        } else {
            update_row('customers', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $me['id']]);
            flash('Your password is changed.');
            redirect(url('account?tab=password'));
        }
        $tab = 'password';
    }
}
$orderNo = (string) ($_GET['order'] ?? '');
$order = $orderNo !== '' ? row("SELECT * FROM orders WHERE order_number = ? AND customer_id = ? AND source = 'website'", [$orderNo, (int) $me['id']]) : null;

page_start('My account', ['noindex' => true]);
page_head('My account', ['My account' => null], 'Signed in as ' . $me['email'] . '.');
?>
<section class="section">
  <div class="wrap account">
    <nav class="side-nav" aria-label="Account">
      <a href="<?= e(url('account')) ?>"<?= $tab === 'orders' ? ' aria-current="page"' : '' ?>>Orders</a>
      <a href="<?= e(url('account?tab=profile')) ?>"<?= $tab === 'profile' ? ' aria-current="page"' : '' ?>>Details and address</a>
      <a href="<?= e(url('account?tab=password')) ?>"<?= $tab === 'password' ? ' aria-current="page"' : '' ?>>Password</a>
      <form method="post" action="<?= e(url('sign-in')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="logout"><button class="link-btn side-out" type="submit">Sign out</button></form>
    </nav>
    <div>
      <?php if ($err): ?><p class="notice notice-err" role="alert"><?= e($err) ?></p><?php endif; ?>
      <?php if ($tab === 'orders' && $order): $items = order_items((int) $order['id']); ?>
        <p><a href="<?= e(url('account')) ?>">All orders</a></p>
        <h2>Order <?= e($order['order_number']) ?></h2>
        <dl class="kv">
          <div><dt>Placed on</dt><dd><?= e(fmt_date($order['placed_at'])) ?></dd></div>
          <div><dt>Payment</dt><dd><?= e(PAYMENT_STATUSES[$order['payment_status']] ?? $order['payment_status']) ?></dd></div>
          <div><dt>Status</dt><dd><?= e(ORDER_STATUSES[$order['order_status']] ?? $order['order_status']) ?></dd></div>
          <div><dt>Total</dt><dd><?= money($order['total']) ?></dd></div>
        </dl>
        <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Amount</th></tr></thead><tbody>
          <?php foreach ($items as $it): ?><tr><td><?= e($it['product_name']) ?></td><td><?= (int) $it['quantity'] ?><?= $it['unit'] === 'kg' ? ' kg' : '' ?></td><td><?= money($it['line_total']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="status-actions">
          <?php if (in_array($order['payment_status'], ['pending', 'failed'], true) && $order['order_status'] !== 'cancelled'): ?><a class="btn" href="<?= e(url(order_pay_link($order))) ?>">Pay <?= money($order['total']) ?> now</a><?php endif; ?>
          <a class="btn btn-ghost" href="<?= e(url(order_view_link($order['order_number']))) ?>">Order summary</a>
        </div>
      <?php elseif ($tab === 'orders'): $orders = rows("SELECT * FROM orders WHERE customer_id = ? AND source = 'website' ORDER BY placed_at DESC", [(int) $me['id']]); ?>
        <h2>Your orders</h2>
        <?php if (!$orders): ?>
          <div class="empty"><p class="muted">You have not placed any orders on this website yet.</p><a class="btn" href="<?= e(url('shop')) ?>">Start shopping</a></div>
        <?php else: ?>
          <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Payment</th><th scope="col">Status</th><th scope="col">Total</th></tr></thead><tbody>
            <?php foreach ($orders as $o): ?><tr><td><a href="<?= e(url('account?order=' . rawurlencode($o['order_number']))) ?>"><?= e($o['order_number']) ?></a></td><td><?= e(fmt_date($o['placed_at'], false)) ?></td>
              <td><?= e(PAYMENT_STATUSES[$o['payment_status']] ?? $o['payment_status']) ?></td><td><?= e(ORDER_STATUSES[$o['order_status']] ?? $o['order_status']) ?></td><td><?= money($o['total']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      <?php elseif ($tab === 'profile'): $f = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($me, $_POST) : $me; ?>
        <h2>Details and address</h2>
        <form method="post" class="panel">
          <?= csrf_field() ?><input type="hidden" name="do" value="profile">
          <div class="form-grid">
            <div class="field"><label for="p-name">Full name</label><input class="input" id="p-name" name="name" required value="<?= e($f['name']) ?>"></div>
            <div class="field"><label for="p-phone">Mobile number</label><input class="input" id="p-phone" name="phone" type="tel" required value="<?= e($f['phone']) ?>"></div>
            <div class="field full"><label for="p-a1">House, street and area</label><input class="input" id="p-a1" name="address_line1" value="<?= e($f['address_line1']) ?>"></div>
            <div class="field full"><label for="p-a2">Landmark</label><input class="input" id="p-a2" name="address_line2" value="<?= e($f['address_line2']) ?>"></div>
            <div class="field"><label for="p-city">City or town</label><input class="input" id="p-city" name="city" value="<?= e($f['city']) ?>"></div>
            <div class="field"><label for="p-state">State</label><select class="select" id="p-state" name="state"><option value="">Select state</option><?php foreach (INDIAN_STATES as $s): ?><option<?= $f['state'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="p-pin">PIN code</label><input class="input" id="p-pin" name="pincode" inputmode="numeric" maxlength="6" value="<?= e($f['pincode']) ?>"></div>
          </div>
          <p class="hint">Email: <?= e($me['email']) ?>. To change it, contact us.</p>
          <button class="btn" type="submit">Save details</button>
        </form>
      <?php else: ?>
        <h2>Change password</h2>
        <form method="post" class="panel narrow">
          <?= csrf_field() ?><input type="hidden" name="do" value="password">
          <div class="field"><label for="pw-c">Current password</label><input class="input" id="pw-c" name="current" type="password" required autocomplete="current-password"></div>
          <div class="field"><label for="pw-n">New password</label><input class="input" id="pw-n" name="new" type="password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label for="pw-n2">Repeat new password</label><input class="input" id="pw-n2" name="new2" type="password" required minlength="8" autocomplete="new-password"></div>
          <button class="btn" type="submit">Change password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_end();
