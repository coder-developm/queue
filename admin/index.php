<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/_boot.php';
$ENV = env_load(dirname(__DIR__) . '/.env');
$db = pdo($ENV);
app_init_logging($db, 'php_admin');
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
