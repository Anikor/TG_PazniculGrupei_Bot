<?php
require_once 'bot.php';
echo 'OK';

<?php
// webhook.php

// 1️⃣ Load config & DB helpers
require_once __DIR__ . '/config.php';  // defines BOT_TOKEN, TELEGRAM_API_URL, etc.
require_once __DIR__ . '/db.php';      // defines $pdo, getUserByTgId()

// 2️⃣ Read the incoming Telegram update
$body   = file_get_contents('php://input');
$update = json_decode($body, true);

// 3️⃣ Check for Web App submissions
if (isset($update['message']['web_app_data'])) {
    // a) Extract the user’s Telegram ID
    $tg_id   = $update['message']['from']['id'];
    // b) Pull out the JSON string we sent from the page
    $rawData = $update['message']['web_app_data']['data'];
    $payload = json_decode($rawData, true);

    // 4️⃣ Look up your internal user
    $user = getUserByTgId($tg_id);
    if (!$user) {
        // Unknown user – nothing to do
        http_response_code(200);
        exit;
    }

    // 5️⃣ Prepare the INSERT statement
    $stmt = $pdo->prepare("
      INSERT INTO attendance 
        (user_id, schedule_id, date, present, motivated, motivation, marked_by)
      VALUES 
        (:user_id, :schedule_id, :dt, :present, :motivated, :motivation, :marked_by)
    ");

    // 6️⃣ Loop & execute for each row
    $today = date('Y-m-d');
    foreach ($payload['attendance'] as $row) {
        $stmt->execute([
          ':user_id'     => $row['user_id'],
          ':schedule_id' => $row['schedule_id'],
          ':dt'          => $today,                 // or use $row['date'] if you include it
          ':present'     => $row['present']   ? 1 : 0,
          ':motivated'   => $row['motivated'] ? 1 : 0,
          ':motivation'  => $row['motivation'] ?: null,
          ':marked_by'   => $user['id'],
        ]);
    }

    // 7️⃣ Send a confirmation back into the chat
    $chat_id = $update['message']['chat']['id'];
    $text    = "✅ Attendance saved for {$today}.";
    file_get_contents(
      TELEGRAM_API_URL 
      . "/sendMessage?chat_id={$chat_id}"
      . "&text=" . urlencode($text)
    );

    // 8️⃣ Done
    http_response_code(200);
    exit;
}

// … handle other update types (commands, messages) below …
http_response_code(200);
