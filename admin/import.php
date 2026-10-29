<?php
/** Import PayU file: upload, check the column mapping and the preview, import, then review results, download problem rows or undo. */
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/spreadsheet.php';
require_once __DIR__ . '/../includes/payu_import.php';
$admin = admin_require();

$self = admin_url('import.php');
$bid = (int) ($_GET['batch'] ?? 0);
$batch = $bid ? row('SELECT * FROM import_batches WHERE id = ?', [$bid]) : null;
$opts = ['window' => (int) setting('import_window_minutes', 60)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'upload') {
        $f = $_FILES['file'] ?? null;
        $ext = $f ? strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) : '';
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('Choose the PayU file to upload.', 'err');
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > 20 * 1024 * 1024) {
            flash('The file is too large. Upload files of up to 20 MB, or split the export into smaller date ranges.', 'err');
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            flash('The upload did not complete. Please try again.', 'err');
        } elseif ($ext === 'xls') {
            flash('Old .xls files cannot be read. Open the file and save it as .xlsx or .csv, or download the PayU report again as .xlsx or CSV.', 'err');
        } elseif (!in_array($ext, ['xlsx', 'csv'], true)) {
            flash('Upload the PayU file as .xlsx or .csv.', 'err');
        } else {
            $dir = APP_ROOT . '/storage/imports/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) {
                flash('The file could not be saved. Check that the storage/imports folder is writable.', 'err');
            } else {
                try {
                    $file = payu_load(['stored_file' => $stored]);
                    $newId = insert_row('import_batches', ['file_name' => str_cut(basename((string) $f['name']), 255), 'stored_file' => $stored,
                        'file_hash' => hash_file('sha256', $dir . $stored), 'status' => 'uploaded', 'header_row' => $file['header_row'],
                        'headers' => json_encode($file['headers'], JSON_UNESCAPED_UNICODE), 'mapping' => json_encode(payu_auto_map($file['headers'])),
                        'admin_id' => (int) $admin['id'], 'created_at' => now()]);
                    redirect($self . '?batch=' . $newId);
                } catch (RuntimeException $e) {
                    @unlink($dir . $stored);
                    flash($e->getMessage(), 'err');
                }
            }
        }
        redirect($self);
    }
    if (!$batch) {
        redirect($self);
    }
    $back = $self . '?batch=' . $bid;
    if ($do === 'mapping' && $batch['status'] === 'uploaded') {
        $headers = (array) json_decode((string) $batch['headers'], true);
        $map = [];
        foreach (array_keys(payu_fields()) as $k) {
            $v = (string) ($_POST['map'][$k] ?? '');
            if ($v !== '' && ctype_digit($v) && array_key_exists((int) $v, $headers)) {
                $map[$k] = (int) $v;
            }
        }
        if (count($map) !== count(array_unique($map))) {
            flash('Each column of the file can be used for one field only. Nothing was changed.', 'err');
        } else {
            update_row('import_batches', ['mapping' => json_encode($map)], 'id = ?', [$bid]);
            payu_remember_map($headers, $map);
            flash('Column mapping saved. The preview below uses it, and the next file with the same headings will too.');
        }
        redirect($back);
    }
    if ($do === 'import' && in_array($batch['status'], ['uploaded', 'importing'], true)) {
        if ($batch['status'] === 'importing') {
            update_row('import_batches', ['status' => 'uploaded'], 'id = ?', [$bid]);
        }
        try {
            $S = payu_import_process($bid, true, $opts + ['update_status' => !empty($_POST['update_status'])]);
            flash('Import finished: ' . $S['new'] . ' transaction' . ($S['new'] === 1 ? '' : 's') . ' imported, ' . $S['orders_new'] . ' order' . ($S['orders_new'] === 1 ? '' : 's')
                . ' created, ' . $S['duplicate'] . ' already recorded, ' . $S['failed'] . ' with problems' . ($S['updated'] ? ', ' . $S['updated'] . ' statuses updated' : '') . '.');
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        redirect($back);
    }
    if ($do === 'undo') {
        $err = payu_import_undo($bid);
        flash($err !== '' ? $err : 'Import undone. Its orders, transactions and the customers it created were removed.', $err !== '' ? 'err' : 'ok');
        redirect($back);
    }
    if ($do === 'discard' && $batch['status'] === 'uploaded') {
        @unlink(APP_ROOT . '/storage/imports/' . basename($batch['stored_file']));
        q('DELETE FROM import_batches WHERE id = ?', [$bid]);
        flash('Upload discarded. Nothing was imported.');
        redirect($self);
    }
    redirect($back);
}

