<?php
require_once 'telegram_api.php';
require_once 'db.php';

// Получаем весь update
$update = json_decode(file_get_contents('php://input'), true);

// --- 1) Обрабатываем текстовые сообщения ---
if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $tg_id   = $msg['from']['id'];
    $text    = $msg['text'] ?? '';

    switch ($text) {
        case '/start':
            sendMessage($chat_id, "Привет! Я — бот-журнал для старосты.\n\nCommands:\n/stats — статистика\n/mark  — отметить студентов");
            break;

        case '/stats':
            $stats = getUserStats($tg_id);
            if (!$stats) {
                sendMessage($chat_id, "Ты не найден в базе данных.");
            } else {
                $perc = $stats['total_classes']
                    ? round($stats['present_count'] / $stats['total_classes'] * 100, 2)
                    : 0;
                sendMessage($chat_id, "<b>{$stats['name']}</b>, у тебя {$stats['present_count']} посещений из {$stats['total_classes']} ({$perc}%)");
            }
            break;

        case '/mark':
            $sched = getTodaySchedule($tg_id);
            if (empty($sched)) {
                sendMessage($chat_id, "На сегодня пар нет или ты не в группе.");
            } else {
                $buttons = [];
                foreach ($sched as $row) {
                    $buttons[][] = [
                        'text' => "{$row['time_slot']} — {$row['subject']}",
                        'callback_data' => "slot_{$row['id']}"
                    ];
                }
                sendMessage($chat_id, "Выбери пару для отметки:", ['inline_keyboard'=>$buttons]);
            }
            break;

        default:
            // игнорируем или выводим подсказку
            break;
    }
    exit;
}

// --- 2) Обрабатываем нажатия inline-кнопок ---
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chat_id = $cq['message']['chat']['id'];
    $tg_id   = $cq['from']['id'];
    $data    = $cq['data'];
    $msg_id  = $cq['message']['message_id'];

    // Шаг 1: выбрана пара
    if (strpos($data, 'slot_') === 0) {
        $sid = (int)substr($data, 5);
        $buttons = [
            [['text'=>'Присутствовал','callback_data'=>"mark_{$sid}_1"]],
            [['text'=>'Отсутствовал','callback_data'=>"mark_{$sid}_0"]],
        ];
        apiRequest('editMessageText', [
            'chat_id'=>$chat_id,
            'message_id'=>$msg_id,
            'text'=>"Пара №{$sid}, отметь статус:",
            'reply_markup'=>json_encode(['inline_keyboard'=>$buttons])
        ]);
        exit;
    }

    // Шаг 2: окончательная отметка
    if (preg_match('/^mark_(\d+)_(0|1)$/', $data, $m)) {
        $sid     = (int)$m[1];
        $present = (bool)$m[2];
        $ok = markAttendance($tg_id, $sid, $present);
        $text = $ok
            ? ($present ? "✅ Отмечено как присутствовал" : "❌ Отмечено как отсутствовал")
            : "Ошибка при сохранении.";
        apiRequest('editMessageText', [
            'chat_id'=>$chat_id,
            'message_id'=>$msg_id,
            'text'=>$text
        ]);
        exit;
    }
}
