<?php
require_once __DIR__ . '/../config/bootstrap.php';
requireAuth();
$response = (new DashboardController((new Database())->getConnection()))->consultar($_GET, $_SERVER['REQUEST_METHOD'] ?? 'GET');
http_response_code($response['statusCode']);
header('Cache-Control: no-store');
if ($response['statusCode'] === 405) header('Allow: GET');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
