<?php
require_once __DIR__ . '/../includes/admin.php';
$admin = admin_require();

$id = (int) ($_GET['id'] ?? 0);
$o = $id ? row('SELECT * FROM orders WHERE id = ?', [$id]) : null;
if (!$o) {
    flash('That order does not exist.', 'err');
    redirect(admin_url('orders.php'));
}
$self = admin_url('order.php?id=' . $id);
$paid = (float) val("SELECT COALESCE(SUM(amount), 0) FROM payu_transactions WHERE order_id = ? AND result IN ('success', 'refunded')", [$id]);
$refunded = (float) val('SELECT COALESCE(SUM(amount), 0) FROM payu_refunds WHERE order_id = ?', [$id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'status') {
        $st = (string) ($_POST['order_status'] ?? '');
        if (isset(ORDER_STATUSES[$st])) {
            update_row('orders', ['order_status' => $st, 'admin_note' => trim(str_cut((string) ($_POST['admin_note'] ?? ''), 2000))], 'id = ?', [$id]);
            flash('Order updated.');
        }
    } elseif ($do === 'refund') {
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $txn = (int) ($_POST['transaction_id'] ?? 0);
        $date = valid_ymd($_POST['refunded_on'] ?? '') ? $_POST['refunded_on'] : date('Y-m-d');
        if ($amount <= 0) {
            flash('Enter the refunded amount.', 'err');
        } elseif ($amount > $paid - $refunded + 0.009) {
            flash('The refund is more than the amount still paid on this order (' . money($paid - $refunded) . ').', 'err');
        } else {
            insert_row('payu_refunds', ['order_id' => $id, 'transaction_id' => $txn ?: null, 'amount' => num2($amount),
                'refund_ref' => trim(str_cut((string) ($_POST['refund_ref'] ?? ''), 100)), 'refunded_on' => $date,
                'note' => trim(str_cut((string) ($_POST['note'] ?? ''), 255)), 'source' => 'admin', 'created_by' => (int) $admin['id'], 'created_at' => now()]);
            order_recalc($id);
            flash('Refund of ' . money($amount) . ' recorded.');
        }
    } elseif ($do === 'refund_delete') {
        q('DELETE FROM payu_refunds WHERE id = ? AND order_id = ?', [(int) ($_POST['refund_id'] ?? 0), $id]);
        order_recalc($id);
        flash('Refund record removed.');
    } elseif ($do === 'link_item') {
        $item = row('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [(int) ($_POST['item_id'] ?? 0), $id]);
        $prod = row('SELECT * FROM products WHERE id = ?', [(int) ($_POST['product_id'] ?? 0)]);
        if ($item && $prod) {
            $qty = trim((string) ($_POST['quantity'] ?? ''));
            update_row('order_items', ['product_id' => (int) $prod['id'], 'sku' => $prod['sku'], 'product_name' => $prod['name'], 'size_label' => $prod['size_label'],
                'unit' => $prod['kind'] === 'loose' ? 'kg' : 'pc', 'quantity' => ctype_digit($qty) && (int) $qty > 0 ? (int) $qty : $item['quantity'],
                'match_status' => 'manual'], 'id = ?', [(int) $item['id']]);
            flash('Item linked to ' . $prod['name'] . '.');
        }
    } elseif ($do === 'recheck') {
        order_recalc($id);
        flash('Payment status worked out again from the PayU transactions.');
    }
    redirect($self);
}

$items = order_items($id);
$txns = rows('SELECT * FROM payu_transactions WHERE order_id = ? ORDER BY added_on, id', [$id]);
$refunds = rows('SELECT r.*, a.name AS admin_name FROM payu_refunds r LEFT JOIN admins a ON a.id = r.created_by WHERE r.order_id = ? ORDER BY r.refunded_on, r.id', [$id]);
$customer = $o['customer_id'] ? row('SELECT * FROM customers WHERE id = ?', [(int) $o['customer_id']]) : null;
$products = rows('SELECT id, name, sku, size_label FROM products ORDER BY name');
$imported = $o['source'] === 'payu_import';
$address = trim(implode(', ', array_filter([$o['address_line1'], $o['address_line2'], $o['city'], $o['state'], $o['pincode'], $o['country']])));
$na = '<span class="muted">Not in PayU file</span>';

admin_header('Order ' . $o['order_number'], 'orders');
?>
<p class="crumbs"><a href="<?= e(admin_url('orders.php' . ($imported ? '?source=payu_import' : '?source=website'))) ?>">Orders</a></p>
<div class="head-badges"><?= source_badge($o['source']) ?> <?= pay_badge($o['payment_status']) ?> <?= order_badge($o['order_status']) ?></div>
<?php if ($imported): ?>
  <div class="note note-info"><strong>Historical order built from a PayU file<?= $o['import_batch_id'] ? ' (<a href="' . e(admin_url('import.php?batch=' . (int) $o['import_batch_id'])) . '">import #' . (int) $o['import_batch_id'] . '</a>)' : '' ?>.</strong>
    Only details present in the file are shown; anything else is marked "Not in PayU file".<?php if ($o['import_note']): ?><br><?= nl2br(e($o['import_note'])) ?><?php endif; ?></div>
<?php endif; ?>

<div class="two two-wide">
  <div>
    <section class="panel">
      <h2>Items</h2>
      <?php if (!$items): ?>
        <p class="muted">No products are recorded for this order<?= $imported ? ': the PayU file does not name them' : '' ?>.</p>
      <?php else: ?>
      <div class="tw"><table class="tbl">
        <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td>
              <?php if ($it['product_id']): ?><a href="<?= e(admin_url('product.php?id=' . (int) $it['product_id'])) ?>"><?= e($it['product_name']) ?></a><?php else: ?><?= e($it['product_name']) ?><?php endif; ?>
              <?= $it['size_label'] !== '' ? '<span class="muted">(' . e($it['size_label']) . ')</span>' : '' ?>
              <?= $it['sku'] !== '' ? '<br><span class="muted">' . e($it['sku']) . '</span>' : '' ?>
              <?php if ($it['source_text'] !== null && $it['source_text'] !== ''): ?><br><span class="muted">PayU text: "<?= e($it['source_text']) ?>"</span><?php endif; ?>
              <?php if ($it['match_status'] === 'unmatched'): ?>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?><input type="hidden" name="do" value="link_item"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                  <select name="product_id" required aria-label="Product"><option value="">Link to a product</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name'] . ' (' . $p['sku'] . ')') ?></option><?php endforeach; ?></select>
                  <input type="number" name="quantity" min="1" placeholder="Qty if known" aria-label="Quantity, if known">
                  <button class="btn btn-sm" type="submit">Link</button>
                </form>
              <?php endif; ?>
            </td>
            <td class="num"><?= $it['quantity'] !== null ? (int) $it['quantity'] . ($it['unit'] === 'kg' ? ' kg' : '') : $na ?></td>
            <td class="num"><?= $it['unit_price'] !== null ? money($it['unit_price']) . ($it['list_price'] !== null && (float) $it['list_price'] > (float) $it['unit_price'] ? '<br><del class="muted">' . money($it['list_price']) . '</del>' : '') : $na ?></td>
            <td class="num"><?= $it['line_total'] !== null ? money($it['line_total']) : $na ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <dl class="totals">
        <div><dt>Items</dt><dd><?= $o['subtotal'] !== null ? money((float) $o['subtotal'] + (float) $o['discount']) : $na ?></dd></div>
        <?php if ((float) $o['discount'] > 0): ?><div><dt><?= e($o['offer_note'] ?: 'Discount') ?></dt><dd>-<?= money($o['discount']) ?></dd></div><?php endif; ?>
        <div><dt>Shipping</dt><dd><?= $o['shipping_fee'] !== null ? money($o['shipping_fee']) : $na ?></dd></div>
        <div class="t-total"><dt>Order total</dt><dd><?= money($o['total']) ?></dd></div>
        <div><dt>Paid through PayU</dt><dd><?= money($paid) ?></dd></div>
        <?php if ($refunded > 0): ?><div><dt>Refunded</dt><dd>-<?= money($refunded) ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>PayU transactions</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="recheck"><button class="btn btn-ghost btn-sm" type="submit">Recalculate payment status</button></form></div>
      <?php if (!$txns): ?><p class="muted">No PayU attempts yet.</p><?php else: ?>
      <div class="tw"><table class="tbl">
        <thead><tr><th>Date</th><th>Transaction ID / PayU ID</th><th>PayU status</th><th class="num">Amount</th><th>Mode</th></tr></thead>
        <tbody><?php foreach ($txns as $t): ?>
          <tr><td><?= e(fmt_date($t['added_on'])) ?></td>
            <td><a href="<?= e(admin_url('payment.php?id=' . (int) $t['id'])) ?>" class="mono"><?= e($t['txnid']) ?></a><br><span class="muted mono"><?= e($t['mihpayid'] ?? '—') ?></span></td>
            <td><?= result_badge($t['result']) ?> <span class="muted"><?= e($t['status']) ?></span><?= $t['error_message'] !== '' && $t['result'] !== 'success' ? '<br><span class="muted">' . e($t['error_message']) . '</span>' : '' ?></td>
            <td class="num"><?= money($t['amount']) ?></td><td><?= e($t['mode'] ?: '—') ?><?= $t['upi_app'] !== '' && $t['upi_app'] !== 'unknown' ? ', ' . e($t['upi_app']) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Refunds</h2>
      <p class="muted">Make the refund in the PayU dashboard, then record it here with PayU's refund ID.</p>
      <?php if ($refunds): ?>
      <div class="tw"><table class="tbl"><thead><tr><th>Date</th><th class="num">Amount</th><th>PayU refund ID</th><th>Note</th><th></th></tr></thead><tbody>
        <?php foreach ($refunds as $r): ?><tr><td><?= e(fmt_date($r['refunded_on'], false)) ?></td><td class="num"><?= money($r['amount']) ?></td><td class="mono"><?= e($r['refund_ref'] ?: '—') ?></td>
          <td><?= e($r['note']) ?><br><span class="muted"><?= $r['source'] === 'import' ? 'From PayU import' : 'By ' . e($r['admin_name'] ?: 'admin') ?></span></td>
          <td><form method="post" data-confirm="Remove this refund record?"><?= csrf_field() ?><input type="hidden" name="do" value="refund_delete"><input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>"><button class="linkish" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <?php if ($paid - $refunded > 0.009): ?>
      <form method="post" class="grid-form">
        <?= csrf_field() ?><input type="hidden" name="do" value="refund">
        <label class="fld"><span>Amount (₹)</span><input type="number" name="amount" step="0.01" min="0.01" max="<?= num2($paid - $refunded) ?>" required></label>
        <label class="fld"><span>PayU refund ID</span><input name="refund_ref" maxlength="100"></label>
        <label class="fld"><span>Refund date</span><input type="date" name="refunded_on" value="<?= date('Y-m-d') ?>"></label>
        <label class="fld"><span>Payment</span><select name="transaction_id"><?php foreach ($txns as $t): if (!in_array($t['result'], ['success', 'refunded'], true)) continue; ?><option value="<?= (int) $t['id'] ?>"><?= e($t['txnid']) ?></option><?php endforeach; ?></select></label>
        <label class="fld fld-wide"><span>Note</span><input name="note" maxlength="255"></label>
        <div><button class="btn" type="submit">Record refund</button></div>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <div>
    <section class="panel">
      <h2>Customer</h2>
      <p><strong><?= e($o['customer_name'] !== '' ? $o['customer_name'] : '—') ?></strong><br><?= e($o['email']) ?><br><?= $o['phone'] !== '' ? e($o['phone']) : ($imported ? $na : '—') ?></p>
      <?php if ($customer): ?><p><a href="<?= e(admin_url('customer.php?id=' . (int) $customer['id'])) ?>">Customer record</a></p><?php endif; ?>
      <h3>Delivery address</h3>
      <p><?= $address !== '' ? e($address) : ($imported ? $na : '—') ?></p>
      <?php if ($o['customer_note']): ?><h3>Customer note</h3><p><?= nl2br(e($o['customer_note'])) ?></p><?php endif; ?>
    </section>
    <section class="panel">
      <h2>Fulfilment</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="status">
        <label class="fld"><span>Order status</span><select name="order_status"><?php foreach (ORDER_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $o['order_status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Admin note (only you see this)</span><textarea name="admin_note" rows="3"><?= e($o['admin_note']) ?></textarea></label>
        <button class="btn" type="submit">Save</button>
      </form>
    </section>
    <section class="panel">
      <h2>Details</h2>
      <dl class="meta">
        <div><dt>Placed</dt><dd><?= e(fmt_date($o['placed_at'])) ?></dd></div>
        <div><dt>Paid</dt><dd><?= e(fmt_date($o['paid_at'])) ?></dd></div>
        <?php if ($o['external_ref']): ?><div><dt>Order ref in PayU file</dt><dd class="mono"><?= e($o['external_ref']) ?></dd></div><?php endif; ?>
        <div><dt>Recorded</dt><dd><?= e(fmt_date($o['created_at'])) ?></dd></div>
      </dl>
    </section>
  </div>
</div>
<?php admin_footer();
