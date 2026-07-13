<?php
/**
 * scheduler.php - cron entrypoint for the scheduling + auto-posting engine.
 *
 * Run every few minutes from cPanel > Cron Jobs. Set the schedule to every 5
 * minutes and the command to:
 *   php /home/USER/public_html/website_eff65c78/bot/scheduler.php >> /home/USER/public_html/website_eff65c78/bot/data/scheduler.log 2>&1
 *
 * It refuses to run from a real browser request so nobody can trigger posting
 * over the web. It DOES run when launched from the shell/cron - whether that is
 * the CLI php binary (php_sapi_name()==='cli') or a CGI php binary invoked from
 * cron (no web request context) - and also via an authenticated URL that carries
 * the API secret (?key=... or an X-Sched-Key header), for hosts whose cron can
 * only fetch a URL.
 */
$config = require __DIR__ . '/config/config.php';

$fromShell = (php_sapi_name() === 'cli') || empty($_SERVER['REQUEST_METHOD']);
$provided  = isset($_GET['key']) ? (string) $_GET['key']
           : (string) ($_SERVER['HTTP_X_SCHED_KEY'] ?? '');
$secret    = (string) ($config['api_secret'] ?? '');
$keyOk     = ($secret !== '' && hash_equals($secret, $provided));
if (!$fromShell && !$keyOk) {
    http_response_code(403);
    exit('This script runs from cron (command line) only.');
}

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
