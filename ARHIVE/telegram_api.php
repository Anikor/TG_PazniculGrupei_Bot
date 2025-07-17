<?php
function apiRequest(string $method, array $params = []): array {
    $url = API_URL . $method;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function sendMessage(int $chat_id, string $text, array $reply_markup = null){
    $data = ['chat_id'=>$chat_id, 'text'=>$text, 'parse_mode'=>'HTML'];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    return apiRequest('sendMessage', $data);
}
