<?php
// Telegram webhook: inline buttons ("vpn:<host>") and /vpn, /stop, /none [host]
// from the allowed chats. Calls without the webhook's secret token get 403.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/plain');
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (TELEGRAM_SECRET === '' || !hash_equals(TELEGRAM_SECRET, $secret)) {
	http_response_code(403);
	exit("forbidden\n");
}
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$allowed = fn($chat) => in_array($chat, TELEGRAM_CHAT_IDS);

if ($query = $input['callback_query'] ?? null) {
	[$answer, $name] = explode(':', $query['data'] ?? '', 2) + ['', ''];
	$text = $allowed($query['message']['chat']['id'] ?? 0) ? set_answer($name, $answer) : 'not allowed';
	telegram('answerCallbackQuery', ['callback_query_id' => $query['id'], 'text' => $text]);
} elseif ($allowed($chat = $input['message']['chat']['id'] ?? 0)) {
	[$command, $name] = explode(' ', trim($input['message']['text'] ?? ''), 2) + ['', ''];
	$answer = ltrim(strtok($command, '@'), '/');
	$failed = array_column(hosts("state = 'failed'"), 'name');
	if (!in_array($answer, ['vpn', 'stop', 'none'], true)) {
		$text = "Failed over: " . (implode(', ', $failed) ?: 'none') . "\n/vpn, /stop, /none [host]";
	} elseif ($name === '' && count($failed) !== 1) {
		$text = 'Which host?';
	} else {
		$text = set_answer($name ?: $failed[0], $answer);
	}
	telegram('sendMessage', ['chat_id' => $chat, 'text' => $text]);
} elseif ($chat) {
	error_log("uplink-webservice: Telegram chat $chat is not in TELEGRAM_CHAT_IDS");
}
exit("ok\n");
