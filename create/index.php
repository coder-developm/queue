<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../api/_boot.php';

$ENV = env_load(__DIR__ . '/../.env');
$db = pdo($ENV);
app_init_logging($db, 'php');

function deny(): void {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"><title>Нет доступа</title><div style="font-family:Inter,Arial,sans-serif;padding:24px">'
    .'<h2>Нет доступа</h2>'
    .'<p>Эта страница доступна только по специальной ссылке или после входа.</p>'
    .''
    .'</div>';
  exit;
}

$parts = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '' )));
// expected: ["create", "<token>"]
$token = '';
if (isset($parts[1]) && $parts[0] === 'create' && $parts[1] !== 'success') {
  $token = $parts[1];
}

$ok = !empty($_SESSION['admin_ok']);
if (!$ok && $token !== '') {
  $st = $db->prepare('SELECT active FROM create_tokens WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $active = (int)($st->fetchColumn() ?? 0);
  $ok = ($active === 1);
  if ($ok) {
    $_SESSION['create_token'] = $token;
  }
}

if (!$ok) deny();

// Render static page
readfile(__DIR__ . '/index.html');
