<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$per = 30;
list($where, $params) = txn_filter($_GET);
$total = (int) val('SELECT COUNT(*) FROM payu_transactions t WHERE ' . $where, $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / $per))));
$list = rows('SELECT t.*, o.order_number FROM payu_transactions t LEFT JOIN orders o ON o.id = t.order_id WHERE ' . $where
    . ' ORDER BY t.added_on DESC, t.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$sums = row('SELECT COALESCE(SUM(CASE WHEN t.result = \'success\' THEN t.amount END), 0) AS ok FROM payu_transactions t WHERE ' . $where, $params);

admin_header('PayU payments', 'payments');
?>
<p class="muted">Every PayU payment attempt, from website checkout and from imported PayU files. Transaction IDs and PayU IDs are kept exactly as PayU sent them.</p>
<form class="filters" method="get">
  <label class="fld"><span>Search</span><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="txnid, PayU ID, bank ref, UTR, email, phone"></label>
  <label class="fld"><span>Result</span><select name="result"><option value="">Any</option><?php foreach (TXN_RESULTS as $k => $v): ?><option value="<?= $k ?>"<?= ($_GET['result'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <label class="fld"><span>Source</span><select name="source"><option value="">Any</option><option value="website"<?= ($_GET['source'] ?? '') === 'website' ? ' selected' : '' ?>>Website checkout</option><option value="import"<?= ($_GET['source'] ?? '') === 'import' ? ' selected' : '' ?>>PayU import</option></select></label>
  <label class="fld"><span>Order</span><select name="linked"><option value="">Any</option><option value="no"<?= ($_GET['linked'] ?? '') === 'no' ? ' selected' : '' ?>>No order (attempts only)</option></select></label>
  <label class="fld"><span>From</span><input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>"></label>
  <label class="fld"><span>To</span><input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>"></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= e(admin_url('payments.php')) ?>">Reset</a></div>
</form>
<div class="bar">
  <p class="muted"><?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?>, <?= money($sums['ok']) ?> successful</p>
  <div class="bar-actions">
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'transactions', 'format' => 'csv', 'page' => null]))) ?>">Export CSV</a>
    <a class="btn btn-ghost" href="<?= e(admin_url('export.php' . qs(['type' => 'transactions', 'format' => 'xlsx', 'page' => null]))) ?>">Export Excel</a>
  </div>
</div>
<div class="tw"><table class="tbl">
  <thead><tr><th>Date</th><th>Transaction ID / PayU ID</th><th>Customer</th><th class="num">Amount</th><th>PayU status</th><th>Order</th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="6" class="muted">No PayU transactions match these filters.</td></tr><?php endif; ?>
  <?php foreach ($list as $t): ?>
    <tr>
      <td><?= e(fmt_date($t['added_on'])) ?><br><?= badge($t['source'] === 'website' ? 'Website' : 'Import', $t['source'] === 'website' ? 'web' : 'imp') ?></td>
      <td><a class="mono" href="<?= e(admin_url('payment.php?id=' . (int) $t['id'])) ?>"><?= e($t['txnid']) ?></a><br><span class="muted mono"><?= e($t['mihpayid'] ?? '—') ?></span></td>
      <td><?= e(trim($t['firstname'] . ' ' . $t['lastname']) ?: '—') ?><br><span class="muted"><?= e($t['email']) ?></span></td>
      <td class="num"><?= money($t['amount']) ?></td>
      <td><?= result_badge($t['result']) ?><br><span class="muted"><?= e($t['status']) ?><?= $t['mode'] !== '' ? ', ' . e($t['mode']) : '' ?></span></td>
      <td><?= $t['order_id'] ? '<a href="' . e(admin_url('order.php?id=' . (int) $t['order_id'])) . '">' . e($t['order_number']) . '</a>' : '<span class="muted">No order</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pager($total, $page, $per) ?>
<?php admin_footer();
