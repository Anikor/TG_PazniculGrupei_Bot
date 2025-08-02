<?php
// miniapp/greeting.php
session_start();
require_once __DIR__ . '/../config.php';  // has $BOT_TOKEN
require_once __DIR__ . '/../db.php';      // has getUserByTgId()

// 1) Fallback for Web—even when initData is missing—grab ?tg_id= from web.telegram.org
if (!isset($_SESSION['user']) && isset($_GET['tg_id'])) {
    $tg_id = intval($_GET['tg_id']);
    $user  = getUserByTgId($tg_id) ?: exit('Unknown user');
    $_SESSION['user'] = $user;
    // redirect away from the ?tg_id so your links stay clean
    header('Location: greeting.php');
    exit;
}

// 2) Telegram-WebApp handshake (mobile/desktop clients)
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];
if (!isset($_SESSION['user']) && !empty($data['initData'])) {
    // parse & verify hash
    parse_str($data['initData'], $auth);
    $hash = $auth['hash'] ?? '';
    unset($auth['hash']);

    ksort($auth);
    $checkArr = [];
    foreach ($auth as $k => $v) {
      $checkArr[] = "$k=$v";
    }
    $checkStr = implode("\n", $checkArr);

    $secretKey = hash_hmac('sha256', $BOT_TOKEN, 'WebAppData', true);
    $calcHash  = hash_hmac('sha256', $checkStr,   $secretKey);

    if (!hash_equals($calcHash, $hash)) {
      http_response_code(403);
      exit('Invalid Telegram signature');
    }

    // lookup & seed session
    $tg_id = intval($auth['id']);
    $user  = getUserByTgId($tg_id) ?: exit('Unknown user');
    $_SESSION['user'] = $user;

    // reload into the real UI
    header('Content-Type: application/json');
    echo json_encode(['success'=>true]);
    exit;
}

// 3) If we still don’t have a session, show the small JS “sign-in” page
if (!isset($_SESSION['user'])) {
    ?><!DOCTYPE html>
    <html lang="en">
      <head>
        <meta charset="UTF-8">
        <title>Signing you in…</title>
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
      </head>
      <body>
        <p>🔒 Signing in via Telegram…</p>
        <script>
          const tg = window.Telegram.WebApp;
          tg.expand();

          fetch('greeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ initData: tg.initData })
          })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              // we’re logged in—reload into the real UI
              window.location.reload();
            } else {
              document.body.innerText = res.error || 'Login failed';
            }
          })
          .catch(err => {
            document.body.innerText = err.toString();
          });
        </script>
      </body>
    </html>
    <?php
    exit;
}

// 4) At this point, $_SESSION['user'] is set—just hand off to your old UI:
$user = $_SESSION['user'];
require __DIR__ . '/greeting_old.php';