$outcomeLabels = ['ready' => ['Ready', 'ok'], 'imported' => ['Imported', 'ok'], 'updated' => ['Status updated', 'info'], 'duplicate' => ['Already recorded', 'muted'], 'failed' => ['Problem', 'bad']];

/* ---------- upload page and history ---------- */
if (!$batch) {
    $history = rows('SELECT b.*, a.name AS admin_name FROM import_batches b LEFT JOIN admins a ON a.id = b.admin_id ORDER BY b.id DESC LIMIT 50');
    admin_header('Import PayU file', 'import');
    ?>
    <div class="two two-wide">
      <section class="panel">
        <h2>Upload a PayU transactions file</h2>
        <p>Use the file exactly as downloaded from the PayU dashboard (Transactions report, .xlsx or .csv). You will see a preview before anything is saved.</p>
        <form method="post" enctype="multipart/form-data" class="upload">
          <?= csrf_field() ?><input type="hidden" name="do" value="upload">
          <input type="file" name="file" required accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
          <button class="btn" type="submit">Upload and preview</button>
        </form>
      </section>
      <section class="panel">
        <h2>How the import works</h2>
        <ul class="plain">
          <li>Columns are matched automatically. You can change the matching on the preview.</li>
          <li>Each PayU row is saved as one payment attempt, with the full original row kept.</li>
          <li>Retries of one purchase (same email and amount within <?= (int) setting('import_window_minutes', 60) ?> minutes) become one order.</li>
          <li>An order is created only when a payment succeeded, is pending or was refunded.</li>
          <li>Transaction IDs and PayU IDs already recorded are skipped, so importing the same file twice is safe.</li>
          <li>Nothing missing from the file is filled in: unknown products, quantities and addresses stay empty.</li>
        </ul>
      </section>
    </div>
    <section class="panel">
      <h2>Earlier imports</h2>
      <?php if (!$history): ?><p class="muted">No imports yet.</p><?php else: ?>
      <div class="tw"><table class="tbl">
        <thead><tr><th>#</th><th>File</th><th>Status</th><th class="num">Rows</th><th class="num">Imported</th><th class="num">Already recorded</th><th class="num">Problems</th><th class="num">Orders created</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?>
          <tr><td><?= (int) $h['id'] ?></td><td><a href="<?= e($self . '?batch=' . (int) $h['id']) ?>"><?= e($h['file_name']) ?></a><br><span class="muted"><?= e(fmt_date($h['created_at'])) ?><?= $h['admin_name'] ? ', ' . e($h['admin_name']) : '' ?></span></td>
            <td><?= e(['uploaded' => 'Not imported yet', 'importing' => 'Interrupted', 'completed' => 'Imported', 'undone' => 'Undone'][$h['status']] ?? $h['status']) ?></td>
            <td class="num"><?= (int) $h['total_rows'] ?></td><td class="num"><?= (int) $h['imported_rows'] ?></td><td class="num"><?= (int) $h['duplicate_rows'] ?></td><td class="num"><?= (int) $h['failed_rows'] ?></td><td class="num"><?= (int) $h['orders_created'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    admin_footer();
    exit;
}

/* ---------- preview (not imported yet) ---------- */
if (in_array($batch['status'], ['uploaded', 'importing'], true)) {
    $error = '';
    $S = null;
    try {
        $S = payu_import_process($bid, false, $opts + ['update_status' => true]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    admin_header('Preview: ' . $batch['file_name'], 'import');
    echo '<p class="crumbs"><a href="' . e($self) . '">Import PayU file</a></p>';
    if ($error !== '') {
        echo '<p class="note note-err">' . e($error) . '</p>';
        admin_footer();
        exit;
    }
    $file = $S['file'];
    $headers = $file['headers'];
    $firstRow = [];
    foreach ($file['rows'] as $line => $cells) {
        if ($line > $file['header_row']) {
            $firstRow = $cells;
            break;
        }
    }
    $prev = row("SELECT id, created_at FROM import_batches WHERE file_hash = ? AND id <> ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$batch['file_hash'], $bid]);
    $changes = count(array_filter($S['rows'], function ($r) { return $r['outcome'] === 'updated'; }));
    $mapped = array_flip($S['map']);
    $unused = [];
    foreach ($headers as $i => $h) {
        if (!isset($mapped[$i])) {
            $unused[] = $h !== '' ? $h : 'column ' . xlsx_col_letter($i);
        }
    }
    $show = in_array($_GET['show'] ?? '', ['failed', 'duplicate', 'updated', 'ready'], true) ? $_GET['show'] : '';
    ?>
    <?php if ($batch['status'] === 'importing'): ?><p class="note note-warn">This import was interrupted before it finished. Importing again is safe: rows that were already saved are skipped.</p><?php endif; ?>
    <?php if ($prev): ?><p class="note note-warn">This exact file was imported before (<a href="<?= e($self . '?batch=' . (int) $prev['id']) ?>">import #<?= (int) $prev['id'] ?></a>, <?= e(fmt_date($prev['created_at'])) ?>). Importing it again only adds rows that are not recorded yet.</p><?php endif; ?>
    <p class="muted">Sheet "<?= e($file['sheet']) ?>", headings on row <?= (int) $file['header_row'] ?>, <?= count($headers) ?> columns, <?= (int) $S['rows_total'] ?> data rows. Nothing has been saved yet.</p>

    <?php if ($S['missing']): ?>
      <p class="note note-err">Choose a column for: <?= e(implode(', ', $S['missing'])) ?>. These are needed for every row.</p>
    <?php else: ?>
    <section class="cards">
      <article class="card"><h2>Ready to import</h2><p class="big"><?= (int) $S['new'] ?></p><p>new PayU transaction<?= $S['new'] === 1 ? '' : 's' ?></p></article>
      <article class="card"><h2>Orders to create</h2><p class="big"><?= (int) $S['orders_new'] ?></p><p><?= (int) $S['customers_new'] ?> new customer<?= $S['customers_new'] === 1 ? '' : 's' ?><?= $S['orders_attached'] ? ', ' . (int) $S['orders_attached'] . ' added to existing orders' : '' ?></p></article>
      <article class="card"><h2>Attempts with no order</h2><p class="big"><?= (int) $S['attempts_only'] ?></p><p>no payment in their group succeeded</p></article>
      <article class="card"><h2>Already recorded</h2><p class="big"><?= (int) $S['duplicate'] ?></p><p>will be skipped<?= $changes ? '; ' . $changes . ' have a newer PayU status' : '' ?></p></article>
      <article class="card<?= $S['failed'] ? ' card-bad' : '' ?>"><h2>Rows with problems</h2><p class="big"><?= (int) $S['failed'] ?></p><p>will not be imported</p></article>
    </section>
    <section class="panel">
      <h2>What the file says about products</h2>
      <p><?= (int) $S['items_matched'] ?> product name<?= $S['items_matched'] === 1 ? '' : 's' ?> matched the catalogue, <?= (int) $S['items_unmatched'] ?> did not, and <?= (int) $S['orders_without_items'] ?> of <?= (int) $S['orders_new'] ?> new order<?= $S['orders_new'] === 1 ? ' has' : 's have' ?> no product details in the file. Those orders are created without items rather than with guessed ones.</p>
      <?php if ($S['earlier_linked']): ?><p><?= (int) $S['earlier_linked'] ?> failed attempt<?= $S['earlier_linked'] === 1 ? '' : 's' ?> from earlier imports will be linked to the new orders they belong to.</p><?php endif; ?>
      <?php if ($S['dup_payments']): ?><p class="note note-warn"><?= (int) $S['dup_payments'] ?> order<?= $S['dup_payments'] === 1 ? ' looks' : 's look' ?> like a customer paid twice. They are imported and flagged so you can check and refund in PayU.</p><?php endif; ?>
    </section>
    <form method="post" class="panel import-go">
      <?= csrf_field() ?><input type="hidden" name="do" value="import">
      <label class="chk"><input type="checkbox" name="update_status" value="1"<?= $changes ? '' : ' disabled' ?>> Update statuses: apply this file's newer PayU status to <?= $changes ?> transaction<?= $changes === 1 ? '' : 's' ?> already recorded<?= $changes ? '' : ' (none in this file)' ?></label>
      <div class="actions">
        <button class="btn" type="submit"<?= $S['new'] || $changes ? '' : ' disabled' ?>>Import <?= (int) $S['new'] ?> transaction<?= $S['new'] === 1 ? '' : 's' ?></button>
        <button class="btn btn-ghost" type="submit" form="discard-form">Discard upload</button>
      </div>
    </form>
    <?php endif; ?>
    <form method="post" id="discard-form" data-confirm="Discard this upload? Nothing has been imported from it."><?= csrf_field() ?><input type="hidden" name="do" value="discard"></form>

    <details class="panel"<?= $S['missing'] ? ' open' : '' ?>>
      <summary><h2>Column mapping</h2><span class="muted"><?= count($S['map']) ?> of <?= count($headers) ?> columns used; the rest are kept in each transaction's original data.</span></summary>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="mapping">
        <div class="tw"><table class="tbl tbl-tight">
          <thead><tr><th>Field</th><th>Column in your file</th><th>First row's value</th></tr></thead>
          <tbody>
          <?php foreach (payu_fields() as $k => $fd): $cur = $S['map'][$k] ?? null; ?>
            <tr<?= $cur === null && !$fd[1] ? ' class="dim"' : '' ?>>
              <td><?= e($fd[0]) ?><?= $fd[1] ? ' <span class="req">required</span>' : '' ?></td>
              <td><select name="map[<?= e($k) ?>]" aria-label="<?= e($fd[0]) ?>"><option value="">Not used</option><?php foreach ($headers as $i => $h): ?><option value="<?= (int) $i ?>"<?= $cur === $i ? ' selected' : '' ?>><?= e(xlsx_col_letter($i) . ': ' . ($h !== '' ? $h : '(no heading)')) ?></option><?php endforeach; ?></select></td>
              <td class="mono"><?= $cur !== null ? e(str_cut((string) ($firstRow[$cur] ?? ''), 60)) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="muted">Not used: <?= e(implode(', ', $unused) ?: 'none') ?>.</p>
        <button class="btn" type="submit">Save mapping and preview again</button>
      </form>
    </details>

    <?php if (!$S['missing']): ?>
      <?php if ($S['orders_preview']): ?>
      <section class="panel">
        <h2>Orders that will be created</h2>
        <div class="tw"><table class="tbl">
          <thead><tr><th>Order</th><th>Customer</th><th class="num">Amount</th><th>PayU result</th><th class="num">Attempts</th><th>Products in the file</th></tr></thead>
          <tbody><?php foreach ($S['orders_preview'] as $op): ?>
            <tr><td class="mono"><?= e($op['number']) ?><br><span class="muted"><?= e(fmt_date($op['placed_at'])) ?></span></td><td><?= e($op['name'] ?: '—') ?><br><span class="muted"><?= e($op['email']) ?></span></td>
              <td class="num"><?= money($op['amount']) ?></td><td><?= result_badge($op['result']) ?></td><td class="num"><?= (int) $op['attempts'] ?></td>
              <td><?php if (!$op['items']): ?><span class="muted">None named<?= $op['productinfo'] !== '' ? ' ("' . e($op['productinfo']) . '")' : '' ?></span><?php else: foreach ($op['items'] as $it): ?><?= $it['product_id'] ? badge('Matched', 'ok') : badge('No match', 'warn') ?> <?= e($it['text']) ?><?= $it['qty'] === null ? ' <span class="muted">(quantity not stated)</span>' : '' ?><br><?php endforeach; endif; ?>
                <?= $op['note'] !== '' ? '<span class="flag">' . e($op['note']) . '</span>' : '' ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      </section>
      <?php endif; ?>
      <section class="panel">
        <h2>Every row</h2>
        <nav class="tabs tabs-sm">
          <a href="<?= e(qs(['show' => null])) ?>"<?= $show === '' ? ' aria-current="page"' : '' ?>>All (<?= count($S['rows']) ?>)</a>
          <?php foreach (['failed' => 'Problems', 'duplicate' => 'Already recorded', 'updated' => 'Newer status', 'ready' => 'Ready'] as $k => $label): $n = count(array_filter($S['rows'], function ($r) use ($k) { return $r['outcome'] === $k; })); if (!$n) continue; ?>
            <a href="<?= e(qs(['show' => $k])) ?>"<?= $show === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> (<?= $n ?>)</a>
          <?php endforeach; ?>
        </nav>
        <div class="tw"><table class="tbl">
          <thead><tr><th class="num">Row</th><th>Transaction ID</th><th>Result</th><th>Details</th></tr></thead>
          <tbody><?php $shown = 0; foreach ($S['rows'] as $line => $r): if ($show !== '' && $r['outcome'] !== $show) continue; if (++$shown > 500) break; $lab = $outcomeLabels[$r['outcome']]; ?>
            <tr><td class="num"><?= (int) $line ?></td><td class="mono"><?= e($r['txnid'] !== '' ? $r['txnid'] : '—') ?></td><td><?= badge($lab[0], $lab[1]) ?></td><td><?= e($r['msg']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($shown > 500): ?><p class="muted">Showing the first 500 rows.</p><?php endif; ?>
      </section>
    <?php endif; ?>
    <?php
    admin_footer();
    exit;
}

/* ---------- results of a finished (or undone) import ---------- */
$per = 100;
$show = in_array($_GET['show'] ?? '', ['imported', 'updated', 'duplicate', 'failed'], true) ? $_GET['show'] : '';
$counts = [];
foreach (rows('SELECT outcome, COUNT(*) AS n FROM import_rows WHERE batch_id = ? GROUP BY outcome', [$bid]) as $c) {
    $counts[$c['outcome']] = (int) $c['n'];
}
$where = 'r.batch_id = ?' . ($show !== '' ? ' AND r.outcome = ?' : '');
$params = $show !== '' ? [$bid, $show] : [$bid];
$total = (int) val('SELECT COUNT(*) FROM import_rows r WHERE ' . $where, $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / $per))));
$list = rows('SELECT r.*, o.order_number FROM import_rows r LEFT JOIN orders o ON o.id = r.order_id WHERE ' . $where . ' ORDER BY r.line_no LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$exp = function ($type, $format) use ($bid) { return admin_url('export.php?type=' . $type . '&batch=' . $bid . '&format=' . $format); };

admin_header('Import #' . $bid . ': ' . $batch['file_name'], 'import');
?>
<p class="crumbs"><a href="<?= e($self) ?>">Import PayU file</a></p>
<?php if ($batch['status'] === 'undone'): ?><p class="note note-warn">This import was undone. The rows below show what it had done.</p><?php endif; ?>
<p class="muted">Imported <?= e(fmt_date($batch['completed_at'])) ?> from <?= (int) $batch['total_rows'] ?> rows.</p>
<section class="cards">
  <article class="card"><h2>Imported</h2><p class="big"><?= (int) $batch['imported_rows'] ?></p><p>new PayU transactions</p></article>
  <article class="card"><h2>Orders created</h2><p class="big"><?= (int) $batch['orders_created'] ?></p><p><?= (int) $batch['customers_created'] ?> new customer<?= (int) $batch['customers_created'] === 1 ? '' : 's' ?></p></article>
  <article class="card"><h2>Already recorded</h2><p class="big"><?= (int) $batch['duplicate_rows'] ?></p><p>skipped<?= (int) $batch['updated_rows'] ? '; ' . (int) $batch['updated_rows'] . ' statuses updated' : '' ?></p></article>
  <article class="card<?= (int) $batch['failed_rows'] ? ' card-bad' : '' ?>"><h2>Problems</h2><p class="big"><?= (int) $batch['failed_rows'] ?></p><p>rows not imported</p></article>
</section>
<section class="panel">
  <div class="actions">
    <?php if ((int) $batch['failed_rows']): ?>
      <a class="btn" href="<?= e($exp('import_problems', 'xlsx')) ?>">Download problem rows (Excel)</a>
      <a class="btn btn-ghost" href="<?= e($exp('import_problems', 'csv')) ?>">Problem rows (CSV)</a>
    <?php endif; ?>
    <?php if ((int) $batch['duplicate_rows']): ?><a class="btn btn-ghost" href="<?= e($exp('import_duplicates', 'csv')) ?>">Already-recorded rows (CSV)</a><?php endif; ?>
    <a class="btn btn-ghost" href="<?= e($exp('import_report', 'csv')) ?>">Full row report (CSV)</a>
    <?php if ($batch['status'] === 'completed'): ?>
      <a class="btn btn-ghost" href="<?= e(admin_url('orders.php?batch=' . $bid)) ?>">Orders from this import</a>
      <a class="btn btn-ghost" href="<?= e(admin_url('payments.php?batch=' . $bid)) ?>">Transactions from this import</a>
    <?php endif; ?>
  </div>
  <?php if ((int) $batch['failed_rows']): ?><p class="muted">The problem file has your original columns plus "Import row" and "Problem". Fix the cells, delete the two extra columns if you like, and upload it again: rows already imported are skipped.</p><?php endif; ?>
</section>
<section class="panel">
  <nav class="tabs tabs-sm">
    <a href="<?= e(qs(['show' => null, 'page' => null])) ?>"<?= $show === '' ? ' aria-current="page"' : '' ?>>All (<?= array_sum($counts) ?>)</a>
    <?php foreach (['imported' => 'Imported', 'updated' => 'Status updated', 'duplicate' => 'Already recorded', 'failed' => 'Problems'] as $k => $label): if (empty($counts[$k])) continue; ?>
      <a href="<?= e(qs(['show' => $k, 'page' => null])) ?>"<?= $show === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </nav>
  <div class="tw"><table class="tbl">
    <thead><tr><th class="num">Row</th><th>Transaction ID</th><th>Result</th><th>Details</th><th>Order</th></tr></thead>
    <tbody><?php foreach ($list as $r): $lab = $outcomeLabels[$r['outcome']] ?? [$r['outcome'], '']; ?>
      <tr><td class="num"><?= (int) $r['line_no'] ?></td>
        <td class="mono"><?= $r['transaction_id'] && $batch['status'] === 'completed' ? '<a href="' . e(admin_url('payment.php?id=' . (int) $r['transaction_id'])) . '">' . e($r['txnid']) . '</a>' : e($r['txnid'] !== '' ? $r['txnid'] : '—') ?></td>
        <td><?= badge($lab[0], $lab[1]) ?></td><td><?= e($r['message']) ?></td>
        <td><?= $r['order_number'] ? '<a href="' . e(admin_url('order.php?id=' . (int) $r['order_id'])) . '">' . e($r['order_number']) . '</a>' : '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= pager($total, $page, $per) ?>
</section>
<?php if ($batch['status'] === 'completed'): ?>
  <form method="post" class="danger-zone" data-confirm="Undo this import? Its orders, transactions, refunds and the customers it created will be deleted.">
    <?= csrf_field() ?><input type="hidden" name="do" value="undo">
    <button class="btn btn-danger" type="submit"<?= (int) $batch['updated_rows'] ? ' disabled title="This import changed earlier transactions"' : '' ?>>Undo this import</button>
  </form>
<?php endif; ?>
<?php admin_footer();
