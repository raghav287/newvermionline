<?php
/** Downloads as CSV or Excel: orders, PayU transactions, customers, and the row reports of an import. */
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/spreadsheet.php';
admin_require();

$type = (string) ($_GET['type'] ?? '');
$format = ($_GET['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv';
$day = date('Y-m-d');
$f = function ($v) { return $v === null || $v === '' ? null : (float) $v; };

if ($type === 'orders') {
    list($where, $params) = orders_filter($_GET);
    $list = rows("SELECT o.*,
        (SELECT GROUP_CONCAT(CONCAT(i.product_name, IF(i.size_label <> '', CONCAT(' (', i.size_label, ')'), ''), ' x ', COALESCE(i.quantity, '?'), IF(i.unit = 'kg', ' kg', '')) ORDER BY i.id SEPARATOR '; ') FROM order_items i WHERE i.order_id = o.id) AS items,
        (SELECT GROUP_CONCAT(t.txnid ORDER BY t.added_on SEPARATOR ' ') FROM payu_transactions t WHERE t.order_id = o.id) AS txnids,
        (SELECT GROUP_CONCAT(t.mihpayid ORDER BY t.added_on SEPARATOR ' ') FROM payu_transactions t WHERE t.order_id = o.id AND t.mihpayid IS NOT NULL) AS payu_ids,
        (SELECT COALESCE(SUM(r.amount), 0) FROM payu_refunds r WHERE r.order_id = o.id) AS refunded
        FROM orders o WHERE " . $where . ' ORDER BY o.placed_at DESC, o.id DESC LIMIT 50000', $params);
    $out = [];
    foreach ($list as $o) {
        $out[] = [$o['order_number'], $o['source'] === 'website' ? 'Website' : 'PayU import', $o['placed_at'], (string) $o['paid_at'], $o['customer_name'], $o['email'], $o['phone'],
            $o['address_line1'], $o['address_line2'], $o['city'], $o['state'], $o['pincode'], $o['country'], (string) $o['items'], $f($o['subtotal']), $f($o['discount']),
            $f($o['shipping_fee']), (float) $o['total'], (float) $o['refunded'], PAYMENT_STATUSES[$o['payment_status']] ?? $o['payment_status'],
            ORDER_STATUSES[$o['order_status']] ?? $o['order_status'], (string) $o['txnids'], (string) $o['payu_ids'], $o['import_batch_id'] ? '#' . $o['import_batch_id'] : '', (string) $o['import_note']];
    }
    sheet_send($format, 'orders-' . $day, ['Order number', 'Source', 'Placed at', 'Paid at', 'Customer', 'Email', 'Phone', 'Address line 1', 'Address line 2', 'City', 'State',
        'PIN code', 'Country', 'Items', 'Subtotal', 'Offer discount', 'Shipping', 'Order total', 'Refunded', 'Payment status', 'Order status', 'PayU transaction IDs',
        'PayU IDs', 'Import', 'Import note'], $out);
}

if ($type === 'transactions') {
    list($where, $params) = txn_filter($_GET);
    $list = rows('SELECT t.*, o.order_number FROM payu_transactions t LEFT JOIN orders o ON o.id = t.order_id WHERE ' . $where . ' ORDER BY t.added_on DESC, t.id DESC LIMIT 50000', $params);
    $out = [];
    foreach ($list as $t) {
        $out[] = [$t['added_on'], $t['source'] === 'website' ? 'Website' : 'PayU import', $t['txnid'], (string) $t['mihpayid'], $t['status'], $t['status_detail'],
            TXN_RESULTS[$t['result']] ?? $t['result'], (float) $t['amount'], $t['mode'], $t['bank_name'], $t['bank_ref_no'], $t['upi_app'], trim($t['firstname'] . ' ' . $t['lastname']),
            $t['email'], $t['phone'], $t['productinfo'], (string) $t['order_number'], (string) $t['success_at'], $f($t['settlement_amount']), (string) $t['settlement_date'],
            $t['utr'], trim($t['error_code'] . ' ' . $t['error_message']), $t['import_batch_id'] ? '#' . $t['import_batch_id'] : ''];
    }
    sheet_send($format, 'payu-transactions-' . $day, ['Date', 'Source', 'Transaction ID', 'PayU ID', 'PayU status', 'Second status', 'Result', 'Amount', 'Mode', 'Bank',
        'Bank reference', 'UPI app', 'Customer', 'Email', 'Phone', 'Product info', 'Order number', 'Success at', 'Settlement amount', 'Settlement date', 'UTR', 'Error', 'Import'], $out);
}

if ($type === 'customers') {
    list($where, $params) = customers_filter($_GET);
    $list = rows("SELECT c.*,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.source = 'website') AS web_orders,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.source = 'payu_import') AS imp_orders,
        (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status IN ('paid', 'partially_refunded')) AS paid_total
        FROM customers c WHERE " . $where . ' ORDER BY c.created_at DESC LIMIT 50000', $params);
    $out = [];
    foreach ($list as $c) {
        $out[] = [$c['name'], $c['email'], $c['phone'], ['website' => 'Website', 'payu_import' => 'PayU import', 'admin' => 'Admin'][$c['source']] ?? $c['source'],
            $c['address_line1'], $c['address_line2'], $c['city'], $c['state'], $c['pincode'], (int) $c['web_orders'], (int) $c['imp_orders'], (float) $c['paid_total'],
            $c['password_hash'] ? 'Yes' : 'No', $c['created_at']];
    }
    sheet_send($format, 'customers-' . $day, ['Name', 'Email', 'Phone', 'Came from', 'Address line 1', 'Address line 2', 'City', 'State', 'PIN code', 'Website orders',
        'Imported orders', 'Paid total', 'Has password', 'Created'], $out);
}

if (in_array($type, ['import_problems', 'import_duplicates', 'import_report'], true)) {
    $b = row('SELECT * FROM import_batches WHERE id = ?', [(int) ($_GET['batch'] ?? 0)]);
    if (!$b) {
        flash('That import does not exist.', 'err');
        redirect(admin_url('import.php'));
    }
    if ($type === 'import_report') {
        $out = [];
        foreach (rows('SELECT r.*, o.order_number FROM import_rows r LEFT JOIN orders o ON o.id = r.order_id WHERE r.batch_id = ? ORDER BY r.line_no', [(int) $b['id']]) as $r) {
            $out[] = [(int) $r['line_no'], $r['txnid'], $r['outcome'], $r['message'], (string) $r['order_number']];
        }
        sheet_send($format, 'payu-import-' . $b['id'] . '-report', ['Row', 'Transaction ID', 'Outcome', 'Details', 'Order number'], $out);
    }
    $headers = (array) json_decode((string) $b['headers'], true);
    $outcome = $type === 'import_problems' ? 'failed' : 'duplicate';
    $out = [];
    foreach (rows('SELECT * FROM import_rows WHERE batch_id = ? AND outcome = ? ORDER BY line_no', [(int) $b['id'], $outcome]) as $r) {
        $cells = array_map('strval', (array) json_decode((string) $r['raw_data'], true));
        $cells = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
        $out[] = array_merge($cells, [(int) $r['line_no'], (string) $r['message']]);
    }
    sheet_send($format, 'payu-import-' . $b['id'] . ($outcome === 'failed' ? '-problems' : '-already-recorded'), array_merge($headers, ['Import row', 'Problem']), $out);
}

flash('Unknown export.', 'err');
redirect(admin_url());
