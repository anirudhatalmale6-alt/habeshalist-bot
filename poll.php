<?php
/**
 * poll.php - outbound long-polling bridge (CLI / cron only).
 *
 * WHY THIS EXISTS
 * The normal way the bot receives messages is a webhook: Telegram POSTs each
 * update INTO webhook.php. On this host the firewall (ModSecurity) blocks
 * Telegram's inbound POSTs (409), so the webhook can't be reached until the
 * host disables ModSecurity for /bot/.
 *
 * This script is the alternative that needs NO firewall change: instead of
 * Telegram pushing updates in, the server reaches OUT to Telegram and pulls
 * them (getUpdates). Outbound requests are not touched by the inbound
 * firewall, so the bot works immediately. It reuses the exact same handlers
 * as the webhook, so behaviour is identical.
 *
 * HOW IT RUNS
 * Run once per minute from cron:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/bot/poll.php >/dev/null 2>&1
 * Each run holds a long-poll connection for up to ~55s (so a 1-minute cron
 * gives near-continuous coverage) and then exits. A file lock guarantees only
 * one poller runs at a time, so overlapping cron ticks never double-poll
 * (which would cause Telegram 409 Conflict).
 *
 * SWITCHING MODES
 * Polling and webhook are mutually exclusive. To use polling the webhook must
 * be removed (deleteWebhook). To go back to the instant webhook later (once
 * ModSecurity is fixed), just re-run setWebhook and stop the cron job.
 */

// Never allow this to be hit over the web.
//
// This must run from cron. On this host some cron setups invoke the CGI PHP
// binary rather than the CLI one, so php_sapi_name() can be 'cgi-fcgi' even
// though there is no web request at all. The reliable signal for "a real HTTP
// request" is an HTTP method being present; cron/CLI runs have none. So we
// treat "no REQUEST_METHOD" as running from the shell regardless of SAPI.
$fromShell = (php_sapi_name() === 'cli') || empty($_SERVER['REQUEST_METHOD']);
if (!$fromShell) {
    http_response_code(403);
    exit;
}

// Load config, database, Telegram client and all handler functions. Under CLI
// webhook.php only sets things up and defines handlers - it does NOT dispatch.
require __DIR__ . '/webhook.php';

// ---- tunables ----
$MAX_RUN_SECONDS = 55;   // stay just under a 1-minute cron cadence
$LONG_POLL       = 50;   // how long each getUpdates call waits for new updates

$dataDir    = __DIR__ . '/data';
$lockPath   = $dataDir . '/poll.lock';
$offsetPath = $dataDir . '/poll.offset';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// Single-instance lock: if another poller is already running, exit quietly so
// two overlapping getUpdates calls can't collide (Telegram 409).
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$token   = $config['bot_token'];
$apiBase = 'https://api.telegram.org/bot' . $token . '/';
$offset  = (int) @file_get_contents($offsetPath);

$deadline = time() + $MAX_RUN_SECONDS;

while (time() < $deadline) {
    $remaining = $deadline - time();
    $timeout   = min($LONG_POLL, max(1, $remaining));

    $url = $apiBase . 'getUpdates?timeout=' . $timeout
         . '&offset=' . $offset
         . '&allowed_updates=' . urlencode('["message","callback_query","chat_member"]');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout + 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if ($resp === false) {
        sleep(1);
        continue;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        // Most common cause: a webhook is still set, so Telegram refuses
        // getUpdates. Log it once so it's obvious in the error log.
        $desc = is_array($data) ? ($data['description'] ?? 'unknown') : 'invalid response';
        error_log('poll.php getUpdates not ok: ' . $desc);
        sleep(2);
        continue;
    }

    foreach ($data['result'] as $update) {
        $offset = $update['update_id'] + 1;
        try {
            if (isset($update['callback_query'])) {
                handleCallbackQuery($update['callback_query']);
            } elseif (isset($update['message'])) {
                handleMessage($update['message']);
            }
        } catch (Throwable $e) {
            error_log('poll.php handler error on update ' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
        }
        // Persist the offset after each update so a crash never re-processes it.
        file_put_contents($offsetPath, (string) $offset, LOCK_EX);
    }
}

flock($lock, LOCK_UN);
fclose($lock);
