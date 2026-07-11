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
        header('Location: login.php');
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
