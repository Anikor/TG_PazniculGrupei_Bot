<?php
// miniapp/login.php
session_start();
require_once __DIR__ . '/../config.php';   // defines $BOT_TOKEN
require_once __DIR__ . '/../db.php';       // defines getUserByTgId()

// 1) Read & decode the JSON body
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!isset($data['initData'])) {
    http_response_code(400);
    echo json_encode(['error'=>'initData missing']);
    exit;
}

// 2) Parse the Telegram-signed query-string
parse_str($data['initData'], $auth);
if (empty($auth['hash'])) {
    http_response_code(400);
    echo json_encode(['error'=>'hash missing']);
    exit;
}
$hash = $auth['hash'];
unset($auth['hash']);

// 3) Build the check-string
ksort($auth);
$check_arr = [];
foreach ($auth as $k => $v) {
    $check_arr[] = "$k=$v";
}
$check_str = implode("\n", $check_arr);

// 4) Compute HMAC with your bot-token
$secret_key = hash_hmac('sha256', $BOT_TOKEN, 'WebAppData', true);
$calc_hash  = hash_hmac('sha256', $check_str,   $secret_key);

// 5) Verify
if (!hash_equals($calc_hash, $hash)) {
    http_response_code(403);
    echo json_encode(['error'=>'Invalid signature']);
    exit;
}

// 6) Lookup user in your own DB
$tg_id = intval($auth['id']);
$user  = getUserByTgId($tg_id);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error'=>'Unknown user']);
    exit;
}

// 7) Remember them in session
$_SESSION['user'] = $user;

// 8) Success
echo json_encode(['success'=>true]);



define('ENCRYPTION_KEY', hex2bin('0123456789abcdef0123456789abcdef'));
define('ENCRYPTION_IV',  hex2bin('abcdef9876543210abcdef9876543210'));

/**
 * Encrypt a numeric Telegram ID into a URL-safe string.
 */
function encrypt_id(int $tg_id): string {
    $plaintext = (string)$tg_id;
    $cipher    = openssl_encrypt(
        $plaintext,
        'AES-128-CBC',
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        ENCRYPTION_IV
    );
    // prepend IV (optional here since IV is constant) or just base64
    return rtrim(strtr(base64_encode($cipher), '+/', '-_'), '=');
}

/**
 * Decrypt the token back into the original ID, or return null on failure.
 */
function decrypt_id(string $token): ?int {
    // restore padding
    $b64   = strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4);
    $cipher = base64_decode($b64, true);
    if ($cipher === false) return null;
    $plain = openssl_decrypt(
        $cipher,
        'AES-128-CBC',
        ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        ENCRYPTION_IV
    );
    if ($plain === false || !ctype_digit($plain)) return null;
    return (int)$plain;
}