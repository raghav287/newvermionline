<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$id = (int) ($_GET['id'] ?? 0);
$p = $id ? row('SELECT * FROM products WHERE id = ?', [$id]) : null;
if ($id && !$p) {
    flash('That product does not exist.', 'err');
    redirect(admin_url('products.php'));
}
$uploadDir = APP_ROOT . '/assets/uploads/products/';

/** Saves one uploaded image after checking it really is an image. Returns the new file name, or '' with a flash error. */
function save_image(array $f): string
{
    global $uploadDir;
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        flash('An image was not saved: images must be under 5 MB.', 'err');
        return '';
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        flash('An image was not saved: use JPG, PNG, WebP or GIF.', 'err');
        return '';
    }
    $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    if (!move_uploaded_file($f['tmp_name'], $uploadDir . $name)) {
        flash('An image could not be saved. Check that assets/uploads/products is writable.', 'err');
        return '';
    }
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['do'] ?? '') === 'delete' && $p) {
        if ($p['kind'] === 'loose') {
            flash('The loose compost item cannot be deleted; switch loose orders off in Settings instead.', 'err');
            redirect(admin_url('product.php?id=' . $id));
        }
        q('DELETE FROM products WHERE id = ?', [$id]);
        flash('Product deleted. Past orders keep their own copy of its name and price.');
        redirect(admin_url('products.php'));
    }
    $f = [
        'name' => trim(str_cut((string) ($_POST['name'] ?? ''), 200)),
        'sku' => strtoupper(trim(str_cut((string) ($_POST['sku'] ?? ''), 60))),
        'size_label' => trim(str_cut((string) ($_POST['size_label'] ?? ''), 60)),
        'category_id' => ($cid = (int) ($_POST['category_id'] ?? 0)) ? $cid : null,
        'price' => round((float) ($_POST['price'] ?? 0), 2),
        'mrp' => trim((string) ($_POST['mrp'] ?? '')) === '' ? null : num2($_POST['mrp']),
        'stock_qty' => trim((string) ($_POST['stock_qty'] ?? '')) === '' ? null : max(0, (int) $_POST['stock_qty']),
        'status' => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
        'description' => trim((string) ($_POST['description'] ?? '')),
        'match_aliases' => trim(str_cut((string) ($_POST['match_aliases'] ?? ''), 2000)),
    ];
    $specs = [];
    foreach ((array) ($_POST['spec_label'] ?? []) as $i => $label) {
        $label = trim(str_cut((string) $label, 80));
        $value = trim(str_cut((string) ($_POST['spec_value'][$i] ?? ''), 500));
        if ($label !== '' && $value !== '') {
            $specs[] = [$label, $value];
        }
    }
    $f['specs'] = $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;
    $errors = [];
    if (strlen($f['name']) < 2) {
        $errors[] = 'Enter the product name.';
    }
    if ($f['sku'] === '' || !preg_match('/^[A-Z0-9._-]+$/', $f['sku'])) {
        $errors[] = 'Enter a product code using letters, numbers, dots or dashes.';
    } elseif (val('SELECT id FROM products WHERE sku = ? AND id <> ?', [$f['sku'], $id])) {
        $errors[] = 'Another product already uses the code ' . $f['sku'] . '.';
    }
    if ($f['price'] <= 0) {
        $errors[] = 'Enter a price above zero.';
    }
    if ($errors) {
        foreach ($errors as $er) {
            flash($er, 'err');
        }
        $_SESSION['product_form'] = $_POST;
        redirect(admin_url('product.php' . ($id ? '?id=' . $id : '')));
    }
    $f['price'] = num2($f['price']);
    $gallery = $p ? (array) json_decode((string) $p['gallery'], true) : [];
    $gallery = array_values(array_diff($gallery, (array) ($_POST['remove_gallery'] ?? [])));
    $main = save_image($_FILES['image'] ?? []);
    if ($main !== '') {
        $f['image'] = $main;
    }
    if (!empty($_FILES['gallery']['name']) && is_array($_FILES['gallery']['name'])) {
        foreach (array_keys($_FILES['gallery']['name']) as $i) {
            $g = save_image(['name' => $_FILES['gallery']['name'][$i], 'tmp_name' => $_FILES['gallery']['tmp_name'][$i], 'error' => $_FILES['gallery']['error'][$i], 'size' => $_FILES['gallery']['size'][$i]]);
            if ($g !== '') {
                $gallery[] = $g;
            }
        }
    }
    $f['gallery'] = $gallery ? json_encode(array_values($gallery)) : null;
    if ($p) {
        update_row('products', $f, 'id = ?', [$id]);
    } else {
        $f['slug'] = slugify($f['name']) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $f['kind'] = 'standard';
        $f['created_at'] = now();
        $id = insert_row('products', $f);
    }
    flash('Product saved.');
    redirect(admin_url('product.php?id=' . $id));
}

