<?php
/** About, FAQ and policy pages, edited in Admin > Pages. .htaccess maps /about_us, /faq, /shipping-policy and so on here. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$slug = (string) ($_GET['slug'] ?? '');
$pg = preg_match('/^[a-z0-9_-]{1,80}$/', $slug) ? row('SELECT * FROM pages WHERE slug = ?', [$slug]) : null;
if (!$pg) {
    require __DIR__ . '/404.php';
    exit;
}
$policies = rows("SELECT slug, title FROM pages WHERE slug IN ('shipping-policy', 'return-policy', 'refund-policy', 'cancellation-policy', 'privacy-policy', 'terms-and-conditions', 'faq')
    ORDER BY FIELD(slug, 'shipping-policy', 'return-policy', 'refund-policy', 'cancellation-policy', 'privacy-policy', 'terms-and-conditions', 'faq')");
$isPolicy = in_array($slug, array_column($policies, 'slug'), true);
page_start($pg['title'], ['active' => $slug === 'about_us' ? 'about' : '', 'description' => $pg['intro'] !== '' ? $pg['intro'] : $pg['title'] . ' for ' . setting('store_name')]);
page_head($pg['title'], [$pg['title'] => null], $pg['intro']);
?>
<section class="section">
  <div class="wrap<?= $isPolicy ? ' page-cols' : '' ?>">
    <?php if ($isPolicy): ?>
      <nav class="side-nav" aria-label="Policies"><?php foreach ($policies as $p): ?><a href="<?= e(url($p['slug'])) ?>"<?= $p['slug'] === $slug ? ' aria-current="page"' : '' ?>><?= e($p['title']) ?></a><?php endforeach; ?></nav>
    <?php endif; ?>
    <div class="prose<?= $slug === 'faq' ? ' faq' : '' ?>"><?= page_tokens((string) $pg['body'], (string) $pg['updated_at']) ?></div>
  </div>
</section>
<?php page_end();
