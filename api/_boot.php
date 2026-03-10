<?php
declare(strict_types=1);

function env_load(string $path): array {
  if (!file_exists($path)) return [];
  $out = [];
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $k = trim($k);
    $v = trim($v);
    $out[$k] = $v;
    // Expose variables to getenv()/$_ENV so envv() works (especially for php-fpm where ENV might not be passed).
    $_ENV[$k] = $v;
    @putenv($k . '=' . $v);
  }
  return $out;
}

function pdo(array $ENV): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = $ENV['DB_DSN'] ?? '';
  $user = $ENV['DB_USER'] ?? '';
  $pass = $ENV['DB_PASS'] ?? '';
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}



function app_envv(string $k, $default=null){
  $v = $_ENV[$k] ?? getenv($k);
  return ($v === false || $v === null || $v === '') ? $default : $v;
}

function app_ensure_server_logs(PDO $db): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try{
    $db->exec("CREATE TABLE IF NOT EXISTS server_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      level VARCHAR(16) NOT NULL,
      source VARCHAR(64) NULL,
      message TEXT NOT NULL,
      context_json LONGTEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_server_logs_created(created_at),
      INDEX idx_server_logs_level(level),
      INDEX idx_server_logs_source(source)
    ) ENGINE=InnoDB");
  }catch(Throwable $e){}
  try{ $db->exec("ALTER TABLE server_logs MODIFY context_json LONGTEXT NULL"); }catch(Throwable $e){}
  try{ $db->exec("ALTER TABLE server_logs ADD INDEX idx_server_logs_source(source)"); }catch(Throwable $e){}
}

function app_log_server(PDO $db, string $level, string $source, string $message, ?array $context=null): void {
  try{
    app_ensure_server_logs($db);
    $ctx = $context ? json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
    $st = $db->prepare("INSERT INTO server_logs(level, source, message, context_json) VALUES (?,?,?,?)");
    $st->execute([$level, $source, $message, $ctx]);
  }catch(Throwable $e){}
}

function app_telegram_send_message_ex(string $text): array {
  $token = app_envv('TELEGRAM_BOT_TOKEN');
  $chat  = app_envv('TELEGRAM_CHAT_ID');
  if(!$token || !$chat) return ['configured'=>false,'ok'=>false,'http'=>0,'resp'=>'','error'=>'Telegram Ð½Ðµ Ð½Ð°ÑÑÑÐ¾ÐµÐ½ (TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID)'];
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

function app_log_level_from_errno(int $errno): string {
  return match($errno){
    E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT => 'info',
    E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warn',
    default => 'error',
  };
}

function app_error_name(int $errno): string {
  return match($errno){
    E_ERROR=>'E_ERROR', E_WARNING=>'E_WARNING', E_PARSE=>'E_PARSE', E_NOTICE=>'E_NOTICE', E_CORE_ERROR=>'E_CORE_ERROR',
    E_CORE_WARNING=>'E_CORE_WARNING', E_COMPILE_ERROR=>'E_COMPILE_ERROR', E_COMPILE_WARNING=>'E_COMPILE_WARNING',
    E_USER_ERROR=>'E_USER_ERROR', E_USER_WARNING=>'E_USER_WARNING', E_USER_NOTICE=>'E_USER_NOTICE', E_STRICT=>'E_STRICT',
    E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR', E_DEPRECATED=>'E_DEPRECATED', E_USER_DEPRECATED=>'E_USER_DEPRECATED',
    default=>'E_UNKNOWN'
  };
}

function app_notify_php_error(PDO $db, string $level, string $message, array $context=[]): void {
  if ($level !== 'error') return;
  $text = "ð¨ PHP error
" . $message;
  if (!empty($context['file'])) $text .= "
Ð¤Ð°Ð¹Ð»: " . $context['file'];
  if (!empty($context['line'])) $text .= ":" . $context['line'];
  if (!empty($context['uri'])) $text .= "
URI: " . $context['uri'];
  if (!empty($context['method'])) $text .= "
ÐÐµÑÐ¾Ð´: " . $context['method'];
  if (!empty($context['ip'])) $text .= "
IP: " . $context['ip'];
  app_telegram_send_message_ex(mb_substr($text, 0, 3900));
}

function app_init_logging(PDO $db, string $source='php', bool $logPageRequest=true): void {
  static $inited = false;
  if ($inited) return;
  $inited = true;
  app_ensure_server_logs($db);
  if ($logPageRequest) {
    app_log_server($db, 'info', $source, 'PHP_REQUEST', [
      'method' => $_SERVER['REQUEST_METHOD'] ?? '',
      'uri' => $_SERVER['REQUEST_URI'] ?? '',
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
      'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
  }
  set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) use ($db, $source){
    if (!(error_reporting() & $errno)) return false;
    $level = app_log_level_from_errno($errno);
    $msg = app_error_name($errno) . ': ' . $errstr;
    $ctx = [
      'file'=>$errfile,
      'line'=>$errline,
      'uri'=>$_SERVER['REQUEST_URI'] ?? '',
      'method'=>$_SERVER['REQUEST_METHOD'] ?? '',
      'ip'=>$_SERVER['REMOTE_ADDR'] ?? '',
    ];
    app_log_server($db, $level, $source, $msg, $ctx);
    app_notify_php_error($db, $level, $msg, $ctx);
    return false;
  });
  set_exception_handler(function(Throwable $e) use ($db, $source){
    $msg = get_class($e) . ': ' . $e->getMessage();
    $ctx = [
      'file'=>$e->getFile(),
      'line'=>$e->getLine(),
      'uri'=>$_SERVER['REQUEST_URI'] ?? '',
      'method'=>$_SERVER['REQUEST_METHOD'] ?? '',
      'ip'=>$_SERVER['REMOTE_ADDR'] ?? '',
      'trace'=>mb_substr($e->getTraceAsString(), 0, 4000),
    ];
    app_log_server($db, 'error', $source, $msg, $ctx);
    app_notify_php_error($db, 'error', $msg, $ctx);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error';
    exit;
  });
  register_shutdown_function(function() use ($db, $source){
    $e = error_get_last();
    if (!$e) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$e['type'], $fatal, true)) return;
    $msg = app_error_name((int)$e['type']) . ': ' . (string)$e['message'];
    $ctx = [
      'file'=>(string)($e['file'] ?? ''),
      'line'=>(int)($e['line'] ?? 0),
      'uri'=>$_SERVER['REQUEST_URI'] ?? '',
      'method'=>$_SERVER['REQUEST_METHOD'] ?? '',
      'ip'=>$_SERVER['REMOTE_ADDR'] ?? '',
    ];
    app_log_server($db, 'error', $source, $msg, $ctx);
    app_notify_php_error($db, 'error', $msg, $ctx);
  });
}


