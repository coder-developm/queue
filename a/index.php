<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/_boot.php';
session_start();
$ENV = env_load(dirname(__DIR__) . '/.env');
$db = pdo($ENV);
app_init_logging($db, 'php_a');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!preg_match('#^/a/(\d+)/([^/]+)$#', rtrim($uri,'/'), $m)) {
  http_response_code(404);
  echo 'Not found';
  exit;
}
$publicId = $m[1];
$token = $m[2];
$st = $db->prepare('SELECT id, admin_token FROM queues WHERE public_id=? LIMIT 1');
$st->execute([$publicId]);
$q = $st->fetch();
if (!$q) {
  http_response_code(404);
  echo 'Queue not found';
  exit;
}
if ((string)($q['admin_token'] ?? '') !== $token) {
  app_log_server($db, 'warn', 'php_a', 'INVALID_ADMIN_LINK_TOKEN', ['public_id'=>$publicId, 'uri'=>$uri, 'ip'=>$_SERVER['REMOTE_ADDR'] ?? '']);
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
