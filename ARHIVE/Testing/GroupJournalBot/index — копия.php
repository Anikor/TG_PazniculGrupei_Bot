<?php
// test_index.php

// ——— API: handle POST → receive & echo back user_id ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // only accept JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === 0) {
        $data = json_decode(file_get_contents('php://input'), true);
        $user_id = isset($data['user_id']) ? intval($data['user_id']) : null;
    } else {
        $user_id = null;
    }

    header('Content-Type: application/json; charset=UTF-8');
    if ($user_id) {
        // (optional) log to a file
        file_put_contents(__DIR__ . '/user_ids.log', $user_id . PHP_EOL, FILE_APPEND);
        echo json_encode(['status' => 'ok', 'user_id' => $user_id]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No user_id provided']);
    }
    exit;
}

// ——— UI: serve your mini-app on GET ———
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Telegram Mini-App Test</title>
  <!-- Telegram WebApp SDK -->
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <style>
    body { font-family: sans-serif; padding: 2rem; }
    #info { white-space: pre-wrap; }
  </style>
</head>
<body>
  <h1>Telegram Mini-App: Fetching Your TG ID</h1>
  <p id="info">Initializing…</p>

  <script>
    // Initialize the WebApp API
    const tg = window.Telegram.WebApp;
    tg.ready();

    // Extract the user object
    const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
    const userId = user && user.id;

    const infoEl = document.getElementById('info');
    if (!userId) {
      infoEl.textContent = '❌ Could not read Telegram user ID.';
    } else {
      infoEl.textContent = '✔ Your Telegram ID is: ' + userId;

      // Send it back to this same script via POST
      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: userId })
      })
      .then(res => res.json())
      .then(json => {
        infoEl.textContent += '\nServer response: ' + JSON.stringify(json);
      })
      .catch(err => {
        infoEl.textContent += '\nError sending to server: ' + err;
      });
    }
  </script>
</body>
</html>