function app_b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function app_b64url_decode(string $data): string {
  $pad = strlen($data) % 4;
  if ($pad) $data .= str_repeat('=', 4 - $pad);
  $out = base64_decode(strtr($data, '-_', '+/'), true);
  if ($out === false) throw new RuntimeException('Invalid base64url data');
  return $out;
}

function app_hkdf_sha256(string $ikm, int $length, string $info = '', string $salt = ''): string {
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $t = '';
  $okm = '';
  $block = 0;
  while (strlen($okm) < $length) {
    $block++;
    $t = hash_hmac('sha256', $t . $info . chr($block), $prk, true);
    $okm .= $t;
  }
  return substr($okm, 0, $length);
}

function app_vapid_subject(): string {
  return (string)app_envv('VAPID_SUBJECT', 'mailto:admin@example.com');
}

function app_vapid_public_key(): ?string {
  $pub = trim((string)app_envv('VAPID_PUBLIC_KEY', ''));
  if ($pub !== '') return $pub;
  $priv = trim((string)app_envv('VAPID_PRIVATE_KEY', ''));
  if ($priv === '') return null;
  try {
    $pem = app_vapid_private_pem_from_raw($priv, null);
    $res = openssl_pkey_get_private($pem);
    if (!$res) return null;
    $details = openssl_pkey_get_details($res);
    $pubRaw = "" . $details['ec']['x'] . $details['ec']['y'];
    return app_b64url_encode($pubRaw);
  } catch (Throwable $e) {
    return null;
  }
}

function app_vapid_private_key(): ?string {
  $priv = trim((string)app_envv('VAPID_PRIVATE_KEY', ''));
  return $priv !== '' ? $priv : null;
}

function app_asn1_len(int $len): string {
  if ($len < 0x80) return chr($len);
  $bin = '';
  while ($len > 0) {
    $bin = chr($len & 0xff) . $bin;
    $len >>= 8;
  }
  return chr(0x80 | strlen($bin)) . $bin;
}

function app_asn1(int $tag, string $value): string {
  return chr($tag) . app_asn1_len(strlen($value)) . $value;
}

