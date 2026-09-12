<?php
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;

$page = preg_replace('/[^a-z_]/','', $_GET['page'] ?? (Auth::check() ? 'dashboard' : 'login'));
$publicPages = ['login'];
if (!in_array($page,$publicPages,true)) {
    Auth::requireLogin();
    if (!Auth::canAccess($page)) {
        http_response_code(403);
        $page='forbidden';
    }
}
$app = require dirname(__DIR__) . '/config/app.php';
$csrf = Csrf::token();
$user = Auth::user();
if ($page === 'login') {
    if (Auth::check()) { header('Location: index.php?page=dashboard'); exit; }
    require dirname(__DIR__) . '/app/Views/pages/login.php';
    exit;
}
require dirname(__DIR__) . '/app/Views/layouts/header.php';
$view = dirname(__DIR__) . '/app/Views/pages/' . $page . '.php';
if (!is_file($view)) $view = dirname(__DIR__) . '/app/Views/pages/dashboard.php';
require $view;
require dirname(__DIR__) . '/app/Views/layouts/footer.php';
