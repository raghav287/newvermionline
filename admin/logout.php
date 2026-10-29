<?php
require_once __DIR__ . '/../includes/admin.php';
admin_boot();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
    session_regenerate_id(true);
}
redirect(admin_url('login.php'));