$form = $_SESSION['product_form'] ?? null;
unset($_SESSION['product_form']);
$v = $form ?: ($p ?: ['name' => '', 'sku' => '', 'size_label' => '', 'category_id' => '', 'price' => '', 'mrp' => '', 'stock_qty' => '', 'status' => 'active', 'description' => '', 'match_aliases' => '']);
$specs = $form ? [] : ($p ? product_specs($p) : []);
if ($form) {
    foreach ((array) ($form['spec_label'] ?? []) as $i => $label) {
        $specs[] = [$label, $form['spec_value'][$i] ?? ''];
    }
}
$specs = array_pad($specs, max(4, count($specs) + 2), ['', '']);
$cats = rows('SELECT id, name FROM categories ORDER BY name');
$gallery = $p ? (array) json_decode((string) $p['gallery'], true) : [];

admin_header($p ? $p['name'] : 'Add product', 'products');
?>
<p class="crumbs"><a href="<?= e(admin_url('products.php')) ?>">Products</a><?= $p && $p['status'] === 'active' && $p['kind'] === 'standard' ? ' &middot; <a href="' . e(product_url($p)) . '" target="_blank" rel="noopener">View in shop</a>' : '' ?></p>
<?php if ($p && $p['kind'] === 'loose'): ?><p class="note note-info">This is the loose compost item sold through the home-page calculator. Its price per kg comes from the slabs in <a href="<?= e(admin_url('settings.php#loose')) ?>">Settings</a>; the price below is only a fallback.</p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="two two-wide">
  <?= csrf_field() ?>
  <div>
    <section class="panel">
      <h2>Product</h2>
      <div class="grid-form">
        <label class="fld fld-wide"><span>Name</span><input name="name" required maxlength="200" value="<?= e($v['name']) ?>"></label>
        <label class="fld"><span>Product code (SKU)</span><input name="sku" required maxlength="60" value="<?= e($v['sku']) ?>"></label>
        <label class="fld"><span>Size or pack</span><input name="size_label" maxlength="60" value="<?= e($v['size_label']) ?>" placeholder="5 kg"></label>
        <label class="fld"><span>Category</span><select name="category_id"><option value="">None</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $v['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Price (₹, before any store-wide offer)</span><input name="price" type="number" step="0.01" min="0.01" required value="<?= e($v['price']) ?>"></label>
        <label class="fld"><span>MRP to show crossed out (optional)</span><input name="mrp" type="number" step="0.01" min="0" value="<?= e($v['mrp']) ?>"></label>
        <label class="fld"><span>Stock (leave empty to not track)</span><input name="stock_qty" type="number" min="0" value="<?= e($v['stock_qty']) ?>"></label>
        <label class="fld"><span>Shown in shop</span><select name="status"><option value="active">Yes</option><option value="inactive"<?= $v['status'] === 'inactive' ? ' selected' : '' ?>>No, hidden</option></select></label>
      </div>
      <label class="fld"><span>Description (HTML allowed, e.g. &lt;p&gt; and &lt;ul&gt;)</span><textarea name="description" rows="7"><?= e($v['description']) ?></textarea></label>
    </section>
    <section class="panel">
      <h2>Details table</h2>
      <p class="muted">Rows shown under "Details" on the product page, such as kit composition. Empty rows are ignored.</p>
      <?php foreach ($specs as $s): ?>
        <div class="spec-row"><input name="spec_label[]" value="<?= e($s[0]) ?>" placeholder="Label" aria-label="Detail label"><input name="spec_value[]" value="<?= e($s[1]) ?>" placeholder="Value" aria-label="Detail value"></div>
      <?php endforeach; ?>
    </section>
    <section class="panel">
      <h2>Names used in PayU files</h2>
      <p class="muted">The PayU import links a payment to this product when PayU's "productinfo" text equals the name, the product code, or one of these names (one per line).</p>
      <textarea name="match_aliases" rows="3"><?= e($v['match_aliases']) ?></textarea>
    </section>
  </div>
  <div>
    <section class="panel">
      <h2>Images</h2>
      <?php if ($p): ?><img class="preview" src="<?= e(product_image($p)) ?>" alt="Main image"><?php endif; ?>
      <label class="fld"><span>Main image</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></label>
      <?php if ($gallery): ?><p class="muted">More images (tick to remove):</p><div class="gal"><?php foreach ($gallery as $g): ?><label><img src="<?= e(upload_file_ok((string) $g) ? url('assets/uploads/products/' . rawurlencode($g)) : asset('img/placeholder.svg')) ?>" alt=""><input type="checkbox" name="remove_gallery[]" value="<?= e($g) ?>"> Remove</label><?php endforeach; ?></div><?php endif; ?>
      <label class="fld"><span>Add more images</span><input type="file" name="gallery[]" multiple accept="image/jpeg,image/png,image/webp,image/gif"></label>
    </section>
    <div class="panel"><button class="btn btn-wide" type="submit">Save product</button></div>
  </div>
</form>
<?php if ($p && $p['kind'] === 'standard'): ?>
  <form method="post" class="danger-zone" data-confirm="Delete this product? Past orders keep their own copy of it.">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn btn-danger" type="submit">Delete product</button>
  </form>
<?php endif; ?>
<?php admin_footer();
