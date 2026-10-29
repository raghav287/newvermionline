<?php
/**
 * Shop logic shared by the storefront and the admin panel:
 * store-wide offer, loose compost pricing, shipping, the session cart, customers,
 * order creation, PayU hashes and the payment-status calculation.
 */

const PAYMENT_STATUSES = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded', 'partially_refunded' => 'Partly refunded'];
const ORDER_STATUSES = ['pending' => 'New', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'not_recorded' => 'Not recorded'];
const TXN_RESULTS = ['success' => 'Success', 'failure' => 'Failed', 'pending' => 'Pending', 'refunded' => 'Refunded'];

function store_boot(): void
{
    session_begin('VSSHOP', base_path() . '/');
}

/* ---------- customer session ---------- */

function customer_id(): ?int
{
    return isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;
}

function current_customer(): ?array
{
    static $c = false;
    if ($c === false) {
        $c = customer_id() ? row('SELECT * FROM customers WHERE id = ?', [customer_id()]) : null;
        if (!$c) {
            unset($_SESSION['customer_id']);
        }
    }
    return $c;
}

function customer_login(array $c): void
{
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int) $c['id'];
    update_row('customers', ['last_login_at' => now()], 'id = ?', [(int) $c['id']]);
}

/* ---------- store-wide offer (Settings > Offer), e.g. "Diwali 30% off" ---------- */

function offer(): array
{
    static $o = null;
    if ($o === null) {
        $pct = max(0.0, min(90.0, (float) setting('offer_percent', 0)));
        $o = [
            'on' => setting('offer_enabled', '0') === '1' && $pct > 0,
            'percent' => $pct,
            'name' => (string) setting('offer_name', 'Offer'),
            'title' => (string) setting('offer_title', ''),
            'text' => (string) setting('offer_text', ''),
            'loose' => setting('offer_includes_loose', '1') === '1',
        ];
    }
    return $o;
}

function offer_applies(bool $loose = false): bool
{
    $o = offer();
    return $o['on'] && (!$loose || $o['loose']);
}

function pct_text(float $p): string
{
    return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
}

function offer_badge(): string
{
    return offer()['name'] . ' ' . pct_text(offer()['percent']) . ' off';
}

/** Price the customer pays after the store-wide offer (rounded per unit, like the live site). */
function sale_price(float $price, bool $loose = false): float
{
    return offer_applies($loose) ? round($price * (100 - offer()['percent']) / 100, 2) : round($price, 2);
}

/** [price now, crossed-out price or null] for a product. */
function product_prices(array $p): array
{
    $base = (float) $p['price'];
    $now = sale_price($base, $p['kind'] === 'loose');
    $was = null;
    if ($now < $base) {
        $was = $base;
    } elseif ($p['mrp'] !== null && (float) $p['mrp'] > $now) {
        $was = (float) $p['mrp'];
    }
    return [$now, $was];
}

function price_html(float $now, ?float $was): string
{
    $h = '<strong>' . money($now) . '</strong>';
    if ($was !== null && $was > $now) {
        $off = (int) round(($was - $now) / $was * 100);
        $h .= ' <del>' . money($was) . '</del>' . ($off > 0 ? ' <span class="off">' . $off . '% off</span>' : '');
    }
    return '<p class="price">' . $h . '</p>';
}

/* ---------- loose compost by the kilo (Settings > Loose compost) ---------- */

function loose_config(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = array_merge(['enabled' => true, 'min' => 10, 'max' => 500, 'step' => 5, 'slabs' => [[10, 14], [100, 12], [500, 10], [1000, 8]]], setting_json('loose'));
    $c['step'] = max(1, (int) $c['step']);
    $c['min'] = max($c['step'], (int) $c['min']);
    $c['max'] = max($c['min'], (int) $c['max']);
    $slabs = [];
    foreach ((array) $c['slabs'] as $s) {
        if (is_array($s) && count($s) >= 2 && (float) $s[1] > 0) {
            $slabs[] = [max(0, (int) $s[0]), round((float) $s[1], 2)];
        }
    }
    usort($slabs, function ($a, $b) { return $a[0] - $b[0]; });
    if (!$slabs) {
        $slabs = [[$c['min'], 14.0]];
    }
    $slabs[0][0] = $c['min'];
    $c['slabs'] = $slabs;
    $c['product'] = row("SELECT * FROM products WHERE kind = 'loose' AND status = 'active' ORDER BY id LIMIT 1");
    $c['enabled'] = !empty($c['enabled']) && $c['product'] !== null;
    return $c;
}

