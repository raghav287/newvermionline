<?php
/** Admin panel: sign-in guard, page chrome, status badges, list filters and pagination. */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/store.php';

function admin_boot(): void
{
    session_begin('VSADMIN', base_path() . '/admin/', 'Strict');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

/** First line of every admin page: returns the signed-in admin, or sends the visitor to the sign-in page. Checks CSRF on every POST. */
function admin_require(): array
{
    admin_boot();
    $a = !empty($_SESSION['admin_id']) ? row('SELECT * FROM admins WHERE id = ? AND is_active = 1', [(int) $_SESSION['admin_id']]) : null;
    if (!$a || time() - (int) ($_SESSION['admin_seen'] ?? 0) > 7200) {
        unset($_SESSION['admin_id']);
        redirect(url('admin/login.php') . '?next=' . rawurlencode(safe_local((string) ($_SERVER['REQUEST_URI'] ?? ''), url('admin/'))));
    }
    $_SESSION['admin_seen'] = time();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
        flash('That form had expired. Please try again.', 'err');
        redirect(safe_local((string) ($_SERVER['REQUEST_URI'] ?? ''), url('admin/')));
    }
    $GLOBALS['ADMIN'] = $a;
    return $a;
}

function admin_url(string $path = ''): string
{
    return url('admin/' . ltrim($path, '/'));
}

function admin_header(string $title, string $active = ''): void
{
    $a = $GLOBALS['ADMIN'] ?? ['name' => ''];
    $unread = (int) val('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0');
    $nav = [
        'dashboard' => ['index.php', 'Dashboard'], 'orders' => ['orders.php', 'Orders'], 'payments' => ['payments.php', 'PayU payments'],
        'import' => ['import.php', 'Import PayU file'], 'customers' => ['customers.php', 'Customers'], 'products' => ['products.php', 'Products'],
        'categories' => ['categories.php', 'Categories'], 'messages' => ['messages.php', 'Messages'], 'pages' => ['pages.php', 'Pages'],
        'settings' => ['settings.php', 'Settings'], 'admins' => ['admins.php', 'Admin users'], 'tools' => ['tools.php', 'Tools'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> | <?= e(setting('store_name', 'Store')) ?> admin</title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body>
<div class="adm">
  <aside class="adm-side" id="adm-side">
    <a class="adm-brand" href="<?= e(admin_url()) ?>"><img src="<?= e(site_img('logo.png') ?: asset('img/logo.svg')) ?>" alt="" width="34" height="34"><span><?= e(setting('store_name', 'Store')) ?></span></a>
    <nav aria-label="Admin">
      <?php foreach ($nav as $key => $item): ?>
        <a href="<?= e(admin_url($item[0])) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($item[1]) ?><?= $key === 'messages' && $unread ? ' <span class="pill">' . $unread . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-me">
      <p>Signed in as <strong><?= e($a['name']) ?></strong></p>
      <a href="<?= e(url()) ?>" target="_blank" rel="noopener">View store</a>
      <form method="post" action="<?= e(admin_url('logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="linkish">Sign out</button></form>
    </div>
  </aside>
  <div class="adm-main">
    <header class="adm-top"><button class="adm-menu" type="button" data-toggle="adm-side" aria-label="Open menu" aria-controls="adm-side">Menu</button><h1><?= e($title) ?></h1></header>
    <div class="adm-body">
<?php
    foreach (flashes() as $f) {
        echo '<p class="note note-' . ($f[0] === 'err' ? 'err' : 'ok') . '" role="status">' . e($f[1]) . '</p>';
    }
}

function admin_footer(): void
{
    echo '</div></div></div><script src="' . e(asset('js/admin.js')) . '" defer></script></body></html>';
}

/* ---------- small view helpers ---------- */

/** Current query string with some values replaced; empty values are dropped. */
function qs(array $over = []): string
{
    $q = array_merge($_GET, $over);
    $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
    return $q ? '?' . http_build_query($q) : '';
}

function pager(int $total, int $page, int $per): string
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) {
        $h .= '<a href="' . e(qs(['page' => $page - 1])) . '">Previous</a>';
    }
    $h .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $h .= '<a href="' . e(qs(['page' => $page + 1])) . '">Next</a>';
    }
    return $h . '</nav>';
}

function badge(string $text, string $tone = ''): string
{
    return '<span class="b' . ($tone !== '' ? ' b-' . $tone : '') . '">' . e($text) . '</span>';
}

