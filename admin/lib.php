<?php
/**
 * lib.php - shared core for the HabeshaList web admin panel.
 *
 * The panel is a small, self-contained PHP app that reads and writes the SAME
 * SQLite database the Telegram bot uses (the "settings" table is the single
 * source of truth). Change a package price or a payment handle here and the
 * bot picks it up on the very next message - no redeploy, no bot restart.
 *
 * It is browser-only (you log in from your own computer), so it is completely
 * separate from the Telegram webhook and is NOT affected by the ModSecurity /
 * polling situation on the bot side.
 *
 * WHERE TO PUT THIS FOLDER
 * Recommended: place this "admin" folder NEXT TO your bot folder, e.g.
 *   public_html/website_eff65c78/bot/          <- the bot
 *   public_html/website_eff65c78/bizadmin/     <- this folder (rename as you like)
 * The database path is auto-detected for common layouts. If your layout is
 * unusual, set BOT_DB_PATH (see below) or an environment variable BOT_DB_PATH.
 */

// ---------------------------------------------------------------------------
// 0) Force HTTPS + set baseline security headers for every browser request.
//    (Skipped on CLI so reset-password.php still runs from the terminal.)
//    Never let the login form or session cookie travel over cleartext http.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $hl_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    if (!$hl_https && !empty($_SERVER['HTTP_HOST'])) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    // Folder-scoped hardening headers (sent only on panel responses; no HSTS so
    // the rest of habeshalist.com is untouched).
    header('X-Frame-Options: DENY');            // no embedding in an iframe (clickjacking)
    header('X-Content-Type-Options: nosniff');  // no MIME sniffing
    header('Referrer-Policy: no-referrer');     // do not leak the panel URL
}

// ---------------------------------------------------------------------------
// 1) Locate the bot's SQLite database (shared with the Telegram bot).
// ---------------------------------------------------------------------------
// If auto-detection ever fails, hard-set the absolute path here, e.g.
//   define('BOT_DB_PATH', '/home/USER/public_html/website_eff65c78/bot/data/bot.sqlite');
if (!defined('BOT_DB_PATH')) {
    $candidates = [
        getenv('BOT_DB_PATH') ?: null,
        __DIR__ . '/../bot/data/bot.sqlite',   // panel is a sibling of the bot folder
        __DIR__ . '/../data/bot.sqlite',       // panel is inside the bot folder
        __DIR__ . '/../../bot/data/bot.sqlite', // panel one level deeper
    ];
    $found = null;
    foreach ($candidates as $c) {
        if ($c && file_exists($c)) { $found = $c; break; }
    }
    define('BOT_DB_PATH', $found ?: '');
}

// ---------------------------------------------------------------------------
// 2) Package metadata (labels only). Prices are read from / written to the
//    settings table, keyed price_<packageKey>, exactly like the bot does.
//    Keep these keys in sync with config/config.php promo_packages.
// ---------------------------------------------------------------------------
const HL_PACKAGES = [
    'one_time' => ['name' => 'One-Time Post',        'default' => 10,  'note' => 'Single promotional post'],
    'monthly'  => ['name' => 'Monthly Plan',         'default' => 50,  'note' => 'Up to 8 posts/month, 1 pinned'],
    'yearly'   => ['name' => 'Yearly Plan',          'default' => 500, 'note' => '96 posts/year, monthly pin'],
    'botw'     => ['name' => 'Business of the Week',  'default' => 75,  'note' => 'Exclusive, pinned 7 days'],
];

const HL_PAY_HANDLES = [
    'pay_zelle'   => ['label' => 'Zelle handle',   'placeholder' => 'email or phone, e.g. habeshalist@email.com'],
    'pay_cashapp' => ['label' => 'Cash App handle', 'placeholder' => 'e.g. $HabeshaList'],
    'pay_support' => ['label' => 'Support contact', 'placeholder' => 'e.g. @Habesha_list'],
];

// ---------------------------------------------------------------------------
// 3) Database helpers (SQLite3, same engine the bot uses).
// ---------------------------------------------------------------------------
function hl_db() {
    static $db = null;
    if ($db === null) {
        if (BOT_DB_PATH === '' || !file_exists(BOT_DB_PATH)) {
            http_response_code(500);
            exit('Database not found. Set BOT_DB_PATH in admin/lib.php to the full path of your bot.sqlite file.');
        }
        $db = new SQLite3(BOT_DB_PATH);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
    }
    return $db;
}

