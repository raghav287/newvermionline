<?php
/**
 * Bootstrap shared by the shop, the admin panel and the installer.
 * Loads config.php, connects to MySQL and defines the small helpers used everywhere.
 * Plain PHP 7.4+ (no PHP 8-only syntax). No framework and no Composer packages.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(APP_ROOT . '/storage/logs') && is_writable(APP_ROOT . '/storage/logs')) {
    ini_set('error_log', APP_ROOT . '/storage/logs/php-errors.log');
}
error_reporting(E_ALL);

$GLOBALS['APP_CONFIG'] = is_file(APP_ROOT . '/config.php') ? require APP_ROOT . '/config.php' : null;
if (!is_array($GLOBALS['APP_CONFIG']) && !defined('APP_INSTALLER')) {
    header('Location: ' . app_detect_base() . '/install.php');
    exit;
}
if (config('debug')) {
    ini_set('display_errors', '1');
}

const INDIAN_STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
    'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
    'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra',
    'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
    'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];

/* ---------- configuration and URLs ---------- */

function config(string $key, $default = null)
{
    $c = is_array($GLOBALS['APP_CONFIG'] ?? null) ? $GLOBALS['APP_CONFIG'] : [];
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

/** Folder the site lives in, worked out from the running script ('' at the domain root). */
function app_detect_base(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = preg_replace('#/admin$#', '', $dir);
    return rtrim($dir, '/.');
}

function base_path(): string
{
    return rtrim((string) config('base_path', app_detect_base()), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    return url('assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** Absolute URL for emails and PayU. Uses the site URL saved at install, never the visitor's Host header. */
function abs_url(string $path = ''): string
{
    $origin = rtrim((string) config('site_url', ''), '/');
    if ($origin === '') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $origin = (is_https() ? 'https://' : 'http://') . $host . base_path();
    }
    return $origin . '/' . ltrim($path, '/');
}

/** Accepts only a path on this site; blocks open redirects such as //evil.example. */
function safe_local(string $to, string $fallback): string
{
    return (preg_match('#^/(?![/\\\\])#', $to) && strpbrk($to, "\r\n") === false) ? $to : $fallback;
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- database (PDO, prepared statements only) ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . config('db_host', 'localhost') . ';port=' . (int) config('db_port', 3306)
            . ';dbname=' . config('db_name', '') . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, (string) config('db_user', ''), (string) config('db_pass', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(503);
            exit('<!doctype html><meta charset="utf-8"><title>Back soon</title><p style="font:17px/1.6 system-ui,sans-serif;padding:40px">The store is down for a few minutes of maintenance. Please try again shortly.</p>');
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function col(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function insert_row(string $table, array $data): int
{
    $cols = array_keys($data);
    q('INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . placeholders($cols) . ')', array_values($data));
    return (int) db()->lastInsertId();
}

function update_row(string $table, array $data, string $where, array $params = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '` = ?';
    }
    return q('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where, array_merge(array_values($data), array_values($params)))->rowCount();
}

function placeholders(array $values): string
{
    return implode(', ', array_fill(0, max(1, count($values)), '?'));
}

/* ---------- formatting ---------- */

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Indian rupees: ₹1,04,930 / ₹1,049.30 / ₹84 (paise only when there are any). NULL shows a dash. */
function money($n): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    $n = round((float) $n, 2);
    $paise = (int) round(abs($n) * 100);
    $int = (string) intdiv($paise, 100);
    $rest = $paise % 100;
    if (strlen($int) > 3) {
        $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
    }
    return ($n < 0 ? '-' : '') . '₹' . $int . ($rest ? '.' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
}

function num2($n): string
{
    return number_format((float) $n, 2, '.', '');
}

function fmt_date($dt, bool $time = true): string
{
    $t = $dt ? strtotime((string) $dt) : false;
    return $t ? date($time ? 'j M Y, g:i A' : 'j M Y', $t) : '—';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function str_cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function slugify(string $s): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? substr($s, 0, 200) : 'item';
}

/** 10-digit Indian mobile number, or '' when the input is not one. */
function clean_phone(string $raw): string
{
    $d = (string) preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') {
        $d = substr($d, 2);
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}

function valid_email(string $e): bool
{
    return strlen($e) <= 190 && (bool) filter_var($e, FILTER_VALIDATE_EMAIL);
}

/* ---------- settings (key/value table, edited in Admin > Settings) ---------- */

function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        try {
            foreach (rows('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) {
            $cache = [];
        }
    }
    return $cache;
}

function setting(string $key, $default = '')
{
    $s = settings_all();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

function setting_json(string $key, array $default = []): array
{
    $v = json_decode((string) setting($key, ''), true);
    return is_array($v) ? $v : $default;
}

function settings_save(array $values): void
{
    $st = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
    foreach ($values as $k => $v) {
        $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        $st->execute([$k, $v, $v]);
    }
    settings_all(true);
}

/* ---------- sessions, CSRF, flash messages, signed links ---------- */

function session_begin(string $name, string $path, string $sameSite = 'Lax'): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($name);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => $sameSite]);
    } else {
        session_set_cookie_params(0, $path . '; samesite=' . $sameSite, '', is_https(), true);
    }
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $t);
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['_flash'][] = [$type, $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function sign(string $data): string
{
    return hash_hmac('sha256', $data, (string) config('app_secret', 'not-installed'));
}

function sign_ok(string $data, string $signature): bool
{
    return $signature !== '' && hash_equals(sign($data), $signature);
}

/* ---------- sign-in throttling (login_attempts table) ---------- */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function throttle_hit(string $scope, string $email = ''): void
{
    insert_row('login_attempts', ['scope' => $scope, 'ip' => client_ip(), 'email' => strtolower(substr($email, 0, 190)), 'attempted_at' => now()]);
}

function throttle_blocked(string $scope, string $email = '', int $maxPerIp = 10, int $maxPerEmail = 5, int $minutes = 15): bool
{
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    if ((int) val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip = ? AND attempted_at > ?', [$scope, client_ip(), $since]) >= $maxPerIp) {
        return true;
    }
    return $email !== '' && (int) val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND email = ? AND attempted_at > ?',
        [$scope, strtolower($email), $since]) >= $maxPerEmail;
}

function throttle_clear(string $scope, string $email): void
{
    q('DELETE FROM login_attempts WHERE (scope = ? AND email = ?) OR attempted_at < ?', [$scope, strtolower($email), date('Y-m-d H:i:s', time() - 86400)]);
}

/* ---------- email (PHP mail(), works on Hostinger shared hosting) ---------- */

function send_mail(string $to, string $subject, string $bodyHtml): bool
{
    $from = trim((string) setting('mail_from', setting('store_email', '')));
    if (!valid_email($to) || !valid_email($from) || !function_exists('mail')) {
        return false;
    }
    $name = '=?UTF-8?B?' . base64_encode((string) setting('store_name', 'Store')) . '?=';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
        . 'From: ' . $name . ' <' . $from . ">\r\nReply-To: " . setting('store_email', $from);
    $html = '<!doctype html><html><body style="margin:0;background:#f4f5f1;font-family:Arial,Helvetica,sans-serif;color:#2a2018">'
        . '<div style="max-width:600px;margin:24px auto;background:#fff;border:1px solid #e3ddd2">'
        . '<div style="background:#2a2018;color:#fff;padding:16px 24px;font-size:20px;font-weight:bold">' . e(setting('store_name', 'Store')) . '</div>'
        . '<div style="padding:24px;font-size:15px;line-height:1.6"><h1 style="font-size:20px;margin:0 0 12px">' . e($subject) . '</h1>' . $bodyHtml . '</div>'
        . '<div style="padding:14px 24px;border-top:1px solid #e3ddd2;font-size:13px;color:#685e54">' . e(setting('legal_name')) . '<br>' . e(setting('address')) . '</div>'
        . '</div></body></html>';
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($html)), $headers);
    if (!$ok) {
        error_log('Email to ' . $to . ' was not sent: ' . $subject);
    }
    return $ok;
}
