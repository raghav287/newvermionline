<?php
/**
 * PayU file import.
 *
 * Takes a PayU transaction export (.xlsx or .csv) exactly as downloaded from the PayU dashboard.
 *  1. Finds the heading row and maps the columns automatically (the admin can change the mapping).
 *  2. Checks every row; rows with problems are listed and never imported.
 *  3. Blocks duplicates: a transaction ID (txnid) or PayU ID (mihpayid) already in the file or the database is skipped.
 *  4. Groups payment attempts that belong to one purchase into one order:
 *       - rows with the same order reference (an "order number" column, or product info like "Order ORD-123");
 *       - otherwise rows with the same email and amount within the attempt window (Settings, default 60 minutes),
 *         where a group ends at its first successful payment.
 *  5. Creates an order only when a group has a successful, pending or refunded payment. Attempts that never
 *     succeeded are kept in the PayU ledger without an order.
 *  6. Never invents data: amounts, quantities and addresses that are not in the file stay empty.
 *
 * payu_import_process() runs as a preview (no database writes) or as the real import.
 */

/** Our fields: key => [label, required, header names PayU and other tools use]. */
function payu_fields(): array
{
    return [
        'txnid' => ['Transaction ID (txnid)', true, ['txnid', 'txn_id', 'transaction_id', 'merchant_transaction_id', 'merchant_txn_id', 'merchant_txnid']],
        'status' => ['Status', true, ['status', 'transaction_status', 'txn_status', 'payment_status']],
        'amount' => ['Amount', true, ['amount', 'transaction_amount', 'txn_amount', 'order_amount', 'amount_inr']],
        'added_on' => ['Transaction date', true, ['addedon', 'added_on', 'transaction_date', 'txn_date', 'date', 'created_at', 'created_on', 'transaction_time', 'date_time', 'payment_date']],
        'email' => ['Customer email', true, ['email', 'customer_email', 'email_id', 'emailid', 'buyer_email']],
        'mihpayid' => ['PayU ID (mihpayid)', false, ['id', 'mihpayid', 'payu_id', 'payuid', 'payu_payment_id', 'payment_id', 'payu_transaction_id', 'payu_reference_id']],
        'status_detail' => ['Second status column', false, ['unmapped_status', 'unmappedstatus', 'detailed_status', 'pg_status']],
        'success_at' => ['Success date', false, ['success_at', 'succeeded_on', 'success_date', 'captured_at', 'captured_on']],
        'productinfo' => ['Product info', false, ['productinfo', 'product_info', 'product', 'product_name', 'products', 'item', 'items', 'description']],
        'order_ref' => ['Your order number', false, ['order_id', 'order_number', 'orderid', 'order_no', 'merchant_order_id', 'invoice_id', 'invoice_number']],
        'firstname' => ['First name', false, ['firstname', 'first_name', 'customer_name', 'name', 'customer_first_name', 'buyer_name']],
        'lastname' => ['Last name', false, ['lastname', 'last_name', 'customer_last_name']],
        'phone' => ['Phone', false, ['phone', 'mobile', 'customer_phone', 'phone_number', 'mobile_number', 'contact_number']],
        'address_line1' => ['Address line 1', false, ['address_line1', 'address1', 'address_1', 'billing_address', 'address']],
        'address_line2' => ['Address line 2', false, ['address_line2', 'address2', 'address_2']],
        'city' => ['City', false, ['city', 'billing_city']],
        'state' => ['State', false, ['state', 'billing_state']],
        'country' => ['Country', false, ['country', 'billing_country']],
        'zipcode' => ['PIN code', false, ['zipcode', 'zip', 'pincode', 'pin_code', 'postal_code', 'zip_code']],
        'ship_firstname' => ['Shipping first name', false, ['shipping_firstname', 'shipping_first_name', 'shipping_name']],
        'ship_lastname' => ['Shipping last name', false, ['shipping_lastname', 'shipping_last_name']],
        'ship_address1' => ['Shipping address line 1', false, ['shipping_address1', 'shipping_address_line1', 'shipping_address']],
        'ship_address2' => ['Shipping address line 2', false, ['shipping_address2', 'shipping_address_line2']],
        'ship_city' => ['Shipping city', false, ['shipping_city']],
        'ship_state' => ['Shipping state', false, ['shipping_state']],
        'ship_country' => ['Shipping country', false, ['shipping_country']],
        'ship_zipcode' => ['Shipping PIN code', false, ['shipping_zipcode', 'shipping_pincode', 'shipping_zip']],
        'ship_phone' => ['Shipping phone', false, ['shipping_phone']],
        'mode' => ['Payment mode', false, ['mode', 'payment_mode', 'paymode', 'payment_method']],
        'bank_name' => ['Bank name', false, ['bank_name', 'bankname', 'issuing_bank']],
        'bank_ref_no' => ['Bank reference no.', false, ['bank_ref_no', 'bank_ref_num', 'bank_reference_number', 'bank_ref', 'bank_reference_no', 'bank_ref_number', 'rrn']],
        'card_type' => ['Card type', false, ['cardtype', 'card_type', 'card_category']],
        'card_last4' => ['Card last 4 digits', false, ['last_four_digits', 'card_last4', 'card_last_four']],
        'upi_app' => ['UPI app', false, ['upi_app_name', 'upi_app']],
        'error_code' => ['Error code', false, ['error_code', 'error']],
        'error_message' => ['Error message', false, ['error_message', 'errordescription', 'error_description', 'field9', 'pg_response']],
        'discount' => ['PayU discount', false, ['discount']],
        'additional_charges' => ['Additional charges', false, ['additional_charges', 'additionalcharges']],
        'currency' => ['Currency', false, ['currency_type', 'currency']],
        'udf1' => ['udf1', false, ['udf1']], 'udf2' => ['udf2', false, ['udf2']], 'udf3' => ['udf3', false, ['udf3']],
        'udf4' => ['udf4', false, ['udf4']], 'udf5' => ['udf5', false, ['udf5']],
        'settlement_amount' => ['Settlement amount', false, ['settlement_amount', 'settled_amount', 'net_settlement_amount']],
        'settlement_date' => ['Settlement date', false, ['settlement_date', 'settled_on', 'settled_date']],
        'utr' => ['Settlement UTR', false, ['utr', 'settlement_utr', 'utr_number']],
        'refund_amount' => ['Refund amount', false, ['refund_amount', 'refunded_amount', 'total_refund_amount']],
        'refund_ref' => ['Refund reference', false, ['refund_id', 'refund_request_id', 'refund_reference', 'refund_ref']],
        'refund_date' => ['Refund date', false, ['refund_date', 'refunded_on', 'refund_processed_on']],
    ];
}

function payu_norm_header(string $h): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($h))), '_');
}