function loose_clamp($kg): int
{
    $c = loose_config();
    $kg = (int) (round(((float) $kg) / $c['step']) * $c['step']);
    return max($c['min'], min($c['max'], $kg));
}

function loose_base_rate(int $kg): float
{
    $rate = loose_config()['slabs'][0][1];
    foreach (loose_config()['slabs'] as $s) {
        if ($kg >= $s[0]) {
            $rate = $s[1];
        }
    }
    return (float) $rate;
}

function loose_rate(int $kg): float
{
    return sale_price(loose_base_rate($kg), true);
}

/* ---------- shipping ---------- */

function shipping_for(float $itemsTotal): float
{
    $free = (float) setting('free_shipping_above', 0);
    if ($itemsTotal <= 0 || ($free > 0 && $itemsTotal >= $free)) {
        return 0.0;
    }
    return round((float) setting('shipping_fee', 0), 2);
}

/* ---------- products and images ---------- */

function product_url(array $p): string
{
    return url('shop-details?id=' . (int) $p['id']);
}

function upload_file_ok(string $file): bool
{
    return $file !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $file) && is_file(APP_ROOT . '/assets/uploads/products/' . $file);
}

function product_image(array $p): string
{
    $f = (string) ($p['image'] ?? '');
    return upload_file_ok($f) ? url('assets/uploads/products/' . rawurlencode($f)) : asset('img/placeholder.svg');
}

function product_gallery(array $p): array
{
    $files = array_merge([(string) ($p['image'] ?? '')], (array) json_decode((string) ($p['gallery'] ?? ''), true));
    $out = [];
    foreach ($files as $f) {
        if (is_string($f) && upload_file_ok($f)) {
            $out[] = url('assets/uploads/products/' . rawurlencode($f));
        }
    }
    return $out ? array_values(array_unique($out)) : [asset('img/placeholder.svg')];
}

function product_specs(array $p): array
{
    $s = json_decode((string) ($p['specs'] ?? ''), true);
    return is_array($s) ? $s : [];
}

/** Site picture copied from the reference site (Admin > Tools), or null when it is not there yet. */
function site_img(string $name): ?string
{
    return is_file(APP_ROOT . '/assets/img/' . $name) ? asset('img/' . $name) : null;
}

/* ---------- cart (kept in the session: product id => quantity, plus loose kilos) ---------- */

function cart_raw(): array
{
    $c = $_SESSION['cart'] ?? [];
    return ['items' => (array) ($c['items'] ?? []), 'loose_kg' => (int) ($c['loose_kg'] ?? 0)];
}

function cart_save(array $c): void
{
    $_SESSION['cart'] = $c;
}

function cart_clear(): void
{
    unset($_SESSION['cart']);
}

function cart_count(): int
{
    $c = cart_raw();
    return (int) array_sum(array_map('intval', $c['items'])) + ($c['loose_kg'] > 0 ? 1 : 0);
}

/** Adds a product. Returns an error message, or '' on success. */
function cart_add(int $productId, int $qty): string
{
    $p = row("SELECT * FROM products WHERE id = ? AND status = 'active' AND kind = 'standard'", [$productId]);
    if (!$p) {
        return 'This product is no longer available.';
    }
    $c = cart_raw();
    $new = min(99, max(1, $qty) + (int) ($c['items'][$productId] ?? 0));
    if ($p['stock_qty'] !== null && (int) $p['stock_qty'] < $new) {
        return (int) $p['stock_qty'] > 0 ? 'Only ' . (int) $p['stock_qty'] . ' left in stock.' : 'This product is out of stock.';
    }
    $c['items'][$productId] = $new;
    cart_save($c);
    return '';
}

