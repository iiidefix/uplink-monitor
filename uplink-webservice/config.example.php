<?php
// Copy to config.php (next to this file) and fill in.

// Shared bearer token of all watchdogs (API_TOKEN); e.g. openssl rand -hex 32
const HOST_TOKEN = '';

// Telegram bot from @BotFather (empty = no Telegram), the chats that get the
// messages and may answer, and the webhook's secret token; e.g.
// openssl rand -hex 32 (after changing it, register the webhook again).
const TELEGRAM_BOT_TOKEN = '';
const TELEGRAM_CHAT_IDS = [];
const TELEGRAM_SECRET = '';

// Logins for the page: user => password hash, made with
//   php -r 'echo password_hash("secret", PASSWORD_DEFAULT), "\n";'
// Empty = nobody can open the page.
const PAGE_USERS = [];

// Optional: const DB_FILE = __DIR__ . '/uplink.sqlite';
