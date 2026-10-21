<?php
// uplink-webservice: host state in SQLite, notifications via a Telegram bot.
declare(strict_types=1);

require __DIR__ . '/config.php';
defined('DB_FILE') || define('DB_FILE', __DIR__ . '/uplink.sqlite');

function db(): PDO {
	static $db;
	if ($db === null) {
		$db = new PDO('sqlite:' . DB_FILE);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$db->exec("CREATE TABLE IF NOT EXISTS hosts (
			name TEXT PRIMARY KEY,
			state TEXT NOT NULL DEFAULT 'normal',  -- normal | failed
			answer TEXT NOT NULL DEFAULT 'none',   -- none | vpn | stop, until changed
			last_event TEXT,
			last_poll TEXT)");
	}
	return $db;
}

function hosts(string $where = '1'): array {
	return db()->query("SELECT * FROM hosts WHERE $where ORDER BY name")->fetchAll();
}

// A failover or failback from the watchdog; both reset the answer.
function event(string $name, string $event, string $time): void {
	db()->prepare("INSERT INTO hosts (name, state, last_event) VALUES (?, ?, ?)
			ON CONFLICT(name) DO UPDATE SET state = excluded.state, answer = 'none', last_event = excluded.last_event")
		->execute([$name, $event === 'failover' ? 'failed' : 'normal', "$event $time"]);
	if ($event === 'failover') {
		notify("⚠️ $name failed over ($time)", ['inline_keyboard' => [[
			['text' => 'Start VPN', 'callback_data' => "vpn:$name"],
			['text' => 'Stop VPN', 'callback_data' => "stop:$name"],
		]]]);
	} else {
		notify("✅ $name is back on its primary uplink ($time)");
	}
}

// A poll from the watchdog: the answer for a failed-over host, else none.
function poll(string $name): string {
	db()->prepare("INSERT INTO hosts (name, last_poll) VALUES (?, datetime('now', 'localtime'))
			ON CONFLICT(name) DO UPDATE SET last_poll = excluded.last_poll")
		->execute([$name]);
	$host = hosts('name = ' . db()->quote($name))[0];
	return $host['state'] === 'failed' ? $host['answer'] : 'none';
}

// Sets the answer of a failed-over host; returns a message for the user.
function set_answer(string $name, string $answer): string {
	if (!in_array($answer, ['none', 'vpn', 'stop'], true)) {
		return "unknown answer '$answer'";
	}
	$st = db()->prepare("UPDATE hosts SET answer = ? WHERE name = ? AND state = 'failed'");
	$st->execute([$answer, $name]);
	return $st->rowCount() ? "$name: $answer with the next poll" : "$name is not failed over";
}

// Calls the Telegram Bot API; a failure is only logged.
function telegram(string $method, array $params): void {
	if (TELEGRAM_BOT_TOKEN === '') {
		return;
	}
	$context = stream_context_create(['http' => [
		'method' => 'POST',
		'header' => 'Content-Type: application/json',
		'content' => json_encode($params),
		'timeout' => 5,
		'ignore_errors' => true,  // return Telegram's error description, not just false
	]]);
	$response = @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/$method", false, $context);
	if (!(json_decode((string)$response, true)['ok'] ?? false)) {
		error_log("uplink-webservice: Telegram $method failed: " . ($response ?: error_get_last()['message'] ?? 'no response'));
	}
}

function notify(string $text, ?array $buttons = null): void {
	foreach (TELEGRAM_CHAT_IDS as $chat) {
		telegram('sendMessage', ['chat_id' => $chat, 'text' => $text] + ($buttons ? ['reply_markup' => $buttons] : []));
	}
}
