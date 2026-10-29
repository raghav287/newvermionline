<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$per = 25;
list($where, $params) = orders_filter($_GET);
$total = (int) val('SELECT COUNT(*) FROM orders o WHERE ' . $where, $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / $per))));
$list = rows('SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count,
    (SELECT COUNT(*) FROM payu_transactions t WHERE t.order_id = o.id) AS txn_count
    FROM orders o WHERE ' . $where . ' ORDER BY o.placed_at DESC, o.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$src = (string) ($_GET['source'] ?? '');

admin_header('Orders', 'orders');
?>
<nav class="tabs">
  <a href="<?= e(qs(['source' => null, 'page' => null])) ?>"<?= $src === '' ? ' aria-current="page"' : '' ?>>All orders</a>
  <a href="<?= e(qs(['source' => 'website', 'page' => null])) ?>"<?= $src === 'website' ? ' aria-current="page"' : '' ?>>Website orders</a>
  <a href="<?= e(qs(['source' => 'payu_import', 'page' => null])) ?>"<?= $src === 'payu_import' ? ' aria-current="page"' : '' ?>>Imported from PayU</a>
</nav>
<form class="filters" method="get">
  <?php if ($src !== ''): ?><input type="hidden" name="source" value="<?= e($src) ?>"><?php endif; ?>
  <label class="fld"><span>Search</span><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Order no., name, email, phone, txnid, PayU ID"></label>
  <label class="fld"><span>Payment</span><select name="payment"><option value="">Any</option><?php foreach (PAYMENT_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= ($_GET['payment'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <label class="fld"><span>Status</span><select name="status"><option value="">Any</option><?php foreach (ORDER_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= ($_GET['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <label class="fld"><span>From</span><input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>"></label>
  <label class="fld"><span>To</span><input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>"></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= e(admin_url('orders.php')) ?>">Reset</a></div>
</form>
<div class="bar">
  <p class="muted"><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?></p>
  <div class="bar-actions">
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'orders', 'format' => 'csv', 'page' => null]))) ?>">Export CSV</a>
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'orders', 'format' => 'xlsx', 'page' => null]))) ?>">Export Excel</a>
  </div>
</div>
<div class="tw"><table class="tbl">
  <thead><tr><th>Order</th><th>Placed</th><th>Customer</th><th>Items</th><th class="num">Total</th><th>Payment</th><th>Status</th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="muted">No orders match these filters.</td></tr><?php endif; ?>
  <?php foreach ($list as $o): ?>
    <tr>
      <td><a href="<?= e(admin_url('order.php?id=' . (int) $o['id'])) ?>"><strong><?= e($o['order_number']) ?></strong></a><br><?= source_badge($o['source']) ?></td>
      <td><?= e(fmt_date($o['placed_at'])) ?></td>
      <td><?= e($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?><br><span class="muted"><?= e($o['email']) ?></span></td>
      <td><?= (int) $o['item_count'] ? (int) $o['item_count'] . ' item' . ((int) $o['item_count'] === 1 ? '' : 's') : '<span class="muted">Not in PayU file</span>' ?><br><span class="muted"><?= (int) $o['txn_count'] ?> PayU attempt<?= (int) $o['txn_count'] === 1 ? '' : 's' ?></span></td>
      <td class="num"><?= money($o['total']) ?></td>
      <td><?= pay_badge($o['payment_status']) ?></td>
      <td><?= order_badge($o['order_status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pager($total, $page, $per) ?>
<?php admin_footer();
