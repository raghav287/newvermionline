<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$id = (int) ($_GET['id'] ?? 0);
$c = $id ? row('SELECT * FROM customers WHERE id = ?', [$id]) : null;
if (!$c) {
    flash('That customer does not exist.', 'err');
    redirect(admin_url('customers.php'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = [];
    foreach (['name' => 120, 'address_line1' => 255, 'address_line2' => 255, 'city' => 80, 'pincode' => 12] as $k => $max) {
        $f[$k] = trim(str_cut((string) ($_POST[$k] ?? ''), $max));
    }
    $f['phone'] = trim(str_cut((string) ($_POST['phone'] ?? ''), 20));
    $f['state'] = trim(str_cut((string) ($_POST['state'] ?? ''), 80));
    $f['notes'] = trim(str_cut((string) ($_POST['notes'] ?? ''), 2000));
    $pass = (string) ($_POST['new_password'] ?? '');
    if ($pass !== '' && strlen($pass) < 8) {
        flash('A new password needs at least 8 characters. Nothing was changed.', 'err');
    } else {
        if ($pass !== '') {
            $f['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        update_row('customers', $f, 'id = ?', [$id]);
        flash('Customer saved.' . ($pass !== '' ? ' The new password is set; share it with the customer privately.' : ''));
    }
    redirect(admin_url('customer.php?id=' . $id));
}
$orders = rows('SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC', [$id]);
$txns = rows('SELECT t.*, o.order_number FROM payu_transactions t LEFT JOIN orders o ON o.id = t.order_id WHERE t.customer_id = ? OR (t.customer_id IS NULL AND t.email = ?) ORDER BY t.added_on DESC LIMIT 50', [$id, $c['email']]);

admin_header($c['name'] !== '' ? $c['name'] : $c['email'], 'customers');
?>
<p class="crumbs"><a href="<?= e(admin_url('customers.php')) ?>">Customers</a></p>
<div class="two two-wide">
  <div>
    <section class="panel">
      <h2>Orders</h2>
      <?php if (!$orders): ?><p class="muted">No orders.</p><?php else: ?>
      <div class="tw"><table class="tbl"><thead><tr><th>Order</th><th>Placed</th><th class="num">Total</th><th>Payment</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr><td><a href="<?= e(admin_url('order.php?id=' . (int) $o['id'])) ?>"><?= e($o['order_number']) ?></a><br><?= source_badge($o['source']) ?></td><td><?= e(fmt_date($o['placed_at'])) ?></td>
          <td class="num"><?= money($o['total']) ?></td><td><?= pay_badge($o['payment_status']) ?></td><td><?= order_badge($o['order_status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
    <section class="panel">
      <h2>PayU transactions</h2>
      <?php if (!$txns): ?><p class="muted">No PayU transactions.</p><?php else: ?>
      <div class="tw"><table class="tbl"><thead><tr><th>Date</th><th>Transaction ID</th><th class="num">Amount</th><th>Result</th><th>Order</th></tr></thead><tbody>
        <?php foreach ($txns as $t): ?><tr><td><?= e(fmt_date($t['added_on'])) ?></td><td><a class="mono" href="<?= e(admin_url('payment.php?id=' . (int) $t['id'])) ?>"><?= e($t['txnid']) ?></a></td>
          <td class="num"><?= money($t['amount']) ?></td><td><?= result_badge($t['result']) ?></td><td><?= $t['order_number'] ? e($t['order_number']) : '<span class="muted">No order</span>' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
  <section class="panel">
    <h2>Details</h2>
    <p class="muted"><?= e($c['email']) ?><br>Came from: <?= e(['website' => 'website', 'payu_import' => 'a PayU import', 'admin' => 'admin'][$c['source']] ?? $c['source']) ?>, <?= e(fmt_date($c['created_at'], false)) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <label class="fld"><span>Name</span><input name="name" value="<?= e($c['name']) ?>"></label>
      <label class="fld"><span>Phone</span><input name="phone" value="<?= e($c['phone']) ?>"></label>
      <label class="fld"><span>Address line 1</span><input name="address_line1" value="<?= e($c['address_line1']) ?>"></label>
      <label class="fld"><span>Address line 2</span><input name="address_line2" value="<?= e($c['address_line2']) ?>"></label>
      <label class="fld"><span>City</span><input name="city" value="<?= e($c['city']) ?>"></label>
      <label class="fld"><span>State</span><input name="state" value="<?= e($c['state']) ?>"></label>
      <label class="fld"><span>PIN code</span><input name="pincode" value="<?= e($c['pincode']) ?>"></label>
      <label class="fld"><span>Notes (only admins see these)</span><textarea name="notes" rows="3"><?= e($c['notes']) ?></textarea></label>
      <label class="fld"><span>Set a new password (optional)</span><input type="password" name="new_password" autocomplete="new-password" minlength="8"></label>
      <button class="btn" type="submit">Save customer</button>
    </form>
  </section>
</div>
<?php admin_footer();
