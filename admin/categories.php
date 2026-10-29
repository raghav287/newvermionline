<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $cid = (int) ($_POST['id'] ?? 0);
    if ($do === 'delete' && $cid) {
        q('UPDATE products SET category_id = NULL WHERE category_id = ?', [$cid]);
        q('DELETE FROM categories WHERE id = ?', [$cid]);
        flash('Category deleted. Its products now have no category.');
    } elseif ($do === 'save') {
        $name = trim(str_cut((string) ($_POST['name'] ?? ''), 120));
        $slug = slugify((string) ($_POST['slug'] ?? '') !== '' ? (string) $_POST['slug'] : $name);
        if (strlen($name) < 2) {
            flash('Enter a category name.', 'err');
        } elseif (val('SELECT id FROM categories WHERE slug = ? AND id <> ?', [$slug, $cid])) {
            flash('Another category already uses the web address "' . $slug . '".', 'err');
        } else {
            $f = ['name' => $name, 'slug' => $slug, 'description' => trim(str_cut((string) ($_POST['description'] ?? ''), 1000)),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0), 'is_active' => !empty($_POST['is_active']) ? 1 : 0];
            if ($cid) {
                update_row('categories', $f, 'id = ?', [$cid]);
            } else {
                $f['created_at'] = now();
                insert_row('categories', $f);
            }
            flash('Category saved.');
        }
    }
    redirect(admin_url('categories.php'));
}
$list = rows('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort_order, c.name');
$edit = !empty($_GET['edit']) ? row('SELECT * FROM categories WHERE id = ?', [(int) $_GET['edit']]) : null;

admin_header('Categories', 'categories');
?>
<div class="two">
  <section class="panel">
    <h2>Categories</h2>
    <div class="tw"><table class="tbl"><thead><tr><th>Name</th><th>Web address</th><th class="num">Products</th><th>Shown</th><th></th></tr></thead><tbody>
      <?php foreach ($list as $c): ?><tr><td><a href="<?= e(admin_url('categories.php?edit=' . (int) $c['id'])) ?>"><?= e($c['name']) ?></a></td><td class="mono">shop?category=<?= (int) $c['id'] ?></td><td class="num"><?= (int) $c['n'] ?></td>
        <td><?= (int) $c['is_active'] ? 'Yes' : 'No' ?></td>
        <td><form method="post" data-confirm="Delete this category? Its products stay, without a category."><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="linkish" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="panel">
    <h2><?= $edit ? 'Edit ' . e($edit['name']) : 'Add a category' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label class="fld"><span>Name</span><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></label>
      <label class="fld"><span>Short name for links (optional)</span><input name="slug" value="<?= e($edit['slug'] ?? '') ?>"></label>
      <label class="fld"><span>Description</span><textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></label>
      <label class="fld"><span>Order in lists (lower comes first)</span><input type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>"></label>
      <label class="chk"><input type="checkbox" name="is_active" value="1"<?= !$edit || (int) $edit['is_active'] ? ' checked' : '' ?>> Show in the shop</label>
      <button class="btn" type="submit">Save category</button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(admin_url('categories.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </section>
</div>
<?php admin_footer();
