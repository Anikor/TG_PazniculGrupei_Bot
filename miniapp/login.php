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
