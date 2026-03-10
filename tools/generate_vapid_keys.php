<?php
declare(strict_types=1);

function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
if (!$key) {
    fwrite(STDERR, "Не удалось сгенерировать EC key pair\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
if (!$details || empty($details['ec']['d']) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    fwrite(STDERR, "Не удалось прочитать детали ключа\n");
    exit(1);
}
$private = $details['ec']['d'];
$public = "\x04" . $details['ec']['x'] . $details['ec']['y'];

$subject = $argv[1] ?? 'mailto:admin@example.com';

echo "VAPID_SUBJECT={$subject}\n";
echo "VAPID_PUBLIC_KEY=" . b64url($public) . "\n";
echo "VAPID_PRIVATE_KEY=" . b64url($private) . "\n";
