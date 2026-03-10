<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../api/_boot.php';

$ENV = env_load(__DIR__ . '/../../.env');
$db = pdo($ENV);
app_init_logging($db, 'php');

function deny(): void {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"><title>Нет доступа</title><div style="font-family:Inter,Arial,sans-serif;padding:24px">'
    .'<h2>Нет доступа</h2>'
    .'<p>Эта страница доступна только после создания очереди.</p>'
    .''
    .'</div>';
  exit;
}

$parts = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '' )));
// ["create","success"] or ["create","success","<token>"]
$token = '';
if (isset($parts[2]) && $parts[0]==='create' && $parts[1]==='success') {
  $token = $parts[2];
}

$ok = !empty($_SESSION['admin_ok']);
if (!$ok) {
  // allow if has create_token session OR valid token in url
  if (!empty($_SESSION['create_token'])) {
    $ok = true;
  } elseif ($token !== '') {
    $st = $db->prepare('SELECT active FROM create_tokens WHERE token=? LIMIT 1');
    $st->execute([$token]);
    $active = (int)($st->fetchColumn() ?? 0);
    $ok = ($active === 1);
    if ($ok) $_SESSION['create_token'] = $token;
  }
}

if (!$ok) deny();

readfile(__DIR__ . '/index.html');
