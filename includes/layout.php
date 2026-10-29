<?php
/** Storefront page chrome (head, offer bar, header, drawers, footer) and the product card. */

function icon(string $name): string
{
    return '<svg class="icon" aria-hidden="true" focusable="false"><use href="#i-' . $name . '"/></svg>';
}

function icon_sprite(): string
{
    $paths = [
        'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'mail' => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="m22 6-10 7L2 6"/>',
        'pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'message' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.5 3h.5a8.5 8.5 0 0 1 8 8z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'truck' => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'hourglass' => '<path d="M5 22h14M5 2h14M17 22v-4.2a2 2 0 0 0-.6-1.4L12 12l-4.4 4.4a2 2 0 0 0-.6 1.4V22M7 2v4.2a2 2 0 0 0 .6 1.4L12 12l4.4-4.4a2 2 0 0 0 .6-1.4V2"/>',
        'return' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/>',
    ];
    $out = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
    foreach ($paths as $id => $d) {
        $out .= '<symbol id="i-' . $id . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</symbol>';
    }
    return $out . '</svg>';
}

function store_logo(): string
{
    return site_img('logo.png') ?: asset('img/logo.svg');
}

function whatsapp_link(): string
{
    $d = preg_replace('/\D+/', '', (string) setting('whatsapp', ''));
    return $d !== '' ? 'https://wa.me/' . $d : '';
}

/** Replaces {tokens} in page content with current settings, so policies always match checkout. */
function page_tokens(string $html, string $updated = ''): string
{
    $L = loose_config();
    $wa = whatsapp_link();
    $phone = e(setting('store_phone'));
    $email = e(setting('store_email'));
    $contact = '<h2>Contact us</h2><p>' . e(setting('legal_name')) . '<br>' . e(setting('address')) . '<br>WhatsApp: '
        . ($wa ? '<a href="' . e($wa) . '">' . $phone . '</a>' : $phone) . '<br>Email: <a href="mailto:' . $email . '">' . $email . '</a><br>Support hours: ' . e(setting('hours')) . '</p>';
    $map = [
        '{store_name}' => e(setting('store_name')), '{legal_name}' => e(setting('legal_name')), '{site_domain}' => e(setting('site_domain')),
        '{email}' => '<a href="mailto:' . $email . '">' . $email . '</a>', '{phone}' => $wa ? '<a href="' . e($wa) . '">' . $phone . '</a>' : $phone,
        '{address}' => e(setting('address')), '{hours}' => e(setting('hours')), '{dispatch_time}' => e(setting('dispatch_time', '7-8 days')),
        '{free_shipping_above}' => money(setting('free_shipping_above', 0)), '{shipping_fee}' => money(setting('shipping_fee', 0)),
        '{loose_min}' => (string) $L['min'], '{loose_max}' => number_format($L['max']), '{contact_block}' => $contact,
        '{updated}' => $updated !== '' ? fmt_date($updated, false) : date('j M Y'),
    ];
    $html = strtr($html, $map);
    return (string) preg_replace_callback('/\{link:([a-z0-9_#\/-]*)\}/i', function ($m) { return e(url($m[1])); }, $html);
}