function hl_get_setting($key, $default = null) {
    $stmt = hl_db()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    return ($row && $row['value'] !== null) ? $row['value'] : $default;
}

function hl_set_setting($key, $value) {
    $stmt = hl_db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':v', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function hl_count($sql) {
    $res = hl_db()->query($sql);
    $row = $res ? $res->fetchArray(SQLITE3_NUM) : [0];
    return (int) ($row[0] ?? 0);
}

// ---------------------------------------------------------------------------
// 4) Session / auth.
// ---------------------------------------------------------------------------
function hl_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('HLADMIN');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function hl_is_configured() {
    return hl_get_setting('admin_pass_hash', '') !== '';
}

function hl_is_logged_in() {
    hl_session_start();
    return !empty($_SESSION['hl_admin']);
}

function hl_require_login() {
    if (!hl_is_logged_in()) {
        header('Location: signin.php');
        exit;
    }
}

// ---------------------------------------------------------------------------
// 5) CSRF + escaping.
// ---------------------------------------------------------------------------
function hl_csrf_token() {
    hl_session_start();
    if (empty($_SESSION['hl_csrf'])) {
        $_SESSION['hl_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['hl_csrf'];
}

function hl_csrf_check() {
    hl_session_start();
    $sent = $_POST['csrf'] ?? '';
    if (!$sent || empty($_SESSION['hl_csrf']) || !hash_equals($_SESSION['hl_csrf'], $sent)) {
        http_response_code(400);
        exit('Invalid session token. Go back and try again.');
    }
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 6) Encrypted secrets (bot token, Stripe key, ...).
//    Secrets are stored ENCRYPTED at rest in the settings table (keys sec_*),
//    using AES-256-GCM with a master key (HL_APP_KEY) that lives ONLY in the
//    bot's .env file - never in the database and never in the repo. So a leak
//    of the database alone reveals nothing; you also need the .env master key.
//    config.php reads the same sec_* rows and decrypts them at bot runtime,
//    always falling back to the plain .env value if anything is off.
// ---------------------------------------------------------------------------

// Load the 32-byte master key. Looks at a real env var first, then the bot's
// .env (sibling of the database: <bot>/.env). Returns the raw key or null.
function hl_app_key() {
    static $key = false;
    if ($key !== false) return $key;
    $b64 = getenv('HL_APP_KEY') ?: ($_SERVER['HL_APP_KEY'] ?? '');
    if ($b64 === '' && BOT_DB_PATH !== '') {
        $envFile = dirname(BOT_DB_PATH, 2) . '/.env';   // <bot>/data/bot.sqlite -> <bot>/.env
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                if (trim($k) === 'HL_APP_KEY') {
                    $v = trim($v);
                    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                        $v = substr($v, 1, -1);
                    }
                    $b64 = $v; break;
                }
            }
        }
    }
    $raw = $b64 !== '' ? base64_decode($b64, true) : false;
    $key = ($raw !== false && strlen($raw) === 32) ? $raw : null;
    return $key;
}

// Read a single KEY from the bot's .env (used to show the current fallback
// value, masked). Returns '' if not found.
function hl_bot_env($key) {
    if (BOT_DB_PATH === '') return '';
    $envFile = dirname(BOT_DB_PATH, 2) . '/.env';
    if (!is_readable($envFile)) return '';
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        if (trim($k) === $key) {
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            return $v;
        }
    }
    return '';
}

function hl_encrypt_secret($plain, $key) {
    if (!function_exists('openssl_encrypt') || $key === null) return false;
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return false;
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function hl_decrypt_secret($blob, $key) {
    if ($key === null || strpos((string) $blob, 'v1:') !== 0) return false;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 28) return false;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? false : $pt;
}

// The live value of a secret: the encrypted DB override if present, else .env.
function hl_effective_secret($envKey, $setKey) {
    $key = hl_app_key();
    if ($key !== null) {
        $blob = hl_get_setting($setKey, '');
        if ($blob !== '') {
            $dec = hl_decrypt_secret($blob, $key);
            if ($dec !== false && $dec !== '') return $dec;
        }
    }
    return hl_bot_env($envKey);
}

