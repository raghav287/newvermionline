<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$id = (int) ($_GET['id'] ?? 0);
$p = $id ? row("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.status = 'active'", [$id]) : null;
if ($p && $p['kind'] === 'loose') {
    redirect(url('#loose'));
}
if (!$p) {
    require __DIR__ . '/404.php';
    exit;
}
list($now, $was) = product_prices($p);
$images = product_gallery($p);
$specs = product_specs($p);
$text = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '</p>'], ' ', (string) $p['description']))));
$short = strlen($text) > 200 ? rtrim(str_cut($text, 200), ' .,') . '…' : $text;
$out = $p['stock_qty'] !== null && (int) $p['stock_qty'] <= 0;
$related = rows("SELECT * FROM products WHERE status = 'active' AND kind = 'standard' AND id <> ? AND category_id <=> ? ORDER BY created_at DESC, id DESC LIMIT 4", [$id, $p['category_id']]);

page_start($p['name'], ['active' => 'shop', 'description' => $short !== '' ? $short : $p['name']]);
page_head($p['name'], $p['category_id'] ? ['Shop' => url('shop'), (string) $p['category_name'] => url('shop?category=' . (int) $p['category_id']), $p['name'] => null] : ['Shop' => url('shop'), $p['name'] => null]);
?>
<section class="section" id="pdp">
  <div class="wrap">
    <div class="pdp">
      <div>
        <div class="gallery-main"><img id="main-img" src="<?= e($images[0]) ?>" alt="<?= e($p['name']) ?>" width="800" height="800"></div>
        <?php if (count($images) > 1): ?>
          <div class="thumbs"><?php foreach ($images as $n => $src): ?><button type="button" data-img="<?= e($src) ?>" aria-current="<?= $n === 0 ? 'true' : 'false' ?>" aria-label="Show image <?= $n + 1 ?>"><img src="<?= e($src) ?>" alt=""></button><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <div class="pdp-info">
        <?php if ($p['category_name']): ?><p class="cat"><?= e($p['category_name']) ?></p><?php endif; ?>
        <?= price_html($now, $was) ?>
        <?php if (offer_applies()): ?><p class="offer-inline"><img src="<?= e(site_img('diwali.svg') ?: asset('img/diya.svg')) ?>" alt="" width="34" height="24"><span><strong><?= e(offer()['title']) ?>.</strong> The price above already includes it.</span></p><?php endif; ?>
        <?php if ($short !== ''): ?><p class="muted"><?= e($short) ?></p><?php endif; ?>
        <?php if ($p['size_label'] !== ''): ?><p class="opt-label">Size: <?= e($p['size_label']) ?></p><?php endif; ?>
        <?php if ($out): ?>
          <p class="stock-out">Out of stock</p>
        <?php else: ?>
          <form method="post" action="<?= e(url('cart')) ?>" id="pdp-form" data-product="<?= (int) $p['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <p class="opt-label"><label for="pdp-qty">Quantity</label></p>
            <div class="qty qty-lg" data-min="1" data-max="<?= $p['stock_qty'] !== null ? max(1, min(99, (int) $p['stock_qty'])) : 99 ?>" data-step="1">
              <button type="button" data-step-by="-1" aria-label="Decrease quantity">&minus;</button>
              <input id="pdp-qty" name="qty" type="number" inputmode="numeric" min="1" max="99" value="1">
              <button type="button" data-step-by="1" aria-label="Increase quantity">+</button>
            </div>
            <div class="buy-row">
              <button class="btn" type="submit" id="pdp-add">Add to cart</button>
              <button class="btn btn-ghost" type="submit" name="buy" value="1" id="pdp-buy">Buy now</button>
            </div>
          </form>
        <?php endif; ?>
        <ul class="assure">
          <li><?= icon('truck') ?>Usually dispatched within <?= e(setting('dispatch_time', '7-8 days')) ?></li>
          <li><?= icon('lock') ?>Secure online payment by PayU</li>
          <li><?= icon('return') ?><span>Replacement or refund for damaged or wrong items (see our <a href="<?= e(url('return-policy')) ?>">return policy</a>)</span></li>
        </ul>
      </div>
    </div>
    <div class="pdp-more">
      <div>
        <h2>Description</h2>
        <div class="rich"><?= trim((string) $p['description']) !== '' ? $p['description'] : '<p class="muted">No description yet.</p>' ?></div>
      </div>
      <div>
        <h2>Details</h2>
        <table class="specs"><tbody>
          <?php foreach ($specs as $s): if (!is_array($s) || count($s) < 2) continue; ?><tr><th scope="row"><?= e($s[0]) ?></th><td><?= e($s[1]) ?></td></tr><?php endforeach; ?>
          <?php if ($p['size_label'] !== ''): ?><tr><th scope="row">Available sizes</th><td><?= e($p['size_label']) ?></td></tr><?php endif; ?>
          <tr><th scope="row">Product code</th><td><?= e($p['sku']) ?></td></tr>
        </tbody></table>
      </div>
    </div>
  </div>
</section>
<?php if ($related): ?>
<section class="section tint">
  <div class="wrap">
    <div class="sec-head"><h2>You may also need</h2></div>
    <div class="grid-products"><?php foreach ($related as $r) { echo product_card($r); } ?></div>
  </div>
</section>
<?php endif; ?>
<?php page_end();