function page_start(string $title, array $o = []): void
{
    $store = (string) setting('store_name', 'Vermi Compost');
    $full = $title !== '' ? $title . ' | ' . $store : $store . ' | Buy vermicompost online';
    $desc = $o['description'] ?? 'Vermicompost in 5, 10 and 15 kg bags, or loose by the kilo for farms and nurseries. Delivered across India with secure online payment.';
    $active = $o['active'] ?? '';
    $count = cart_count();
    $offer = offer();
    $free = (float) setting('free_shipping_above', 0);
    $wa = whatsapp_link();
    $nav = ['home' => ['', 'Home'], 'shop' => ['shop', 'Shop'], 'loose' => ['#loose', 'Buy loose'], 'about' => ['about_us', 'About'],
        'track' => ['track-order', 'Track order'], 'contact' => ['contact', 'Contact']];
    if (!loose_config()['enabled']) {
        unset($nav['loose']);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?><!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($o['noindex'])): ?><meta name="robots" content="noindex">
<?php endif; ?>
<meta name="base" content="<?= e(base_path()) ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#2a2018">
<link rel="icon" href="<?= e(store_logo()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700&amp;family=Zilla+Slab:wght@600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
</head>
<body<?= !empty($o['body']) ? ' ' . $o['body'] : '' ?>>
<?= icon_sprite() ?>
<a class="skip" href="#main">Skip to content</a>
<?php if ($offer['on']): ?>
<div class="offerbar"><div class="wrap">
  <img src="<?= e(site_img('diwali.svg') ?: asset('img/diya.svg')) ?>" alt="" width="34" height="34">
  <p><strong><?= e($offer['title']) ?>.</strong> <?= e($offer['text']) ?> <a href="<?= e(url('shop')) ?>">Shop now</a></p>
</div></div>
<?php endif; ?>
<div class="topbar"><div class="wrap">
  <p><?= $free > 0 ? 'Free shipping on orders above ' . money($free) : 'Delivery across India' ?></p>
  <ul class="topbar-links">
    <?php if ($wa): ?><li><a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?><span>WhatsApp <?= e(setting('store_phone')) ?></span></a></li><?php endif; ?>
    <li class="hide-sm"><a href="mailto:<?= e(setting('store_email')) ?>"><?= icon('mail') ?><span><?= e(setting('store_email')) ?></span></a></li>
  </ul>
</div></div>
<header class="header"><div class="wrap">
  <button class="icon-btn menu-btn" type="button" data-open="menu-drawer" aria-controls="menu-drawer" aria-label="Open menu"><?= icon('menu') ?></button>
  <a class="brand" href="<?= e(url()) ?>"><img src="<?= e(store_logo()) ?>" alt="" width="48" height="48"><span><?= e($store) ?></span></a>
  <nav class="nav" aria-label="Main">
    <?php foreach ($nav as $key => $item): ?><a href="<?= e(url($item[0])) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($item[1]) ?></a><?php endforeach; ?>
  </nav>
  <div class="header-tools">
    <form class="search" action="<?= e(url('shop')) ?>" method="get" role="search">
      <?= icon('search') ?><input class="input" type="search" name="q" placeholder="Search products" aria-label="Search products" value="<?= e($_GET['q'] ?? '') ?>">
    </form>
    <a class="icon-btn" href="<?= e(url(customer_id() ? 'account' : 'sign-in')) ?>" aria-label="<?= customer_id() ? 'My account' : 'Sign in' ?>"><?= icon('user') ?></a>
    <a class="icon-btn" href="<?= e(url('cart')) ?>" data-open="cart-drawer" aria-label="Cart"><?= icon('cart') ?><span class="count" data-cart-count<?= $count ? '' : ' hidden' ?>><?= $count ?></span></a>
  </div>
</div></header>
<main id="main">
<?php
    foreach (flashes() as $f) {
        echo '<div class="wrap"><p class="notice notice-' . ($f[0] === 'err' ? 'err' : 'ok') . '" role="status">' . e($f[1]) . '</p></div>';
    }
}

/** Title band with breadcrumbs. $crumbs = [label => url or null]. */
function page_head(string $title, array $crumbs = [], string $intro = ''): void
{
    echo '<div class="page-head"><div class="wrap"><ol class="crumbs"><li><a href="' . e(url()) . '">Home</a></li>';
    foreach ($crumbs as $label => $href) {
        echo '<li>' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : '<span aria-current="page">' . e($label) . '</span>') . '</li>';
    }
    echo '</ol><h1>' . e($title) . '</h1>' . ($intro !== '' ? '<p class="lead">' . e($intro) . '</p>' : '') . '</div></div>';
}

