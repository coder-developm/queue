<?php
declare(strict_types=1);

/**
 * Queue backend (PHP + MySQL) for the provided frontend.
 * Router lives at /api/index.php and handles /api/* requests.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

session_start();

// --- request timing + body cache (for logging) ---
$__REQUEST_START = microtime(true);
$__RAW_BODY = null;
$__JSON_BODY = null;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/_boot.php';

$ENV = env_load(__DIR__ . '/../.env');

function json_in(): array {
  // Cache php://input so it can be reused for audit logging
  global $__RAW_BODY, $__JSON_BODY;
  if ($__JSON_BODY !== null) return $__JSON_BODY;
  if ($__RAW_BODY === null) {
    $__RAW_BODY = file_get_contents('php://input');
    if ($__RAW_BODY === false) $__RAW_BODY = '';
  }
  if ($__RAW_BODY === '') { $__JSON_BODY = []; return $__JSON_BODY; }
  $data = json_decode($__RAW_BODY, true);
  $__JSON_BODY = is_array($data) ? $data : [];
  return $__JSON_BODY;
}


function envv($k, $default=null){ return $_ENV[$k] ?? getenv($k) ?: $default; }

function log_auth_history($db, $userId, $username, $success, $reason=null){
  try{
    $st=$db->prepare("INSERT INTO auth_history(user_id, username, ip, user_agent, success, reason) VALUES (?,?,?,?,?,?)");
    $st->execute([$userId, $username, client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255), $success?1:0, $reason]);
  }catch(Exception $e){}
}

function log_server($db, $level, $source, $message, $context=null){
  try{
    ensure_server_logs($db);
    $ctx = $context ? json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
    $st = $db->prepare("INSERT INTO server_logs(level, source, message, context_json) VALUES (?,?,?,?)");
    $st->execute([$level, $source, $message, $ctx]);
  } catch(Exception $e){}
}

function ensure_server_logs(PDO $db): void {
  // Auto-migration for server logs table (safe to run repeatedly)
  static $done = false;
  if ($done) return;
  $done = true;
  try{
    $db->exec("CREATE TABLE IF NOT EXISTS server_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      level VARCHAR(16) NOT NULL,
      source VARCHAR(64) NULL,
      message TEXT NOT NULL,
      context_json TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_server_logs_created(created_at),
      INDEX idx_server_logs_level(level)
    ) ENGINE=InnoDB");
  }catch(Throwable $e){}
}

function ensure_log_settings(PDO $db): void {
  // Lightweight auto-migration for log settings (safe to run repeatedly)
  $db->exec("CREATE TABLE IF NOT EXISTS log_settings (
    id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    auto_delete TINYINT(1) NOT NULL DEFAULT 0,
    keep_days INT NOT NULL DEFAULT 30,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $db->exec("INSERT IGNORE INTO log_settings(id, auto_delete, keep_days) VALUES (1, 0, 30)");
}

function ensure_backup_tables(PDO $db): void {
  // Base tables
  $db->exec("CREATE TABLE IF NOT EXISTS backup_settings (
    id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    frequency_minutes INT NOT NULL DEFAULT 1440,
    auto_delete TINYINT(1) NOT NULL DEFAULT 0,
    keep_days INT NOT NULL DEFAULT 30,
    last_run_at DATETIME NULL,
    cleanup_last_run_at DATETIME NULL
  ) ENGINE=InnoDB");
  $db->exec("INSERT IGNORE INTO backup_settings(id, enabled, frequency_minutes, auto_delete, keep_days, last_run_at, cleanup_last_run_at) VALUES (1, 0, 1440, 0, 30, NULL, NULL)");

  try{ $db->exec("ALTER TABLE backup_settings ADD COLUMN auto_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER frequency_minutes"); }catch(Throwable $e){}
  try{ $db->exec("ALTER TABLE backup_settings ADD COLUMN keep_days INT NOT NULL DEFAULT 30 AFTER auto_delete"); }catch(Throwable $e){}
  try{ $db->exec("ALTER TABLE backup_settings ADD COLUMN cleanup_last_run_at DATETIME NULL AFTER last_run_at"); }catch(Throwable $e){}

  $db->exec("CREATE TABLE IF NOT EXISTS backups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_to_telegram TINYINT(1) NOT NULL DEFAULT 0,
    telegram_error TEXT NULL,
    INDEX idx_backups_created(created_at)
  ) ENGINE=InnoDB");

  // Light migration: add telegram_error if table existed previously
  try{ $db->exec("ALTER TABLE backups ADD COLUMN telegram_error TEXT NULL"); }catch(Throwable $e){}
}


function maybe_run_backup_pseudocron(PDO $db, string $path): void {
  try{
    if (strpos($path, '/admin/backups') === 0) return;
    ensure_backup_tables($db);
    $st=$db->query("SELECT enabled, frequency_minutes, auto_delete, keep_days, last_run_at, cleanup_last_run_at FROM backup_settings WHERE id=1");
    $s=$st->fetch();
    if(!$s || (int)$s['enabled'] !== 1) return;
    $freq = max(10, (int)($s['frequency_minutes'] ?? 1440));
    $last = !empty($s['last_run_at']) ? strtotime((string)$s['last_run_at']) : 0;
    $due = $last + ($freq * 60);
    if(time() < $due) return;
    $db->prepare("UPDATE backup_settings SET last_run_at=NOW() WHERE id=1")->execute();
    run_backup_now($db);
  }catch(Throwable $e){}
}


function backup_cleanup_if_needed(PDO $db, string $path): void {
  try{
    if (strpos($path, '/admin/backups') === 0) return;
    ensure_backup_tables($db);
    $st=$db->query("SELECT auto_delete, keep_days, cleanup_last_run_at FROM backup_settings WHERE id=1");
    $s=$st->fetch();
    if(!$s || (int)($s['auto_delete'] ?? 0) !== 1) return;
    $keep = max(1, (int)($s['keep_days'] ?? 30));
    $last = !empty($s['cleanup_last_run_at']) ? strtotime((string)$s['cleanup_last_run_at']) : 0;
    if($last > 0 && (time() - $last) < 3600) return;

    $cutoff = date('Y-m-d H:i:s', time() - ($keep * 86400));
    $st=$db->prepare("SELECT id, file_name FROM backups WHERE created_at < ? ORDER BY id ASC");
    $st->execute([$cutoff]);
    $rows=$st->fetchAll();
    $db->prepare("UPDATE backup_settings SET cleanup_last_run_at=NOW() WHERE id=1")->execute();
    if(!$rows) return;

    $rootDir = dirname(__DIR__);
    foreach($rows as $row){
      $fileName = (string)$row['file_name'];
      $filePath = $rootDir . '/backups/' . $fileName;
      if (is_file($filePath)) {
        $tr = telegram_send_file_ex($filePath, 'Бекап перед автоудалением: ' . $fileName);
        if ($tr['configured'] === false || $tr['ok'] !== true) {
          log_server($db, 'warn', 'backup_cleanup', 'AUTO_DELETE_SKIPPED', ['file'=>$fileName,'error'=>$tr['error'] ?? 'Telegram send failed']);
          return;
        }
      }
    }

    $del=$db->prepare("DELETE FROM backups WHERE created_at < ?");
    $del->execute([$cutoff]);
    foreach($rows as $row){
      $fileName = (string)$row['file_name'];
      $filePath = $rootDir . '/backups/' . $fileName;
      if (is_file($filePath)) @unlink($filePath);
    }
    log_server($db, 'info', 'backup_cleanup', 'AUTO_DELETE_DONE', ['count'=>count($rows),'keep_days'=>$keep]);
  }catch(Throwable $e){}
}

function logs_settings(PDO $db): array {
  ensure_log_settings($db);
  $st = $db->query("SELECT auto_delete, keep_days, updated_at FROM log_settings WHERE id=1");
  $row = $st->fetch() ?: ["auto_delete"=>0, "keep_days"=>30, "updated_at"=>null];
  return $row;
}

function build_logs_export_file(PDO $db, string $whereSql, array $params, string $title): ?string {
  try{
    ensure_server_logs($db);
    $st = $db->prepare("SELECT id, level, source, message, context_json, created_at FROM server_logs {$whereSql} ORDER BY id ASC");
    foreach ($params as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll();
    $tmp = tempnam(sys_get_temp_dir(), 'queue_logs_');
    if ($tmp === false) return null;
    $path = $tmp . '.json';
    @rename($tmp, $path);
    $payload = [
      'title' => $title,
      'generated_at' => date('c'),
      'count' => count($rows),
      'items' => array_map(function($r){
        return [
          'id' => (int)$r['id'],
          'level' => (string)$r['level'],
          'source' => (string)($r['source'] ?? ''),
          'message' => (string)$r['message'],
          'created_at' => (string)$r['created_at'],
          'context' => !empty($r['context_json']) ? json_decode((string)$r['context_json'], true) : null,
        ];
      }, $rows),
    ];
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    return $path;
  }catch(Throwable $e){
    return null;
  }
}

function export_logs_to_telegram_and_delete(PDO $db, string $whereSql, array $params, string $caption): array {
  ensure_server_logs($db);
  $cnt = $db->prepare("SELECT COUNT(*) FROM server_logs {$whereSql}");
  foreach ($params as $i => $v) $cnt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  $cnt->execute();
  $total = (int)$cnt->fetchColumn();
  if ($total <= 0) return ['ok'=>true,'count'=>0,'sent'=>false];
  $file = build_logs_export_file($db, $whereSql, $params, $caption);
  if (!$file) return ['ok'=>false,'error'=>'Не удалось подготовить файл логов'];
  $send = telegram_send_file_ex($file, $caption);
  @unlink($file);
  if (!$send['configured']) return ['ok'=>false,'error'=>$send['error'] ?: 'Telegram не настроен'];
  if (!$send['ok']) return ['ok'=>false,'error'=>$send['error'] ?: 'Ошибка отправки в Telegram'];
  $del = $db->prepare("DELETE FROM server_logs {$whereSql}");
  foreach ($params as $i => $v) $del->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  $del->execute();
  return ['ok'=>true,'count'=>$total,'sent'=>true];
}

function logs_cleanup_if_needed(PDO $db): void {
  try{
    ensure_server_logs($db);
    $s = logs_settings($db);
    if ((int)($s['auto_delete'] ?? 0) !== 1) return;
    $keep = max(1, (int)($s['keep_days'] ?? 30));
    $title = "Логи перед автоудалением за пределами {$keep} дн. (" . date('Y-m-d H:i:s') . ")";
    export_logs_to_telegram_and_delete($db, "WHERE created_at < (NOW() - INTERVAL ? DAY)", [$keep], $title);
  }catch(Throwable $e){}
}

function telegram_send_message($text){
  // Backward-compat wrapper: return bool (configured+attempted)
  $r = telegram_send_message_ex($text);
  return $r['configured'] ? (bool)$r['ok'] : false;
}

function telegram_send_message_ex(string $text): array {
  $token = envv('TELEGRAM_BOT_TOKEN');
  $chat  = envv('TELEGRAM_CHAT_ID');
  if(!$token || !$chat) return ['configured'=>false,'ok'=>false,'http'=>0,'resp'=>'','error'=>'Telegram не настроен (TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID)'];
  if(!function_exists('curl_init')) return ['configured'=>true,'ok'=>false,'http'=>0,'resp'=>'','error'=>'PHP curl extension is not enabled'];
  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $post = http_build_query(['chat_id'=>$chat,'text'=>$text]);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  $cerr = $resp === false ? (string)curl_error($ch) : '';
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if($resp === false) return ['configured'=>true,'ok'=>false,'http'=>$http,'resp'=>'','error'=>$cerr ?: 'curl_exec failed'];
  $j = json_decode((string)$resp, true);
  $ok = (is_array($j) && array_key_exists('ok',$j)) ? (bool)$j['ok'] : ($http>=200 && $http<300);
  $err = '';
  if(!$ok){
    if(is_array($j) && isset($j['description'])) $err = (string)$j['description'];
    else $err = "HTTP {$http}";
  }
  return ['configured'=>true,'ok'=>$ok,'http'=>$http,'resp'=>substr((string)$resp,0,4000),'error'=>$err];
}

/**
 * Send file to Telegram.
 * @return ?bool null = not configured, true = sent ok, false = attempted but failed
 */
function telegram_send_file($filePath, $caption=null){
  $r = telegram_send_file_ex($filePath, $caption);
  if($r['configured'] === false) return null;
  return (bool)$r['ok'];
}

