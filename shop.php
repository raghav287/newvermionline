<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$per = 12;
$cat = (int) ($_GET['category'] ?? 0);
$search = trim(str_cut((string) ($_GET['q'] ?? ''), 80));
$min = is_numeric($_GET['min'] ?? '') ? max(0, (float) $_GET['min']) : null;
$max = is_numeric($_GET['max'] ?? '') ? max(0, (float) $_GET['max']) : null;
$sorts = ['new' => 'Newest first', 'low_high' => 'Price: low to high', 'high_low' => 'Price: high to low', 'name' => 'Name: A to Z'];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
$factor = offer_applies() ? (100 - offer()['percent']) / 100 : 1;

$where = ["p.status = 'active'", "p.kind = 'standard'"];
$params = [];
if ($cat) {
    $where[] = 'p.category_id = ?';
    $params[] = $cat;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($min !== null) {
    $where[] = 'ROUND(p.price * ?, 2) >= ?';
    array_push($params, $factor, $min);
}
if ($max !== null) {
    $where[] = 'ROUND(p.price * ?, 2) <= ?';
    array_push($params, $factor, $max);
}
$whereSql = implode(' AND ', $where);
$orderSql = ['new' => 'p.created_at DESC, p.id DESC', 'low_high' => 'p.price ASC, p.id DESC', 'high_low' => 'p.price DESC, p.id DESC', 'name' => 'p.name ASC'][$sort];
$total = (int) val('SELECT COUNT(*) FROM products p WHERE ' . $whereSql, $params);
$pages = max(1, (int) ceil($total / $per));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$items = rows('SELECT p.* FROM products p WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$cats = rows("SELECT c.id, c.name FROM categories c WHERE c.is_active = 1
    AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.status = 'active' AND p.kind = 'standard') ORDER BY c.sort_order, c.name");
$catName = '';
foreach ($cats as $c) {
    if ((int) $c['id'] === $cat) {
        $catName = $c['name'];
    }
}

function shop_link(array $over): string
{
    global $cat, $search, $min, $max, $sort;
    $q = array_merge(['category' => $cat ?: null, 'q' => $search !== '' ? $search : null, 'min' => $min, 'max' => $max, 'sort' => $sort !== 'new' ? $sort : null], $over);
    $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
    return url('shop') . ($q ? '?' . http_build_query($q) : '');
}

$title = $search !== '' ? 'Search results' : ($catName !== '' ? $catName : 'Shop');
page_start($title, ['active' => 'shop', 'noindex' => $search !== '', 'description' => 'Buy vermicompost bags and organic garden inputs online with secure payment.']);
page_head($title, $title === 'Shop' ? ['Shop' => null] : ['Shop' => url('shop'), $title => null], $search !== '' ? 'Products matching "' . $search . '".' : '');
?>
<section class="section">
  <div class="wrap shop">
    <aside>
      <button class="btn btn-ghost btn-sm filter-toggle" type="button" aria-expanded="false" aria-controls="filters">Filter products</button>
      <div class="filters" id="filters">
        <div>
          <h2>Categories</h2>
          <ul class="cat-list">
            <li><a href="<?= e(shop_link(['category' => null, 'page' => null])) ?>"<?= !$cat ? ' aria-current="true"' : '' ?>>All products</a></li>
            <?php foreach ($cats as $c): ?><li><a href="<?= e(shop_link(['category' => (int) $c['id'], 'page' => null])) ?>"<?= (int) $c['id'] === $cat ? ' aria-current="true"' : '' ?>><?= e($c['name']) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
        <form method="get" action="<?= e(url('shop')) ?>">
          <h2>Price</h2>
          <div class="price-range">
            <input class="input" type="number" name="min" min="0" step="1" placeholder="Min ₹" aria-label="Minimum price" value="<?= $min !== null ? e($min) : '' ?>">
            <input class="input" type="number" name="max" min="0" step="1" placeholder="Max ₹" aria-label="Maximum price" value="<?= $max !== null ? e($max) : '' ?>">
          </div>
          <?php foreach (['category' => $cat ?: null, 'q' => $search !== '' ? $search : null, 'sort' => $sort !== 'new' ? $sort : null] as $k => $v): if ($v === null) continue; ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endforeach; ?>
          <button class="btn btn-sm btn-block" type="submit">Apply</button>
        </form>
        <?php if ($cat || $search !== '' || $min !== null || $max !== null): ?><a class="link-btn" href="<?= e(url('shop')) ?>">Clear all filters</a><?php endif; ?>
      </div>
    </aside>
    <div>
      <?php if (offer_applies()): ?>
        <div class="offer-note"><img src="<?= e(site_img('diwali.svg') ?: asset('img/diya.svg')) ?>" alt="" width="44" height="30"><p><strong><?= e(offer()['title']) ?>.</strong> Prices below already include the discount. <?= e(offer()['text']) ?></p></div>
      <?php endif; ?>
      <div class="toolbar">
        <p><?= $total ?> product<?= $total === 1 ? '' : 's' ?></p>
        <form method="get" action="<?= e(url('shop')) ?>">
          <?php foreach (['category' => $cat ?: null, 'q' => $search !== '' ? $search : null, 'min' => $min, 'max' => $max] as $k => $v): if ($v === null) continue; ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endforeach; ?>
          <label class="sr-only" for="sort">Sort by</label>
          <select class="select" id="sort" name="sort" data-autosubmit>
            <?php foreach ($sorts as $k => $label): ?><option value="<?= $k ?>"<?= $k === $sort ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm" type="submit">Sort</button></noscript>
        </form>
      </div>
      <?php if ($items): ?>
        <div class="grid-products cols-3"><?php foreach ($items as $p) { echo product_card($p); } ?></div>
      <?php else: ?>
        <div class="empty"><h2>No products found</h2><p class="muted">Try another search or clear the filters.</p><a class="btn btn-ghost" href="<?= e(url('shop')) ?>">See all products</a></div>
      <?php endif; ?>
      <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pages">
          <?php if ($page > 1): ?><a href="<?= e(shop_link(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e(shop_link(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $pages): ?><a href="<?= e(shop_link(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_end();