/** Sets a line ('p12' or 'loose') to a quantity; 0 removes it. */
function cart_set(string $key, int $qty): string
{
    $c = cart_raw();
    if ($key === 'loose') {
        if ($qty <= 0) {
            $c['loose_kg'] = 0;
        } elseif (!loose_config()['enabled']) {
            return 'Loose orders are paused right now.';
        } else {
            $c['loose_kg'] = loose_clamp($qty);
        }
    } elseif (preg_match('/^p(\d+)$/', $key, $m)) {
        $pid = (int) $m[1];
        if ($qty <= 0) {
            unset($c['items'][$pid]);
        } else {
            $p = row('SELECT stock_qty FROM products WHERE id = ?', [$pid]);
            $qty = min(99, $qty);
            if ($p && $p['stock_qty'] !== null && (int) $p['stock_qty'] < $qty) {
                return (int) $p['stock_qty'] > 0 ? 'Only ' . (int) $p['stock_qty'] . ' left in stock.' : 'This product is out of stock.';
            }
            $c['items'][$pid] = $qty;
        }
    }
    cart_save($c);
    return '';
}

/** Cart lines with live prices. Products that disappeared are dropped. */
function cart_lines(): array
{
    $c = cart_raw();
    $lines = [];
    $changed = false;
    if ($c['items']) {
        $ids = array_map('intval', array_keys($c['items']));
        $products = [];
        foreach (rows('SELECT * FROM products WHERE id IN (' . placeholders($ids) . ") AND status = 'active' AND kind = 'standard'", $ids) as $p) {
            $products[(int) $p['id']] = $p;
        }
        foreach ($c['items'] as $pid => $qty) {
            $p = $products[(int) $pid] ?? null;
            $qty = max(1, min(99, (int) $qty));
            if ($p && $p['stock_qty'] !== null && (int) $p['stock_qty'] < $qty) {
                $qty = (int) $p['stock_qty'];
            }
            if (!$p || $qty < 1) {
                unset($c['items'][$pid]);
                $changed = true;
                continue;
            }
            if ($qty !== (int) $c['items'][$pid]) {
                $c['items'][$pid] = $qty;
                $changed = true;
            }
            list($now, ) = product_prices($p);
            $lines[] = ['key' => 'p' . $p['id'], 'product' => $p, 'name' => $p['name'], 'size' => $p['size_label'], 'unit' => 'pc',
                'qty' => $qty, 'unit_price' => $now, 'list_price' => (float) $p['price'], 'total' => round($now * $qty, 2),
                'list_total' => round((float) $p['price'] * $qty, 2), 'image' => product_image($p), 'url' => product_url($p),
                'loose' => false, 'min' => 1, 'max' => 99, 'step' => 1];
        }
    }
    if ($c['loose_kg'] > 0) {
        $lc = loose_config();
        if (!$lc['enabled']) {
            $c['loose_kg'] = 0;
            $changed = true;
        } else {
            $kg = loose_clamp($c['loose_kg']);
            if ($kg !== $c['loose_kg']) {
                $c['loose_kg'] = $kg;
                $changed = true;
            }
            $base = loose_base_rate($kg);
            $rate = loose_rate($kg);
            $lines[] = ['key' => 'loose', 'product' => $lc['product'], 'name' => $lc['product']['name'], 'size' => money($rate) . ' per kg', 'unit' => 'kg',
                'qty' => $kg, 'unit_price' => $rate, 'list_price' => $base, 'total' => round($rate * $kg, 2), 'list_total' => round($base * $kg, 2),
                'image' => site_img('castings.jpg') ?: asset('img/soil.svg'), 'url' => url('#loose'), 'loose' => true,
                'min' => $lc['min'], 'max' => $lc['max'], 'step' => $lc['step']];
        }
    }
    if ($changed) {
        cart_save($c);
    }
    return $lines;
}

