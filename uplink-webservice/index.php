<?php
// Router and status page. Every request arrives here (Apache via .htaccess,
// ./server.bash via php -S): …/host and …/telegram go to host.php and
// telegram.php, anything else gets this page, behind HTTP basic auth
// against page_users.
declare(strict_types=1);
$endpoint = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if (in_array($endpoint, ['host', 'telegram'], true)) {
		require __DIR__ . "/$endpoint.php";
		exit;
}
require __DIR__ . '/lib.php';

// PHP-FPM/CGI often leave PHP_AUTH_USER unset; then read the header itself.
[$user, $password] = [$_SERVER['PHP_AUTH_USER'] ?? '', $_SERVER['PHP_AUTH_PW'] ?? ''];
// if ($user === '' && preg_match('/^Basic (.+)$/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
// 	[$user, $password] = explode(':', (string)base64_decode($m[1]), 2) + ['', ''];
// }
if (!password_verify($password, PAGE_USERS[$user] ?? '')) {
	header('WWW-Authenticate: Basic realm="uplink"');
	http_response_code(401);
	exit("login required\n");
}

$message = $_SERVER['REQUEST_METHOD'] === 'POST' ? set_answer((string)($_POST['host'] ?? ''), (string)($_POST['answer'] ?? '')) : '';
$h = fn($s) => htmlspecialchars((string)$s);
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Uplink hosts</title>
<style>
	body { font: 15px/1.4 system-ui, sans-serif; margin: 1rem; color-scheme: light dark; }
	td, th { text-align: left; padding: .3rem .6rem; }
</style>
<h1>Uplink hosts</h1>
<p><?= $h($message) ?></p>
<table>
<tr><th>Host</th><th>State</th><th>Answer</th><th>Last event</th><th>Last poll</th></tr>
<?php foreach (hosts() as $host): ?>
<tr>
	<td><?= $h($host['name']) ?></td>
	<td><?= $h($host['state']) ?></td>
	<td><?php if ($host['state'] === 'failed'): ?>
		<form method="post"><input type="hidden" name="host" value="<?= $h($host['name']) ?>">
		<?php foreach (['vpn', 'stop'] as $answer): ?>
			<button name="answer" value="<?= $answer ?>"<?= $answer === $host['answer'] ? ' disabled' : '' ?>><?= $answer ?></button>
		<?php endforeach ?></form>
	<?php else: ?>–<?php endif ?></td>
	<td><?= $h($host['last_event']) ?></td>
	<td><?= $h($host['last_poll']) ?></td>
</tr>
<?php endforeach ?>
</table>