/** The heading row: within the first 15 rows, the one with a txnid column and the most known headings. */
function payu_find_header_row(array $rows): ?int
{
    $aliases = [];
    foreach (payu_fields() as $k => $f) {
        foreach ($f[2] as $a) {
            $aliases[$a] = $k;
        }
    }
    $txnAliases = payu_fields()['txnid'][2];
    $best = null;
    $bestScore = 0;
    $i = 0;
    foreach ($rows as $line => $cells) {
        if (++$i > 15) {
            break;
        }
        $norm = array_map('payu_norm_header', array_map('strval', $cells));
        $found = [];
        foreach ($norm as $h) {
            if (isset($aliases[$h])) {
                $found[$aliases[$h]] = true;
            }
        }
        if (array_intersect($norm, $txnAliases) && count($found) > $bestScore) {
            $best = (int) $line;
            $bestScore = count($found);
        }
    }
    return $bestScore >= 3 ? $best : null;
}

/** Column chosen for each field: the admin's earlier choice for that heading, otherwise the first known heading. */
function payu_auto_map(array $headers): array
{
    $norm = array_map('payu_norm_header', $headers);
    $remembered = setting_json('import_mapping');
    $map = [];
    $used = [];
    foreach (payu_fields() as $key => $f) {
        if (array_key_exists($key, $remembered)) {
            if ($remembered[$key] === '') {
                continue;
            }
            $i = array_search($remembered[$key], $headers, true);
            if ($i !== false && !isset($used[$i])) {
                $map[$key] = (int) $i;
                $used[$i] = true;
                continue;
            }
        }
        foreach ($f[2] as $alias) {
            $i = array_search($alias, $norm, true);
            if ($i !== false && !isset($used[$i])) {
                $map[$key] = (int) $i;
                $used[$i] = true;
                break;
            }
        }
    }
    return $map;
}

/** Remembers the admin's column choices (by heading text) for the next file. */
function payu_remember_map(array $headers, array $map): void
{
    $mem = setting_json('import_mapping');
    foreach (payu_fields() as $key => $f) {
        $mem[$key] = isset($map[$key]) ? (string) ($headers[$map[$key]] ?? '') : '';
    }
    settings_save(['import_mapping' => $mem]);
}

/** Reads the stored upload of a batch and finds its heading row. */
function payu_load(array $batch): array
{
    $path = APP_ROOT . '/storage/imports/' . basename((string) $batch['stored_file']);
    if (!is_file($path)) {
        throw new RuntimeException('The uploaded file is no longer on the server. Upload it again.');
    }
    $sheet = sheet_read($path, strtolower(pathinfo($path, PATHINFO_EXTENSION)), max(100, (int) setting('import_max_rows', 20000)));
    $hr = payu_find_header_row($sheet['rows']);
    if ($hr === null) {
        throw new RuntimeException('Could not find PayU column headings (such as txnid, status and amount) in the first 15 rows of this file.');
    }
    $headers = array_map(function ($h) { return trim((string) $h); }, $sheet['rows'][$hr]);
    return ['headers' => $headers, 'rows' => $sheet['rows'], 'header_row' => $hr, 'date1904' => $sheet['date1904'], 'sheet' => $sheet['sheet']];
}

/* ---------- reading one row ---------- */

function payu_looks_scientific(string $s): bool
{
    return (bool) preg_match('/^-?\d+(\.\d+)?e[+-]?\d+$/i', trim($s));
}

function payu_money(string $s): ?float
{
    $s = str_replace([',', ' ', "\xC2\xA0"], '', trim(str_ireplace(['₹', 'INR', 'Rs.', 'Rs'], '', $s)));
    if ($s === '' || !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return null;
    }
    return round((float) $s, 2);
}

/** Reads the date formats PayU and Excel produce. Day comes before month (Indian format). */
function payu_date(string $s, bool $date1904 = false): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $s)) {
        $n = (float) $s;
        return ($n > 20000 && $n < 80000) ? excel_serial_to_datetime($n, $date1904) : null;
    }
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:s.uP', 'Y-m-d H:i:s.u', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y/m/d H:i:s', 'Y/m/d', 'd M Y H:i:s', 'd M Y H:i', 'd M Y', 'd-M-Y H:i:s', 'd-M-Y H:i', 'd-M-Y',
        'M d, Y h:i A', 'M d, Y H:i', 'd/m/Y h:i A', 'd/m/Y h:i:s A', 'd-m-Y h:i A', 'd-m-Y h:i:s A'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat('!' . $f, $s);
        $errors = DateTime::getLastErrors();
        if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $year = (int) $dt->format('Y');
            return ($year >= 2000 && $year <= 2100) ? $dt->format('Y-m-d H:i:s') : null;
        }
    }
    return null;
}

