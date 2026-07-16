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
 * Run from cron:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/bot/poll.php >/dev/null 2>&1
 *
 * IMPORTANT - this must NOT depend on the cron firing every minute. Some shared
 * hosts (Bluehost included) silently enforce a MINIMUM cron interval (commonly
 * ~15 minutes) and quietly rewrite an every-minute cron into an every-16-minute
 * one. If a run only lived ~55s, the bot would then be dead for ~15 min and
 * appear frozen. So instead each run STAYS ALIVE for the whole interval: it
 * long-polls continuously and ticks the scheduler every 60s from inside the
 * loop. The cron is now just a watchdog that restarts the poller if it ever
 * died. A file lock guarantees only one poller runs at a time, so a fresh cron
 * tick that arrives while a run is still alive simply exits (no 409, no
 * double-poll, no double-posting).
 *
 * The run length is $MAX_RUN_SECONDS below (override with the 'poll_run_seconds'
 * setting). It is deliberately a little SHORTER than the host's cron interval so
 * each run hands off cleanly to the next tick with only a few seconds' gap,
 * whatever interval the host allows (every minute, or every ~15 min).
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
// Stay alive for close to a full host-cron interval so the bot never goes quiet
// between ticks even if the host forces a ~15-minute cron. Kept a touch under
// the interval so each run exits just before the next tick (clean hand-off).
// Overridable via the 'poll_run_seconds' setting if a host needs a shorter run.
$MAX_RUN_SECONDS = 840;                 // ~14 min default (bridges a */15-*/16 cron)
$SCHED_EVERY     = 60;                  // run the scheduler tick this often (seconds)
$LONG_POLL       = 50;                  // how long each getUpdates call waits for new updates

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

// Allow a host to shorten the run without editing code (e.g. if a host kills
// long CLI processes). Falls back to the default above.
$override = (int) $db->getSetting('poll_run_seconds', 0);
if ($override > 0) {
    $MAX_RUN_SECONDS = $override;
}

// ---- scheduler tick ----
// The scheduling + auto-posting engine runs from INSIDE this poll loop (see
// runScheduler() below), once at start and then every $SCHED_EVERY seconds for
// the whole run. This gives near per-minute posting accuracy WITHOUT relying on
// a separate scheduler cron (this host has a history of that second cron being
// missing or misconfigured) and WITHOUT depending on how often the poll cron
// itself fires. Newly-approved promotions get booked here and any post whose
// time has arrived is published here. We hold the single-instance lock the whole
// time, so a booking can never be posted twice. Wrapped so a scheduler hiccup
// can never bring the poller down.
$runScheduler = function () use ($config, $tg) {
    try {
        require_once __DIR__ . '/includes/scheduler.php';
        $sched = new HL_Scheduler(__DIR__ . '/data/bot.sqlite', $tg, $config);
        $sched->run();
    } catch (Throwable $e) {
        error_log('poll.php scheduler tick failed: ' . $e->getMessage());
    }
};

$runScheduler();
$lastSched = time();

$deadline = time() + $MAX_RUN_SECONDS;

while (time() < $deadline) {
    // Tick the scheduler roughly every $SCHED_EVERY seconds during the run so
    // scheduled posts go out on time even on a long-lived run.
    if (time() - $lastSched >= $SCHED_EVERY) {
        $runScheduler();
        $lastSched = time();
    }

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