function telegram_send_file_ex(string $filePath, ?string $caption=null): array {
  $token = envv('TELEGRAM_BOT_TOKEN');
  $chat  = envv('TELEGRAM_CHAT_ID');
  if(!$token || !$chat) return ['configured'=>false,'ok'=>false,'http'=>0,'resp'=>'','error'=>'Telegram не настроен (TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID)'];
  if(!function_exists('curl_init')) return ['configured'=>true,'ok'=>false,'http'=>0,'resp'=>'','error'=>'PHP curl extension is not enabled'];
  if(!is_file($filePath)) return ['configured'=>true,'ok'=>false,'http'=>0,'resp'=>'','error'=>'Файл не найден'];
  $url = "https://api.telegram.org/bot{$token}/sendDocument";
  $ch = curl_init($url);
  $post = ["chat_id"=>$chat, "document"=> new CURLFile($filePath)];
  if($caption) $post["caption"] = $caption;
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  $resp = curl_exec($ch);
  $cerr = $resp === false ? (string)curl_error($ch) : '';
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if($resp === false) return ['configured'=>true,'ok'=>false,'http'=>$http,'resp'=>'','error'=>$cerr ?: 'curl_exec failed'];
  $j = json_decode((string)$resp, true);
  $ok = (is_array($j) && array_key_exists('ok',$j)) ? (bool)$j['ok'] : ($http>=200 && $http<300);
  $err = '';
  if(!$ok){
    if(is_array($j) && isset($j['description'])) $err = (string)$j['description'];
    else $err = "HTTP {$http}";
  }
  return ['configured'=>true,'ok'=>$ok,'http'=>$http,'resp'=>substr((string)$resp,0,4000),'error'=>$err];
}

function json_out($data, int $code = 200): void {
  global $__REQUEST_START, $__RAW_BODY;
  // Audit: log every API request (including admin actions)
  try{
    if (isset($GLOBALS['__DB']) && $GLOBALS['__DB'] instanceof PDO) {
      /** @var PDO $db */
      $db = $GLOBALS['__DB'];
      $path = (string)($GLOBALS['__PATH'] ?? '');
      // Skip extremely noisy OPTIONS
      if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
        $dur = (int)round((microtime(true) - (float)$__REQUEST_START) * 1000);
        $ctx = [
          'method' => $_SERVER['REQUEST_METHOD'] ?? '',
          'path' => $path,
          'status' => $code,
          'ip' => client_ip(),
          'ms' => $dur,
        ];
        if (!empty($_SESSION['admin_user'])) {
          $ctx['admin_user'] = (string)$_SESSION['admin_user'];
          $ctx['admin_id'] = admin_id();
        }
        $q = $_SERVER['QUERY_STRING'] ?? '';
        if ($q) $ctx['query'] = $q;
        if ($__RAW_BODY === null) {
          $__RAW_BODY = file_get_contents('php://input');
          if ($__RAW_BODY === false) $__RAW_BODY = '';
        }
        $body = (string)$__RAW_BODY;
        if ($body !== '') {
          $ctx['body_trunc'] = substr($body, 0, 1500);
        }
        log_server($db, 'info', 'api', 'REQUEST', $ctx);
        // optional cleanup
        logs_cleanup_if_needed($db);
      }
    }
  }catch(Throwable $e){}

  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function route(): string {
  $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
  $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
  $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

  // Support installation in a subfolder, e.g. /que2/api/index.php -> /auth/needs-setup
  if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir . '/')) {
    $uri = substr($uri, strlen($scriptDir));
  } elseif ($scriptDir !== '' && $scriptDir !== '/' && $uri === $scriptDir) {
    $uri = '/';
  }

  $uri = rtrim($uri, '/');
  return $uri === '' ? '/' : $uri;
}

function is_admin(): bool {
  return !empty($_SESSION['admin_ok']);
}

const REMEMBER_COOKIE = 'queue_remember';
const REMEMBER_DAYS = 30;

