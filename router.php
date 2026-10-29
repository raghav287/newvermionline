<?php
/** Local testing only:  php -S localhost:8000 router.php   (on Hostinger, .htaccess does this job). */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/(includes|storage|database|docs)(/|$)|^/(config\.php|README\.md)$|/\.#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    require rtrim($file, '/') . '/index.php';
    return true;
}
$slug = trim($path, '/');
if (in_array($slug, ['about_us', 'faq', 'shipping-policy', 'return-policy', 'refund-policy', 'cancellation-policy', 'privacy-policy', 'terms-and-conditions'], true)) {
    $_GET['slug'] = $slug;
    $slug = 'page';
} elseif ($slug === 'sign-up') {
    $_GET['mode'] = 'register';
    $slug = 'sign-in';
}
if ($slug !== '' && preg_match('/^[a-z0-9_-]+$/', $slug) && is_file(__DIR__ . '/' . $slug . '.php')) {
    $_SERVER['SCRIPT_NAME'] = '/' . $slug . '.php';
    require __DIR__ . '/' . $slug . '.php';
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/404.php';
require __DIR__ . '/404.php';
return true;