/** Validates and cleans one row. Returns [data, errors, warnings]. */
function payu_parse_row(array $cells, array $map, bool $date1904): array
{
    $g = function ($k) use ($cells, $map) {
        return isset($map[$k]) ? trim(str_replace("\xC2\xA0", ' ', (string) ($cells[$map[$k]] ?? ''))) : '';
    };
    $err = [];
    $warn = [];
    $d = [];
    $excelNote = ' Excel turned this ID into a number and lost digits: download the file again from PayU and upload it without saving it in Excel, or format that column as Text.';

    $d['txnid'] = $g('txnid');
    if ($d['txnid'] === '') {
        $err[] = 'Transaction ID (txnid) is empty.';
    } elseif (payu_looks_scientific($d['txnid'])) {
        $err[] = 'Transaction ID ' . $d['txnid'] . ' is not usable.' . $excelNote;
    } elseif (strlen($d['txnid']) > 100) {
        $err[] = 'Transaction ID is longer than 100 characters.';
    }

    $pid = $g('mihpayid');
    if ($pid !== '' && payu_looks_scientific($pid)) {
        $err[] = 'PayU ID ' . $pid . ' is not usable.' . $excelNote;
    } elseif ($pid !== '') {
        $pid = (string) preg_replace('/\.0+$/', '', $pid);
        if (!preg_match('/^\d{6,30}$/', $pid)) {
            $err[] = 'PayU ID "' . $pid . '" should be a number of 6 or more digits.';
        }
    }
    $d['mihpayid'] = $pid !== '' ? $pid : null;

    $st = $g('status');
    $st2 = $g('status_detail');
    $r1 = payu_result($st);
    $r2 = payu_result($st2);
    if ($st === '' && $st2 === '') {
        $err[] = 'Status is empty.';
    } elseif ($st !== '' && $r1 === '') {
        $err[] = 'Status "' . $st . '" is not a PayU status this importer knows.';
    } elseif ($st === '' && $r2 === '') {
        $err[] = 'Status "' . $st2 . '" is not a PayU status this importer knows.';
    }
    $result = $r1 !== '' ? $r1 : $r2;
    if ($r1 === 'refunded' || $r2 === 'refunded') {
        $result = 'refunded';
    } elseif ($r1 !== '' && $r2 !== '' && $r1 !== $r2) {
        $warn[] = 'The two status columns disagree ("' . $st . '" and "' . $st2 . '"); "' . $st . '" was used.';
    }
    $d['status'] = str_cut($st !== '' ? $st : $st2, 40);
    $d['status_detail'] = str_cut($st !== '' ? $st2 : '', 40);
    $d['result'] = $result;

    $d['amount'] = payu_money($g('amount'));
    if ($g('amount') === '') {
        $err[] = 'Amount is empty.';
    } elseif ($d['amount'] === null) {
        $err[] = 'Amount "' . $g('amount') . '" is not a number.';
    } elseif ($d['amount'] <= 0) {
        $err[] = 'Amount must be more than zero.';
    }

    $d['added_on'] = payu_date($g('added_on'), $date1904);
    if ($g('added_on') === '') {
        $err[] = 'Transaction date is empty.';
    } elseif ($d['added_on'] === null) {
        $err[] = 'Transaction date "' . $g('added_on') . '" is not a date this importer can read.';
    }
    $d['success_at'] = payu_date($g('success_at'), $date1904);
    if ($g('success_at') !== '' && $d['success_at'] === null) {
        $warn[] = 'Success date "' . $g('success_at') . '" could not be read and was left empty.';
    }

    $d['email'] = strtolower($g('email'));
    if ($d['email'] === '') {
        $err[] = 'Customer email is empty.';
    } elseif (!valid_email($d['email'])) {
        $err[] = 'Customer email "' . $g('email') . '" is not a valid email address.';
    }

    $currency = strtoupper($g('currency'));
    if ($currency !== '' && $currency !== 'INR') {
        $err[] = 'Currency is ' . $currency . '; this store records Indian rupees (INR) only.';
    }

    $phone = clean_phone($g('phone'));
    $d['phone'] = $phone !== '' ? $phone : str_cut((string) preg_replace('/[^\d+]/', '', $g('phone')), 20);
    $shipPhone = clean_phone($g('ship_phone'));
    $d['ship_phone'] = $shipPhone !== '' ? $shipPhone : str_cut((string) preg_replace('/[^\d+]/', '', $g('ship_phone')), 20);

    $lengths = ['firstname' => 100, 'lastname' => 100, 'address_line1' => 255, 'address_line2' => 255, 'city' => 80, 'state' => 80, 'country' => 60,
        'zipcode' => 12, 'ship_firstname' => 100, 'ship_lastname' => 100, 'ship_address1' => 255, 'ship_address2' => 255, 'ship_city' => 80,
        'ship_state' => 80, 'ship_country' => 60, 'ship_zipcode' => 12, 'productinfo' => 255, 'order_ref' => 100, 'mode' => 30, 'bank_name' => 100,
        'bank_ref_no' => 100, 'card_type' => 40, 'upi_app' => 60, 'error_code' => 20, 'error_message' => 255, 'udf1' => 255, 'udf2' => 255,
        'udf3' => 255, 'udf4' => 255, 'udf5' => 255, 'utr' => 60, 'refund_ref' => 100];
    foreach ($lengths as $k => $max) {
        $d[$k] = str_cut($g($k), $max);
    }
    $d['card_last4'] = substr((string) preg_replace('/\D/', '', $g('card_last4')), -4);
    foreach (['discount', 'additional_charges', 'settlement_amount', 'refund_amount'] as $k) {
        $d[$k] = payu_money($g($k));
    }
    $d['settlement_date'] = payu_date($g('settlement_date'), $date1904);
    $d['refund_date'] = payu_date($g('refund_date'), $date1904);
    foreach (['bank_ref_no' => 'Bank reference', 'utr' => 'UTR'] as $k => $label) {
        if ($d[$k] !== '' && payu_looks_scientific($d[$k])) {
            $warn[] = $label . ' ' . $d[$k] . ' was turned into a number by Excel and is stored as shown.';
        }
    }
    return [$d, $err, $warn];
}

/** Every column of the original row, keyed by its heading, for the raw_data record. */
function payu_raw_assoc(array $headers, array $cells): array
{
    $out = [];
    foreach ($headers as $i => $h) {
        $k = trim((string) $h) !== '' ? trim((string) $h) : 'column_' . ($i + 1);
        if (array_key_exists($k, $out)) {
            $k .= ' (' . ($i + 1) . ')';
        }
        $out[$k] = (string) ($cells[$i] ?? '');
    }
    for ($i = count($headers); $i < count($cells); $i++) {
        if (trim((string) $cells[$i]) !== '') {
            $out['column_' . ($i + 1)] = (string) $cells[$i];
        }
    }
    return $out;
}

/* ---------- products named in "productinfo" ---------- */

function payu_generic_productinfo(string $s): bool
{
    $n = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    return $n === '' || in_array($n, ['payment', 'payments', 'order', 'orders', 'product', 'products', 'purchase', 'online payment', 'payment for order',
        'test', 'na', 'n a', 'none', 'item', 'items', 'shopping', 'cart', 'checkout', 'pay', 'payu', 'payment link', 'quick pay'], true);
}

/** "Order ORD-261029-7K3Q" style product info carries an order number, not a product. */
function payu_order_ref_from_productinfo(string $s): string
{
    return preg_match('/^\s*order\s*(?:no\.?|number|id)?\s*[:#-]?\s*((?=[A-Za-z_\/-]*\d)[A-Za-z0-9][A-Za-z0-9_\/-]{2,39})\s*$/i', $s, $m) ? $m[1] : '';
}

function payu_norm_text(string $s): string
{
    $s = strtolower($s);
    $s = (string) preg_replace('/(\d)\s+(kg|g|gm|l|ltr|litre|liter|ml)\b/', '$1$2', $s);
    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $s)));
}

function payu_product_index(bool $reload = false): array
{
    static $idx = null;
    if ($idx !== null && !$reload) {
        return $idx;
    }
    $idx = ['sku' => [], 'name' => [], 'alias' => [], 'all' => []];
    foreach (rows('SELECT id, name, sku, size_label, kind, match_aliases FROM products') as $p) {
        $id = (int) $p['id'];
        $idx['all'][$id] = $p;
        $idx['sku'][strtolower(trim($p['sku']))] = $id;
        $idx['name'][payu_norm_text($p['name'])][] = $id;
        foreach (preg_split('/[\r\n]+/', (string) $p['match_aliases']) as $a) {
            $a = payu_norm_text($a);
            if ($a !== '') {
                $idx['alias'][$a][] = $id;
            }
        }
    }
    return $idx;
}