function cookie_secure(): bool {
  // OpenServer typically uses http. In production, set HTTPS and cookie_secure will be true.
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function remember_set_cookie(string $selector, string $validator, int $expiresTs): void {
  $value = $selector . ':' . $validator;
  setcookie(REMEMBER_COOKIE, $value, [
    'expires'  => $expiresTs,
    'path'     => '/',
    'secure'   => cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function remember_clear_cookie(): void {
  if (!isset($_COOKIE[REMEMBER_COOKIE])) return;
  setcookie(REMEMBER_COOKIE, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function require_admin(): void {
  if (!is_admin()) json_out(["error"=>"FORBIDDEN"], 403);
}

function is_owner(): bool {
  return is_admin() && (($_SESSION['admin_role'] ?? '') === 'owner');
}

function require_owner(): void {
  if (!is_owner()) json_out(["error"=>"FORBIDDEN"], 403);
}

function admin_id(): int {
  return (int)($_SESSION['admin_id'] ?? 0);
}

function admin_perms(): array {
  $raw = $_SESSION['admin_perms'] ?? null;
  if (is_array($raw)) return $raw;
  if (is_string($raw) && $raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
  }
  return [];
}

function has_perm(string $key): bool {
  if (!is_admin()) return false;
  if (is_owner()) return true;
  $perms = admin_perms();
  return !empty($perms[$key]);
}

function require_perm(string $key): void {
  if (!has_perm($key)) json_out(["error"=>"FORBIDDEN"], 403);
}

function remember_parse_cookie(): ?array {
  $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
  if ($raw === '') return null;
  $parts = explode(':', $raw, 2);
  if (count($parts) !== 2) return null;
  [$selector, $validator] = $parts;
  if (!preg_match('/^[0-9a-f]{32}$/i', $selector)) return null;
  if (!preg_match('/^[0-9a-f]{64}$/i', $validator)) return null;
  return ['selector'=>strtolower($selector), 'validator'=>strtolower($validator)];
}

function remember_validator_hash(string $validatorHex): string {
  // Store sha256 hash of validator
  return hash('sha256', hex2bin($validatorHex));
}

function auth_try_remember(PDO $db): void {
  if (is_admin()) return;
  $ck = remember_parse_cookie();
  if (!$ck) return;

  $st = $db->prepare(
    "SELECT s.id, s.user_id, s.token_hash, s.expires_at, u.username
     FROM admin_sessions s
     JOIN admin_users u ON u.id=s.user_id
     WHERE s.kind='long' AND s.selector=? LIMIT 1"
  );
  $st->execute([$ck['selector']]);
  $row = $st->fetch();
  if (!$row) { remember_clear_cookie(); return; }

  // Expired?
  if (strtotime((string)$row['expires_at']) < time()) {
    $st2 = $db->prepare("DELETE FROM admin_sessions WHERE id=?");
    $st2->execute([(int)$row['id']]);
    remember_clear_cookie();
    return;
  }

  $calc = remember_validator_hash($ck['validator']);
  if (!hash_equals((string)$row['token_hash'], $calc)) {
    // Possible theft: revoke this selector
    $st2 = $db->prepare("DELETE FROM admin_sessions WHERE id=?");
    $st2->execute([(int)$row['id']]);
    remember_clear_cookie();
    return;
  }

  // Login OK
  $_SESSION['admin_ok'] = true;
  $_SESSION['admin_user'] = (string)$row['username'];
  $_SESSION['admin_id'] = (int)$row['user_id'];
  // load role + perms
  $stR = $db->prepare("SELECT role, permissions_json FROM admin_users WHERE id=? LIMIT 1");
  $stR->execute([(int)$row['user_id']]);
  $r = $stR->fetch() ?: ['role'=>'admin','permissions_json'=>null];
  $_SESSION['admin_role'] = $r['role'] ?: 'admin';
  $_SESSION['admin_perms'] = $r['permissions_json'] ? json_decode((string)$r['permissions_json'], true) : ['links'=>1,'branding'=>1];

  track_short_session($db, (int)$row['user_id']);

  // Rotate validator
  $newValidator = bin2hex(random_bytes(32));
  $newHash = remember_validator_hash($newValidator);
  $expiresTs = time() + (REMEMBER_DAYS * 86400);

  $st3 = $db->prepare("UPDATE admin_sessions SET token_hash=?, last_used_at=NOW(), expires_at=FROM_UNIXTIME(?), ip=?, user_agent=? WHERE id=?");
  $st3->execute([
    $newHash,
    $expiresTs,
    client_ip(),
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    (int)$row['id']
  ]);
  remember_set_cookie($ck['selector'], $newValidator, $expiresTs);
}


function client_ip(): string {
  // Basic, safe for local OpenServer. If behind proxy, you can extend this.
  return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function auth_needs_setup(PDO $db): bool {
  $st = $db->query("SELECT COUNT(*) FROM admin_users");
  return ((int)$st->fetchColumn()) === 0;
}

function auth_rate_limited(PDO $db, string $ip, string $username): array {
  // Allow up to 5 failed attempts per 10 minutes per IP.
  // If exceeded, block for 15 minutes from last failed attempt in that window.
  $st = $db->prepare("
    SELECT COUNT(*) AS fails, MAX(created_at) AS last_fail
    FROM auth_attempts
    WHERE ip=? AND success=0 AND created_at >= (NOW() - INTERVAL 10 MINUTE)
  ");
  $st->execute([$ip]);
  $row = $st->fetch() ?: ["fails"=>0,"last_fail"=>null];
  $fails = (int)($row['fails'] ?? 0);
  $lastFail = $row['last_fail'] ?? null;

  if ($fails < 5 || !$lastFail) return ["limited"=>false];

  $st2 = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(?, INTERVAL 15 MINUTE)) AS retry_after");
  $st2->execute([$lastFail]);
  $retryAfter = (int)($st2->fetchColumn() ?? 0);

  if ($retryAfter > 0) return ["limited"=>true, "retryAfter"=>$retryAfter];
  return ["limited"=>false];
}


function track_short_session(PDO $db, int $userId): void {
  $sid = session_id();
  if (!$sid) return;
  // Session lifetime (seconds)
  $ttl = (int)ini_get('session.gc_maxlifetime');
  if ($ttl <= 0) $ttl = 7200;
  $db->prepare("DELETE FROM admin_sessions WHERE kind='short' AND session_id=?")->execute([$sid]);
  $db->prepare(
    "INSERT INTO admin_sessions(user_id, kind, session_id, ip, user_agent, expires_at) VALUES(?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
  )->execute([
    $userId,
    'short',
    $sid,
    client_ip(),
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    $ttl
  ]);
}
function auth_log_attempt(PDO $db, string $ip, string $username, bool $success): void {
  $st = $db->prepare("INSERT INTO auth_attempts(ip,username,success) VALUES(?,?,?)");
  $st->execute([$ip, $username, $success ? 1 : 0]);
}

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex, 0, 8),
    substr($hex, 8, 4),
    substr($hex, 12, 4),
    substr($hex, 16, 4),
    substr($hex, 20, 12)
  );
}

function fmt_ticket(int $n): string { return '#' . str_pad((string)$n, 3, '0', STR_PAD_LEFT); }
function fmt_ticket_plain(int $n): string { return str_pad((string)$n, 3, '0', STR_PAD_LEFT); }

function get_queue(PDO $db, string $publicId): ?array {
  $st = $db->prepare("SELECT * FROM queues WHERE public_id=?");
  $st->execute([$publicId]);
  $q = $st->fetch();
  return $q ?: null;
}
function get_queue_services(PDO $db, int $queueId): array {
  $st = $db->prepare("SELECT code,label FROM services WHERE queue_id=? ORDER BY sort_order,id");
  $st->execute([$queueId]);
  return $st->fetchAll();
}
function get_queue_cashiers(PDO $db, int $queueId): array {
  $st = $db->prepare("SELECT idx,name,paused,hidden,current_ticket_number,allowed_services FROM cashiers WHERE queue_id=? ORDER BY idx");
  $st->execute([$queueId]);
  $rows = $st->fetchAll();
  foreach ($rows as &$r) {
    $r['allowedServices'] = $r['allowed_services'] ? array_values(array_filter(explode(',', (string)$r['allowed_services']))) : [];
  }
  return $rows;
}
function ensure_site_branding_schema(PDO $db): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try { $db->exec("ALTER TABLE site_branding ADD COLUMN favicon_url VARCHAR(255) NULL AFTER logo_url"); } catch (Throwable $e) {}
}

function get_site_branding(PDO $db): array {
  ensure_site_branding_schema($db);
  $st = $db->query("SELECT page_key, primary_color, accent_color, logo_url, favicon_url, theme_mode, texts_json, notify_sound_url FROM site_branding");
  $rows = $st->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $out[$r["page_key"]] = [
      "primary" => $r["primary_color"],
      "accent" => $r["accent_color"],
      "logoUrl" => $r["logo_url"],
      "faviconUrl" => $r["favicon_url"] ?? null,
      "themeMode" => $r["theme_mode"],
      "notifySoundUrl" => $r["notify_sound_url"],
      "texts" => $r["texts_json"] ? json_decode((string)$r["texts_json"], true) : null,
    ];
  }
  return $out;
}

function upsert_site_branding(PDO $db, string $pageKey, ?string $primary, ?string $accent, ?string $logoUrl, ?string $faviconUrl, ?string $themeMode, ?string $notifySoundUrl, $texts): void {
  ensure_site_branding_schema($db);
  $textsJson = null;
  if (is_array($texts)) $textsJson = json_encode($texts, JSON_UNESCAPED_UNICODE);
  $st = $db->prepare("INSERT INTO site_branding(page_key,primary_color,accent_color,logo_url,favicon_url,theme_mode,notify_sound_url,texts_json) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE primary_color=VALUES(primary_color), accent_color=VALUES(accent_color), logo_url=VALUES(logo_url), favicon_url=VALUES(favicon_url), theme_mode=VALUES(theme_mode), notify_sound_url=VALUES(notify_sound_url), texts_json=VALUES(texts_json)");
  $st->execute([$pageKey, $primary, $accent, $logoUrl, $faviconUrl, $themeMode, $notifySoundUrl, $textsJson]);
}

function admin_auth(PDO $db, string $publicId, string $token): array {
  $q = get_queue($db, $publicId);
  if (!$q) json_out(["error"=>"QUEUE_NOT_FOUND"], 404);
  if (!hash_equals($q['admin_token'], $token)) json_out(["error"=>"FORBIDDEN"], 403);
  return $q;
}

$db = pdo($ENV);
app_init_logging($db, 'php_api', false);

// expose for json_out audit logger
$GLOBALS['__DB'] = $db;

set_exception_handler(function($e) use ($db){
  $msg = $e->getMessage();
  log_server($db, "error", "php", $msg, ["file"=>$e->getFile(),"line"=>$e->getLine()]);
  telegram_send_message("API error: ".$msg);
  json_out(["error"=>"SERVER_ERROR"], 500);
});
$path = route();

// expose for json_out audit logger
$GLOBALS['__PATH'] = $path;

// Accept both /api/... and /... when Apache passes PATH_INFO stripped
if (str_starts_with($path, '/api')) {
  $path = substr($path, 4);
  if ($path === '') $path = '/';
}

// keep logger path in sync
$GLOBALS['__PATH'] = $path;

// Pseudocron must run only after the route is known and normalized.
maybe_run_backup_pseudocron($db, $path);
backup_cleanup_if_needed($db, $path);

// Auto-login via persistent cookie ("Remember me") if present
auth_try_remember($db);

if ($path === '' || $path === '/') json_out(["ok"=>true, "service"=>"queue-api"]);

if ($path === '/health' && $_SERVER['REQUEST_METHOD'] === 'GET') json_out(["ok"=>true]);

// Public: get global branding per page
if ($path === "/site/branding" && $_SERVER["REQUEST_METHOD"] === "GET") {
  $all = get_site_branding($db);
  $page = (string)($_GET["page"] ?? "");
  if ($page !== "") {
    json_out(["page"=>$page, "branding"=>($all[$page] ?? null)]);
  }
  json_out(["branding"=>$all]);
}

// Admin: update global branding
if ($path === "/site/branding" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('branding');
  $in = json_in();
  $items = $in["items"] ?? [];
  if (!is_array($items)) json_out(["error"=>"VALIDATION"], 400);
  foreach ($items as $k=>$v) {
    if (!is_array($v)) continue;
    $primary = isset($v["primary"]) ? (string)$v["primary"] : null;
    $accent  = isset($v["accent"]) ? (string)$v["accent"] : null;
    $logoUrl = isset($v["logoUrl"]) ? (string)$v["logoUrl"] : null;
    $texts   = $v["texts"] ?? null;
    $themeMode = isset($v["themeMode"]) ? (string)$v["themeMode"] : null;
    $notifySoundUrl = isset($v["notifySoundUrl"]) ? (string)$v["notifySoundUrl"] : null;
    $faviconUrl = isset($v["faviconUrl"]) ? (string)$v["faviconUrl"] : null;
    upsert_site_branding($db, (string)$k, $primary, $accent, $logoUrl, $faviconUrl, $themeMode, $notifySoundUrl, $texts);
  }
  json_out(["ok"=>true]);
}
// Admin: upload logo for a page (multipart/form-data: pageKey, file=logo)
if ($path === "/site/branding/upload" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('branding');
  $pageKey = trim((string)($_POST['pageKey'] ?? ''));
  if ($pageKey === '') json_out(["error"=>"VALIDATION"], 400);
  $kind = trim((string)($_POST['kind'] ?? 'logo')); // logo|sound|favicon
  $field = ($kind === 'sound') ? 'sound' : (($kind === 'favicon') ? 'favicon' : 'logo');
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) json_out(["error"=>"NO_FILE"], 400);

  $f = $_FILES[$field];
  if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) json_out(["error"=>"UPLOAD"], 400);

  $name = (string)($f['name'] ?? 'logo');
  $tmp = (string)($f['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) json_out(["error"=>"UPLOAD"], 400);

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($kind === 'sound') {
    if (!in_array($ext, ['wav','mp3','ogg'], true)) json_out(["error"=>"FILE_TYPE"], 400);
  } else {
    if (!in_array($ext, ['png','jpg','jpeg','svg','webp','ico'], true)) json_out(["error"=>"FILE_TYPE"], 400);
  }

  $dirFs = dirname(__DIR__) . "/uploads/branding";
  if (!is_dir($dirFs)) @mkdir($dirFs, 0777, true);

  $safeKey = preg_replace('/[^a-z0-9_-]+/i', '', $pageKey);
  $ts = time();
  $suffix = ($kind === 'sound') ? 'sound' : 'logo';
  $fileFs = $dirFs . "/" . $safeKey . "_" . $suffix . "_" . $ts . "." . $ext;

  if (!@move_uploaded_file($tmp, $fileFs)) json_out(["error"=>"UPLOAD"], 400);

  $url = "/uploads/branding/" . basename($fileFs);

  if ($kind === 'sound') {
    $st = $db->prepare("INSERT INTO site_branding(page_key, notify_sound_url) VALUES(?,?) ON DUPLICATE KEY UPDATE notify_sound_url=VALUES(notify_sound_url)");
    $st->execute([$pageKey, $url]);
    json_out(["ok"=>true, "notifySoundUrl"=>$url]);
  } elseif ($kind === 'favicon') {
    ensure_site_branding_schema($db);
    $st = $db->prepare("INSERT INTO site_branding(page_key, favicon_url) VALUES(?,?) ON DUPLICATE KEY UPDATE favicon_url=VALUES(favicon_url)");
    $st->execute([$pageKey, $url]);
    json_out(["ok"=>true, "faviconUrl"=>$url]);
  } else {
    $st = $db->prepare("INSERT INTO site_branding(page_key, logo_url) VALUES(?,?) ON DUPLICATE KEY UPDATE logo_url=VALUES(logo_url)");
    $st->execute([$pageKey, $url]);
    json_out(["ok"=>true, "logoUrl"=>$url]);
  }
}




// Auth (mini-admin)

// Auth (mini-admin): credentials stored in DB (admin_users) with password_hash()
// Setup is allowed only when there are no users yet.
if ($path === '/auth/needs-setup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  json_out(["ok"=>true, "needsSetup"=>auth_needs_setup($db)]);
}

if ($path === '/auth/setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!auth_needs_setup($db)) json_out(["error"=>"ALREADY_CONFIGURED"], 409);

  $in = json_in();
  $u = trim((string)($in['username'] ?? ''));
  $p = (string)($in['password'] ?? '');
  if ($u === '' || $p === '') json_out(["error"=>"VALIDATION"], 400);

  $hash = password_hash($p, PASSWORD_DEFAULT);
  // first user becomes "owner"
  $st = $db->prepare("INSERT INTO admin_users(username,password_hash,role) VALUES(?,?,?)");
  $st->execute([$u, $hash, 'owner']);
  $uid = (int)$db->lastInsertId();

  $_SESSION['admin_ok'] = true;
  $_SESSION['admin_id'] = $uid;
  $_SESSION['admin_perms'] = ['links'=>1,'branding'=>1,'users'=>1];
  $_SESSION['admin_user'] = $u;
  $_SESSION['admin_role'] = 'owner';

  track_short_session($db, $uid);
  json_out(["ok"=>true]);
}

if ($path === '/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = json_in();
  $u = trim((string)($in['username'] ?? ''));
  $p = (string)($in['password'] ?? '');
  $remember = !empty($in['rememberMe']);
  if ($u === '' || $p === '') json_out(["error"=>"INVALID_CREDENTIALS"], 401);

  $ip = client_ip();
  $rl = auth_rate_limited($db, $ip, $u);
  if (!empty($rl['limited'])) {
    log_auth_history($db, null, $u, false, "RATE_LIMITED");
    json_out(["error"=>"RATE_LIMITED", "retryAfter"=>(int)$rl['retryAfter']], 429);
  }

  $st = $db->prepare("SELECT id, username, password_hash, role, permissions_json FROM admin_users WHERE username=? LIMIT 1");
  $st->execute([$u]);
  $user = $st->fetch();

  $ok = false;
  if ($user && password_verify($p, (string)$user['password_hash'])) {
    $ok = true;
    // Optional: rehash if algorithm params changed
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
      $newHash = password_hash($p, PASSWORD_DEFAULT);
      $st2 = $db->prepare("UPDATE admin_users SET password_hash=? WHERE id=?");
      $st2->execute([$newHash, (int)$user['id']]);
    }
  }

  auth_log_attempt($db, $ip, $u, $ok);

  if (!$ok) json_out(["error"=>"INVALID_CREDENTIALS"], 401);

  $_SESSION['admin_ok'] = true;
  $_SESSION['admin_user'] = $u;
  $_SESSION['admin_id'] = (int)$user['id'];
  $_SESSION['admin_role'] = $user['role'] ?? 'admin';
  $_SESSION['admin_perms'] = $user['permissions_json'] ? json_decode((string)$user['permissions_json'], true) : ['links'=>1,'branding'=>1];

  // Track short session in DB too
  track_short_session($db, (int)$user['id']);

  if ($remember) {
    $selector = bin2hex(random_bytes(16)); // 32 hex
    $validator = bin2hex(random_bytes(32)); // 64 hex
    $hash = remember_validator_hash($validator);
    $expiresTs = time() + (REMEMBER_DAYS * 86400);

    // Clean old sessions for this user occasionally
    $db->prepare("DELETE FROM admin_sessions WHERE user_id=? AND expires_at < NOW()")
      ->execute([(int)$user['id']]);

    $stS = $db->prepare(
      "INSERT INTO admin_sessions(user_id, kind, selector, token_hash, ip, user_agent, expires_at)
       VALUES(?,?,?,?,?,?,FROM_UNIXTIME(?))"
    );
    $stS->execute([
      (int)$user['id'],
      'long',
      $selector,
      $hash,
      client_ip(),
      substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
      $expiresTs
    ]);
    remember_set_cookie($selector, $validator, $expiresTs);
  }
  json_out(["ok"=>true]);
}

if ($path === '/auth/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // Revoke remember-me token if present
  $ck = remember_parse_cookie();
  if ($ck) {
    $st = $db->prepare("DELETE FROM admin_sessions WHERE selector=?");
    $st->execute([$ck['selector']]);
  }
  remember_clear_cookie();

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  json_out(["ok"=>true]);
}

if ($path === '/auth/me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  json_out(["ok"=>true, "isAdmin"=>is_admin(), "username"=>($_SESSION["admin_user"] ?? null), "role"=>($_SESSION['admin_role'] ?? null), "perms"=>admin_perms()]);
}

if ($path === '/auth/change-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin();
  $in = json_in();
  $current = (string)($in['currentPassword'] ?? '');
  $next = (string)($in['newPassword'] ?? '');
  if ($current === '' || $next === '' || strlen($next) < 6) {
    json_out(["error"=>"VALIDATION"], 400);
  }

  $username = (string)($_SESSION['admin_user'] ?? '');
  $st = $db->prepare("SELECT id, password_hash FROM admin_users WHERE username=? LIMIT 1");
  $st->execute([$username]);
  $user = $st->fetch();
  if (!$user) json_out(["error"=>"FORBIDDEN"], 403);

  // Rate limit password change attempts too (by IP)
  $ip = client_ip();
  $rl = auth_rate_limited($db, $ip, $username);
  if (!empty($rl['limited'])) {
    log_auth_history($db, null, $u, false, "RATE_LIMITED");
    json_out(["error"=>"RATE_LIMITED", "retryAfter"=>(int)$rl['retryAfter']], 429);
  }

  $ok = password_verify($current, (string)$user['password_hash']);
  auth_log_attempt($db, $ip, $username, $ok);
  if (!$ok) json_out(["error"=>"INVALID_CREDENTIALS"], 401);

  $hash = password_hash($next, PASSWORD_DEFAULT);
  $st2 = $db->prepare("UPDATE admin_users SET password_hash=? WHERE id=?");
  $st2->execute([$hash, (int)$user['id']]);

  // Revoke all sessions for this user
  $db->prepare("DELETE FROM admin_sessions WHERE user_id=?")->execute([(int)$user['id']]);
  remember_clear_cookie();

  json_out(["ok"=>true]);
}


if ($path === '/auth/sessions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  require_admin();
  $username = (string)($_SESSION['admin_user'] ?? '');
  $stU = $db->prepare("SELECT id FROM admin_users WHERE username=? LIMIT 1");
  $stU->execute([$username]);
  $uid = (int)($stU->fetchColumn() ?? 0);
  if ($uid <= 0) json_out(["items"=>[]]);

  $cur = remember_parse_cookie();
  $curSelector = $cur['selector'] ?? null;
  $curSid = session_id();

  $st = $db->prepare("SELECT id, kind, session_id, selector, ip, user_agent, created_at, last_used_at, expires_at
                      FROM admin_sessions
                      WHERE user_id=? AND expires_at > NOW()
                      ORDER BY last_used_at DESC, created_at DESC");
  $st->execute([$uid]);
  $items = $st->fetchAll();
  $out = [];
  foreach ($items as $it) {
    $out[] = [
      "id" => (int)$it["id"],
      "kind" => $it["kind"],
      "sessionId" => $it["session_id"],
      "selector" => $it["selector"],
      "ip" => $it["ip"],
      "userAgent" => $it["user_agent"],
      "createdAt" => $it["created_at"],
      "lastUsedAt" => $it["last_used_at"],
      "expiresAt" => $it["expires_at"],
      "isCurrent" => (
        ($it['kind'] === 'long' && $curSelector !== null && $it['selector'] && hash_equals((string)$curSelector, (string)$it["selector"]))
        || ($it['kind'] === 'short' && $curSid && $it['session_id'] && hash_equals((string)$curSid, (string)$it['session_id']))
      )
    ];
  }
  json_out(["items"=>$out]);
}

if (preg_match('#^/auth/sessions/(\d+)/revoke$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin();
  $sidId = (int)$m[1];

  $username = (string)($_SESSION['admin_user'] ?? '');
  $stU = $db->prepare("SELECT id FROM admin_users WHERE username=? LIMIT 1");
  $stU->execute([$username]);
  $uid = (int)($stU->fetchColumn() ?? 0);

  // Get session info first
  $stG = $db->prepare("SELECT kind, selector, session_id FROM admin_sessions WHERE id=? AND user_id=? LIMIT 1");
  $stG->execute([$sidId, $uid]);
  $row = $stG->fetch();
  if ($row) {
    $db->prepare("DELETE FROM admin_sessions WHERE id=? AND user_id=?")->execute([$sidId, $uid]);
    // if revoked current session
    if ($row['kind'] === 'long') {
      $cur = remember_parse_cookie();
      if ($cur && $row['selector'] && hash_equals($cur['selector'], $row['selector'])) {
        // Clear remember-me cookie and also end current short session
        remember_clear_cookie();
        $curSid = session_id();
        if ($curSid) {
          $db->prepare("DELETE FROM admin_sessions WHERE user_id=? AND kind='short' AND session_id=?")->execute([$uid, $curSid]);
          $_SESSION = [];
          session_destroy();
        }
      }
    }
    if ($row['kind'] === 'short') {
      if ($row['session_id'] && session_id() && hash_equals((string)$row['session_id'], (string)session_id())) {
        $_SESSION = [];
        session_destroy();
      }
    }
  }

  json_out(["ok"=>true]);
}

if ($path === '/auth/sessions/revoke-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin();
  $username = (string)($_SESSION['admin_user'] ?? '');
  $stU = $db->prepare("SELECT id FROM admin_users WHERE username=? LIMIT 1");
  $stU->execute([$username]);
  $uid = (int)($stU->fetchColumn() ?? 0);

  $db->prepare("DELETE FROM admin_sessions WHERE user_id=?")->execute([$uid]);
  remember_clear_cookie();
  // also end current session
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  json_out(["ok"=>true, "loggedOut"=>true]);
}


if ($path === '/auth/history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  require_admin();
  $username = (string)($_SESSION['admin_user'] ?? '');
  $st = $db->prepare("SELECT ip, username, success, created_at FROM auth_attempts WHERE username=? ORDER BY created_at DESC LIMIT 200");
  $st->execute([$username]);
  json_out(["items"=>$st->fetchAll()]);
}

// Admin users management (owner only)
if ($path === '/auth/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  require_owner();
  $st = $db->query("SELECT id, username, role, permissions_json, comment, created_at FROM admin_users ORDER BY created_at ASC");
  json_out(["items"=>$st->fetchAll()]);
}

if ($path === '/auth/users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_owner();
  $in = json_in();
  $u = trim((string)($in['username'] ?? ''));
  $p = (string)($in['password'] ?? '');
  $comment = trim((string)($in['comment'] ?? ''));
  $perms = $in['permissions'] ?? null;
  if (!is_array($perms)) $perms = [];
  // whitelist known permissions
  $allowedKeys = ['links','branding','users'];
  $clean = [];
  foreach ($allowedKeys as $k) { if (!empty($perms[$k])) $clean[$k] = 1; }
  if ($u === '' || $p === '' || strlen($p) < 6) json_out(["error"=>"VALIDATION"], 400);

  $hash = password_hash($p, PASSWORD_DEFAULT);
  $permsJson = json_encode($clean, JSON_UNESCAPED_UNICODE);

  try {
    $st = $db->prepare("INSERT INTO admin_users(username,password_hash,role,permissions_json,comment) VALUES(?,?,?,?,?)");
    $st->execute([$u, $hash, 'admin', $permsJson, $comment !== '' ? $comment : null]);
  } catch (Throwable $e) {
    json_out(["error"=>"DB"], 400);
  }
  json_out(["ok"=>true]);
}

if (preg_match('#^/auth/users/(\d+)/delete$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_owner();
  $id = (int)$m[1];
  // prevent deleting self
  $me = (string)($_SESSION['admin_user'] ?? '');
  $stMe = $db->prepare("SELECT id FROM admin_users WHERE username=? LIMIT 1");
  $stMe->execute([$me]);
  $meId = (int)($stMe->fetchColumn() ?? 0);
  if ($id === $meId) json_out(["error"=>"CANNOT_DELETE_SELF"], 409);
  $stChk = $db->prepare("SELECT role FROM admin_users WHERE id=? LIMIT 1");
  $stChk->execute([$id]);
  $role = (string)($stChk->fetchColumn() ?? '');
  if ($role === 'owner') json_out(["error"=>"CANNOT_DELETE_OWNER"], 409);
  $db->prepare("DELETE FROM admin_users WHERE id=?")->execute([$id]);
  json_out(["ok"=>true]);
}

// Update user permissions (owner only)
if (preg_match('#^/auth/users/(\d+)/permissions$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_owner();
  $uidEdit = (int)$m[1];
  $in = json_in();
  $perms = $in['permissions'] ?? null;
  if (!is_array($perms)) $perms = [];
  $allowedKeys = ['links','branding','users'];
  $clean = [];
  foreach ($allowedKeys as $k) { if (!empty($perms[$k])) $clean[$k] = 1; }
  $permsJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
  $st = $db->prepare('UPDATE admin_users SET permissions_json=? WHERE id=?');
  $st->execute([$permsJson, $uidEdit]);
  json_out(['ok'=>true]);
}


// Create tokens management
if ($path === '/create-tokens' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  require_perm('links');
  $st = $db->prepare("SELECT token,label,active,created_at FROM create_tokens WHERE created_by=? ORDER BY created_at DESC");
  $st->execute([admin_id()]);
  json_out(["items"=>$st->fetchAll()]);
}

if ($path === '/create-tokens' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_perm('links');
  $in = json_in();
  $label = trim((string)($in['label'] ?? ''));
  $token = random_token(32);
  $st = $db->prepare("INSERT INTO create_tokens(token,label,active,created_by) VALUES(?,?,1,?)");
  $st->execute([$token, $label ?: null, admin_id()]);
  json_out(["ok"=>true, "token"=>$token, "createUrl"=>"/create/".$token]);
}

if (preg_match('#^/create-tokens/([^/]+)/revoke$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_perm('links');
  $tok = $m[1];
  $st = $db->prepare("UPDATE create_tokens SET active=0 WHERE token=? AND created_by=?");
  $st->execute([$tok, admin_id()]);
  json_out(["ok"=>true]);
}

// Access check for create pages
if ($path === '/create/access' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $tok = trim((string)($_GET['token'] ?? ''));
  if (is_admin()) json_out(["ok"=>true, "mode"=>"admin"]);
  if ($tok === '') json_out(["ok"=>false], 403);
  $st = $db->prepare("SELECT active FROM create_tokens WHERE token=? LIMIT 1");
  $st->execute([$tok, admin_id()]);
  $active = (int)($st->fetchColumn() ?? 0);
  if ($active !== 1) json_out(["ok"=>false], 403);
  json_out(["ok"=>true, "mode"=>"token"]);
}

// Create queue
if ($path === '/queues' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = json_in();

  // Protect creation: admin session OR valid create token
  if (!is_admin()) {
    $ct = trim((string)($in['createToken'] ?? ''));
    if ($ct === '') json_out(["error"=>"FORBIDDEN"], 403);
    $st = $db->prepare("SELECT active FROM create_tokens WHERE token=? LIMIT 1");
    $st->execute([$ct]);
    $active = (int)($st->fetchColumn() ?? 0);
    if ($active !== 1) json_out(["error"=>"FORBIDDEN"], 403);
  }
  $name = trim((string)($in['queueName'] ?? ''));
  if ($name === '') json_out(["error"=>"VALIDATION", "field"=>"queueName"], 400);

  $publicId = (string)($in['publicId'] ?? '');
  if ($publicId === '') $publicId = (string)random_int(1000000, 9999999);

  $adminToken = random_token(45);

  $requireUser = (bool)($in['requireUserInput'] ?? true);
  $prompt = trim((string)($in['userPrompt'] ?? ''));
  $inputMask = strtolower(trim((string)($in['inputMask'] ?? 'uuid')));
  if (!in_array($inputMask, ['uuid','digits','any'], true)) $inputMask = 'uuid';
  if ($requireUser) {
    if ($prompt === '') json_out(["error"=>"VALIDATION", "field"=>"userPrompt"], 400);
  } else {
    if ($prompt === '') $prompt = "";
  }

  $allowSelf = (bool)($in['allowSelfRegistration'] ?? true);
  $maxCapacity = (int)($in['maxCapacity'] ?? 1000);
  if ($maxCapacity <= 0) $maxCapacity = 1000;

  $statusLang = strtolower(trim((string)($in['statusLang'] ?? 'auto')));
  if (!in_array($statusLang, ['auto','ru','en'], true)) $statusLang = 'auto';
  $ttsLang = trim((string)($in['ttsLang'] ?? 'auto'));
  if ($ttsLang === '') $ttsLang = 'auto';

  $db->beginTransaction();
  try {
    $st = $db->prepare("INSERT INTO queues(public_id,name,require_user_id,user_prompt,input_mask,allow_self_registration,max_capacity,status_lang,tts_lang,admin_token) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
      $publicId,
      $name,
      $requireUser ? 1 : 0,
      $prompt,
      $inputMask,
      $allowSelf ? 1 : 0,
      $maxCapacity,
      $statusLang,
      $ttsLang,
      $adminToken
    ]);
    $queueId = (int)$db->lastInsertId();

    $services = $in['services'] ?? [];
    // allow 0 services (then selection is hidden on client/admin)
    if (!is_array($services)) $services = [];
    $i = 0;
    $seen = [];
    foreach ($services as $s) {
      $code = trim((string)$s);
      if ($code === '') continue;
      $k = mb_strtolower($code);
      if (isset($seen[$k])) continue; // ignore duplicates from UI
      $seen[$k] = true;
      $label = $code;
      // avoid 500 on duplicates (keep UNIQUE constraint)
      $st = $db->prepare("INSERT IGNORE INTO services(queue_id,code,label,sort_order) VALUES(?,?,?,?)");
      $st->execute([$queueId, $code, $label, $i++]);
    }

    $cashiers = $in['cashiers'] ?? [];
    // if none provided, default to "Касса 1"
    if (!is_array($cashiers) || count($cashiers) === 0) $cashiers = ["Касса 1"];
    $idx = 1;
    foreach ($cashiers as $c) {
      $nm = trim((string)$c);
      if ($nm === '') $nm = "Касса " . $idx;
      $st = $db->prepare("INSERT INTO cashiers(queue_id,idx,name) VALUES(?,?,?)");
      $st->execute([$queueId, $idx, $nm]);
      $idx++;
    }

    $db->commit();
  } catch (Throwable $e) {
    $db->rollBack();
    json_out(["error"=>"DB", "message"=>$e->getMessage()], 500);
  }

  json_out([
    "publicId"=>$publicId,
    "adminToken"=>$adminToken,
    "urls"=>[
      "queue"=>"/".$publicId,
      "poster"=>"/poster/".$publicId,
      "admin"=>"/a/".$publicId."/".$adminToken,
      "status"=>"/status/".$publicId,
    ]
  ]);
}

// Public queue info
if (preg_match('#^/queue/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $publicId = $m[1];
  $q = get_queue($db, $publicId);
  if (!$q) json_out(["error"=>"QUEUE_NOT_FOUND"], 404);

  json_out([
    "publicId"=>$q['public_id'],
    "queueName"=>$q['name'],
    "requireUserInput"=> (bool)$q['require_user_id'],
    "userPrompt"=>$q['user_prompt'],
    "inputMask"=>$q['input_mask'],
    "allowSelfRegistration"=> (bool)$q['allow_self_registration'],
    "maxCapacity"=> (int)$q['max_capacity'],
    "statusLang"=> $q['status_lang'],
    "ttsLang"=> $q['tts_lang'],
    "branding"=>[
      "primary"=>$q['brand_primary'],
      "accent"=>$q['brand_accent'],
      "logoQueue"=>$q['logo_queue'],
      "logoAdmin"=>$q['logo_admin'],
      "logoPoster"=>$q['logo_poster'],
      "texts"=> ($q['texts_json'] ? json_decode((string)$q['texts_json'], true) : null)
    ],
    "services"=>get_queue_services($db, (int)$q['id']),
    "cashiers"=>get_queue_cashiers($db, (int)$q['id']),
  ]);
}

if ($path === "/push/public-config" && $_SERVER['REQUEST_METHOD'] === 'GET') {
  json_out(["publicKey"=>app_vapid_public_key()]);
}

// Join queue
if (preg_match('#^/queue/(\d+)/join$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1];
  $q = get_queue($db, $publicId);
  if (!$q) json_out(["error"=>"QUEUE_NOT_FOUND"], 404);

  $in = json_in();
  $userId = trim((string)($in['userId'] ?? ''));
  $service = trim((string)($in['service'] ?? ''));

  if (!(bool)$q['allow_self_registration']) {
    json_out(["error"=>"SELF_REG_DISABLED"], 403);
  }

  // capacity check (only waiting)
  $st = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='waiting'");
  $st->execute([(int)$q['id']]);
  $waitingCount = (int)$st->fetchColumn();
  $cap = (int)$q['max_capacity'];
  if ($cap > 0 && $waitingCount >= $cap) {
    json_out(["error"=>"QUEUE_FULL"], 409);
  }

  if ((bool)$q['require_user_id']) {
    if ($userId === '') json_out(["error"=>"VALIDATION","field"=>"userId"], 400);
    $mask = strtolower((string)($q['input_mask'] ?? 'uuid'));
    if ($mask === 'digits' && !preg_match('/^[0-9]{1,32}$/', $userId)) {
      json_out(["error"=>"VALIDATION","field"=>"userId"], 400);
    }
    if ($mask === 'uuid' && !preg_match('/^[0-9a-fA-F-]{6,64}$/', $userId)) {
      json_out(["error"=>"VALIDATION","field"=>"userId"], 400);
    }
  }

  $st = $db->prepare("SELECT COUNT(*) FROM services WHERE queue_id=?");
  $st->execute([(int)$q['id']]);
  $svcCount = (int)$st->fetchColumn();
  $serviceCode = null;
  if ($svcCount > 0) {
    if ($service === '') json_out(["error"=>"VALIDATION","field"=>"service"], 400);
    $st = $db->prepare("SELECT 1 FROM services WHERE queue_id=? AND code=?");
    $st->execute([(int)$q['id'], $service]);
    if (!$st->fetchColumn()) json_out(["error"=>"VALIDATION","field"=>"service"], 400);
    $serviceCode = $service;
  }

  $st = $db->prepare("SELECT COALESCE(MAX(number),0)+1 FROM tickets WHERE queue_id=?");
  $st->execute([(int)$q['id']]);
  $num = (int)$st->fetchColumn();

  $uuid = uuidv4();
  $st = $db->prepare("INSERT INTO tickets(queue_id,uuid,user_id,service_code,number,status) VALUES(?,?,?,?,?,'waiting')");
  $st->execute([(int)$q['id'], $uuid, ((bool)$q['require_user_id'] ? ($userId ?: null) : null), $serviceCode, $num]);

  json_out([
    "ticketUuid"=>$uuid,
    "number"=>$num,
    "displayNumber"=>fmt_ticket($num)
  ]);
}

if (preg_match('#^/queue/(\d+)/push-subscribe$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1];
  $q = get_queue($db, $publicId);
  if (!$q) json_out(["error"=>"QUEUE_NOT_FOUND"], 404);
  app_ensure_push_subscriptions($db);
  $in = json_in();
  $sub = $in['subscription'] ?? null;
  if (!is_array($sub) || empty($sub['endpoint'])) json_out(["error"=>"VALIDATION","field"=>"subscription"], 400);
  $ticketUuid = null;
  if (!empty($in['ticketUuid']) && preg_match('/^[0-9a-f-]{36}$/i', (string)$in['ticketUuid'])) $ticketUuid = strtolower((string)$in['ticketUuid']);
  $endpoint = (string)$sub['endpoint'];
  $payload = json_encode($sub, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $st = $db->prepare("INSERT INTO push_subscriptions(queue_id, ticket_uuid, endpoint, subscription_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE queue_id=VALUES(queue_id), ticket_uuid=COALESCE(VALUES(ticket_uuid), ticket_uuid), subscription_json=VALUES(subscription_json), updated_at=NOW()");
  $st->execute([(int)$q['id'], $ticketUuid, $endpoint, $payload]);
  json_out(["ok"=>true]);
}

// Ticket status
if (preg_match('#^/ticket/([0-9a-f-]{36})$#i', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $uuid = strtolower($m[1]);
  $st = $db->prepare("SELECT t.*, q.public_id, q.name FROM tickets t JOIN queues q ON q.id=t.queue_id WHERE t.uuid=?");
  $st->execute([$uuid]);
  $t = $st->fetch();
  if (!$t) json_out(["error"=>"TICKET_NOT_FOUND"], 404);

  $ahead = 0;
  if ($t['status'] === 'waiting') {
    $st = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='waiting' AND number < ?");
    $st->execute([(int)$t['queue_id'], (int)$t['number']]);
    $ahead = (int)$st->fetchColumn();
  }

  $cashierName = null;
  if ($t['status'] === 'called' && $t['called_cashier_idx']) {
    $st = $db->prepare("SELECT name FROM cashiers WHERE queue_id=? AND idx=?");
    $st->execute([(int)$t['queue_id'], (int)$t['called_cashier_idx']]);
    $cashierName = $st->fetchColumn() ?: null;
  }

  json_out([
    "queuePublicId"=>$t['public_id'],
    "queueName"=>$t['name'],
    "uuid"=>$t['uuid'],
    "number"=>(int)$t['number'],
    "displayNumber"=>fmt_ticket((int)$t['number']),
    "service"=>$t['service_code'],
    "status"=>$t['status'],
    "ahead"=>$ahead,
    "calledCashierIdx"=>$t['called_cashier_idx'] ? (int)$t['called_cashier_idx'] : null,
    "calledCashierName"=>$cashierName,
  ]);
}

// Leave queue
if (preg_match('#^/ticket/([0-9a-f-]{36})/leave$#i', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $uuid = strtolower($m[1]);
  $st = $db->prepare("UPDATE tickets SET status='left' WHERE uuid=? AND status IN ('waiting','called')");
  $st->execute([$uuid]);
  json_out(["ok"=>true]);
}

// Status screen data
if (preg_match('#^/status/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $publicId = $m[1];
  $q = get_queue($db, $publicId);
  if (!$q) json_out(["error"=>"QUEUE_NOT_FOUND"], 404);
  $queueId = (int)$q['id'];

  $st = $db->prepare("SELECT number FROM tickets WHERE queue_id=? AND status='waiting' ORDER BY number ASC LIMIT 2");
  $st->execute([$queueId]);
  $prepareNums = array_map(fn($r)=>fmt_ticket_plain((int)$r['number']), $st->fetchAll());

  $cashiersAll = get_queue_cashiers($db, $queueId);
  // paused cashiers must not be shown on status screen
  $cashiers = array_values(array_filter($cashiersAll, fn($c)=>((int)($c['paused'] ?? 0) === 0) && ((int)($c['hidden'] ?? 0) === 0)));
  $cashMap = [];
  foreach ($cashiers as $c) {
    $n = $c['current_ticket_number'] ? fmt_ticket_plain((int)$c['current_ticket_number']) : "000";
    $cashMap[(int)$c['idx']] = $n;
  }

  $st = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='waiting'");
  $st->execute([$queueId]);
  $queueCount = (int)$st->fetchColumn();

  json_out([
    "prepare"=>$prepareNums,
    "cashiers"=>$cashMap,
    "queueCount"=>$queueCount,
    "statusLang"=>$q['status_lang'],
    "ttsLang"=>$q['tts_lang']
  ]);
}

// Admin state
if (preg_match('#^/admin/(\d+)/([^/]+)/state$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $st = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='waiting'");
  $st->execute([$queueId]);
  $queueCount = (int)$st->fetchColumn();

  $cashiers = get_queue_cashiers($db, $queueId);
  // current called ticket details per cashier
  $current = [];
  $lastServed = [];
  foreach ($cashiers as $c) {
    $idx = (int)$c['idx'];
    $st = $db->prepare("SELECT uuid, number, service_code, user_id FROM tickets WHERE queue_id=? AND status='called' AND called_cashier_idx=? ORDER BY updated_at DESC LIMIT 1");
    $st->execute([$queueId, $idx]);
    $t = $st->fetch();
    if ($t) {
      $stS = $db->prepare("SELECT created_at FROM call_log WHERE queue_id=? AND cashier_idx=? AND ticket_number=? ORDER BY created_at DESC LIMIT 1");
      $stS->execute([$queueId, $idx, (int)$t['number']]);
      $startedAt = $stS->fetchColumn();
      $current[$idx] = [
        "uuid"=>$t['uuid'],
        "number"=>(int)$t['number'],
        "displayNumber"=>fmt_ticket((int)$t['number']),
        "service"=>$t['service_code'],
        "userId"=>$t['user_id'],
        "serviceStartedAt"=>$startedAt,
      ];
    } else {
      $current[$idx] = null;
    }

    $stL = $db->prepare("SELECT t.uuid, t.number, t.service_code, t.user_id, t.updated_at AS served_at
                         FROM tickets t
                         JOIN call_log cl ON cl.queue_id=t.queue_id AND cl.ticket_number=t.number AND cl.cashier_idx=?
                         WHERE t.queue_id=? AND t.status='served'
                         ORDER BY t.updated_at DESC LIMIT 1");
    $stL->execute([$idx, $queueId]);
    $ls = $stL->fetch();
    if ($ls) {
      $lastServed[$idx] = [
        "uuid"=>$ls['uuid'],
        "number"=>(int)$ls['number'],
        "displayNumber"=>fmt_ticket((int)$ls['number']),
        "service"=>$ls['service_code'],
        "userId"=>$ls['user_id'],
        "servedAt"=>$ls['served_at'],
      ];
    } else {
      $lastServed[$idx] = null;
    }
  }

  json_out([
    "queueName"=>$q['name'],
    "publicId"=>$q['public_id'],
    "queueCount"=>$queueCount,
    "settings"=>[
      "allowSelfRegistration"=>(bool)$q['allow_self_registration'],
      "requireUserInput"=>(bool)$q['require_user_id'],
      "userPrompt"=>$q['user_prompt'],
      "inputMask"=>$q['input_mask'],
      "maxCapacity"=>(int)$q['max_capacity'],
      "statusLang"=>$q['status_lang'],
      "ttsLang"=>$q['tts_lang'],
      "brandPrimary"=>$q['brand_primary'],
      "brandAccent"=>$q['brand_accent'],
      "logoQueue"=>$q['logo_queue'],
      "logoPoster"=>$q['logo_poster'],
      "texts"=> ($q['texts_json'] ? json_decode((string)$q['texts_json'], true) : null),
    ],
    "services"=>get_queue_services($db, $queueId),
    "cashiers"=>$cashiers,
    "currentCalled"=>$current,
    "lastServed"=>$lastServed
  ]);
}

// Admin settings (load)
if (preg_match('#^/admin/(\d+)/([^/]+)/settings$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  json_out([
    "queueName"=>$q['name'],
    "publicId"=>$q['public_id'],
    "allowSelfRegistration"=>(bool)$q['allow_self_registration'],
    "requireUserInput"=>(bool)$q['require_user_id'],
    "userPrompt"=>$q['user_prompt'],
    "inputMask"=>$q['input_mask'],
    "maxCapacity"=>(int)$q['max_capacity'],
    "statusLang"=>$q['status_lang'],
    "ttsLang"=>$q['tts_lang'],
    "brandPrimary"=>$q['brand_primary'],
    "brandAccent"=>$q['brand_accent'],
    "logoQueue"=>$q['logo_queue'],
    "logoPoster"=>$q['logo_poster'],
    "texts"=> ($q['texts_json'] ? json_decode((string)$q['texts_json'], true) : null),
  ]);
}

// Admin settings (save)
if (preg_match('#^/admin/(\d+)/([^/]+)/settings$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];
  $in = json_in();

  $name = trim((string)($in['queueName'] ?? $q['name']));
  if ($name === '') $name = $q['name'];

  $allowSelf = isset($in['allowSelfRegistration']) ? (bool)$in['allowSelfRegistration'] : (bool)$q['allow_self_registration'];
  $requireUser = isset($in['requireUserInput']) ? (bool)$in['requireUserInput'] : (bool)$q['require_user_id'];
  $prompt = trim((string)($in['userPrompt'] ?? $q['user_prompt']));
  $inputMask = strtolower(trim((string)($in['inputMask'] ?? ($q['input_mask'] ?? 'uuid'))));
  if (!in_array($inputMask, ['uuid','digits','any'], true)) $inputMask = 'uuid';
  if ($requireUser) {
    if ($prompt === '') json_out(["error"=>"VALIDATION","field"=>"userPrompt"], 400);
  } else {
    if ($prompt === '') $prompt = '';
  }

  $maxCapacity = (int)($in['maxCapacity'] ?? $q['max_capacity']);
  if ($maxCapacity <= 0) $maxCapacity = 1000;

  $statusLang = strtolower(trim((string)($in['statusLang'] ?? $q['status_lang'])));
  if (!in_array($statusLang, ['auto','ru','en'], true)) $statusLang = 'auto';
  $ttsLang = trim((string)($in['ttsLang'] ?? $q['tts_lang']));
  if ($ttsLang === '') $ttsLang = 'auto';

  $brandPrimary = trim((string)($in['brandPrimary'] ?? $q['brand_primary'] ?? ''));
  $brandAccent = trim((string)($in['brandAccent'] ?? $q['brand_accent'] ?? ''));
  $logoQueue = trim((string)($in['logoQueue'] ?? $q['logo_queue'] ?? ''));
  $logoPoster = trim((string)($in['logoPoster'] ?? $q['logo_poster'] ?? ''));
  $texts = $in['texts'] ?? null;
  $textsJson = null;
  if (is_array($texts)) {
    $textsJson = json_encode($texts, JSON_UNESCAPED_UNICODE);
  }

  // basic validation: allow empty or #RRGGBB
  if ($brandPrimary !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $brandPrimary)) $brandPrimary = null;
  if ($brandAccent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $brandAccent)) $brandAccent = null;
  if ($logoQueue === '') $logoQueue = null;
  if ($logoPoster === '') $logoPoster = null;

  $st = $db->prepare("UPDATE queues SET name=?, allow_self_registration=?, require_user_id=?, user_prompt=?, input_mask=?, max_capacity=?, status_lang=?, tts_lang=?, brand_primary=?, brand_accent=?, logo_queue=?, logo_poster=?, texts_json=? WHERE id=?");
  $st->execute([
    $name,
    $allowSelf ? 1 : 0,
    $requireUser ? 1 : 0,
    $prompt,
    $inputMask,
    $maxCapacity,
    $statusLang,
    $ttsLang,
    $brandPrimary,
    $brandAccent,
    $logoQueue,
    $logoPoster,
    $textsJson,
    $queueId
  ]);

  json_out(["ok"=>true]);
}

// Admin waiting list
if (preg_match('#^/admin/(\d+)/([^/]+)/waiting$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $st = $db->prepare("SELECT uuid, number, service_code, user_id FROM tickets WHERE queue_id=? AND status='waiting' ORDER BY number ASC LIMIT 200");
  $st->execute([$queueId]);
  $rows = $st->fetchAll();
  foreach ($rows as &$r) $r['displayNumber'] = fmt_ticket((int)$r['number']);
  json_out(["items"=>$rows]);
}

// Invite next
if (preg_match('#^/admin/(\d+)/([^/]+)/invite/next$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  $forceUnsupported = (bool)($in['forceUnsupported'] ?? false);

  $st = $db->prepare("SELECT paused FROM cashiers WHERE queue_id=? AND idx=?");
  $st->execute([$queueId, $cashierIdx]);
  $paused = (int)($st->fetchColumn() ?? 0);
  if ($paused) json_out(["error"=>"CASHIER_PAUSED"], 409);

  // allowed services filtering
  $stA = $db->prepare("SELECT allowed_services FROM cashiers WHERE queue_id=? AND idx=?");
  $stA->execute([$queueId, $cashierIdx]);
  $allowedRaw = (string)($stA->fetchColumn() ?? '');
  $allowed = array_values(array_filter(array_map('trim', $allowedRaw !== '' ? explode(',', $allowedRaw) : [])));

  // do not allow calling next when cashier already has active called client
  $stB = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='called' AND called_cashier_idx=?");
  $stB->execute([$queueId, $cashierIdx]);
  if ((int)$stB->fetchColumn() > 0) json_out(["error"=>"CASHIER_BUSY"], 409);

  $db->beginTransaction();
  try {
    $t = null;

    if (count($allowed) > 0) {
      // 1) supported first
      $ph = implode(',', array_fill(0, count($allowed), '?'));
      $sql = "SELECT * FROM tickets WHERE queue_id=? AND status='waiting' AND (service_code IS NULL OR service_code IN ($ph)) ORDER BY number ASC LIMIT 1 FOR UPDATE";
      $st = $db->prepare($sql);
      $st->execute(array_merge([$queueId], $allowed));
      $t = $st->fetch();

      // 2) fallback to any (may be unsupported)
      if (!$t) {
        $st = $db->prepare("SELECT * FROM tickets WHERE queue_id=? AND status='waiting' ORDER BY number ASC LIMIT 1 FOR UPDATE");
        $st->execute([$queueId]);
        $t = $st->fetch();
        if ($t && !$forceUnsupported) {
          $db->commit();
          json_out([
            "ok"=>false,
            "reason"=>"UNSUPPORTED",
            "ticket"=>[
              "uuid"=>$t['uuid'],
              "number"=>(int)$t['number'],
              "displayNumber"=>fmt_ticket((int)$t['number']),
              "service"=>$t['service_code'],
              "cashierIdx"=>$cashierIdx
            ]
          ]);
        }
      }
    } else {
      $st = $db->prepare("SELECT * FROM tickets WHERE queue_id=? AND status='waiting' ORDER BY number ASC LIMIT 1 FOR UPDATE");
      $st->execute([$queueId]);
      $t = $st->fetch();
    }

    if (!$t) { $db->commit(); json_out(["ok"=>false, "reason"=>"EMPTY"]); }

    $st = $db->prepare("UPDATE tickets SET status='called', called_cashier_idx=? WHERE id=?");
    $st->execute([$cashierIdx, (int)$t['id']]);

    $st = $db->prepare("UPDATE cashiers SET current_ticket_number=? WHERE queue_id=? AND idx=?");
    $st->execute([(int)$t['number'], $queueId, $cashierIdx]);

    $st = $db->prepare("INSERT INTO call_log(queue_id,cashier_idx,ticket_number) VALUES(?,?,?)");
    $st->execute([$queueId, $cashierIdx, (int)$t['number']]);

    $db->commit();
    $cashierLabel = (string)$cashierIdx;
    try {
      $stC = $db->prepare("SELECT name FROM cashiers WHERE queue_id=? AND idx=? LIMIT 1");
      $stC->execute([$queueId, $cashierIdx]);
      $cashierLabel = (string)($stC->fetchColumn() ?: $cashierIdx);
    } catch (Throwable $e) {}
    try { app_push_notify_ticket_called($db, $queueId, (string)$q['public_id'], (string)$t['uuid'], fmt_ticket((int)$t['number']), $cashierLabel); } catch (Throwable $e) {}
    json_out([
      "ok"=>true,
      "ticket"=>[
        "uuid"=>$t['uuid'],
        "number"=>(int)$t['number'],
        "displayNumber"=>fmt_ticket((int)$t['number']),
        "service"=>$t['service_code'],
        "cashierIdx"=>$cashierIdx
      ]
    ]);
  } catch (Throwable $e) {
    $db->rollBack();
    json_out(["error"=>"DB","message"=>$e->getMessage()], 500);
  }
}

// Invite by number
if (preg_match('#^/admin/(\d+)/([^/]+)/invite/byNumber$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  $number = (int)($in['number'] ?? 0);
  if ($number <= 0) json_out(["error"=>"VALIDATION","field"=>"number"], 400);

  $st = $db->prepare("SELECT * FROM tickets WHERE queue_id=? AND number=? AND status='waiting' LIMIT 1");
  $st->execute([$queueId, $number]);
  $t = $st->fetch();
  if (!$t) json_out(["error"=>"NOT_FOUND"], 404);

  // detect allowed services (for UI warning only)
  $stA = $db->prepare("SELECT allowed_services FROM cashiers WHERE queue_id=? AND idx=?");
  $stA->execute([$queueId, $cashierIdx]);
  $allowedRaw = (string)($stA->fetchColumn() ?? '');
  $allowed = array_values(array_filter(array_map('trim', $allowedRaw !== '' ? explode(',', $allowedRaw) : [])));
  $unsupported = (count($allowed) > 0 && $t['service_code'] !== null && !in_array((string)$t['service_code'], $allowed, true));

  // busy check
  $stB = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='called' AND called_cashier_idx=?");
  $stB->execute([$queueId, $cashierIdx]);
  if ((int)$stB->fetchColumn() > 0) json_out(["error"=>"CASHIER_BUSY"], 409);

  $db->beginTransaction();
  try {
    $st = $db->prepare("UPDATE tickets SET status='called', called_cashier_idx=? WHERE id=?");
    $st->execute([$cashierIdx, (int)$t['id']]);

    $st = $db->prepare("UPDATE cashiers SET current_ticket_number=? WHERE queue_id=? AND idx=?");
    $st->execute([$number, $queueId, $cashierIdx]);

    $st = $db->prepare("INSERT INTO call_log(queue_id,cashier_idx,ticket_number) VALUES(?,?,?)");
    $st->execute([$queueId, $cashierIdx, $number]);

    $db->commit();
  } catch (Throwable $e) {
    $db->rollBack();
    json_out(["error"=>"DB","message"=>$e->getMessage()], 500);
  }

  $cashierLabel = (string)$cashierIdx;
  try {
    $stC = $db->prepare("SELECT name FROM cashiers WHERE queue_id=? AND idx=? LIMIT 1");
    $stC->execute([$queueId, $cashierIdx]);
    $cashierLabel = (string)($stC->fetchColumn() ?: $cashierIdx);
  } catch (Throwable $e) {}
  try { app_push_notify_ticket_called($db, $queueId, (string)$q['public_id'], (string)$t['uuid'], fmt_ticket((int)$t['number']), $cashierLabel); } catch (Throwable $e) {}

  json_out(["ok"=>true, "unsupported"=>$unsupported, "ticket"=>[
    "uuid"=>$t['uuid'],
    "number"=>(int)$t['number'],
    "displayNumber"=>fmt_ticket((int)$t['number']),
    "service"=>$t['service_code'],
    "cashierIdx"=>$cashierIdx
  ]]);
}

// Return currently called ticket back to waiting (admin)
if (preg_match('#^/admin/(\d+)/([^/]+)/ticket/return$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $uuid = strtolower(trim((string)($in['uuid'] ?? '')));
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  if ($uuid === '') json_out(["error"=>"VALIDATION","field"=>"uuid"], 400);

  $db->beginTransaction();
  try {
    $st = $db->prepare("UPDATE tickets SET status='waiting', called_cashier_idx=NULL WHERE queue_id=? AND uuid=? AND status='called' AND called_cashier_idx=?");
    $st->execute([$queueId, $uuid, $cashierIdx]);
    $st = $db->prepare("UPDATE cashiers SET current_ticket_number=NULL WHERE queue_id=? AND idx=?");
    $st->execute([$queueId, $cashierIdx]);
    $db->commit();
  } catch (Throwable $e) {
    $db->rollBack();
    json_out(["error"=>"DB","message"=>$e->getMessage()], 500);
  }

  json_out(["ok"=>true]);
}

// Mark currently called ticket as served (admin)
if (preg_match('#^/admin/(\d+)/([^/]+)/ticket/served$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $uuid = strtolower(trim((string)($in['uuid'] ?? '')));
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  if ($uuid === '') json_out(["error"=>"VALIDATION","field"=>"uuid"], 400);

  $db->beginTransaction();
  try {
    $st = $db->prepare("UPDATE tickets SET status='served', called_cashier_idx=NULL WHERE queue_id=? AND uuid=? AND status='called' AND called_cashier_idx=?");
    $st->execute([$queueId, $uuid, $cashierIdx]);
    $st = $db->prepare("UPDATE cashiers SET current_ticket_number=NULL WHERE queue_id=? AND idx=?");
    $st->execute([$queueId, $cashierIdx]);
    $db->commit();
  } catch (Throwable $e) {
    $db->rollBack();
    json_out(["error"=>"DB","message"=>$e->getMessage()], 500);
  }
  json_out(["ok"=>true]);
}

// Add visitor (admin)
if (preg_match('#^/admin/(\d+)/([^/]+)/ticket/add$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $userId = trim((string)($in['userId'] ?? ''));
  $service = trim((string)($in['service'] ?? ''));
  if ((bool)$q['require_user_id'] && $userId === '') json_out(["error"=>"VALIDATION","field"=>"userId"], 400);
  $st = $db->prepare("SELECT COUNT(*) FROM services WHERE queue_id=?");
  $st->execute([$queueId]);
  $svcCount = (int)$st->fetchColumn();
  $serviceCode = null;
  if ($svcCount > 0) {
    if ($service === '') json_out(["error"=>"VALIDATION","field"=>"service"], 400);
    $st = $db->prepare("SELECT 1 FROM services WHERE queue_id=? AND code=?");
    $st->execute([$queueId, $service]);
    if (!$st->fetchColumn()) json_out(["error"=>"VALIDATION","field"=>"service"], 400);
    $serviceCode = $service;
  }

  // capacity check
  $st = $db->prepare("SELECT COUNT(*) FROM tickets WHERE queue_id=? AND status='waiting'");
  $st->execute([$queueId]);
  $waitingCount = (int)$st->fetchColumn();
  $cap = (int)$q['max_capacity'];
  if ($cap > 0 && $waitingCount >= $cap) {
    json_out(["error"=>"QUEUE_FULL"], 409);
  }

  $st = $db->prepare("SELECT COALESCE(MAX(number),0)+1 FROM tickets WHERE queue_id=?");
  $st->execute([$queueId]);
  $num = (int)$st->fetchColumn();

  $uuid = uuidv4();
  $st = $db->prepare("INSERT INTO tickets(queue_id,uuid,user_id,service_code,number,status) VALUES(?,?,?,?,?,'waiting')");
  $st->execute([$queueId, $uuid, ((bool)$q['require_user_id'] ? ($userId ?: null) : null), $serviceCode, $num]);

  json_out(["ok"=>true, "uuid"=>$uuid, "number"=>$num, "displayNumber"=>fmt_ticket($num)]);
}

// Remove visitor (admin)
if (preg_match('#^/admin/(\d+)/([^/]+)/ticket/remove$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $uuid = strtolower(trim((string)($in['uuid'] ?? '')));
  if ($uuid === '') json_out(["error"=>"VALIDATION","field"=>"uuid"], 400);

  $st = $db->prepare("UPDATE tickets SET status='removed' WHERE queue_id=? AND uuid=? AND status IN ('waiting','called')");
  $st->execute([$queueId, $uuid]);

  json_out(["ok"=>true]);
}

// Pause cashier
if (preg_match('#^/admin/(\d+)/([^/]+)/cashier/pause$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  $paused = (int)($in['paused'] ?? 0);

  $st = $db->prepare("UPDATE cashiers SET paused=? WHERE queue_id=? AND idx=?");
  $st->execute([$paused ? 1 : 0, $queueId, $cashierIdx]);

  json_out(["ok"=>true]);
}

// Hide cashier
if (preg_match('#^/admin/(\d+)/([^/]+)/cashier/hide$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  $hidden = (int)($in['hidden'] ?? 0);

  $st = $db->prepare("UPDATE cashiers SET hidden=? WHERE queue_id=? AND idx=?");
  $st->execute([$hidden ? 1 : 0, $queueId, $cashierIdx]);

  json_out(["ok"=>true]);
}

// Configure cashier (allowed services)
if (preg_match('#^/admin/(\d+)/([^/]+)/cashier/config$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $publicId = $m[1]; $token = $m[2];
  $q = admin_auth($db, $publicId, $token);
  $queueId = (int)$q['id'];

  $in = json_in();
  $cashierIdx = (int)($in['cashierIdx'] ?? 1);
  $arr = $in['allowedServices'] ?? [];
  if (!is_array($arr)) $arr = [];
  $arr = array_values(array_filter(array_map(fn($x)=>trim((string)$x), $arr)));
  // store as comma-separated codes
  $val = count($arr) ? implode(',', $arr) : null;
  $st = $db->prepare("UPDATE cashiers SET allowed_services=? WHERE queue_id=? AND idx=?");
  $st->execute([$val, $queueId, $cashierIdx]);
  json_out(["ok"=>true]);
}


// Mini-admin: server logs
if ($path === "/admin/logs" && $_SERVER["REQUEST_METHOD"] === "GET") {
  require_perm('users');
  ensure_server_logs($db);
  ensure_log_settings($db);
  $page = max(1, (int)($_GET['page'] ?? 1));
  $perPage = min(200, max(10, (int)($_GET['perPage'] ?? 50)));
  $dateFrom = trim((string)($_GET['dateFrom'] ?? ''));
  $dateTo = trim((string)($_GET['dateTo'] ?? ''));
  $level = trim((string)($_GET['level'] ?? ''));
  $source = trim((string)($_GET['source'] ?? ''));
  $apiStatus = trim((string)($_GET['apiStatus'] ?? ''));
  $where = [];
  $params = [];
  if ($dateFrom !== '') { $where[] = 'created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
  if ($dateTo !== '') { $where[] = 'created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
  if ($level !== '' && in_array($level, ['info','warn','error'], true)) { $where[] = 'level = ?'; $params[] = $level; }
  if ($source !== '') { $where[] = 'source = ?'; $params[] = $source; }
  if ($apiStatus !== '') {
    $statusExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE NULL END, '$.status')) AS CHAR)";
    if ($apiStatus === '2xx') { $where[] = "{$statusExpr} REGEXP '^[2][0-9][0-9]$'"; }
    elseif ($apiStatus === '3xx') { $where[] = "{$statusExpr} REGEXP '^[3][0-9][0-9]$'"; }
    elseif ($apiStatus === '4xx') { $where[] = "{$statusExpr} REGEXP '^[4][0-9][0-9]$'"; }
    elseif ($apiStatus === '5xx') { $where[] = "{$statusExpr} REGEXP '^[5][0-9][0-9]$'"; }
    elseif (preg_match('/^[0-9]{3}$/', $apiStatus)) { $where[] = "{$statusExpr} = ?"; $params[] = $apiStatus; }
  }
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $cnt = $db->prepare("SELECT COUNT(*) FROM server_logs {$whereSql}");
  foreach ($params as $i => $v) $cnt->bindValue($i + 1, $v, PDO::PARAM_STR);
  $cnt->execute();
  $total = (int)$cnt->fetchColumn();
  $pages = max(1, (int)ceil($total / $perPage));
  if ($page > $pages) $page = $pages;
  $offset = max(0, ($page - 1) * $perPage);
  $sql = "SELECT id, level, source, message, context_json, created_at FROM server_logs {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?";
  $st = $db->prepare($sql);
  $i = 1;
  foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
  $st->bindValue($i++, $perPage, PDO::PARAM_INT);
  $st->bindValue($i++, $offset, PDO::PARAM_INT);
  $st->execute();
  $items = $st->fetchAll();
  foreach($items as &$it){
    $it['context'] = $it['context_json'] ? json_decode((string)$it['context_json'], true) : null;
    unset($it['context_json']);
  }
  $srcRows = $db->query("SELECT source, COUNT(*) cnt FROM server_logs GROUP BY source ORDER BY source ASC")->fetchAll();
  $sources = [];
  foreach($srcRows as $srcRow){ if (($srcRow['source'] ?? '') !== null) $sources[] = ['source'=>(string)$srcRow['source'], 'count'=>(int)$srcRow['cnt']]; }
  json_out([
    'items'=>$items,
    'page'=>$page,
    'perPage'=>$perPage,
    'total'=>$total,
    'pages'=>$pages,
    'sources'=>$sources,
    'filters'=>['dateFrom'=>$dateFrom, 'dateTo'=>$dateTo, 'level'=>$level, 'source'=>$source, 'apiStatus'=>$apiStatus],
  ]);
}

// Mini-admin: log settings
if ($path === "/admin/logs/settings" && $_SERVER["REQUEST_METHOD"] === "GET") {
  require_perm('users');
  json_out(["settings"=>logs_settings($db)]);
}
if ($path === "/admin/logs/settings" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  $in = json_in();
  $auto = !empty($in['autoDelete']) ? 1 : 0;
  $keep = max(1, (int)($in['keepDays'] ?? 30));
  ensure_log_settings($db);
  $st = $db->prepare("UPDATE log_settings SET auto_delete=?, keep_days=? WHERE id=1");
  $st->execute([$auto,$keep]);
  json_out(["ok"=>true]);
}

// Mini-admin: clear logs now
if ($path === "/admin/logs/clear" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  ensure_server_logs($db);
  $res = export_logs_to_telegram_and_delete($db, '', [], 'Логи перед ручной очисткой (' . date('Y-m-d H:i:s') . ')');
  if (empty($res['ok'])) json_out(["error"=>"TELEGRAM_SEND_FAILED","message"=>$res['error'] ?? 'Не удалось отправить логи в Telegram перед очисткой'], 500);
  json_out(["ok"=>true,"deleted"=>(int)($res['count'] ?? 0)]);
}

// Mini-admin: backup settings
if ($path === "/admin/backups/settings" && $_SERVER["REQUEST_METHOD"] === "GET") {
  require_perm('users');
  ensure_backup_tables($db);
  $st=$db->query("SELECT enabled, frequency_minutes, auto_delete, keep_days, last_run_at, cleanup_last_run_at FROM backup_settings WHERE id=1");
  $row=$st->fetch() ?: ["enabled"=>0,"frequency_minutes"=>1440,"auto_delete"=>0,"keep_days"=>30,"last_run_at"=>null,"cleanup_last_run_at"=>null];
  json_out(["settings"=>$row]);
}
if ($path === "/admin/backups/settings" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  ensure_backup_tables($db);
  $in=json_in();
  $enabled = !empty($in["enabled"]) ? 1 : 0;
  $freq = max(10, (int)($in["frequencyMinutes"] ?? 1440));
  $autoDelete = !empty($in["autoDelete"]) ? 1 : 0;
  $keepDays = max(1, (int)($in["keepDays"] ?? 30));
  $st=$db->prepare("UPDATE backup_settings SET enabled=?, frequency_minutes=?, auto_delete=?, keep_days=? WHERE id=1");
  $st->execute([$enabled,$freq,$autoDelete,$keepDays]);
  json_out(["ok"=>true]);
}

// Mini-admin: Telegram test
if ($path === "/admin/backups/telegram-test" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  $r = telegram_send_message_ex("✅ Queue: тест Telegram (".date('Y-m-d H:i:s').")");
  if (!$r['configured']) {
    json_out(["ok"=>false,"error"=>"TELEGRAM_NOT_CONFIGURED","message"=>$r['error']], 400);
  }
  if (!$r['ok']) {
    json_out(["ok"=>false,"error"=>"TELEGRAM_SEND_FAILED","message"=>($r['error'] ?: 'Telegram send failed'),"http"=>$r['http']], 500);
  }
  json_out(["ok"=>true]);
}


// Mini-admin: Push test (current browser only)
if ($path === "/admin/push/test" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  if (!app_vapid_public_key() || !app_vapid_private_key()) {
    json_out(["ok"=>false,"error"=>"VAPID_NOT_CONFIGURED","message"=>"VAPID ключи не настроены"], 400);
  }
  $in = json_in();
  $sub = $in['subscription'] ?? null;
  if (!is_array($sub) || empty($sub['endpoint'])) {
    json_out(["ok"=>false,"error"=>"SUBSCRIPTION_REQUIRED","message"=>"Для теста нужна активная push-подписка в текущем браузере"], 400);
  }
  try {
    $basePrefix = trim((string)($sub['appData']['basePrefix'] ?? ''), '/');
    $prefix = $basePrefix !== '' ? '/' . $basePrefix : '';
    $clickUrl = (string)($sub['appData']['ticketPath'] ?? ($prefix !== '' ? ($prefix . '/admin') : '/admin'));
    $payload = [
      'title' => 'Тест push-уведомления',
      'body' => 'Проверка Web Push на ' . date('Y-m-d H:i:s'),
      'icon' => $prefix . '/img/logo.svg',
      'badge' => $prefix . '/img/logo.svg',
      'data' => ['url' => $clickUrl, 'kind' => 'test', 'scope' => 'current-browser'],
    ];
    $res = app_send_push_notification($sub, $payload);
    if (!empty($res['ok'])) {
      app_log_server($db, 'info', 'push', 'PUSH_TEST_SINGLE_OK', [
        'endpoint' => mb_substr((string)$sub['endpoint'], 0, 250),
        'admin_user' => $_SESSION['admin_user']['login'] ?? null,
      ]);
      json_out(["ok"=>true,"sent"=>1,"failed"=>0,"message"=>"Push отправлен в текущий браузер"]);
    }
    app_log_server($db, 'warn', 'push', 'PUSH_TEST_SINGLE_FAILED', [
      'endpoint' => mb_substr((string)$sub['endpoint'], 0, 250),
      'http' => (int)($res['http'] ?? 0),
      'error' => (string)($res['error'] ?? ''),
      'admin_user' => $_SESSION['admin_user']['login'] ?? null,
    ]);
    json_out(["ok"=>false,"error"=>"PUSH_TEST_FAILED","message"=>((string)($res['error'] ?? 'HTTP error')),"http"=>(int)($res['http'] ?? 0)], 500);
  } catch (Throwable $e) {
    app_log_server($db, 'error', 'push', 'PUSH_TEST_SINGLE_EXCEPTION', [
      'error' => $e->getMessage(),
      'admin_user' => $_SESSION['admin_user']['login'] ?? null,
    ]);
    json_out(["ok"=>false,"error"=>"PUSH_TEST_FAILED","message"=>$e->getMessage()], 500);
  }
}


function ensure_speech_settings(PDO $db): void {
  $db->exec("CREATE TABLE IF NOT EXISTS speech_settings (
    id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    provider VARCHAR(32) NOT NULL DEFAULT 'yandex',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    api_key VARCHAR(255) NULL,
    folder_id VARCHAR(255) NULL,
    voice VARCHAR(64) NOT NULL DEFAULT 'filipp',
    emotion VARCHAR(32) NULL,
    speed DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    template_text TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $db->exec("INSERT IGNORE INTO speech_settings(id, provider, enabled, api_key, folder_id, voice, emotion, speed, template_text) VALUES (1, 'yandex', 0, NULL, NULL, 'filipp', NULL, 1.00, 'Номер {number}. Пройдите к кассе {cashier}')");
}

function speech_settings(PDO $db): array {
  ensure_speech_settings($db);
  $st = $db->query("SELECT provider, enabled, api_key, folder_id, voice, emotion, speed, template_text, updated_at FROM speech_settings WHERE id=1");
  return $st->fetch() ?: ['provider'=>'yandex','enabled'=>0,'api_key'=>'','folder_id'=>'','voice'=>'filipp','emotion'=>'','speed'=>'1.00','template_text'=>'Номер {number}. Пройдите к кассе {cashier}','updated_at'=>null];
}

function yandex_tts_request(array $cfg, string $text, string $lang='ru-RU'): array {
  $apiKey = trim((string)($cfg['api_key'] ?? ''));
  $folderId = trim((string)($cfg['folder_id'] ?? ''));
  if ($apiKey === '' || $folderId === '') return ['ok'=>false,'error'=>'Yandex Speech не настроен: нужен API key и Folder ID'];
  if(!function_exists('curl_init')) return ['ok'=>false,'error'=>'PHP curl extension is not enabled'];
  $voice = trim((string)($cfg['voice'] ?? 'filipp')) ?: 'filipp';
  $emotion = trim((string)($cfg['emotion'] ?? ''));
  $speed = (string)($cfg['speed'] ?? '1.0');
  $post = [
    'text' => $text,
    'lang' => $lang,
    'voice' => $voice,
    'speed' => $speed,
    'folderId' => $folderId,
    'format' => 'mp3'
  ];
  if ($emotion !== '') $post['emotion'] = $emotion;
  $ch = curl_init('https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Api-Key ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = $resp === false ? (string)curl_error($ch) : '';
  curl_close($ch);
  if ($resp === false) return ['ok'=>false,'error'=>$err ?: 'curl_exec failed'];
  if ($http < 200 || $http >= 300) return ['ok'=>false,'error'=>trim((string)$resp) ?: ('HTTP ' . $http)];
  return ['ok'=>true,'audio'=>$resp,'contentType'=>'audio/mpeg'];
}

function speech_render_template(string $tpl, string $number, string $cashier): string {
  return strtr($tpl, [
    '{number}' => $number,
    '{cashier}' => $cashier,
    '{desk}' => $cashier,
  ]);
}

function dump_db_sql($db){
  $out="-- Backup generated ".date("c")."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
  $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
  foreach($tables as $t){
    $row = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
    $create = $row["Create Table"] ?? "";
    $out.="\nDROP TABLE IF EXISTS `{$t}`;\n{$create};\n";
    $rows = $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
      $cols = array_map(fn($c)=>"`".$c."`", array_keys($r));
      $vals = [];
      foreach($r as $v){
        if ($v === null) $vals[]="NULL";
        else $vals[]=$db->quote((string)$v);
      }
      $out.="INSERT INTO `{$t}` (".implode(",",$cols).") VALUES (".implode(",",$vals).");\n";
    }
  }
  $out.="\nSET FOREIGN_KEY_CHECKS=1;\n";
  return $out;
}

function run_backup_now($db){
  $rootDir = dirname(__DIR__);
  $backupDir = $rootDir . "/backups";
  if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
  $ts = date("Ymd_His");
  $zipName = "backup_{$ts}.zip";
  $zipPath = $backupDir . "/" . $zipName;

  $dbSql = dump_db_sql($db);

  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE)!==true) return [false,null];

  $zip->addFromString("db.sql", $dbSql);

  // add site files excluding backups directory
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
  foreach($it as $file){
    $fp = (string)$file;
    if (strpos($fp, $backupDir)===0) continue;
    // avoid big vendor? none
    $rel = substr($fp, strlen($rootDir)+1);
    if ($file->isDir()) continue;
    $zip->addFile($fp, $rel);
  }
  $zip->close();

  $size = filesize($zipPath) ?: 0;
  // sent_to_telegram: 0=not configured/skip, 1=sent, 2=failed
  $st=$db->prepare("INSERT INTO backups(file_name,size_bytes,created_at,sent_to_telegram,telegram_error) VALUES (?,?,NOW(),0,NULL)");
  $st->execute([$zipName,$size]);

  // telegram (store error text)
  $tr = telegram_send_file_ex($zipPath, "Backup {$ts}");
  if ($tr['configured'] === false){
    $db->prepare("UPDATE backups SET sent_to_telegram=0, telegram_error=? WHERE file_name=?")
       ->execute([substr((string)$tr['error'],0,2000), $zipName]);
  } elseif ($tr['ok'] === true){
    $db->prepare("UPDATE backups SET sent_to_telegram=1, telegram_error=NULL WHERE file_name=?")->execute([$zipName]);
  } else {
    $err = $tr['error'] ?: 'Telegram sendDocument failed';
    $db->prepare("UPDATE backups SET sent_to_telegram=2, telegram_error=? WHERE file_name=?")
       ->execute([substr((string)$err,0,2000), $zipName]);
  }
  return [true,$zipName];
}

// Mini-admin: run backup now
if ($path === "/admin/backups/run" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('users');
  ensure_backup_tables($db);
  [$ok,$name]=run_backup_now($db);
  if(!$ok) json_out(["error"=>"BACKUP_FAILED"], 500);
  json_out(["ok"=>true,"file"=>$name]);
}

if ($path === "/admin/backups" && $_SERVER["REQUEST_METHOD"] === "GET") {
  require_perm('users');
  ensure_backup_tables($db);
  $st=$db->query("SELECT id,file_name,size_bytes,created_at,sent_to_telegram,telegram_error FROM backups ORDER BY id DESC LIMIT 200");
  json_out(["items"=>$st->fetchAll()]);
}



if ($path === "/admin/speech/settings" && $_SERVER["REQUEST_METHOD"] === "GET") {
  require_perm('branding');
  json_out(["settings"=>speech_settings($db)]);
}
if ($path === "/admin/speech/settings" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('branding');
  $in = json_in();
  ensure_speech_settings($db);
  $enabled = !empty($in['enabled']) ? 1 : 0;
  $apiKey = trim((string)($in['apiKey'] ?? ''));
  $folderId = trim((string)($in['folderId'] ?? ''));
  $voice = trim((string)($in['voice'] ?? 'filipp')) ?: 'filipp';
  $emotion = trim((string)($in['emotion'] ?? ''));
  $speed = max(0.1, min(3.0, (float)($in['speed'] ?? 1)));
  $templateText = trim((string)($in['templateText'] ?? 'Номер {number}. Пройдите к кассе {cashier}'));
  $st = $db->prepare("UPDATE speech_settings SET enabled=?, api_key=?, folder_id=?, voice=?, emotion=?, speed=?, template_text=? WHERE id=1");
  $st->execute([$enabled, $apiKey !== '' ? $apiKey : null, $folderId !== '' ? $folderId : null, $voice, $emotion !== '' ? $emotion : null, $speed, $templateText]);
  json_out(["ok"=>true]);
}
if ($path === "/admin/speech/test" && $_SERVER["REQUEST_METHOD"] === "POST") {
  require_perm('branding');
  $cfg = speech_settings($db);
  $res = yandex_tts_request($cfg, 'Тест озвучки очереди. Номер 101. Пройдите к кассе 2', 'ru-RU');
  if (!$res['ok']) json_out(['ok'=>false,'error'=>'TTS_FAILED','message'=>$res['error']], 500);
  header('Content-Type: ' . ($res['contentType'] ?? 'audio/mpeg'));
  header('Content-Length: ' . strlen((string)$res['audio']));
  echo $res['audio'];
  exit;
}
if ($path === "/speech/tts" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $in = json_in();
  $cfg = speech_settings($db);
  if ((int)($cfg['enabled'] ?? 0) !== 1) json_out(['ok'=>false,'error'=>'DISABLED'], 400);
  $number = trim((string)($in['number'] ?? ''));
  $cashier = trim((string)($in['cashier'] ?? ''));
  $lang = trim((string)($in['lang'] ?? 'ru-RU')) ?: 'ru-RU';
  $template = trim((string)($cfg['template_text'] ?? 'Номер {number}. Пройдите к кассе {cashier}'));
  $text = speech_render_template($template, $number, $cashier);
  $res = yandex_tts_request($cfg, $text, $lang);
  if (!$res['ok']) {
    log_server($db, 'warn', 'speech', 'TTS_FAILED', ['error'=>$res['error'] ?? 'unknown']);
    json_out(['ok'=>false,'error'=>'TTS_FAILED','message'=>$res['error']], 500);
  }
  header('Content-Type: ' . ($res['contentType'] ?? 'audio/mpeg'));
  header('Content-Length: ' . strlen((string)$res['audio']));
  echo $res['audio'];
  exit;
}

json_out(["error"=>"NOT_FOUND","path"=>$path], 404);