function cart_totals(array $lines): array
{
    $sub = 0.0;
    $list = 0.0;
    foreach ($lines as $l) {
        $sub += $l['total'];
        $list += $l['list_total'];
    }
    $ship = shipping_for($sub);
    return ['subtotal' => round($sub, 2), 'savings' => round($list - $sub, 2), 'shipping' => $ship, 'total' => round($sub + $ship, 2)];
}

/** Cart as JSON for the mini-cart drawer. */
function cart_json(): array
{
    $lines = cart_lines();
    $t = cart_totals($lines);
    $out = [];
    foreach ($lines as $l) {
        $out[] = ['key' => $l['key'], 'name' => $l['name'], 'size' => $l['size'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'total' => $l['total'],
            'image' => $l['image'], 'url' => $l['url'], 'min' => $l['min'], 'max' => $l['max'], 'step' => $l['step'], 'loose' => $l['loose']];
    }
    return ['count' => cart_count(), 'lines' => $out, 'subtotal' => $t['subtotal'], 'savings' => $t['savings'], 'shipping' => $t['shipping'], 'total' => $t['total']];
}

/* ---------- orders ---------- */

function new_order_number(): string
{
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) setting('order_prefix', 'ORD'))) ?: 'ORD';
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < 4; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $n = $prefix . '-' . date('ymd') . '-' . $code;
        if (!val('SELECT 1 FROM orders WHERE order_number = ?', [$n])) {
            return $n;
        }
    }
    return $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/** Finds the customer for an email, or creates one (no password). Fills blanks, never overwrites. */
function customer_for_email(string $email, string $name, string $phone, string $source): int
{
    $c = row('SELECT * FROM customers WHERE email = ?', [$email]);
    if ($c) {
        $fill = [];
        if (trim($c['name']) === '' && $name !== '') {
            $fill['name'] = $name;
        }
        if (trim($c['phone']) === '' && $phone !== '') {
            $fill['phone'] = $phone;
        }
        if ($fill) {
            update_row('customers', $fill, 'id = ?', [(int) $c['id']]);
        }
        return (int) $c['id'];
    }
    return insert_row('customers', ['name' => $name, 'email' => $email, 'phone' => $phone, 'source' => $source, 'created_at' => now()]);
}

