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
			answer TEXT NOT NULL DEFAULT 'stop',   -- vpn | stop, until changed
			last_event TEXT,
			last_poll TEXT,
			delivered TEXT)");
		$db->exec("CREATE TABLE IF NOT EXISTS messages (
			host TEXT NOT NULL, chat TEXT NOT NULL, id INTEGER NOT NULL, text TEXT NOT NULL,
			PRIMARY KEY (host, chat))");
		if (!in_array('delivered', array_column($db->query('PRAGMA table_info(hosts)')->fetchAll(), 'name'))) {
			$db->exec('ALTER TABLE hosts ADD COLUMN delivered TEXT');  // database from an older version
		}
		$db->exec("UPDATE hosts SET answer = 'stop' WHERE answer = 'none'");  // 'none' is gone
	}
	return $db;
}

function hosts(string $where = '1'): array {
	return db()->query("SELECT * FROM hosts WHERE $where ORDER BY name")->fetchAll();
}

// A failover or failback from the watchdog; both reset the answer.
function event(string $name, string $event, string $time): void {
	db()->prepare("INSERT INTO hosts (name, state, answer, delivered, last_event) VALUES (?, ?, 'stop', ?, ?)
			ON CONFLICT(name) DO UPDATE SET state = excluded.state, answer = 'stop', delivered = excluded.delivered, last_event = excluded.last_event")
		->execute([$name, $event === 'failover' ? 'failed' : 'normal', $event === 'failover' ? '-' : null, "$event $time"]);
	if ($event === 'failover') {
		$text = "⚠️ $name failed over ($time)";
		db()->prepare('DELETE FROM messages WHERE host = ?')->execute([$name]);
		foreach (TELEGRAM_CHAT_IDS as $chat) {
			$sent = telegram('sendMessage', ['chat_id' => $chat, 'text' => status_text($text, hosts('name = ' . db()->quote($name))[0])]
				+ ['reply_markup' => buttons($name)]);
			if (isset($sent['message_id'])) {
				db()->prepare('INSERT OR REPLACE INTO messages (host, chat, id, text) VALUES (?, ?, ?, ?)')
					->execute([$name, (string)$chat, $sent['message_id'], $text]);
			}
		}
	} else {
		refresh($name, "failed back at $time");
		db()->prepare('DELETE FROM messages WHERE host = ?')->execute([$name]);
		notify("✅ $name is back on its primary uplink ($time)");
	}
}

// One button: Stop VPN once the answer is vpn, otherwise Start VPN.
function buttons(string $name, string $answer = 'stop'): array {
	return ['inline_keyboard' => [[$answer === 'vpn'
		? ['text' => 'Stop VPN', 'callback_data' => "stop:$name"]
		: ['text' => 'Start VPN', 'callback_data' => "vpn:$name"]]]];
}

// The failover message with the current action status below it.
function status_text(string $text, array $host, ?string $final = null): string {
	if ($final !== null) {
		return "$text\nStatus: $final";
	}
	return "$text\nStatus: " . match (true) {
		$host['delivered'] === '-' => 'no VPN requested, stop is the default',
		$host['delivered'] === null => "{$host['answer']} requested, goes out with the next poll",
		default => "{$host['answer']} sent to the host at {$host['delivered']}",
	};
}

// Edits the host's failover messages to show its current status; $final ends the
// action (failback) and removes the buttons.
function refresh(string $name, ?string $final = null): void {
	$host = hosts('name = ' . db()->quote($name))[0] ?? null;
	$st = db()->prepare('SELECT * FROM messages WHERE host = ?');
	$st->execute([$name]);
	foreach ($host ? $st->fetchAll() : [] as $message) {
		telegram('editMessageText', [
			'chat_id' => $message['chat'], 'message_id' => $message['id'],
			'text' => status_text($message['text'], $host, $final),
			'reply_markup' => $final === null ? buttons($name, $host['answer']) : ['inline_keyboard' => []],
		]);
	}
}

// A poll from the watchdog: the answer for a failed-over host, else stop.
function poll(string $name): string {
	db()->prepare("INSERT INTO hosts (name, answer, last_poll) VALUES (?, 'stop', datetime('now', 'localtime'))
			ON CONFLICT(name) DO UPDATE SET last_poll = excluded.last_poll")
		->execute([$name]);
	$host = hosts('name = ' . db()->quote($name))[0];
	if ($host['state'] !== 'failed') {
		return 'stop';
	}
	if ($host['delivered'] === null) {
		db()->prepare("UPDATE hosts SET delivered = datetime('now', 'localtime') WHERE name = ?")->execute([$name]);
		refresh($name);
	}
	return $host['answer'];
}

// Sets the answer of a failed-over host; returns a message for the user.
function set_answer(string $name, string $answer): string {
	if (!in_array($answer, ['vpn', 'stop'], true)) {
		return "unknown answer '$answer'";
	}
	$st = db()->prepare("UPDATE hosts SET answer = ?, delivered = NULL WHERE name = ? AND state = 'failed'");
	$st->execute([$answer, $name]);
	if ($st->rowCount()) {
		refresh($name);
	}
	return $st->rowCount() ? "$name: $answer with the next poll" : "$name is not failed over";
}

// Calls the Telegram Bot API; a failure is only logged.
function telegram(string $method, array $params): ?array {
	if (TELEGRAM_BOT_TOKEN === '') {
		return null;
	}
	$context = stream_context_create(['http' => [
		'method' => 'POST',
		'header' => 'Content-Type: application/json',
		'content' => json_encode($params),
		'timeout' => 5,
		'ignore_errors' => true,  // return Telegram's error description, not just false
	]]);
	$response = @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/$method", false, $context);
	$reply = json_decode((string)$response, true);
	if (!($reply['ok'] ?? false)) {
		error_log("uplink-webservice: Telegram $method failed: " . ($response ?: error_get_last()['message'] ?? 'no response'));
		return null;
	}
	return is_array($reply['result']) ? $reply['result'] : null;
}

function notify(string $text, ?array $buttons = null): void {
	foreach (TELEGRAM_CHAT_IDS as $chat) {
		telegram('sendMessage', ['chat_id' => $chat, 'text' => $text] + ($buttons ? ['reply_markup' => $buttons] : []));
	}
}
