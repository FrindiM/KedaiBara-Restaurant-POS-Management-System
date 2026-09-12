<?php
require dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\ApiController;

$resource = $_GET['resource'] ?? $_POST['resource'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$input = array_merge($_GET, $_POST);
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) $input = array_merge($input, $json);
}
(new ApiController())->handle((string)$resource,(string)$action,$input);
