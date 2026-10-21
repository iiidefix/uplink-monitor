# uplink-webservice: webservice and Telegram bot for uplink-watchdog

A small PHP webservice for [`uplink-watchdog`](../uplink-watchdog/README.md).
It keeps each host's state in SQLite and reports failover and failback in
Telegram. When a failed-over host polls, it gets the answer you chose with
a Telegram button or command, or on the web page: `vpn`, `stop` or `none`.

## How it works

Everything is in this one directory:

| File | Purpose |
|---|---|
| `host.php` | Watchdog endpoint `…/host` (bearer token) |
| `telegram.php` | Telegram webhook `…/telegram` (secret token) |
| `index.php` | Router for every request, and the status and control page |
| `lib.php` | Database (one `hosts` table) and Telegram calls |
| `config.example.php` | Copy to `config.php` |
| `server.bash` | Runs it with PHP's built-in web server |
| `.htaccess` | Apache: rewrites every request to `index.php` |
| `uplink.sqlite` | Created on first use |

Each host has a `state` (`normal`/`failed`) and an `answer`
(`none`/`vpn`/`stop`). The answer stays until it's changed. Failover and
failback reset it to `none`. A host that isn't failed over always gets
`none`.

**Watchdog** (`Authorization: Bearer <token>`, else 401):

| Request | Reply |
|---|---|
| `POST …/host` with JSON `{"host","event":"failover"\|"failback","time"}` | `ok`, plus a Telegram message (on failover with **Start VPN** / **Stop VPN** buttons) |
| `GET …/host?host=<name>` | `none`, `vpn` or `stop` |

**Telegram** (`…/telegram`, the webhook: calls without the secret token
get 403; only chats in `TELEGRAM_CHAT_IDS` are accepted):

| Input | Effect |
|---|---|
| Button **Start VPN** / **Stop VPN** | Answer `vpn` / `stop` for that host |
| `/vpn`, `/stop`, `/none [host]` | Set the answer. Without a host, it applies to the one failed-over host |
| anything else | Lists the failed-over hosts |

**Page** (`index.php`): the hosts with state, answer, last event and last
poll, plus buttons for failed-over hosts. PHP protects it with HTTP basic
auth, checked against `PAGE_USERS`. With no users configured, nobody can
open it. Use it only over HTTPS, since basic auth sends the password with
every request.

## Configuration

`config.php` defines constants:

| Constant | Meaning |
|---|---|
| `HOST_TOKEN` | Shared bearer token of the watchdogs |
| `TELEGRAM_BOT_TOKEN` | Bot token; `''` = no Telegram |
| `TELEGRAM_CHAT_IDS` | Chats that get the messages and may answer |
| `TELEGRAM_SECRET` | Secret token of the webhook |
| `PAGE_USERS` | Page logins: user name ⇒ password hash |
| `DB_FILE` | Optional; default `uplink.sqlite` next to the code |

Make a password hash with:

```sh
php -r 'echo password_hash("secret", PASSWORD_DEFAULT), "\n";'
```

Make `TELEGRAM_SECRET` (and `HOST_TOKEN`) a random token with either of:

```sh
openssl rand -hex 32
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

Both print 64 hex characters. Telegram accepts a secret of 1–256
characters from `A-Z`, `a-z`, `0-9`, `_` and `-`, so hex always fits.
Telegram sends it in the `X-Telegram-Bot-Api-Secret-Token` header of
every webhook call, and `telegram.php` rejects calls without it (403).
After changing it, register the webhook again (step 3 of the setup) with
the new value, or Telegram's calls will be rejected.

## Setup

Needs PHP 8.2+ with PDO SQLite and OpenSSL. For the Telegram webhook it
also needs public HTTPS with a valid certificate.

1. Copy the directory to the server. The PHP user must be able to write
   to the directory, because SQLite creates its journal next to the
   database. Alternatively, set `DB_FILE` to a path elsewhere.
2. `cp config.example.php config.php` and fill it in (random tokens for
   `HOST_TOKEN` and `TELEGRAM_SECRET`, see Configuration).
3. Telegram: create a bot with @BotFather. To find your chat ID, write to
   the bot and open `https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getUpdates`
   before setting the webhook. Then register the webhook:

   ```sh
   curl -F url=https://example.org/uplink/telegram -F secret_token=<TELEGRAM_SECRET> \
        -F 'allowed_updates=["message","callback_query"]' \
        https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook
   ```
4. Web server: point the document root (or an alias) at the directory,
   and **send every request to `index.php`**. It hands paths ending in
   `/host` and `/telegram` to `host.php` and `telegram.php`, and shows the
   page for anything else. That way `config.php` and `uplink.sqlite` are never
   served. Don't configure basic auth in the web server: `index.php` does
   it, and the endpoints use their own tokens.
   - Apache: the included `.htaccess` does it with `mod_rewrite`
     (`RewriteRule ^ index.php [END]`). It needs `mod_rewrite` and
     `AllowOverride FileInfo` (or All); otherwise copy the two lines into
     the server config. Without `mod_rewrite`, Apache answers 500 rather
     than serving files. With CGI/FastCGI PHP, also set `CGIPassAuth On`,
     so the `Authorization` header reaches PHP.
   - nginx: one location that runs `index.php` for every path, e.g.
     `location /uplink/ { include fastcgi_params; fastcgi_param
     SCRIPT_FILENAME /srv/uplink-webservice/index.php; fastcgi_pass …; }`.
5. Each watchdog (`/etc/default/uplink-watchdog`):

   ```sh
   NOTIFY_URL="https://example.org/uplink/host"
   SERVICE_IP="203.0.113.10"   # the server's address: no DNS over the backup
   API_TOKEN="<token>"
   ```

### Quick start with the built-in web server

```sh
cp config.example.php config.php   # at least token
./server.bash                      # http://127.0.0.1:8080/
./server.bash 0.0.0.0:8080         # all addresses (warns: no TLS)
```

It checks for PHP, its extensions and `config.php`, then runs `php -S`
with `index.php` as the router, like the Apache rewrite. So only the
endpoints and the page are served; any other path shows the page (or its
login). That server has no TLS, so passwords and tokens travel
unencrypted.

Telegram can't send webhooks to it. Instead, with `TELEGRAM_BOT_TOKEN` and
`TELEGRAM_SECRET` set, the script runs a poller next to the server. It
fetches the updates with `getUpdates` (long polling) and POSTs each one to
the local `/telegram` with the secret header, just like the webhook. So
buttons and commands work locally too. `getUpdates` doesn't work while
the bot has a webhook, so the script first deletes an existing one
(pending updates are kept) and prints its URL. **That server then gets no
updates until you register the webhook again** (step 3 of the setup), so
use a separate test bot if one is in production. No other program may
fetch the bot's updates at the same time; otherwise Telegram answers with
an error, which the script prints. Messages from
chats that aren't in `TELEGRAM_CHAT_IDS` are logged with their chat ID,
which is the easiest way to find yours.

## Limitations

- One shared token: every host with it can report under any host name.
- The page has no CSRF protection: a site you visit while logged in
  could send it answers.
- No history: only the latest event and poll per host are kept.