function app_vapid_private_pem_from_raw(string $rawPrivB64Url, ?string $rawPubB64Url): string {
  $d = app_b64url_decode($rawPrivB64Url);
  if (strlen($d) !== 32) throw new RuntimeException('Invalid VAPID private key length');
  $pub = $rawPubB64Url ? app_b64url_decode($rawPubB64Url) : null;
  if ($pub !== null && strlen($pub) !== 65) throw new RuntimeException('Invalid VAPID public key length');
  $oidPrime256v1 = app_asn1(0x06, hex2bin('2A8648CE3D030107'));
  $version = app_asn1(0x02, "");
  $privateKey = app_asn1(0x04, $d);
  $params = chr(0xA0) . app_asn1_len(strlen($oidPrime256v1)) . $oidPrime256v1;
  $publicKey = '';
  if ($pub !== null) {
    $bitString = app_asn1(0x03, " " . $pub);
    $publicKey = chr(0xA1) . app_asn1_len(strlen($bitString)) . $bitString;
  }
  $seq = app_asn1(0x30, $version . $privateKey . $params . $publicKey);
  return "-----BEGIN EC PRIVATE KEY-----
" . chunk_split(base64_encode($seq), 64, "
") . "-----END EC PRIVATE KEY-----
";
}

function app_der_to_jose(string $der, int $partLength = 32): string {
  $pos = 0;
  if (ord($der[$pos++]) !== 0x30) throw new RuntimeException('Invalid DER sequence');
  $seqLen = ord($der[$pos++]);
  if ($seqLen & 0x80) {
    $bytes = $seqLen & 0x7f;
    $seqLen = 0;
    for ($i = 0; $i < $bytes; $i++) $seqLen = ($seqLen << 8) | ord($der[$pos++]);
  }
  if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('Invalid DER integer R');
  $rLen = ord($der[$pos++]);
  $r = substr($der, $pos, $rLen); $pos += $rLen;
  if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('Invalid DER integer S');
  $sLen = ord($der[$pos++]);
  $s = substr($der, $pos, $sLen);
  $r = ltrim($r, " ");
  $s = ltrim($s, " ");
  $r = str_pad($r, $partLength, " ", STR_PAD_LEFT);
  $s = str_pad($s, $partLength, " ", STR_PAD_LEFT);
  return $r . $s;
}

function app_vapid_jwt(string $aud): array {
  $pub = app_vapid_public_key();
  $priv = app_vapid_private_key();
  if (!$pub || !$priv) throw new RuntimeException('VAPID keys are not configured');
  $header = ['typ'=>'JWT','alg'=>'ES256'];
  $claims = ['aud'=>$aud,'exp'=>time()+12*3600,'sub'=>app_vapid_subject()];
  $unsigned = app_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . app_b64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
  $pem = app_vapid_private_pem_from_raw($priv, $pub);
  $res = openssl_pkey_get_private($pem);
  if (!$res) throw new RuntimeException('Unable to load VAPID private key');
  $sig = '';
  if (!openssl_sign($unsigned, $sig, $res, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Unable to sign VAPID JWT');
  return [
    'jwt' => $unsigned . '.' . app_b64url_encode(app_der_to_jose($sig)),
    'publicKey' => $pub,
  ];
}

function app_webpush_encrypt(string $payloadJson, array $subscription): array {
  if (empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
    throw new RuntimeException('Push subscription keys are missing');
  }
  $userPublicRaw = app_b64url_decode((string)$subscription['keys']['p256dh']);
  $authSecret = app_b64url_decode((string)$subscription['keys']['auth']);
  if (strlen($userPublicRaw) !== 65) throw new RuntimeException('Invalid user public key');
  $algoOid = app_asn1(0x06, hex2bin('2A8648CE3D0201'));
  $curveOid = app_asn1(0x06, hex2bin('2A8648CE3D030107'));
  $spki = app_asn1(0x30, app_asn1(0x30, $algoOid . $curveOid) . app_asn1(0x03, " " . $userPublicRaw));
  $userPublicPem = "-----BEGIN PUBLIC KEY-----
" . chunk_split(base64_encode($spki), 64, "
") . "-----END PUBLIC KEY-----
";
  $ephemeral = openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]);
  if (!$ephemeral) throw new RuntimeException('Unable to generate ephemeral EC key');
  $details = openssl_pkey_get_details($ephemeral);
  $serverPublicRaw = "" . $details['ec']['x'] . $details['ec']['y'];
  $sharedSecret = openssl_pkey_derive($userPublicPem, $ephemeral, 32);
  if ($sharedSecret === false || strlen($sharedSecret) !== 32) throw new RuntimeException('ECDH derive failed');
  $ikm = app_hkdf_sha256($sharedSecret, 32, "WebPush: info " . $userPublicRaw . $serverPublicRaw, $authSecret);
  $salt = random_bytes(16);
  $contentEncryptionKey = app_hkdf_sha256($ikm, 16, "Content-Encoding: aes128gcm ", $salt);
  $nonce = app_hkdf_sha256($ikm, 12, "Content-Encoding: nonce ", $salt);
  $plaintext = $payloadJson . "";
  $tag = '';
  $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
  if ($ciphertext === false) throw new RuntimeException('Payload encryption failed');
  $rs = pack('N', 4096);
  $body = $salt . $rs . chr(strlen($serverPublicRaw)) . $serverPublicRaw . $ciphertext . $tag;
  return [
    'body' => $body,
    'serverPublicKey' => app_b64url_encode($serverPublicRaw),
  ];
}