/** Creates a website order from the cart. $b = buyer and address fields already validated. */
function order_create_from_cart(array $b, array $lines): array
{
    $t = cart_totals($lines);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cid = customer_id() ?: customer_for_email($b['email'], $b['name'], $b['phone'], 'website');
        if (!empty($b['save_address'])) {
            update_row('customers', ['address_line1' => $b['address_line1'], 'address_line2' => $b['address_line2'], 'city' => $b['city'],
                'state' => $b['state'], 'pincode' => $b['pincode']], 'id = ?', [$cid]);
        }
        $number = new_order_number();
        $offerNote = $t['savings'] > 0 ? offer_badge() : '';
        $id = insert_row('orders', [
            'order_number' => $number, 'source' => 'website', 'customer_id' => $cid, 'customer_name' => $b['name'], 'email' => $b['email'],
            'phone' => $b['phone'], 'address_line1' => $b['address_line1'], 'address_line2' => $b['address_line2'], 'city' => $b['city'],
            'state' => $b['state'], 'pincode' => $b['pincode'], 'country' => 'India', 'subtotal' => num2($t['subtotal']),
            'discount' => num2($t['savings']), 'shipping_fee' => num2($t['shipping']), 'total' => num2($t['total']),
            'payment_method' => 'payu', 'payment_status' => 'pending', 'order_status' => 'pending', 'offer_note' => $offerNote,
            'customer_note' => $b['note'] !== '' ? $b['note'] : null, 'placed_at' => now(), 'created_at' => now(),
        ]);
        foreach ($lines as $l) {
            insert_row('order_items', [
                'order_id' => $id, 'product_id' => (int) $l['product']['id'], 'sku' => $l['product']['sku'], 'product_name' => $l['name'],
                'size_label' => $l['loose'] ? '' : $l['size'], 'unit' => $l['unit'], 'quantity' => $l['qty'], 'unit_price' => num2($l['unit_price']),
                'list_price' => num2($l['list_price']), 'line_total' => num2($l['total']), 'match_status' => 'catalog',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['id' => $id, 'number' => $number];
}

function order_view_link(string $number): string
{
    return 'order-status?o=' . rawurlencode($number) . '&t=' . sign('view|' . $number);
}

function order_pay_link(array $o, bool $go = false): string
{
    return 'payu.php?order=' . (int) $o['id'] . '&t=' . sign('pay|' . $o['id'] . '|' . $o['order_number']) . ($go ? '&go=1' : '');
}

/**
 * Works out an order's payment status from its PayU transactions and recorded refunds.
 * Website orders move from New to Processing once paid, and their stock is reduced once.
 * Returns true when the order has just become paid.
 */
function order_recalc(int $orderId): bool
{
    $o = row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$o) {
        return false;
    }
    $paid = 0.0;
    $paidAt = null;
    $pending = false;
    $failed = false;
    $refundStatus = false;
    foreach (rows('SELECT result, amount, success_at, added_on FROM payu_transactions WHERE order_id = ?', [$orderId]) as $t) {
        if ($t['result'] === 'success' || $t['result'] === 'refunded') {
            $paid += (float) $t['amount'];
            $at = $t['success_at'] ?: $t['added_on'];
            if ($paidAt === null || $at < $paidAt) {
                $paidAt = $at;
            }
            $refundStatus = $refundStatus || $t['result'] === 'refunded';
        } elseif ($t['result'] === 'pending') {
            $pending = true;
        } elseif ($t['result'] === 'failure') {
            $failed = true;
        }
    }
    $refunded = (float) val('SELECT COALESCE(SUM(amount), 0) FROM payu_refunds WHERE order_id = ?', [$orderId]);
    if ($paid > 0) {
        if ($refunded > 0 && $refunded < $paid - 0.009) {
            $status = 'partially_refunded';
        } elseif ($refunded > 0 || $refundStatus) {
            $status = 'refunded';
        } elseif ($paid + 0.009 < (float) $o['total']) {
            $status = 'pending';
        } else {
            $status = 'paid';
        }
    } else {
        $status = $pending ? 'pending' : ($failed ? 'failed' : 'pending');
    }
    $set = ['payment_status' => $status, 'paid_at' => $paid > 0 ? $paidAt : null];
    if ($o['source'] === 'website' && $status === 'paid' && $o['order_status'] === 'pending') {
        $set['order_status'] = 'processing';
    }
    if ($o['source'] === 'website' && $status === 'paid' && !(int) $o['stock_deducted']) {
        foreach (rows('SELECT product_id, quantity, unit FROM order_items WHERE order_id = ? AND product_id IS NOT NULL', [$orderId]) as $it) {
            if ($it['unit'] === 'pc') {
                q('UPDATE products SET stock_qty = GREATEST(stock_qty - ?, 0) WHERE id = ? AND stock_qty IS NOT NULL', [(int) $it['quantity'], (int) $it['product_id']]);
            }
        }
        $set['stock_deducted'] = 1;
    }
    update_row('orders', $set, 'id = ?', [$orderId]);
    return $status === 'paid' && $o['payment_status'] !== 'paid';
}

function order_items(int $orderId): array
{
    return rows('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

/** Order confirmation email, sent once a website order is paid. */
function order_email_paid(int $orderId): void
{
    $o = row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$o || setting('order_emails', '1') !== '1') {
        return;
    }
    $rowsHtml = '';
    foreach (order_items($orderId) as $it) {
        $rowsHtml .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . e($it['product_name']) . ($it['size_label'] !== '' ? ' (' . e($it['size_label']) . ')' : '')
            . ' &times; ' . (int) $it['quantity'] . ($it['unit'] === 'kg' ? ' kg' : '') . '</td><td align="right" style="padding:6px 0;border-bottom:1px solid #eee">' . money($it['line_total']) . '</td></tr>';
    }
    $body = '<p>Hi ' . e($o['customer_name']) . ', thank you for your order. We have received your payment of ' . money($o['total']) . '.</p>'
        . '<p><strong>Order number:</strong> ' . e($o['order_number']) . '</p><table width="100%" style="font-size:14px;border-collapse:collapse">' . $rowsHtml
        . '<tr><td style="padding:6px 0">Shipping</td><td align="right">' . money($o['shipping_fee']) . '</td></tr>'
        . '<tr><td style="padding:6px 0"><strong>Total paid</strong></td><td align="right"><strong>' . money($o['total']) . '</strong></td></tr></table>'
        . '<p>Orders are usually dispatched within ' . e(setting('dispatch_time', '7-8 days')) . '.</p>'
        . '<p><a href="' . e(abs_url(order_view_link($o['order_number']))) . '">View your order</a></p>';
    send_mail($o['email'], 'Order ' . $o['order_number'] . ' confirmed', $body);
}

/* ---------- PayU ---------- */

function payu_config(): array
{
    return ['mode' => setting('payu_mode', 'test') === 'live' ? 'live' : 'test', 'key' => trim((string) setting('payu_key', '')), 'salt' => trim((string) setting('payu_salt', ''))];
}

function payu_ready(): bool
{
    $c = payu_config();
    return $c['key'] !== '' && $c['salt'] !== '';
}

function payu_endpoint(): string
{
    return payu_config()['mode'] === 'live' ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';
}

/** sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT) */
function payu_request_hash(array $f, string $salt): string
{
    $seq = [$f['key'], $f['txnid'], $f['amount'], $f['productinfo'], $f['firstname'], $f['email'], $f['udf1'], $f['udf2'], $f['udf3'], $f['udf4'], $f['udf5']];
    return strtolower(hash('sha512', implode('|', $seq) . '||||||' . $salt));
}

/** sha512([additionalCharges|]SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key) */
function payu_response_hash(array $p, string $salt): string
{
    $g = function ($k) use ($p) { return (string) ($p[$k] ?? ''); };
    $s = $salt . '|' . $g('status') . '||||||' . $g('udf5') . '|' . $g('udf4') . '|' . $g('udf3') . '|' . $g('udf2') . '|' . $g('udf1')
        . '|' . $g('email') . '|' . $g('firstname') . '|' . $g('productinfo') . '|' . $g('amount') . '|' . $g('txnid') . '|' . $g('key');
    if ($g('additionalCharges') !== '') {
        $s = $g('additionalCharges') . '|' . $s;
    }
    return strtolower(hash('sha512', $s));
}

/** Reads any PayU status word (captured, dropped, success, userCancelled, ...). Returns '' when unknown. */
function payu_result(string $status): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $words = [
            'success' => ['captured', 'success', 'successful', 'completed', 'complete', 'settled', 'paid'],
            'failure' => ['failed', 'failure', 'fail', 'dropped', 'bounced', 'usercancelled', 'cancelled', 'canceled', 'cancelledbyuser',
                'timeout', 'timedout', 'rejected', 'declined', 'pgfailure', 'aborted', 'error'],
            'pending' => ['pending', 'initiated', 'inprogress', 'processing', 'auth', 'authorized', 'authorised', 'notstarted', 'awaiting', 'onhold', 'hold'],
            'refunded' => ['refunded', 'refund', 'partiallyrefunded', 'partialrefund', 'autorefund', 'autorefunded', 'chargeback', 'chargebacked', 'reversed', 'reversal'],
        ];
        foreach ($words as $result => $list) {
            foreach ($list as $w) {
                $map[$w] = $result;
            }
        }
    }
    return $map[strtolower((string) preg_replace('/[^a-z]/i', '', $status))] ?? '';
}