/** Product id for a name in the PayU file: SKU, exact name, an alias, or the start of exactly one product name. */
function payu_find_product(string $name): ?int
{
    $idx = payu_product_index();
    $sku = strtolower(trim($name));
    if (isset($idx['sku'][$sku])) {
        return $idx['sku'][$sku];
    }
    $n = payu_norm_text($name);
    if ($n === '') {
        return null;
    }
    foreach (['name', 'alias'] as $k) {
        if (isset($idx[$k][$n]) && count(array_unique($idx[$k][$n])) === 1) {
            return $idx[$k][$n][0];
        }
    }
    $hits = [];
    if (strlen($n) >= 6) {
        foreach ($idx['name'] as $pn => $ids) {
            if (strpos($pn . ' ', $n . ' ') === 0) {
                foreach ($ids as $id) {
                    $hits[$id] = true;
                }
            }
        }
    }
    return count($hits) === 1 ? (int) key($hits) : null;
}

/**
 * Items named in product info: "Vermicompost 5kg x 2, Neem Cake Powder".
 * Quantity is taken only when the text states it; otherwise it stays empty.
 */
function payu_match_items(string $productinfo): array
{
    if (payu_generic_productinfo($productinfo) || payu_order_ref_from_productinfo($productinfo) !== '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s*(?:[,;|\n]|\s\+\s)\s*/', $productinfo) as $part) {
        $part = trim($part);
        if ($part === '' || payu_generic_productinfo($part)) {
            continue;
        }
        $qty = null;
        $name = $part;
        if (preg_match('/^(.+?)\s+(?:x|×|\*|qty\s*:?|quantity\s*:?)\s*(\d{1,4})\s*(?:nos?|pcs?|units?|bags?)?$/iu', $part, $m)) {
            $name = $m[1];
            $qty = (int) $m[2];
        } elseif (preg_match('/^(\d{1,4})\s*(?:x|×|\*)\s*(.+)$/iu', $part, $m)) {
            $qty = (int) $m[1];
            $name = $m[2];
        } elseif (preg_match('/^(.+?)\s*\((\d{1,4})\)$/u', $part, $m)) {
            $name = $m[1];
            $qty = (int) $m[2];
        }
        $out[] = ['text' => str_cut($part, 255), 'qty' => $qty > 0 ? $qty : null, 'product_id' => payu_find_product($name)];
    }
    return $out;
}

/** Shipping block if the file has one, otherwise the billing address. */
function payu_address(array $d): array
{
    if ($d['ship_address1'] !== '' || $d['ship_city'] !== '' || $d['ship_zipcode'] !== '') {
        return ['name' => trim($d['ship_firstname'] . ' ' . $d['ship_lastname']), 'line1' => $d['ship_address1'], 'line2' => $d['ship_address2'],
            'city' => $d['ship_city'], 'state' => $d['ship_state'], 'country' => $d['ship_country'], 'pincode' => $d['ship_zipcode'], 'phone' => $d['ship_phone']];
    }
    return ['name' => '', 'line1' => $d['address_line1'], 'line2' => $d['address_line2'], 'city' => $d['city'], 'state' => $d['state'],
        'country' => $d['country'], 'pincode' => $d['zipcode'], 'phone' => ''];
}

/* ---------- writing ---------- */

/** Finds the customer by email or creates one from the file. Blank fields of an existing customer are filled, never overwritten. */
function payu_import_customer(array $d, int $batchId): array
{
    $name = str_cut(trim($d['firstname'] . ' ' . $d['lastname']), 120);
    $a = payu_address($d);
    $c = row('SELECT * FROM customers WHERE email = ?', [$d['email']]);
    if ($c) {
        $fill = [];
        if (trim($c['name']) === '' && $name !== '') {
            $fill['name'] = $name;
        }
        if (trim($c['phone']) === '' && $d['phone'] !== '') {
            $fill['phone'] = $d['phone'];
        }
        if ($c['address_line1'] === '' && $c['city'] === '' && $c['pincode'] === '') {
            foreach (['address_line1' => 'line1', 'address_line2' => 'line2', 'city' => 'city', 'state' => 'state', 'pincode' => 'pincode'] as $col => $k) {
                if ($a[$k] !== '') {
                    $fill[$col] = $a[$k];
                }
            }
        }
        if ($fill) {
            update_row('customers', $fill, 'id = ?', [(int) $c['id']]);
        }
        return [(int) $c['id'], false];
    }
    $id = insert_row('customers', ['name' => $name, 'email' => $d['email'], 'phone' => $d['phone'], 'address_line1' => $a['line1'],
        'address_line2' => $a['line2'], 'city' => $a['city'], 'state' => $a['state'], 'pincode' => $a['pincode'],
        'source' => 'payu_import', 'import_batch_id' => $batchId, 'created_at' => now()]);
    return [$id, true];
}

function payu_import_order_number(array $p): string
{
    return 'PU-' . ($p['mihpayid'] !== null && $p['mihpayid'] !== '' ? $p['mihpayid'] : strtoupper(substr(sha1($p['txnid']), 0, 12)));
}

/** Creates a historical order from the main payment of a group. Only what the file says is stored. */
function payu_import_order_create(array $p, string $ref, int $batchId, ?int $customerId, array $items, string $placedAt, string $extraNote): int
{
    $a = payu_address($p);
    $base = payu_import_order_number($p);
    $number = $base;
    for ($n = 2; val('SELECT 1 FROM orders WHERE order_number = ?', [$number]); $n++) {
        $number = $base . '-' . $n;
    }
    $notes = [];
    if (!$items) {
        $notes[] = $p['productinfo'] === '' ? 'The PayU file has no product details for this payment.'
            : 'The PayU file does not name the products (product info: "' . $p['productinfo'] . '").';
    } else {
        $unmatched = count(array_filter($items, function ($i) { return !$i['product_id']; }));
        if ($unmatched) {
            $notes[] = $unmatched . ' product name(s) in the PayU file did not match the catalogue. Link them on this page.';
        }
        if (array_filter($items, function ($i) { return $i['qty'] === null; })) {
            $notes[] = 'The PayU file does not state quantities.';
        }
    }
    if ($extraNote !== '') {
        $notes[] = $extraNote;
    }
    $name = $a['name'] !== '' ? $a['name'] : trim($p['firstname'] . ' ' . $p['lastname']);
    $orderId = insert_row('orders', [
        'order_number' => $number, 'source' => 'payu_import', 'customer_id' => $customerId, 'customer_name' => str_cut($name, 120),
        'email' => $p['email'], 'phone' => $a['phone'] !== '' ? $a['phone'] : $p['phone'], 'address_line1' => $a['line1'], 'address_line2' => $a['line2'],
        'city' => $a['city'], 'state' => $a['state'], 'pincode' => $a['pincode'], 'country' => $a['country'],
        'subtotal' => null, 'discount' => null, 'shipping_fee' => null, 'total' => num2($p['amount']), 'payment_method' => 'payu',
        'payment_status' => 'pending', 'order_status' => 'not_recorded', 'import_note' => implode("\n", $notes),
        'external_ref' => $ref !== '' ? $ref : null, 'import_batch_id' => $batchId, 'placed_at' => $placedAt, 'created_at' => now(),
    ]);
    $catalogue = payu_product_index()['all'];
    foreach ($items as $it) {
        $prod = $it['product_id'] ? ($catalogue[$it['product_id']] ?? null) : null;
        insert_row('order_items', [
            'order_id' => $orderId, 'product_id' => $prod ? (int) $prod['id'] : null, 'sku' => $prod ? $prod['sku'] : '',
            'product_name' => str_cut($prod ? $prod['name'] : $it['text'], 255), 'size_label' => $prod ? $prod['size_label'] : '',
            'unit' => ($prod && $prod['kind'] === 'loose') ? 'kg' : 'pc', 'quantity' => $it['qty'], 'unit_price' => null, 'list_price' => null,
            'line_total' => null, 'source_text' => $it['text'], 'match_status' => $prod ? 'matched' : 'unmatched',
        ]);
    }
    return $orderId;
}

