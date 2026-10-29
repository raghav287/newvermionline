<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$w = ['1 = 1'];
$p = [];
if (!empty($_GET['category'])) {
    $w[] = 'p.category_id = ?';
    $p[] = (int) $_GET['category'];
}
if (in_array($_GET['status'] ?? '', ['active', 'inactive'], true)) {
    $w[] = 'p.status = ?';
    $p[] = $_GET['status'];
}
$s = trim((string) ($_GET['q'] ?? ''));
if ($s !== '') {
    $w[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    array_push($p, like($s), like($s));
}
$list = rows('SELECT p.*, c.name AS category_name, (SELECT COUNT(*) FROM order_items i WHERE i.product_id = p.id) AS sold_lines
    FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.kind DESC, p.created_at DESC, p.id DESC', $p);
$cats = rows('SELECT id, name FROM categories ORDER BY name');

admin_header('Products', 'products');
?>
<form class="filters" method="get">
  <label class="fld"><span>Search</span><input type="search" name="q" value="<?= e($s) ?>" placeholder="Name or product code"></label>
  <label class="fld"><span>Category</span><select name="category"><option value="">Any</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($_GET['category'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  <label class="fld"><span>Status</span><select name="status"><option value="">Any</option><option value="active"<?= ($_GET['status'] ?? '') === 'active' ? ' selected' : '' ?>>Shown in shop</option><option value="inactive"<?= ($_GET['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Hidden</option></select></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn" href="<?= e(admin_url('product.php')) ?>">Add product</a></div>
</form>
<p class="muted"><?= count($list) ?> product<?= count($list) === 1 ? '' : 's' ?><?= offer_applies() ? '. ' . e(offer_badge()) . ' is on, so customers see the sale price.' : '' ?></p>
<div class="tw"><table class="tbl">
  <thead><tr><th></th><th>Product</th><th>Code</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($list as $pr): list($now, $was) = product_prices($pr); ?>
    <tr>
      <td><img class="thumb" src="<?= e(product_image($pr)) ?>" alt="" width="44" height="44"></td>
      <td><a href="<?= e(admin_url('product.php?id=' . (int) $pr['id'])) ?>"><strong><?= e($pr['name']) ?></strong></a><br><span class="muted"><?= e($pr['size_label']) ?><?= $pr['kind'] === 'loose' ? ' (loose compost calculator)' : '' ?></span></td>
      <td class="mono"><?= e($pr['sku']) ?></td>
      <td><?= e($pr['category_name'] ?? '—') ?></td>
      <td class="num"><?= $pr['kind'] === 'loose' ? '<span class="muted">Per-kg slabs</span>' : money($pr['price']) . ($now < (float) $pr['price'] ? '<br><span class="muted">Sells at ' . money($now) . '</span>' : '') ?></td>
      <td class="num"><?= $pr['stock_qty'] === null ? '<span class="muted">Not tracked</span>' : (int) $pr['stock_qty'] ?></td>
      <td><?= $pr['status'] === 'active' ? badge('Shown', 'ok') : badge('Hidden', 'muted') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php admin_footer();
