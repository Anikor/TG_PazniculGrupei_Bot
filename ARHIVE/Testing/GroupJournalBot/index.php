<?php
// index.php

// 1) Database credentials — adjust to your setup
$dbHost = '127.0.0.1';
$dbName = 'attendence_utm';
$dbUser = 'root';
$dbPass = '';

// Establish PDO connection
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'DB connection failed']);
        exit;
    } else {
        die("Database connection failed.");
    }
}

// 2) If this is an AJAX lookup, handle JSON POST & return name
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body['tg_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'tg_id is required']);
        exit;
    }
    $tg_id = (int)$body['tg_id'];

    $stmt = $pdo->prepare('SELECT name FROM users WHERE tg_id = :tg');
    $stmt->execute(['tg' => $tg_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['name' => $user['name']]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
    }
    exit;
}

// 3) Otherwise, render the mini-app HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Welcome</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <style>
    body { font-family: sans-serif; text-align: center; margin-top: 50px; }
    #user-name { color: #007aff; }
  </style>
</head>
<body>
  <h1>Welcome, <span id="user-name">loading…</span></h1>

  <script>
    const tg = window.Telegram.WebApp;
    tg.ready();

    // Extract Telegram user ID
    const init = tg.initDataUnsafe || {};
    const user = init.user || {};
    if (!user.id) {
      document.getElementById('user-name').textContent = 'Guest';
      console.error('No Telegram user info available');
    } else {
      const tg_id = user.id;
      // Fetch name from this same endpoint
      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tg_id })
      })
      .then(res => res.json())
      .then(data => {
        if (data.name) {
          document.getElementById('user-name').textContent = data.name;
        } else {
          document.getElementById('user-name').textContent = 'Unknown user';
        }
      })
      .catch(err => {
        console.error(err);
        document.getElementById('user-name').textContent = 'Error';
      });
    }
  </script>
</body>
</html>
