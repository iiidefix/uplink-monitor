#!/bin/bash
# server.bash — run uplink-webservice with PHP's built-in web server, for
# tests or a small trusted network. index.php is the router, as with Apache:
# only /host, /telegram and the page are served, never config.php or the
# database.
# It has no TLS, so the page's password and the tokens travel unencrypted.
# Telegram can't reach it with a webhook, so with a bot token configured it
# fetches the updates itself (getUpdates) and posts them to /telegram. That
# needs the bot's webhook removed, so it deletes an existing one.
#   ./server.bash [ADDRESS:PORT]    default 127.0.0.1:8080 (or $LISTEN)
set -e
dir=$(cd "$(dirname "$0")" && pwd)
listen=${1:-${LISTEN:-127.0.0.1:8080}}

command -v php >/dev/null || { echo "php is not installed" >&2; exit 1; }
for ext in pdo_sqlite openssl; do
	php -m | grep -qx $ext || { echo "PHP extension $ext is missing" >&2; exit 1; }
done
[ -f "$dir/config.php" ] || { echo "$dir/config.php is missing: copy config.example.php and fill it in" >&2; exit 1; }

case $listen in
	127.*|localhost:*|\[::1\]:*) ;;
	*) echo "warning: listening on $listen without TLS; passwords and tokens travel unencrypted" >&2 ;;
esac
poll_telegram() {  # poll_telegram BOT_TOKEN SECRET — stand in for the webhook
	local api="https://api.telegram.org/bot$1" offset=0 response id update webhook
	# getUpdates fails while a webhook is set: remove it, keeping pending updates.
	webhook=$(curl -s --max-time 10 "$api/getWebhookInfo" \
		| php -r 'echo json_decode(stream_get_contents(STDIN), true)["result"]["url"] ?? "";')
	if [ -n "$webhook" ]; then
		echo "telegram: removing the webhook $webhook; register it again for that server" >&2
		curl -s --max-time 10 "$api/deleteWebhook" | grep -q '"ok":true' \
			|| echo "telegram: could not remove the webhook" >&2
	fi
	while :; do
		response=$(curl -s --max-time 60 "$api/getUpdates?timeout=50&offset=$offset") || { sleep 5; continue; }
		# One "update_id<TAB>update JSON" line per update, or an error line.
		while IFS=$'\t' read -r id update; do
			if [ "$id" = error ]; then
				echo "telegram: $update" >&2
				sleep 10
				continue
			fi
			curl -s -o /dev/null -H "X-Telegram-Bot-Api-Secret-Token: $2" --data "$update" "http://$listen/telegram" \
				|| echo "telegram: could not forward update $id" >&2
			offset=$((id + 1))
		done < <(php -r '$r = json_decode(stream_get_contents(STDIN), true);
			if (empty($r["ok"])) { echo "error\t", $r["description"] ?? "no answer", "\n"; exit; }
			foreach ($r["result"] as $u) echo $u["update_id"], "\t", json_encode($u), "\n";' <<<"$response")
	done
}

echo "uplink-webservice on http://$listen/  (/host, /telegram, the page at /); Ctrl-C stops it"
php -S "$listen" -t "$dir" "$dir/index.php" &
pids=$!
trap 'kill $pids 2>/dev/null' EXIT INT TERM

read -r bot secret < <(php -r 'require $argv[1]; echo TELEGRAM_BOT_TOKEN ?: "-", " ", TELEGRAM_SECRET ?: "-", "\n";' "$dir/config.php")
if [ "$bot" != - ] && [ "$secret" != - ]; then
	echo "fetching Telegram updates for /telegram (instead of a webhook)"
	poll_telegram "$bot" "$secret" &
	pids="$pids $!"
fi
wait -n   # whichever ends first; the trap stops the other
