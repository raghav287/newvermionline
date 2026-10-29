<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();
http_response_code(404);
page_start('Page not found', ['noindex' => true]);
?>
<section class="section"><div class="wrap">
  <div class="status-card is-wait">
    <div class="status-icon"><?= icon('search') ?></div>
    <h1>We could not find that page</h1>
    <p>The link may be old or mistyped. Try the shop, or search for a product.</p>
    <div class="status-actions"><a class="btn" href="<?= e(url('shop')) ?>">Go to the shop</a><a class="btn btn-ghost" href="<?= e(url()) ?>">Home page</a></div>
  </div>
</div></section>
<?php page_end();
