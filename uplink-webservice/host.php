<?php
// Endpoint for uplink-watchdog (NOTIFY_URL/POLL_URL), with its bearer token.
//   POST {"host","event":"failover"|"failback","time",...}  → "ok"
//   GET  ?host=<name>                                       → "vpn" | "stop"
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: text/plain');
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (HOST_TOKEN === '' || !hash_equals('Bearer ' . HOST_TOKEN, $_SERVER['HTTP_AUTHORIZATION'] ?? $authorization)) {
	http_response_code(401);
	exit("unauthorized\n");
}
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$name = (string)($post ? $input['host'] ?? '' : $_GET['host'] ?? '');
if (!preg_match('/^[\w.-]{1,64}$/', $name) || ($post && !in_array($input['event'] ?? '', ['failover', 'failback'], true))) {
	http_response_code(400);
	exit("bad request\n");
}
if ($post) {
	event($name, $input['event'], (string)($input['time'] ?? ''));
	exit("ok\n");
}
exit(poll($name) . "\n");
