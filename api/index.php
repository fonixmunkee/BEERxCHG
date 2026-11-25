<?php
// HTTP entry point for /BEERxCHG/api/v1/*. All logic is in includes/api.php.
declare(strict_types=1);
require_once __DIR__ . '/../includes/api.php';

$base = cfg('base_url') . 'api/';
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = str_starts_with($path, $base) ? substr($path, strlen($base)) : '';

// CGI strips Authorization unless .htaccess copies it into the environment.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

[$status, $payload, $headers] = api_handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    (string)$auth,
    (string)($_SERVER['HTTP_X_API_KEY'] ?? ''),
    (string)file_get_contents('php://input'),
    $_GET
);

http_response_code($status);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
foreach ($headers as $k => $v) {
    header("$k: $v");
}
if ($status !== 204) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
