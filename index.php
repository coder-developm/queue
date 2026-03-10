<?php
declare(strict_types=1);
require_once __DIR__ . '/api/_boot.php';
$ENV = env_load(__DIR__ . '/.env');
$db = pdo($ENV);
app_init_logging($db, 'php_queue');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!preg_match('#^/(\d+)$#', rtrim($uri,'/'), $m)) {
  http_response_code(404);
  echo 'Not found';
  exit;
}
$publicId = $m[1];
$st = $db->prepare('SELECT id FROM queues WHERE public_id=? LIMIT 1');
$st->execute([$publicId]);
if (!$st->fetchColumn()) {
  http_response_code(404);
  echo 'Queue not found';
  exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