function page_end(): void
{
    $store = (string) setting('store_name', 'Vermi Compost');
    $wa = whatsapp_link();
    $policies = ['shipping-policy' => 'Shipping', 'return-policy' => 'Returns', 'refund-policy' => 'Refunds', 'cancellation-policy' => 'Cancellation',
        'privacy-policy' => 'Privacy', 'terms-and-conditions' => 'Terms', 'faq' => 'FAQ'];
    ?>
</main>
<footer class="footer"><div class="wrap">
  <div class="foot-grid">
    <div>
      <p class="foot-brand"><img src="<?= e(store_logo()) ?>" alt="" width="44" height="44"><?= e($store) ?></p>
      <p><?= e(setting('tagline')) ?></p>
      <p class="foot-small"><?= e($store) ?> is a brand of <?= e(setting('legal_name')) ?></p>
    </div>
    <div>
      <h2>Shop</h2>
      <ul>
        <li><a href="<?= e(url('shop')) ?>">All products</a></li>
        <?php if (loose_config()['enabled']): ?><li><a href="<?= e(url('#loose')) ?>">Buy loose compost</a></li><?php endif; ?>
        <li><a href="<?= e(url('track-order')) ?>">Track your order</a></li>
        <li><a href="<?= e(url(customer_id() ? 'account' : 'sign-in')) ?>"><?= customer_id() ? 'My account' : 'Sign in' ?></a></li>
        <li><a href="<?= e(url('about_us')) ?>">About us</a></li>
      </ul>
    </div>
    <div>
      <h2>Policies</h2>
      <ul><?php foreach ($policies as $slug => $label): ?><li><a href="<?= e(url($slug)) ?>"><?= e($label) ?></a></li><?php endforeach; ?></ul>
    </div>
    <div>
      <h2>Contact</h2>
      <ul class="foot-contact">
        <li><?= icon('pin') ?><span><?= e(setting('address')) ?></span></li>
        <?php if ($wa): ?><li><?= icon('message') ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp <?= e(setting('store_phone')) ?></a></li><?php endif; ?>
        <li><?= icon('mail') ?><a href="mailto:<?= e(setting('store_email')) ?>"><?= e(setting('store_email')) ?></a></li>
        <li><?= icon('clock') ?><span><?= e(setting('hours')) ?></span></li>
      </ul>
    </div>
  </div>
  <div class="foot-bottom">
    <p>&copy; <?= date('Y') ?> <?= e(setting('legal_name')) ?> All prices are in Indian rupees (INR).</p>
    <p>Online payments are processed securely by PayU.</p>
  </div>
</div></footer>

<div class="overlay" id="overlay"></div>
<aside class="drawer drawer-right" id="cart-drawer" aria-hidden="true" aria-labelledby="cart-title" role="dialog">
  <div class="drawer-head"><h2 id="cart-title">Your cart</h2><button class="icon-btn" type="button" data-close aria-label="Close cart"><?= icon('x') ?></button></div>
  <div class="drawer-body" id="mini-list"><p class="muted">Loading your cart…</p></div>
  <div class="drawer-foot" id="mini-foot" hidden>
    <dl class="sum-lines"><div><dt>Subtotal</dt><dd id="mini-subtotal"></dd></div></dl>
    <p class="hint">Shipping is added at checkout.</p>
    <a class="btn btn-block" href="<?= e(url('checkout')) ?>">Checkout</a>
    <a class="btn btn-ghost btn-block" href="<?= e(url('cart')) ?>">View cart</a>
  </div>
</aside>
<aside class="drawer drawer-left" id="menu-drawer" aria-hidden="true" aria-label="Menu" role="dialog">
  <div class="drawer-head"><span class="brand"><img src="<?= e(store_logo()) ?>" alt="" width="40" height="40"><span><?= e($store) ?></span></span><button class="icon-btn" type="button" data-close aria-label="Close menu"><?= icon('x') ?></button></div>
  <div class="drawer-body">
    <form class="search" action="<?= e(url('shop')) ?>" method="get" role="search"><?= icon('search') ?><input class="input" type="search" name="q" placeholder="Search products" aria-label="Search products"></form>
    <nav class="mobile-nav" aria-label="Mobile">
      <a href="<?= e(url()) ?>">Home</a><a href="<?= e(url('shop')) ?>">Shop</a>
      <?php if (loose_config()['enabled']): ?><a href="<?= e(url('#loose')) ?>" data-close>Buy loose compost</a><?php endif; ?>
      <a href="<?= e(url('about_us')) ?>">About</a><a href="<?= e(url('track-order')) ?>">Track order</a><a href="<?= e(url('contact')) ?>">Contact</a>
      <a href="<?= e(url(customer_id() ? 'account' : 'sign-in')) ?>"><?= customer_id() ? 'My account' : 'Sign in or create account' ?></a>
    </nav>
    <?php if ($wa): ?><p class="hint"><a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp <?= e(setting('store_phone')) ?></a><br><?= e(setting('hours')) ?></p><?php endif; ?>
  </div>
</aside>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</body>
</html>
<?php
}

function product_card(array $p): string
{
    list($now, $was) = product_prices($p);
    $link = e(product_url($p));
    $out = $p['stock_qty'] !== null && (int) $p['stock_qty'] <= 0;
    return '<article class="card"><a class="card-media" href="' . $link . '" tabindex="-1" aria-hidden="true"><img src="' . e(product_image($p)) . '" alt="" loading="lazy" width="400" height="400">'
        . (offer_applies() ? '<span class="badge-offer">' . e(offer_badge()) . '</span>' : '') . '</a>'
        . '<div class="card-body"><h3 class="card-title"><a href="' . $link . '">' . e($p['name']) . '</a></h3>'
        . ($p['size_label'] !== '' ? '<p class="card-meta">' . e($p['size_label']) . '</p>' : '')
        . price_html($now, $was)
        . ($out ? '<p class="stock-out">Out of stock</p>' : '<button class="btn btn-sm" type="button" data-add="' . (int) $p['id'] . '">Add to cart</button>')
        . '</div></article>';
}
