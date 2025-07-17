<?php
// poll.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';       // BOT_TOKEN, API_URL
require_once __DIR__ . '/telegram_api.php'; // apiRequest(), sendMessage()
require_once __DIR__ . '/db.php';           // getUserStats(), getTodaySchedule(), markAttendance(), getScheduleForDate(), getWeekSchedule()

// Use long polling
apiRequest('deleteWebhook', ['drop_pending_updates' => true]);
echo '[' . date('H:i:s') . '] Bot started, long polling...' . PHP_EOL;

$offset = 0;
while (true) {
    echo '[' . date('H:i:s') . "] Polling (offset={$offset})..." . PHP_EOL;
    $updates = apiRequest('getUpdates', [
        'offset'  => $offset,
        'timeout' => 20,
        'limit'   => 5
    ]);
    if (empty($updates['result'])) {
        sleep(1);
        continue;
    }
    foreach ($updates['result'] as $upd) {
        $offset = $upd['update_id'] + 1;

        //
        // 1) Handle Web App payload (attendance form)
        //
        if (isset($upd['message']['web_app_data'])) {
            $chatId   = $upd['message']['chat']['id'];
            $markerTg = $upd['message']['from']['id'];
            $payload  = json_decode($upd['message']['web_app_data']['data'], true);

            if (!empty($payload['attendance']) && is_array($payload['attendance'])) {
                foreach ($payload['attendance'] as $rec) {
                    markAttendance(
                        $markerTg,
                        intval($rec['user_id']),
                        intval($rec['schedule_id']),
                        (bool)$rec['present']
                    );
                }
                sendMessage($chatId, '✅ Attendance saved.');
            }
            continue;
        }

        //
        // 2) Handle text commands
        //
        if (isset($upd['message']['text'])) {
            $chatId = $upd['message']['chat']['id'];
            $userId = $upd['message']['from']['id'];
            $text   = trim($upd['message']['text']);
            echo '[' . date('H:i:s') . "] MSG: $text" . PHP_EOL;

            switch (true) {
                case $text === '/start':
                    sendMessage($chatId,
                        "Welcome to Attendance Bot!\n".
                        "/id     – show your Telegram ID\n".
                        "/echo   – echo back text\n".
                        "/stats  – your attendance stats\n".
                        "/mark   – mark today’s attendance\n".
                        "/app    – open the attendance app"
                    );
                    break;

                case $text === '/id':
                    sendMessage($chatId, "Your Telegram ID: {$userId}");
                    break;

                case mb_strpos($text, '/echo ') === 0:
                    sendMessage($chatId, mb_substr($text, 6));
                    break;

                case $text === '/stats':
                    $stats = getUserStats($userId);
                    if (! $stats) {
                        sendMessage($chatId, '❌ You are not registered.');
                    } else {
                        $pct = $stats['total_classes']
                             ? round($stats['present_count'] / $stats['total_classes'] * 100, 2)
                             : 0;
                        sendMessage($chatId,
                            "Name: {$stats['name']}\n".
                            "Present: {$stats['present_count']} of {$stats['total_classes']} ({$pct}%)"
                        );
                    }
                    break;

                case $text === '/mark':
                    $sched = getTodaySchedule($userId);
                    if (empty($sched)) {
                        sendMessage($chatId, 'No classes today or you’re not in a group.');
                    } else {
                        $keyboard = [];
                        foreach ($sched as $row) {
                            $keyboard[][] = [
                                'text'          => $row['time_slot'].' '.$row['subject'],
                                'callback_data' => 'slot_'.$row['id']
                            ];
                        }
                        sendMessage($chatId, 'Select class to mark:', [
                            'inline_keyboard' => $keyboard
                        ]);
                    }
                    break;

                case $text === '/app':
                    // 🔑 POINT to greeting.php instead of index.html, no tg_id param
                    $host   = 'https://234ebeb345c8.ngrok-free.app/miniapp/index.html'; 
                    $url    = $host . '/miniapp/greeting.php';
                    $kbd    = [
                        'inline_keyboard' => [[
                            [
                              'text'    => '📝 Open Attendance Journal',
                              'web_app' => ['url' => $url]
                            ]
                        ]]
                    ];
                    sendMessage($chatId,
                        'Tap below to open your personalized attendance journal:',
                        json_encode(['reply_markup' => $kbd])
                    );
                    break;

                default:
                    // ignore other text
                    break;
            }
        }

        //
        // 3) Handle callback queries for /mark flow
        //
        if (isset($upd['callback_query'])) {
            $cq     = $upd['callback_query'];
            $chatId = $cq['message']['chat']['id'];
            $msgId  = $cq['message']['message_id'];
            $userId = $cq['from']['id'];
            $data   = $cq['data'];
            echo '[' . date('H:i:s') . "] CQ: $data" . PHP_EOL;

            if (strpos($data, 'slot_') === 0) {
                // User picked a class slot
                $sid = (int)substr($data, 5);
                $buttons = [
                  [['text'=>'Present','callback_data'=>"mark_{$sid}_1"]],
                  [['text'=>'Absent','callback_data'=>"mark_{$sid}_0"]]
                ];
                apiRequest('editMessageText', [
                  'chat_id'      => $chatId,
                  'message_id'   => $msgId,
                  'text'         => "Class #{$sid}: select status",
                  'reply_markup' => json_encode(['inline_keyboard'=>$buttons])
                ]);
            }

            if (preg_match('/^mark_(\d+)_(0|1)$/',$data,$m)) {
                // User picked present/absent
                $sid     = (int)$m[1];
                $present = (bool)$m[2];
                $ok      = markAttendance($userId, $sid, $present);
                $resp    = $ok
                         ? ($present ? '✅ Marked present' : '❌ Marked absent')
                         : '❌ Error saving attendance';
                apiRequest('editMessageText', [
                  'chat_id'    => $chatId,
                  'message_id' => $msgId,
                  'text'       => $resp
                ]);
            }
        }
    }
}