function payu_import_insert_txn(array $d, array $cells, array $headers, int $batchId, ?int $orderId, ?int $customerId): int
{
    $data = ['source' => 'import', 'txnid' => $d['txnid'], 'mihpayid' => $d['mihpayid'], 'order_id' => $orderId, 'customer_id' => $customerId,
        'import_batch_id' => $batchId, 'status' => $d['status'], 'status_detail' => $d['status_detail'], 'result' => $d['result'],
        'amount' => num2($d['amount']), 'discount' => $d['discount'] === null ? null : num2($d['discount']),
        'additional_charges' => $d['additional_charges'] === null ? null : num2($d['additional_charges']),
        'added_on' => $d['added_on'], 'success_at' => $d['success_at'],
        'settlement_amount' => $d['settlement_amount'] === null ? null : num2($d['settlement_amount']), 'settlement_date' => $d['settlement_date'],
        'raw_data' => json_encode(payu_raw_assoc($headers, $cells), JSON_UNESCAPED_UNICODE)];
    foreach (['mode', 'bank_name', 'bank_ref_no', 'upi_app', 'card_type', 'card_last4', 'error_code', 'error_message', 'productinfo', 'firstname', 'lastname',
        'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'country', 'zipcode', 'udf1', 'udf2', 'udf3', 'udf4', 'udf5', 'utr'] as $k) {
        $data[$k] = $d[$k];
    }
    return insert_row('payu_transactions', $data);
}

function payu_db_error(Throwable $e): string
{
    return strpos($e->getMessage(), 'Duplicate entry') !== false ? 'it was recorded at the same moment by another import or by the website.' : 'a database error (see storage/logs/php-errors.log).';
}

/**
 * Previews ($commit = false) or imports ($commit = true) a stored upload.
 * Options: window (minutes for grouping attempts), update_status (refresh status of transactions already stored).
 */
function payu_import_process(int $batchId, bool $commit, array $opts = []): array
{
    @set_time_limit(600);
    $batch = row('SELECT * FROM import_batches WHERE id = ?', [$batchId]);
    if (!$batch) {
        throw new RuntimeException('This import no longer exists.');
    }
    $file = payu_load($batch);
    $headers = $file['headers'];
    $map = json_decode((string) $batch['mapping'], true);
    if (!is_array($map) || !$map) {
        $map = payu_auto_map($headers);
    }
    $window = max(1, (int) ($opts['window'] ?? setting('import_window_minutes', 60))) * 60;
    $updateExisting = !empty($opts['update_status']);
    $S = ['file' => $file, 'map' => $map, 'missing' => [], 'rows_total' => 0, 'new' => 0, 'updated' => 0, 'duplicate' => 0, 'failed' => 0,
        'orders_new' => 0, 'orders_attached' => 0, 'customers_new' => 0, 'attempts_only' => 0, 'earlier_linked' => 0, 'items_matched' => 0,
        'items_unmatched' => 0, 'orders_without_items' => 0, 'dup_payments' => 0, 'rows' => [], 'orders_preview' => []];
    foreach (payu_fields() as $k => $f) {
        if ($f[1] && !isset($map[$k])) {
            $S['missing'][] = $f[0];
        }
    }
    foreach ($file['rows'] as $line => $cells) {
        if ($line > $file['header_row']) {
            $S['rows_total']++;
        }
    }
    if ($S['missing']) {
        return $S;
    }
    if ($commit && !q("UPDATE import_batches SET status = 'importing' WHERE id = ? AND status = 'uploaded'", [$batchId])->rowCount()) {
        throw new RuntimeException('This file is already being imported, or was imported already.');
    }
    payu_product_index(true);

    // 1. Check every row, and repeats inside the file.
    $parsed = [];
    $seenTxn = [];
    $seenPid = [];
    foreach ($file['rows'] as $line => $cells) {
        if ($line <= $file['header_row']) {
            continue;
        }
        list($d, $err, $warn) = payu_parse_row($cells, $map, $file['date1904']);
        if (!$err && isset($seenTxn[$d['txnid']])) {
            $first = $seenTxn[$d['txnid']];
            if ($parsed[$first]['cells'] == $cells) {
                $S['rows'][$line] = ['outcome' => 'duplicate', 'txnid' => $d['txnid'], 'msg' => 'Same as row ' . $first . ' of this file.', 'cells' => $cells];
                continue;
            }
            $err[] = 'Transaction ID ' . $d['txnid'] . ' is also on row ' . $first . ' with different values.';
        }
        if (!$err && $d['mihpayid'] !== null && isset($seenPid[$d['mihpayid']])) {
            $err[] = 'PayU ID ' . $d['mihpayid'] . ' is also on row ' . $seenPid[$d['mihpayid']] . ' with another transaction ID.';
        }
        if ($err) {
            $S['rows'][$line] = ['outcome' => 'failed', 'txnid' => (string) ($d['txnid'] ?? ''), 'msg' => implode(' ', $err), 'cells' => $cells];
            continue;
        }
        $seenTxn[$d['txnid']] = $line;
        if ($d['mihpayid'] !== null) {
            $seenPid[$d['mihpayid']] = $line;
        }
        $parsed[$line] = ['d' => $d, 'cells' => $cells, 'warn' => implode(' ', $warn)];
    }

    // 2. Compare with transactions already stored (from earlier imports or website checkout).
    $existing = [];
    $existingPid = [];
    $txnids = array_values(array_map(function ($p) { return $p['d']['txnid']; }, $parsed));
    foreach (array_chunk($txnids, 500) as $chunk) {
        foreach (rows('SELECT * FROM payu_transactions WHERE txnid IN (' . placeholders($chunk) . ')', $chunk) as $t) {
            $existing[$t['txnid']] = $t;
        }
    }
    $pids = [];
    foreach ($parsed as $p) {
        if ($p['d']['mihpayid'] !== null) {
            $pids[] = $p['d']['mihpayid'];
        }
    }
    foreach (array_chunk($pids, 500) as $chunk) {
        foreach (rows('SELECT txnid, mihpayid FROM payu_transactions WHERE mihpayid IN (' . placeholders($chunk) . ')', $chunk) as $t) {
            $existingPid[$t['mihpayid']] = $t;
        }
    }
    $new = [];
    $updates = [];
    foreach ($parsed as $line => $p) {
        $d = $p['d'];
        if (isset($existing[$d['txnid']])) {
            $t = $existing[$d['txnid']];
            if ($d['mihpayid'] !== null && (string) $t['mihpayid'] !== '' && $t['mihpayid'] !== $d['mihpayid']) {
                $S['rows'][$line] = ['outcome' => 'failed', 'txnid' => $d['txnid'], 'msg' => 'Already recorded with another PayU ID (' . $t['mihpayid'] . ').', 'cells' => $p['cells']];
                continue;
            }
            $where = $t['source'] === 'website' ? 'by website checkout' : 'by import #' . (int) $t['import_batch_id'];
            $statusChanged = $t['status'] !== $d['status'] || $t['result'] !== $d['result'];
            $changed = $statusChanged || ((string) $t['utr'] === '' && $d['utr'] !== '') || ($t['settlement_amount'] === null && $d['settlement_amount'] !== null);
            if ($changed && $updateExisting) {
                $updates[$line] = ['d' => $d, 't' => $t, 'cells' => $p['cells']];
                $S['rows'][$line] = ['outcome' => 'updated', 'txnid' => $d['txnid'], 'cells' => $p['cells'], 'msg' => 'Already recorded ' . $where . '; '
                    . ($statusChanged ? 'status changes from "' . $t['status'] . '" to "' . $d['status'] . '".' : 'settlement details added.')];
            } else {
                $S['rows'][$line] = ['outcome' => 'duplicate', 'txnid' => $d['txnid'], 'cells' => $p['cells'], 'order_id' => $t['order_id'] ? (int) $t['order_id'] : null,
                    'txn_id' => (int) $t['id'], 'msg' => 'Already recorded ' . $where . ($statusChanged ? ' with status "' . $t['status'] . '"; this file says "' . $d['status']
                    . '" (tick "Update statuses" to apply it)' : '') . '.'];
            }
            continue;
        }
        if ($d['mihpayid'] !== null && isset($existingPid[$d['mihpayid']])) {
            $S['rows'][$line] = ['outcome' => 'failed', 'txnid' => $d['txnid'], 'cells' => $p['cells'],
                'msg' => 'PayU ID ' . $d['mihpayid'] . ' is already recorded on transaction ' . $existingPid[$d['mihpayid']]['txnid'] . '.'];
            continue;
        }
        $ref = $d['order_ref'] !== '' ? $d['order_ref'] : payu_order_ref_from_productinfo($d['productinfo']);
        $new[] = ['line' => $line, 'd' => $d, 'cells' => $p['cells'], 'warn' => $p['warn'], 'ref' => $ref, 'ts' => strtotime($d['added_on'])];
    }

    // 3. Group payment attempts into purchases.
    usort($new, function ($a, $b) { return $a['ts'] === $b['ts'] ? $a['line'] - $b['line'] : $a['ts'] - $b['ts']; });
    $groups = [];
    $byRef = [];
    $open = [];
    foreach ($new as $r) {
        $d = $r['d'];
        if ($r['ref'] !== '') {
            $key = strtolower($r['ref']);
            if (!isset($byRef[$key])) {
                $groups[] = ['ref' => $r['ref'], 'email' => $d['email'], 'amount' => $d['amount'], 'rows' => [], 'last' => 0, 'success' => false];
                $byRef[$key] = count($groups) - 1;
            }
            $gi = $byRef[$key];
        } else {
            $key = $d['email'] . '|' . num2($d['amount']);
            $gi = $open[$key] ?? null;
            if ($gi !== null && ($groups[$gi]['success'] || $r['ts'] - $groups[$gi]['last'] > $window)) {
                $gi = null;
            }
            if ($gi === null) {
                $groups[] = ['ref' => '', 'email' => $d['email'], 'amount' => $d['amount'], 'rows' => [], 'last' => 0, 'success' => false];
                $gi = count($groups) - 1;
                $open[$key] = $gi;
            }
        }
        $groups[$gi]['rows'][] = $r;
        $groups[$gi]['last'] = $r['ts'];
        if (in_array($d['result'], ['success', 'refunded'], true)) {
            $groups[$gi]['success'] = true;
        }
    }
    $successes = [];
    foreach ($groups as $gi => $g) {
        foreach ($g['rows'] as $r) {
            if (in_array($r['d']['result'], ['success', 'refunded'], true)) {
                $successes[] = [$gi, $r['d']['email'], num2($r['d']['amount']), $r['ts'], $r['d']['txnid']];
            }
        }
    }

    // 4. Decide and (when importing) save each group.
    $previewEmails = [];
    foreach ($groups as $gi => $g) {
        $grows = $g['rows'];
        $firstTs = $grows[0]['ts'];
        $lastTs = $grows[count($grows) - 1]['ts'];
        $primary = null;
        foreach ($grows as $r) {
            if (in_array($r['d']['result'], ['success', 'refunded'], true)) {
                $primary = $r;
                break;
            }
        }
        if (!$primary) {
            foreach (array_reverse($grows) as $r) {
                if ($r['d']['result'] === 'pending') {
                    $primary = $r;
                    break;
                }
            }
        }
        $makesOrder = $primary !== null;
        if (!$primary) {
            $primary = $grows[count($grows) - 1];
        }
        $p = $primary['d'];
        $amount = num2($g['amount']);

        $attachId = null;
        if ($g['ref'] !== '') {
            $attachId = val("SELECT id FROM orders WHERE source = 'payu_import' AND external_ref = ? ORDER BY id LIMIT 1", [$g['ref']]);
        } else {
            $cands = rows("SELECT t.order_id, MAX(t.result IN ('success', 'refunded')) AS paid FROM payu_transactions t JOIN orders o ON o.id = t.order_id
                WHERE o.source = 'payu_import' AND t.email = ? AND t.amount = ? AND t.added_on BETWEEN ? AND ? GROUP BY t.order_id ORDER BY MIN(t.added_on) DESC",
                [$g['email'], $amount, date('Y-m-d H:i:s', $firstTs - $window), date('Y-m-d H:i:s', $lastTs + $window)]);
            foreach ($cands as $c) {
                if ((int) $c['paid'] && $g['success']) {
                    continue;
                }
                $attachId = (int) $c['order_id'];
                break;
            }
        }
        $attachId = $attachId ? (int) $attachId : null;
        $mode = $attachId ? 'attach' : ($makesOrder ? 'new' : 'none');

        $dupNote = '';
        if ($mode === 'new' && $g['success']) {
            $pts = strtotime($p['added_on']);
            foreach ($successes as $s) {
                if ($s[0] !== $gi && $s[1] === $g['email'] && $s[2] === $amount && abs($s[3] - $pts) <= $window) {
                    $dupNote = 'Possible duplicate payment: PayU also captured ' . money($amount) . ' from this customer at ' . fmt_date(date('Y-m-d H:i:s', $s[3])) . ' (txnid ' . $s[4] . ').';
                    break;
                }
            }
            if ($dupNote === '') {
                $other = row("SELECT txnid, added_on FROM payu_transactions WHERE email = ? AND amount = ? AND result IN ('success', 'refunded') AND added_on BETWEEN ? AND ? LIMIT 1",
                    [$g['email'], $amount, date('Y-m-d H:i:s', $pts - $window), date('Y-m-d H:i:s', $pts + $window)]);
                if ($other) {
                    $dupNote = 'Possible duplicate payment: PayU also captured ' . money($amount) . ' from this customer at ' . fmt_date($other['added_on']) . ' (txnid ' . $other['txnid'] . ').';
                }
            }
        }
        $items = $mode === 'new' ? payu_match_items($p['productinfo']) : [];
        $placedAt = date('Y-m-d H:i:s', $firstTs);
        $earlierSql = "FROM payu_transactions WHERE order_id IS NULL AND source = 'import' AND email = ? AND amount = ? AND result IN ('failure', 'pending') AND added_on BETWEEN ? AND ?";
        $earlierParams = [$g['email'], $amount, date('Y-m-d H:i:s', $firstTs - $window), $placedAt];
        $count = ['orders_new' => 0, 'orders_attached' => 0, 'customers_new' => 0, 'attempts_only' => 0, 'earlier_linked' => 0, 'items_matched' => 0,
            'items_unmatched' => 0, 'orders_without_items' => 0, 'dup_payments' => 0];
        if ($mode === 'new') {
            $count['orders_new'] = 1;
            foreach ($items as $it) {
                $count[$it['product_id'] ? 'items_matched' : 'items_unmatched']++;
            }
            $count['orders_without_items'] = $items ? 0 : 1;
            $count['dup_payments'] = $dupNote !== '' ? 1 : 0;
        } elseif ($mode === 'attach') {
            $count['orders_attached'] = 1;
        } else {
            $count['attempts_only'] = count($grows);
        }
        $orderNo = '';
        if ($mode === 'attach') {
            $orderNo = (string) val('SELECT order_number FROM orders WHERE id = ?', [$attachId]);
        }

        if (!$commit) {
            if ($mode === 'new') {
                if (!isset($previewEmails[$p['email']]) && !val('SELECT id FROM customers WHERE email = ?', [$p['email']])) {
                    $count['customers_new'] = 1;
                }
                $previewEmails[$p['email']] = true;
                if ($g['ref'] === '') {
                    $count['earlier_linked'] = (int) val('SELECT COUNT(*) ' . $earlierSql, $earlierParams);
                }
                $orderNo = payu_import_order_number($p);
                if (count($S['orders_preview']) < 100) {
                    $S['orders_preview'][] = ['number' => $orderNo, 'name' => trim($p['firstname'] . ' ' . $p['lastname']), 'email' => $p['email'],
                        'amount' => $p['amount'], 'result' => $p['result'], 'attempts' => count($grows), 'placed_at' => $placedAt, 'items' => $items, 'note' => $dupNote, 'productinfo' => $p['productinfo']];
                }
            }
            foreach ($grows as $r) {
                $msg = $mode === 'new' ? 'Creates order ' . $orderNo . '.' : ($mode === 'attach' ? 'Added to existing order ' . $orderNo . '.' : 'Payment attempt only: no order, because no payment in this group succeeded.');
                $S['rows'][$r['line']] = ['outcome' => 'ready', 'txnid' => $r['d']['txnid'], 'msg' => trim($msg . ' ' . $r['warn']), 'cells' => $r['cells']];
            }
            foreach ($count as $k => $v) {
                $S[$k] += $v;
            }
            continue;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $orderId = null;
            if ($mode === 'new') {
                list($customerId, $created) = payu_import_customer($p, $batchId);
                $count['customers_new'] = $created ? 1 : 0;
                $orderId = payu_import_order_create($p, $g['ref'], $batchId, $customerId, $items, $placedAt, $dupNote);
                $orderNo = (string) val('SELECT order_number FROM orders WHERE id = ?', [$orderId]);
            } elseif ($mode === 'attach') {
                $orderId = $attachId;
                $customerId = ($cid = val('SELECT customer_id FROM orders WHERE id = ?', [$orderId])) ? (int) $cid : null;
            } else {
                $customerId = ($cid = val('SELECT id FROM customers WHERE email = ?', [$p['email']])) ? (int) $cid : null;
            }
            $saved = [];
            foreach ($grows as $r) {
                $txnId = payu_import_insert_txn($r['d'], $r['cells'], $headers, $batchId, $orderId, $customerId);
                if ($orderId && $r['d']['refund_amount'] !== null && $r['d']['refund_amount'] > 0 && in_array($r['d']['result'], ['success', 'refunded'], true)) {
                    insert_row('payu_refunds', ['order_id' => $orderId, 'transaction_id' => $txnId, 'amount' => num2($r['d']['refund_amount']),
                        'refund_ref' => $r['d']['refund_ref'], 'refunded_on' => substr((string) ($r['d']['refund_date'] ?: $r['d']['added_on']), 0, 10),
                        'note' => 'From PayU import #' . $batchId, 'source' => 'import', 'import_batch_id' => $batchId, 'created_at' => now()]);
                }
                $msg = $mode === 'new' ? 'Order ' . $orderNo . ' created.' : ($mode === 'attach' ? 'Added to order ' . $orderNo . '.' : 'Saved as a payment attempt with no order (no payment in this group succeeded).');
                $saved[$r['line']] = ['outcome' => 'imported', 'txnid' => $r['d']['txnid'], 'msg' => trim($msg . ' ' . $r['warn']), 'order_id' => $orderId, 'txn_id' => $txnId, 'cells' => $r['cells']];
            }
            if ($mode === 'new' && $g['ref'] === '') {
                $count['earlier_linked'] = q('UPDATE payu_transactions SET order_id = ?, customer_id = ? WHERE order_id IS NULL AND source = \'import\' AND email = ? AND amount = ?'
                    . " AND result IN ('failure', 'pending') AND added_on BETWEEN ? AND ? AND import_batch_id <> ?",
                    array_merge([$orderId, $customerId], $earlierParams, [$batchId]))->rowCount();
            }
            if ($orderId) {
                order_recalc($orderId);
            }
            $pdo->commit();
            foreach ($saved as $line => $r) {
                $S['rows'][$line] = $r;
            }
            foreach ($count as $k => $v) {
                $S[$k] += $v;
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('PayU import #' . $batchId . ' group failed: ' . $e->getMessage());
            foreach ($grows as $r) {
                $S['rows'][$r['line']] = ['outcome' => 'failed', 'txnid' => $r['d']['txnid'], 'msg' => 'Not saved because of ' . payu_db_error($e), 'cells' => $r['cells']];
            }
        }
    }

    // 5. Status refreshes of transactions stored earlier (only when "Update statuses" is ticked).
    if ($commit) {
        foreach ($updates as $line => $u) {
            $d = $u['d'];
            $t = $u['t'];
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $set = ['status' => $d['status'], 'status_detail' => $d['status_detail'], 'result' => $d['result'],
                    'raw_data' => json_encode(payu_raw_assoc($headers, $u['cells']), JSON_UNESCAPED_UNICODE)];
                foreach (['success_at', 'settlement_date'] as $k) {
                    if ($d[$k] !== null) {
                        $set[$k] = $d[$k];
                    }
                }
                if ($d['settlement_amount'] !== null) {
                    $set['settlement_amount'] = num2($d['settlement_amount']);
                }
                foreach (['utr', 'bank_ref_no', 'error_code', 'error_message', 'mode', 'bank_name'] as $k) {
                    if ($d[$k] !== '') {
                        $set[$k] = $d[$k];
                    }
                }
                if ((string) $t['mihpayid'] === '' && $d['mihpayid'] !== null) {
                    $set['mihpayid'] = $d['mihpayid'];
                }
                update_row('payu_transactions', $set, 'id = ?', [(int) $t['id']]);
                $orderId = $t['order_id'] ? (int) $t['order_id'] : null;
                $extra = '';
                if (!$orderId && $t['source'] === 'import' && in_array($d['result'], ['success', 'pending', 'refunded'], true)) {
                    list($cid, $created) = payu_import_customer($d, $batchId);
                    $ref = $d['order_ref'] !== '' ? $d['order_ref'] : payu_order_ref_from_productinfo($d['productinfo']);
                    $orderId = payu_import_order_create($d, $ref, $batchId, $cid, payu_match_items($d['productinfo']), (string) $t['added_on'], '');
                    update_row('payu_transactions', ['order_id' => $orderId, 'customer_id' => $cid], 'id = ?', [(int) $t['id']]);
                    $S['orders_new']++;
                    $S['customers_new'] += $created ? 1 : 0;
                    $extra = ' Order ' . val('SELECT order_number FROM orders WHERE id = ?', [$orderId]) . ' created.';
                }
                if ($orderId) {
                    order_recalc($orderId);
                }
                $pdo->commit();
                $S['rows'][$line]['order_id'] = $orderId;
                $S['rows'][$line]['txn_id'] = (int) $t['id'];
                $S['rows'][$line]['msg'] .= $extra;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('PayU import #' . $batchId . ' update failed: ' . $e->getMessage());
                $S['rows'][$line] = ['outcome' => 'failed', 'txnid' => $d['txnid'], 'msg' => 'Status not updated because of ' . payu_db_error($e), 'cells' => $u['cells']];
            }
        }
    }

    ksort($S['rows']);
    foreach ($S['rows'] as $r) {
        $k = in_array($r['outcome'], ['ready', 'imported'], true) ? 'new' : $r['outcome'];
        $S[$k]++;
    }

    if ($commit) {
        $ins = db()->prepare('INSERT INTO import_rows (batch_id, line_no, txnid, outcome, message, order_id, transaction_id, raw_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($S['rows'] as $line => $r) {
            $keepRaw = in_array($r['outcome'], ['failed', 'duplicate'], true);
            $ins->execute([$batchId, $line, str_cut((string) $r['txnid'], 100), $r['outcome'], $r['msg'], $r['order_id'] ?? null, $r['txn_id'] ?? null,
                $keepRaw ? json_encode(array_values($r['cells']), JSON_UNESCAPED_UNICODE) : null]);
        }
        update_row('import_batches', ['status' => 'completed', 'completed_at' => now(), 'total_rows' => $S['rows_total'], 'imported_rows' => $S['new'],
            'updated_rows' => $S['updated'], 'duplicate_rows' => $S['duplicate'], 'failed_rows' => $S['failed'], 'orders_created' => $S['orders_new'],
            'customers_created' => $S['customers_new'], 'options' => json_encode(['window_minutes' => $window / 60, 'update_status' => $updateExisting])], 'id = ?', [$batchId]);
    }
    return $S;
}

/** Removes everything an import created. Not possible when it changed transactions stored before it. */
function payu_import_undo(int $batchId): string
{
    $b = row('SELECT * FROM import_batches WHERE id = ?', [$batchId]);
    if (!$b || $b['status'] !== 'completed') {
        return 'Only a completed import can be undone.';
    }
    if ((int) $b['updated_rows'] > 0) {
        return 'This import changed the status of transactions recorded earlier, so it cannot be undone automatically.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $orderIds = array_map('intval', col('SELECT id FROM orders WHERE import_batch_id = ?', [$batchId]));
        $otherOrders = array_map('intval', col('SELECT DISTINCT order_id FROM payu_transactions WHERE import_batch_id = ? AND order_id IS NOT NULL', [$batchId]));
        $otherOrders = array_values(array_diff($otherOrders, $orderIds));
        foreach ($otherOrders as $oid) {
            $later = (int) val("SELECT import_batch_id FROM orders WHERE id = ? AND source = 'payu_import'", [$oid]);
            if ($later > $batchId) {
                $pdo->rollBack();
                return 'Import #' . $later . ' created orders from this import\'s transactions. Undo that import first.';
            }
        }
        if ($orderIds) {
            $in = placeholders($orderIds);
            q('UPDATE payu_transactions SET order_id = NULL, customer_id = NULL WHERE order_id IN (' . $in . ') AND (import_batch_id IS NULL OR import_batch_id <> ?)', array_merge($orderIds, [$batchId]));
            q('DELETE FROM payu_refunds WHERE order_id IN (' . $in . ')', $orderIds);
            q('DELETE FROM order_items WHERE order_id IN (' . $in . ')', $orderIds);
            q('DELETE FROM orders WHERE id IN (' . $in . ')', $orderIds);
        }
        q('DELETE FROM payu_refunds WHERE import_batch_id = ?', [$batchId]);
        q('DELETE FROM payu_transactions WHERE import_batch_id = ?', [$batchId]);
        foreach ($otherOrders as $oid) {
            order_recalc($oid);
        }
        q('DELETE FROM customers WHERE import_batch_id = ? AND password_hash IS NULL AND id NOT IN (SELECT customer_id FROM orders WHERE customer_id IS NOT NULL)'
            . ' AND id NOT IN (SELECT customer_id FROM payu_transactions WHERE customer_id IS NOT NULL)', [$batchId]);
        q('UPDATE customers SET import_batch_id = NULL WHERE import_batch_id = ?', [$batchId]);
        update_row('import_batches', ['status' => 'undone'], 'id = ?', [$batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Undo of import #' . $batchId . ' failed: ' . $e->getMessage());
        return 'The import could not be undone because of a database error.';
    }
    return '';
}
