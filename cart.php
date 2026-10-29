<?php
/** Cart page. Also handles the plain form posts (add, update, remove, loose) used when JavaScript is off. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = '';
    $next = url('cart');
    if (!csrf_ok()) {
        $err = 'Your session expired. Please try again.';
    } else {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'add') {
            $err = cart_add((int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 1));
            if ($err === '' && !empty($_POST['buy'])) {
                $next = url('checkout');
            }
        } elseif ($do === 'update') {
            foreach ((array) ($_POST['qty'] ?? []) as $key => $qty) {
                $err = cart_set((string) $key, (int) $qty) ?: $err;
            }
        } elseif ($do === 'remove') {
            cart_set((string) ($_POST['key'] ?? ''), 0);
        } elseif ($do === 'loose') {
            $err = cart_set('loose', max(1, (int) ($_POST['kg'] ?? 0)));
        }
    }
    if ($err !== '') {
        flash($err, 'err');
    }
    redirect($next);
}

$lines = cart_lines();
$t = cart_totals($lines);
$free = (float) setting('free_shipping_above', 0);
page_start('Your cart', ['noindex' => true, 'body' => 'data-cart-page']);
page_head('Your cart', ['Cart' => null]);
?>
<section class="section">
  <div class="wrap">
  <?php if (!$lines): ?>
    <div class="empty">
      <h2>Your cart is empty</h2>
      <p class="muted">Add a bag of compost, or price a loose order by the kilo.</p>
      <a class="btn" href="<?= e(url('shop')) ?>">Shop products</a>
      <?php if (loose_config()['enabled']): ?><a class="btn btn-ghost" href="<?= e(url('#loose')) ?>">Buy loose compost</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="cart-layout">
      <form method="post" action="<?= e(url('cart')) ?>" id="cart-form">
        <?= csrf_field() ?><input type="hidden" name="do" value="update">
        <ul class="cart-list">
          <?php foreach ($lines as $l): ?>
          <li class="cart-row">
            <a href="<?= e($l['url']) ?>"><img src="<?= e($l['image']) ?>" alt="" width="88" height="88"></a>
            <div>
              <h3><a href="<?= e($l['url']) ?>"><?= e($l['name']) ?></a></h3>
              <p class="hint"><?= $l['loose'] ? e($l['size']) : e(trim($l['size'] . ', ' . money($l['unit_price']) . ' each', ', ')) ?></p>
              <button class="link-btn" type="submit" form="rm-<?= e($l['key']) ?>">Remove</button>
            </div>
            <div class="qty" data-min="<?= $l['min'] ?>" data-max="<?= $l['max'] ?>" data-step="<?= $l['step'] ?>">
              <button type="button" data-step-by="-1" aria-label="Decrease quantity">&minus;</button>
              <input type="number" inputmode="numeric" name="qty[<?= e($l['key']) ?>]" value="<?= $l['qty'] ?>" min="<?= $l['min'] ?>" max="<?= $l['max'] ?>" step="<?= $l['step'] ?>" aria-label="Quantity<?= $l['loose'] ? ' in kg' : '' ?>">
              <button type="button" data-step-by="1" aria-label="Increase quantity">+</button>
            </div>
            <p class="line-total"><?= money($l['total']) ?></p>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="cart-actions"><button class="btn btn-ghost btn-sm" type="submit">Update cart</button><a class="hint" href="<?= e(url('shop')) ?>">Continue shopping</a></div>
      </form>
      <?php foreach ($lines as $l): ?><form id="rm-<?= e($l['key']) ?>" method="post" action="<?= e(url('cart')) ?>" hidden><?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="key" value="<?= e($l['key']) ?>"></form><?php endforeach; ?>
      <aside class="summary">
        <h2>Order summary</h2>
        <dl class="sum-lines">
          <div><dt>Items</dt><dd><?= money($t['subtotal'] + $t['savings']) ?></dd></div>
          <?php if ($t['savings'] > 0): ?><div class="sum-save"><dt><?= e(offer_badge()) ?></dt><dd>-<?= money($t['savings']) ?></dd></div><?php endif; ?>
          <div><dt>Shipping</dt><dd><?= $t['shipping'] > 0 ? money($t['shipping']) : 'Free' ?></dd></div>
          <div class="sum-total"><dt>Total</dt><dd><?= money($t['total']) ?></dd></div>
        </dl>
        <?php if ($free > 0 && $t['shipping'] > 0): ?><p class="hint">Add <?= money($free - $t['subtotal']) ?> more for free shipping.</p><?php endif; ?>
        <a class="btn btn-block" style="margin-top:16px" href="<?= e(url('checkout')) ?>">Checkout</a>
      </aside>
    </div>
  <?php endif; ?>
  </div>
</section>
<?php page_end();
