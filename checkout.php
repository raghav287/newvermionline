<?php
/** Checkout: contact details and delivery address, then the order is created and the customer goes to PayU. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$lines = cart_lines();
if (!$lines) {
    redirect(url('cart'));
}
$t = cart_totals($lines);
$c = current_customer();
$f = ['name' => $c['name'] ?? '', 'email' => $c['email'] ?? '', 'phone' => $c['phone'] ?? '', 'address_line1' => $c['address_line1'] ?? '',
    'address_line2' => $c['address_line2'] ?? '', 'city' => $c['city'] ?? '', 'state' => $c['state'] ?? '', 'pincode' => $c['pincode'] ?? '', 'note' => ''];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($f) as $k) {
        $f[$k] = trim(str_cut((string) ($_POST[$k] ?? ''), $k === 'note' ? 500 : 255));
    }
    $f['email'] = strtolower($f['email']);
    $phone = clean_phone($f['phone']);
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please check your details and submit again.';
    }
    if (strlen($f['name']) < 2) {
        $errors[] = 'Enter your full name.';
    }
    if (!valid_email($f['email'])) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone === '') {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    }
    if (strlen($f['address_line1']) < 5) {
        $errors[] = 'Enter your house, street and area.';
    }
    if (strlen($f['city']) < 2) {
        $errors[] = 'Enter your city or town.';
    }
    if (!in_array($f['state'], INDIAN_STATES, true)) {
        $errors[] = 'Choose your state.';
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', $f['pincode'])) {
        $errors[] = 'Enter a valid 6-digit PIN code.';
    }
    if (!payu_ready()) {
        $errors[] = 'Online payment is not available right now. Please WhatsApp us to place your order.';
    }
    if (!$errors) {
        try {
            $o = order_create_from_cart(array_merge($f, ['name' => str_cut($f['name'], 120), 'phone' => $phone, 'save_address' => $c && !empty($_POST['save_address'])]), $lines);
            cart_clear();
            redirect(url(order_pay_link(['id' => $o['id'], 'order_number' => $o['number']], true)));
        } catch (Throwable $e) {
            error_log('Checkout failed: ' . $e->getMessage());
            $errors[] = 'We could not save your order. Please try again.';
        }
    }
}

page_start('Checkout', ['noindex' => true]);
page_head('Checkout', ['Cart' => url('cart'), 'Checkout' => null]);
?>
<section class="section">
<form class="wrap checkout" method="post" action="<?= e(url('checkout')) ?>" novalidate>
  <?= csrf_field() ?>
  <div>
    <?php if ($errors): ?><div class="notice notice-err" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
    <?php if (!$c): ?><p class="notice notice-info">Have an account? <a href="<?= e(url('sign-in?next=checkout')) ?>">Sign in</a> to fill in your saved address. Otherwise, continue as a guest.</p><?php endif; ?>
    <div class="panel">
      <h2>Contact details</h2>
      <div class="form-grid">
        <div class="field full"><label for="name">Full name</label><input class="input" id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e($f['name']) ?>"></div>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f['email']) ?>"></div>
        <div class="field"><label for="phone">Mobile number</label><input class="input" id="phone" name="phone" type="tel" required inputmode="tel" autocomplete="tel" value="<?= e($f['phone']) ?>"></div>
      </div>
      <p class="hint">We send order updates to this email and call this number if the courier needs directions.</p>
    </div>
    <div class="panel">
      <h2>Delivery address</h2>
      <div class="form-grid">
        <div class="field full"><label for="address_line1">House, street and area</label><input class="input" id="address_line1" name="address_line1" required maxlength="255" autocomplete="address-line1" value="<?= e($f['address_line1']) ?>"></div>
        <div class="field full"><label for="address_line2">Landmark <span class="muted">(optional)</span></label><input class="input" id="address_line2" name="address_line2" maxlength="255" autocomplete="address-line2" value="<?= e($f['address_line2']) ?>"></div>
        <div class="field"><label for="city">City or town</label><input class="input" id="city" name="city" required maxlength="80" autocomplete="address-level2" value="<?= e($f['city']) ?>"></div>
        <div class="field"><label for="state">State</label>
          <select class="select" id="state" name="state" required autocomplete="address-level1"><option value="">Select state</option>
            <?php foreach (INDIAN_STATES as $s): ?><option<?= $f['state'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="pincode">PIN code</label><input class="input" id="pincode" name="pincode" required inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" autocomplete="postal-code" value="<?= e($f['pincode']) ?>"></div>
        <div class="field"><label>Country</label><input class="input" value="India" disabled></div>
      </div>
      <?php if ($c): ?><label class="check"><input type="checkbox" name="save_address" value="1" checked><span>Save this address to my account</span></label><?php endif; ?>
      <div class="field"><label for="note">Delivery notes <span class="muted">(optional)</span></label><textarea class="textarea" id="note" name="note" maxlength="500" rows="2" placeholder="Unloading instructions or a good time to deliver"><?= e($f['note']) ?></textarea></div>
    </div>
  </div>
  <aside class="summary">
    <h2>Your order</h2>
    <ul class="summary-items">
      <?php foreach ($lines as $l): ?>
        <li><img src="<?= e($l['image']) ?>" alt="" width="52" height="52"><span><?= e($l['name']) ?><br><span class="hint"><?= $l['loose'] ? number_format($l['qty']) . ' kg at ' . e($l['size']) : e(trim($l['size'] . ' × ' . $l['qty'], ' ×')) ?></span></span><strong><?= money($l['total']) ?></strong></li>
      <?php endforeach; ?>
    </ul>
    <dl class="sum-lines">
      <div><dt>Items</dt><dd><?= money($t['subtotal'] + $t['savings']) ?></dd></div>
      <?php if ($t['savings'] > 0): ?><div class="sum-save"><dt><?= e(offer_badge()) ?></dt><dd>-<?= money($t['savings']) ?></dd></div><?php endif; ?>
      <div><dt>Shipping</dt><dd><?= $t['shipping'] > 0 ? money($t['shipping']) : 'Free' ?></dd></div>
      <div class="sum-total"><dt>Total</dt><dd><?= money($t['total']) ?></dd></div>
    </dl>
    <button class="btn btn-block" type="submit">Pay <?= money($t['total']) ?> securely</button>
    <p class="terms-note">You pay on PayU's secure page by UPI, card or net banking. By placing this order you agree to our <a href="<?= e(url('terms-and-conditions')) ?>">terms</a> and <a href="<?= e(url('refund-policy')) ?>">refund policy</a>.</p>
  </aside>
</form>
</section>
<?php page_end();