function pay_badge(string $s): string
{
    $t = ['paid' => 'ok', 'pending' => 'warn', 'failed' => 'bad', 'refunded' => 'muted', 'partially_refunded' => 'muted'];
    return badge(PAYMENT_STATUSES[$s] ?? $s, $t[$s] ?? '');
}

function order_badge(string $s): string
{
    $t = ['pending' => 'warn', 'processing' => 'info', 'shipped' => 'info', 'delivered' => 'ok', 'cancelled' => 'bad', 'not_recorded' => 'muted'];
    return badge(ORDER_STATUSES[$s] ?? $s, $t[$s] ?? '');
}

function result_badge(string $r): string
{
    $t = ['success' => 'ok', 'failure' => 'bad', 'pending' => 'warn', 'refunded' => 'muted'];
    return badge(TXN_RESULTS[$r] ?? $r, $t[$r] ?? '');
}

function source_badge(string $s): string
{
    return in_array($s, ['website'], true) ? badge('Website', 'web') : badge('PayU import', 'imp');
}

function valid_ymd($s): bool
{
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
}

function like(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}

/* ---------- list filters (shared by the list pages and the exports) ---------- */

function orders_filter(array $g): array
{
    $w = ['1 = 1'];
    $p = [];
    if (in_array($g['source'] ?? '', ['website', 'payu_import'], true)) {
        $w[] = 'o.source = ?';
        $p[] = $g['source'];
    }
    if (isset(PAYMENT_STATUSES[$g['payment'] ?? ''])) {
        $w[] = 'o.payment_status = ?';
        $p[] = $g['payment'];
    }
    if (isset(ORDER_STATUSES[$g['status'] ?? ''])) {
        $w[] = 'o.order_status = ?';
        $p[] = $g['status'];
    }
    if (valid_ymd($g['from'] ?? '')) {
        $w[] = 'o.placed_at >= ?';
        $p[] = $g['from'] . ' 00:00:00';
    }
    if (valid_ymd($g['to'] ?? '')) {
        $w[] = 'o.placed_at <= ?';
        $p[] = $g['to'] . ' 23:59:59';
    }
    if (!empty($g['batch'])) {
        $w[] = 'o.import_batch_id = ?';
        $p[] = (int) $g['batch'];
    }
    $s = trim((string) ($g['q'] ?? ''));
    if ($s !== '') {
        $w[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.id IN (SELECT order_id FROM payu_transactions WHERE order_id IS NOT NULL AND (txnid = ? OR mihpayid = ?)))';
        array_push($p, like($s), like($s), like($s), like($s), $s, $s);
    }
    return [implode(' AND ', $w), $p];
}

function txn_filter(array $g): array
{
    $w = ['1 = 1'];
    $p = [];
    if (in_array($g['source'] ?? '', ['website', 'import'], true)) {
        $w[] = 't.source = ?';
        $p[] = $g['source'];
    }
    if (isset(TXN_RESULTS[$g['result'] ?? ''])) {
        $w[] = 't.result = ?';
        $p[] = $g['result'];
    }
    if (($g['linked'] ?? '') === 'no') {
        $w[] = 't.order_id IS NULL';
    }
    if (valid_ymd($g['from'] ?? '')) {
        $w[] = 't.added_on >= ?';
        $p[] = $g['from'] . ' 00:00:00';
    }
    if (valid_ymd($g['to'] ?? '')) {
        $w[] = 't.added_on <= ?';
        $p[] = $g['to'] . ' 23:59:59';
    }
    if (!empty($g['batch'])) {
        $w[] = 't.import_batch_id = ?';
        $p[] = (int) $g['batch'];
    }
    $s = trim((string) ($g['q'] ?? ''));
    if ($s !== '') {
        $w[] = '(t.txnid = ? OR t.mihpayid = ? OR t.bank_ref_no = ? OR t.utr = ? OR t.email LIKE ? OR t.phone LIKE ? OR t.firstname LIKE ?)';
        array_push($p, $s, $s, $s, $s, like($s), like($s), like($s));
    }
    return [implode(' AND ', $w), $p];
}

function customers_filter(array $g): array
{
    $w = ['1 = 1'];
    $p = [];
    if (in_array($g['source'] ?? '', ['website', 'payu_import', 'admin'], true)) {
        $w[] = 'c.source = ?';
        $p[] = $g['source'];
    }
    $s = trim((string) ($g['q'] ?? ''));
    if ($s !== '') {
        $w[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
        array_push($p, like($s), like($s), like($s));
    }
    return [implode(' AND ', $w), $p];
}
