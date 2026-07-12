<?php
/**
 * scheduler.php - cron entrypoint for the scheduling + auto-posting engine.
 *
 * Run every few minutes from cPanel > Cron Jobs. Set the schedule to every 5
 * minutes and the command to:
 *   php /home/USER/public_html/website_eff65c78/bot/scheduler.php >> /home/USER/public_html/website_eff65c78/bot/data/scheduler.log 2>&1
 *
 * CLI-only: it refuses to run from a browser so nobody can trigger posting over
 * the web. (If your host only offers URL-based cron, tell me and I'll add a
 * secret-token gate instead.)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script runs from cron (command line) only.');
}

$config = require __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/scheduler.php';

if (empty($config['bot_token'])) {
    fwrite(STDERR, "scheduler: bot token missing; aborting.\n");
    exit(1);
}

$tg = new Telegram($config['bot_token']);
$sched = new HL_Scheduler(__DIR__ . '/data/bot.sqlite', $tg, $config);
$log = $sched->run();

$stamp = gmdate('Y-m-d H:i:s');
if (!$log) { echo "[$stamp UTC] nothing to do\n"; }
foreach ($log as $line) { echo "[$stamp UTC] $line\n"; }
