<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$v = ['name' => '', 'email' => '', 'phone' => '', 'subject' => 'General enquiry', 'message' => ''];
$errors = [];
$subjects = ['General enquiry', 'Bulk or loose compost', 'An existing order', 'Returns and refunds'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($v) as $k) {
        $v[$k] = trim(str_cut((string) ($_POST[$k] ?? ''), $k === 'message' ? 2000 : 160));
    }
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please send the form again.';
    }
    if (($_POST['website'] ?? '') !== '') {
        $errors[] = 'Your message could not be sent.';
    }
    if (strlen($v['name']) < 2) {
        $errors[] = 'Enter your name.';
    }
    if (!valid_email($v['email'])) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($v['phone'] !== '' && clean_phone($v['phone']) === '') {
        $errors[] = 'Enter a valid 10-digit mobile number, or leave it blank.';
    }
    if (strlen($v['message']) < 10) {
        $errors[] = 'Tell us a little more in your message.';
    }
    if (!$errors && throttle_blocked('contact', '', 5, 5)) {
        $errors[] = 'You have sent several messages already. Please wait a few minutes.';
    }
    if (!$errors) {
        throttle_hit('contact');
        insert_row('contact_messages', ['name' => str_cut($v['name'], 120), 'email' => strtolower($v['email']), 'phone' => clean_phone($v['phone']),
            'subject' => in_array($v['subject'], $subjects, true) ? $v['subject'] : 'General enquiry', 'message' => $v['message'], 'created_at' => now()]);
        flash('Thank you. Your message has been sent and we will get back to you soon.');
        redirect(url('contact'));
    }
}
$wa = whatsapp_link();
page_start('Contact us', ['active' => 'contact']);
page_head('Contact us', ['Contact' => null], 'Questions about an order, bulk loose compost or delivery? We reply within one working day.');
?>
<section class="section">
  <div class="wrap contact-grid">
    <ul class="contact-list">
      <?php if ($wa): ?><li><?= icon('message') ?><span><strong>WhatsApp</strong><a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= e(setting('store_phone')) ?></a></span></li><?php endif; ?>
      <li><?= icon('mail') ?><span><strong>Email</strong><a href="mailto:<?= e(setting('store_email')) ?>"><?= e(setting('store_email')) ?></a></span></li>
      <li><?= icon('pin') ?><span><strong>Office</strong><?= e(setting('legal_name')) ?><br><?= e(setting('address')) ?></span></li>
      <li><?= icon('clock') ?><span><strong>Support hours</strong><?= e(setting('hours')) ?></span></li>
    </ul>
    <div class="panel">
      <h2>Send us a message</h2>
      <?php if ($errors): ?><div class="notice notice-err" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('contact')) ?>">
        <?= csrf_field() ?>
        <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
        <div class="form-grid">
          <div class="field"><label for="c-name">Name</label><input class="input" id="c-name" name="name" required maxlength="120" autocomplete="name" value="<?= e($v['name']) ?>"></div>
          <div class="field"><label for="c-email">Email</label><input class="input" id="c-email" name="email" type="email" required autocomplete="email" value="<?= e($v['email']) ?>"></div>
          <div class="field"><label for="c-phone">Mobile <span class="muted">(optional)</span></label><input class="input" id="c-phone" name="phone" type="tel" autocomplete="tel" value="<?= e($v['phone']) ?>"></div>
          <div class="field"><label for="c-subject">Subject</label><select class="select" id="c-subject" name="subject"><?php foreach ($subjects as $s): ?><option<?= $v['subject'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
          <div class="field full"><label for="c-message">Message</label><textarea class="textarea" id="c-message" name="message" required maxlength="2000" rows="5"><?= e($v['message']) ?></textarea></div>
        </div>
        <button class="btn" type="submit">Send message</button>
      </form>
    </div>
  </div>
</section>
<?php page_end();