// ---------------------------------------------------------------------------
// 7) Telegram send (used to notify a customer when a promo is approved/rejected
//    from the web). Uses the live bot token, curl with a stream fallback.
// ---------------------------------------------------------------------------
function hl_tg_api($token, $method, array $params) {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = http_build_query($params);
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
    }
    if ($resp === false) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $body, 'timeout' => 12, 'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
    }
    $j = $resp !== false ? json_decode($resp, true) : null;
    return is_array($j) ? $j : ['ok' => false, 'description' => 'Could not reach Telegram.'];
}

// Approve or reject a promotion from the web, mirroring the in-bot flow, and
// notify the customer in Telegram. Returns [okBool, humanMessage].
function hl_moderate_promo($promoId, $decision) {
    $db = hl_db();
    $stmt = $db->prepare('SELECT * FROM promotions WHERE id = :id');
    $stmt->bindValue(':id', (int) $promoId, SQLITE3_INTEGER);
    $promo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$promo) return [false, 'That promotion could not be found.'];
    if (($promo['status'] ?? '') !== 'pending_review') {
        return [false, 'This one was already handled (status: ' . ($promo['status'] ?: 'unknown') . ').'];
    }
    $bname = $promo['business_name'] !== '' ? $promo['business_name'] : 'your business';
    $tid = (int) $promo['telegram_id'];

    if ($decision === 'approve') {
        $db->exec("UPDATE promotions SET status='approved', payment_status='verified' WHERE id=" . (int) $promoId);
        $text = "\xF0\x9F\x8E\x89 <b>Great news!</b> Your promotion for <b>" . $bname . "</b> has been approved.\n\n"
              . "It will be posted in the HabeshaList Telegram Group as scheduled. You'll be notified once it goes live. Thank you!";
        $verb = 'Approved';
    } else {
        $db->exec("UPDATE promotions SET status='rejected' WHERE id=" . (int) $promoId);
        $text = "Regarding your promotion for <b>" . $bname . "</b> - unfortunately it wasn't approved this time. "
              . "Please contact our support team if you have any questions about your payment or would like to resubmit.";
        $verb = 'Rejected';
    }

    $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
    if ($token === '') {
        return [true, $verb . ': ' . $bname . ' (saved, but the bot token is not available here so the customer was not messaged).'];
    }
    $r = hl_tg_api($token, 'sendMessage', ['chat_id' => $tid, 'text' => $text, 'parse_mode' => 'HTML']);
    if (!empty($r['ok'])) {
        return [true, $verb . ': ' . $bname . ' - the customer was notified in Telegram.'];
    }
    return [true, $verb . ': ' . $bname . ' (saved, but the Telegram notice failed: ' . ($r['description'] ?? 'unknown') . ').'];
}

function hl_pending_count() {
    return hl_count("SELECT COUNT(*) FROM promotions WHERE status='pending_review'");
}
function hl_plan_name($key) {
    return HL_PACKAGES[$key]['name'] ?? ($key !== '' ? $key : '-');
}
function hl_money($n) {
    $s = number_format((float) $n, 2);
    $s = rtrim(rtrim($s, '0'), '.');
    return '$' . $s;
}
// Returns [pillClass, label] for a promotion status / payment_status.
function hl_status_meta($status) {
    switch ($status) {
        case 'approved': case 'verified': case 'posted': case 'paid':
            return ['ok', ucfirst($status)];
        case 'pending_review':
            return ['pend', 'Pending review'];
        case 'pending': case 'awaiting_verification': case 'unpaid':
            return ['pend', 'Pending'];
        case 'rejected':
            return ['rej', 'Rejected'];
        case 'draft':
            return ['mut', 'Draft'];
        default:
            return ['mut', $status !== '' && $status !== null ? ucfirst(str_replace('_', ' ', $status)) : '-'];
    }
}

// Handle an approve/reject POST on a page (dashboard or pending). Returns
// [message, type] to flash, or null if this request was not a moderation POST.
function hl_process_moderation() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;
    $act = $_POST['action'] ?? '';
    if ($act !== 'approve' && $act !== 'reject') return null;
    hl_csrf_check();
    $id = (int) ($_POST['promo_id'] ?? 0);
    list($ok, $msg) = hl_moderate_promo($id, $act);
    return [$msg, $ok ? 'ok' : 'err'];
}

// Show a secret as dots + last 4 chars, never the whole value.
function hl_secret_masked($plain) {
    $plain = (string) $plain;
    $n = strlen($plain);
    if ($n === 0) return '';
    if ($n <= 4) return str_repeat("\xE2\x80\xA2", $n);
    return str_repeat("\xE2\x80\xA2", 8) . substr($plain, -4);
}
