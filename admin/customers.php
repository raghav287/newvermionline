<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$per = 30;
list($where, $params) = customers_filter($_GET);
$total = (int) val('SELECT COUNT(*) FROM customers c WHERE ' . $where, $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / $per))));
$list = rows("SELECT c.*,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.source = 'website') AS web_orders,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.source = 'payu_import') AS imp_orders,
    (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status IN ('paid', 'partially_refunded')) AS paid_total
    FROM customers c WHERE " . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$sources = ['website' => 'Website', 'payu_import' => 'PayU import', 'admin' => 'Added by admin'];

admin_header('Customers', 'customers');
?>
<form class="filters" method="get">
  <label class="fld"><span>Search</span><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Name, email or phone"></label>
  <label class="fld"><span>Came from</span><select name="source"><option value="">Any</option><?php foreach ($sources as $k => $v): ?><option value="<?= $k ?>"<?= ($_GET['source'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= e(admin_url('customers.php')) ?>">Reset</a></div>
</form>
<div class="bar">
  <p class="muted"><?= number_format($total) ?> customer<?= $total === 1 ? '' : 's' ?></p>
  <div class="bar-actions">
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'customers', 'format' => 'csv', 'page' => null]))) ?>">Export CSV</a>
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'customers', 'format' => 'xlsx', 'page' => null]))) ?>">Export Excel</a>
  </div>
</div>
<div class="tw"><table class="tbl">
  <thead><tr><th>Customer</th><th>Phone</th><th>Came from</th><th class="num">Website orders</th><th class="num">Imported orders</th><th class="num">Paid</th><th>Account</th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="muted">No customers yet.</td></tr><?php endif; ?>
  <?php foreach ($list as $c): ?>
    <tr><td><a href="<?= e(admin_url('customer.php?id=' . (int) $c['id'])) ?>"><strong><?= e($c['name'] !== '' ? $c['name'] : '—') ?></strong></a><br><span class="muted"><?= e($c['email']) ?></span></td>
      <td><?= e($c['phone'] ?: '—') ?></td><td><?= e($sources[$c['source']] ?? $c['source']) ?></td>
      <td class="num"><?= (int) $c['web_orders'] ?></td><td class="num"><?= (int) $c['imp_orders'] ?></td><td class="num"><?= money($c['paid_total']) ?></td>
      <td><?= $c['password_hash'] ? 'Has password' : '<span class="muted">No password</span>' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pager($total, $page, $per) ?>
<?php admin_footer();