function app_send_push_notification(array $subscription, array $payload): array {
  $endpoint = trim((string)($subscription['endpoint'] ?? ''));
  if ($endpoint === '') return ['ok'=>false, 'http'=>0, 'error'=>'Empty endpoint'];
  if (!function_exists('curl_init')) return ['ok'=>false, 'http'=>0, 'error'=>'PHP curl extension is not enabled'];
  $parts = parse_url($endpoint);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return ['ok'=>false, 'http'=>0, 'error'=>'Invalid endpoint'];
  $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  $vapid = app_vapid_jwt($aud);
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $encrypted = app_webpush_encrypt($payloadJson, $subscription);
  $headers = [
    'TTL: 60',
    'Content-Type: application/octet-stream',
    'Content-Encoding: aes128gcm',
    'Authorization: vapid t=' . $vapid['jwt'] . ', k=' . $vapid['publicKey'],
    'Crypto-Key: p256ecdsa=' . $vapid['publicKey'],
  ];
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted['body']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HEADER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 7);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  $cerr = $resp === false ? (string)curl_error($ch) : '';
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) return ['ok'=>false, 'http'=>$http, 'error'=>$cerr ?: 'curl_exec failed'];
  $ok = in_array($http, [200, 201, 202, 204], true);
  return ['ok'=>$ok, 'http'=>$http, 'error'=>$ok ? '' : ('HTTP ' . $http), 'resp'=>substr((string)$resp, 0, 1000)];
}

function app_push_test_broadcast(PDO $db, ?int $queueId = null): array {
  app_ensure_push_subscriptions($db);
  if (!app_vapid_public_key() || !app_vapid_private_key()) {
    throw new RuntimeException('VAPID keys are not configured');
  }
  $sql = "SELECT id, queue_id, endpoint, subscription_json, ticket_uuid FROM push_subscriptions";
  $params = [];
  if ($queueId !== null) {
    $sql .= " WHERE queue_id=?";
    $params[] = $queueId;
  }
  $sql .= " ORDER BY id DESC";
  $st = $db->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $seen = [];
  $sent = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $endpoint = (string)($row['endpoint'] ?? '');
    if ($endpoint === '' || isset($seen[$endpoint])) continue;
    $seen[$endpoint] = true;
    $sub = json_decode((string)($row['subscription_json'] ?? ''), true);
    if (!is_array($sub)) { $failed++; continue; }
    try {
      $queueIdRow = (int)($row['queue_id'] ?? 0);
      $basePrefix = trim((string)($sub['appData']['basePrefix'] ?? ''), '/');
      $prefix = $basePrefix !== '' ? '/' . $basePrefix : '';
      $defaultUrl = $prefix !== '' ? ($prefix . '/') : '/';
      $clickUrl = (string)($sub['appData']['ticketPath'] ?? $defaultUrl);
      $payload = [
        'title' => 'Тест push-уведомления',
        'body' => 'Проверка Web Push на ' . date('Y-m-d H:i:s'),
        'icon' => $prefix . '/img/logo.svg',
        'badge' => $prefix . '/img/logo.svg',
        'data' => ['url' => $clickUrl, 'kind' => 'test', 'queueId' => $queueIdRow],
      ];
      $res = app_send_push_notification($sub, $payload);
      if (!empty($res['ok'])) {
        $sent++;
      } else {
        $failed++;
        if (in_array((int)($res['http'] ?? 0), [404, 410], true)) {
          try {
            $del = $db->prepare("DELETE FROM push_subscriptions WHERE id=?");
            $del->execute([(int)$row['id']]);
          } catch (Throwable $e) {}
        }
        app_log_server($db, 'warn', 'push', 'PUSH_TEST_FAILED', [
          'queue_id' => $queueIdRow,
          'endpoint' => mb_substr($endpoint, 0, 250),
          'http' => (int)($res['http'] ?? 0),
          'error' => (string)($res['error'] ?? ''),
        ]);
      }
    } catch (Throwable $e) {
      $failed++;
      app_log_server($db, 'error', 'push', 'PUSH_TEST_EXCEPTION', [
        'queue_id' => (int)($row['queue_id'] ?? 0),
        'endpoint' => mb_substr($endpoint, 0, 250),
        'error' => $e->getMessage(),
      ]);
    }
  }
  app_log_server($db, ($failed > 0 ? 'warn' : 'info'), 'push', 'PUSH_TEST_BROADCAST', [
    'queue_id' => $queueId,
    'subscriptions' => count($seen),
    'sent' => $sent,
    'failed' => $failed,
  ]);
  return ['total' => count($seen), 'sent' => $sent, 'failed' => $failed];
}

function app_push_notify_ticket_called(PDO $db, int $queueId, string $queuePublicId, string $ticketUuid, string $displayNumber, string $cashierLabel): array {
  app_ensure_push_subscriptions($db);
  $st = $db->prepare("SELECT id, endpoint, subscription_json, ticket_uuid FROM push_subscriptions WHERE queue_id=? AND (ticket_uuid=? OR ticket_uuid IS NULL) ORDER BY (ticket_uuid IS NULL) ASC, id DESC");
  $st->execute([$queueId, $ticketUuid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $seen = [];
  $sent = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $endpoint = (string)($row['endpoint'] ?? '');
    if ($endpoint === '' || isset($seen[$endpoint])) continue;
    $seen[$endpoint] = true;
    $sub = json_decode((string)$row['subscription_json'], true);
    if (!is_array($sub)) { $failed++; continue; }
    try {
      $basePrefix = trim((string)($sub['appData']['basePrefix'] ?? ''), '/');
      $prefix = $basePrefix !== '' ? '/' . $basePrefix : '';
      $ticketPath = (string)($sub['appData']['ticketPath'] ?? ($prefix . '/' . $queuePublicId));
      $res = app_send_push_notification($sub, [
        'title' => 'ÐÐ°Ñ Ð¿ÑÐ¸Ð³Ð»Ð°ÑÐ¸Ð»Ð¸ Ð² ÐºÐ°ÑÑÑ',
        'body' => $displayNumber . ' â Ð¿ÑÐ¾Ð¹Ð´Ð¸ÑÐµ Ðº ÐºÐ°ÑÑÐµ ' . $cashierLabel,
        'icon' => $prefix . '/img/logo.svg',
        'badge' => $prefix . '/img/logo.svg',
        'data' => ['url' => $ticketPath],
      ]);
      if ($res['ok']) {
        $sent++;
      } else {
        $failed++;
        if (in_array((int)$res['http'], [404, 410], true)) {
          try {
            $del = $db->prepare("DELETE FROM push_subscriptions WHERE id=?");
            $del->execute([(int)$row['id']]);
          } catch (Throwable $e) {}
        }
        app_log_server($db, 'warn', 'push', 'PUSH_SEND_FAILED', [
          'queue_id' => $queueId,
          'ticket_uuid' => $ticketUuid,
          'endpoint' => mb_substr($endpoint, 0, 250),
          'http' => (int)($res['http'] ?? 0),
          'error' => (string)($res['error'] ?? ''),
        ]);
      }
    } catch (Throwable $e) {
      $failed++;
      app_log_server($db, 'error', 'push', 'PUSH_SEND_EXCEPTION', [
        'queue_id' => $queueId,
        'ticket_uuid' => $ticketUuid,
        'endpoint' => mb_substr($endpoint, 0, 250),
        'error' => $e->getMessage(),
      ]);
    }
  }
  if ($sent > 0 || $failed > 0) {
    app_log_server($db, $failed > 0 ? 'warn' : 'info', 'push', 'PUSH_NOTIFY_CALLED', [
      'queue_id' => $queueId,
      'ticket_uuid' => $ticketUuid,
      'sent' => $sent,
      'failed' => $failed,
    ]);
  }
  return ['sent'=>$sent, 'failed'=>$failed];
}

function app_ensure_push_subscriptions(PDO $db): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      queue_id INT UNSIGNED NOT NULL,
      ticket_uuid CHAR(36) NULL,
      endpoint TEXT NOT NULL,
      subscription_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_push_endpoint (endpoint(190)),
      KEY idx_push_queue_ticket (queue_id, ticket_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}
}

function random_token(int $len = 45): string {
  return substr(bin2hex(random_bytes(64)), 0, $len);
}
