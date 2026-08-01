<?php
/**
 * player.php - the public per-screen PLAYOUT page (Option A playout).
 *
 * A physical touchscreen (portrait, mounted at a community location) is pointed
 * once at:
 *     https://habeshalist.com/.../player.php?s=<screen-slug>
 * and left running full-screen. It runs in TWO modes and switches between them:
 *
 *   ATTRACT MODE (passive) - a rotating loop of the currently-live business ads
 *     for this screen. Images show for their dwell time; videos play MUTED
 *     (the only way browsers allow autoplay on an unattended screen). Re-pulls
 *     the playlist every 60s so approvals/removals reflect with no one touching
 *     the TV. This is what plays when nobody is interacting.
 *
 *   INTERACTIVE MODE (touch) - the screen opens a live, touch-browsable view of
 *     the HabeshaList website (local posts, events, business listings, videos
 *     WITH sound). It opens automatically every `attract_seconds`, or instantly
 *     when a passer-by taps the screen, and it returns to the ad loop after
 *     `idle_return_seconds` with no touch (a hard cap also protects against a
 *     screen being left on one page).
 *
 * The website view is embedded in an iframe, so it MUST be served from the SAME
 * domain as the site (SAMEORIGIN) - which is why we host player.php on
 * habeshalist.com. Same-origin also lets us detect touches inside the site to
 * drive the idle timer.
 *
 * This endpoint is PUBLIC (loaded with no login) and deliberately stays OUTSIDE
 * the admin bootstrap: it never loads lib.php, it only ever exposes approved +
 * paid media for one screen, and the screen is addressed by an unguessable slug.
 *
 * WHY "GET pull", not a webhook: the TV FETCHES from us; nothing reaches IN, so
 * the whole feature runs fine even though inbound webhooks are blocked here.
 */

// Locate the screens module regardless of where player.php is dropped (inside the
// bot folder, in the web root with the bot in a subfolder, or beside the bot folder).
$__pl_lib = '';
foreach ([
    __DIR__ . '/includes/screens.php',        // player.php is inside the bot folder
    __DIR__ . '/bot/includes/screens.php',    // player.php in web root, bot in /bot
    __DIR__ . '/../bot/includes/screens.php',  // player.php beside the bot folder
    __DIR__ . '/../includes/screens.php',
] as $__c) {
    if (is_file($__c)) { $__pl_lib = $__c; break; }
}
if ($__pl_lib === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Screen player not fully installed: includes/screens.php was not found. '
       . 'Place player.php inside the bot folder (next to includes/ and data/).');
}
require $__pl_lib;

// The bot folder that owns the module (…/bot/includes/screens.php → …/bot). The
// admin writes house-ad uploads under <bot>/uploads/screens/. player.php may be
// dropped somewhere the browser cannot reach that folder by a relative path, so
// we SERVE uploaded media through player.php itself (it resolves the bot folder
// independently). This makes the image/video work no matter where player.php lives.
$__pl_botroot = dirname(dirname($__pl_lib));

// --- Media proxy: /player.php?media=<file> streams an uploaded house-ad file --
if (isset($_GET['media'])) {
    $name = basename((string) $_GET['media']);            // strip any path components
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = array_merge(HL_SCREEN_IMAGE_EXT, HL_SCREEN_VIDEO_EXT);
    $file = $__pl_botroot . '/uploads/screens/' . $name;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || !in_array($ext, $allowed, true) || !is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=3600');
    header('Accept-Ranges: bytes');
    readfile($file);
    exit;
}

/**
 * Rewrite a playlist so locally-uploaded files are fetched THROUGH this player
 * (player.php?media=<file>), which always resolves them correctly; absolute
 * http(s) URLs are left untouched.
 */
function hl_player_public_playlist(array $playlist) {
    $self = basename(__FILE__); // relative to this page's URL, e.g. "player.php"
    foreach ($playlist as &$item) {
        $p = (string) ($item['path'] ?? '');
        if ($p !== '' && strpos($p, 'uploads/screens/') === 0 && !preg_match('#^https?://#i', $p)) {
            $item['path'] = $self . '?media=' . rawurlencode(basename($p));
        }
    }
    unset($item);
    return $playlist;
}

// --- Locate the shared bot database (read-only use here) --------------------
if (!defined('SCREENS_DB_PATH')) {
    $cands = [
        getenv('BOT_DB_PATH') ?: null,
        __DIR__ . '/data/bot.sqlite',
        __DIR__ . '/bot/data/bot.sqlite',
        __DIR__ . '/../bot/data/bot.sqlite',
        __DIR__ . '/../data/bot.sqlite',
    ];
    $found = '';
    foreach ($cands as $c) { if ($c && file_exists($c)) { $found = $c; break; } }
    define('SCREENS_DB_PATH', $found);
}

$slug = isset($_GET['s']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['s']) : '';
$wantJson = isset($_GET['json']);

$db = null;
if (SCREENS_DB_PATH && file_exists(SCREENS_DB_PATH)) {
    try {
        $db = new SQLite3(SCREENS_DB_PATH, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(4000);
        $db->exec('PRAGMA journal_mode=WAL');
        hl_screens_ensure_schema($db);
    } catch (\Throwable $e) { $db = null; }
}

$screen = ($db && $slug !== '') ? hl_screen_by_slug($db, $slug) : null;

$tz = 'America/New_York';
if ($db) {
    $st = $db->prepare("SELECT value FROM settings WHERE key='sched_tz'");
    $r = $st ? $st->execute() : null;
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
    if ($row && !empty($row['value'])) $tz = $row['value'];
}
try { $now = new DateTime('now', new DateTimeZone($tz)); }
catch (\Throwable $e) { $now = new DateTime('now'); }
$today = $now->format('Y-m-d');

// --- Single-ad PREVIEW: player.php?s=<slug>&ad=<bookingId> ------------------
// Shows ONE booking's media exactly as it will appear on this screen (same
// portrait framing and rotation), regardless of its status or booked dates. The
// screen's unguessable slug is the key and the ad must belong to that screen, so
// the link is safe to hand a customer. This powers the "here's your ad preview"
// link the customer receives the moment their ad is approved.
$previewAdId  = isset($_GET['ad']) ? (int) $_GET['ad'] : 0;
$previewMode  = false;
$previewName  = '';
if ($previewAdId && $screen && $db && function_exists('hl_screen_booking_by_id')) {
    $previewMode = true;
    $pb = hl_screen_booking_by_id($db, $previewAdId);
    $pMedia = ($pb && (int) ($pb['screen_id'] ?? 0) === (int) $screen['id'])
        ? (json_decode((string) $pb['media'], true) ?: [])
        : [];
    $previewName = $pb['business_name'] ?? '';
    $playlist = [];
    foreach ($pMedia as $m) {
        if (empty($m['path'])) continue;
        $playlist[] = [
            'path'  => (string) $m['path'],
            'type'  => in_array(($m['type'] ?? ''), ['image', 'video'], true) ? $m['type'] : hl_screen_media_type($m['path']),
            'dwell' => (int) ($m['dwell'] ?? 10) ?: 10,
        ];
    }
    $playlist = hl_player_public_playlist($playlist);
} else {
    $playlist = ($screen && ($screen['status'] ?? '') === 'active')
        ? hl_screen_playlist($db, $screen['id'], $today, (int) ($screen['dwell_seconds'] ?? 10))
        : [];
    $playlist = hl_player_public_playlist($playlist);
}

// Only the real, unattended TV should register a heartbeat - never a preview.
if ($screen && $db && !$previewMode) {
    try { hl_screen_touch($db, $screen['id'], gmdate('Y-m-d H:i:s')); } catch (\Throwable $e) {}
}

// --- Self-check: /player.php?s=<slug>&diag=1 prints why a screen is/ isn't playing
if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $lines = [];
    $lines[] = 'HabeshaList screen player - diagnostics';
    $lines[] = 'db file        : ' . (SCREENS_DB_PATH ?: '(none found)');
    $lines[] = 'db opened      : ' . ($db ? 'yes' : 'NO');
    $lines[] = 'slug           : ' . ($slug !== '' ? $slug : '(none in URL - add ?s=<slug>)');
    $lines[] = 'screen found   : ' . ($screen ? ('yes - "' . ($screen['name'] ?? '') . '"') : 'NO');
    if ($screen) {
        $lines[] = 'screen status  : ' . ($screen['status'] ?? '');
        $lines[] = 'timezone/today : ' . $tz . ' / ' . $today;
    }
    $lines[] = 'playlist items : ' . count($playlist);
    foreach ($playlist as $i => $it) {
        $lines[] = sprintf('  [%d] type=%s url=%s', $i, $it['type'] ?? '?', $it['path'] ?? '');
    }
    $lines[] = 'uploads dir    : ' . $__pl_botroot . '/uploads/screens';
    $lines[] = 'uploads writable: ' . (is_dir($__pl_botroot . '/uploads/screens') ? (is_writable($__pl_botroot . '/uploads/screens') ? 'yes' : 'exists (not writable)') : 'MISSING');

    // --- Booking availability (why the bot may say "already booked") ----------
    if ($screen && $db && function_exists('hl_screen_is_available') && function_exists('hl_screen_bookings')) {
        $t0  = $today;
        $t30 = date('Y-m-d', strtotime($today . ' +30 day'));
        $availFix = strpos((string) @file_get_contents(__DIR__ . '/includes/screens.php'), "COALESCE(payment_ref") !== false;
        $lines[] = '';
        $lines[] = 'house-ad fix in includes/screens.php : ' . ($availFix ? 'YES (new code on disk)' : 'NO (old file - re-upload it)');
        $lines[] = 'bookable today (' . $t0 . ')  : ' . (hl_screen_is_available($db, $screen['id'], $t0, $t0) ? 'YES' : 'no - blocked');
        $lines[] = 'bookable +30d  (' . $t30 . ')  : ' . (hl_screen_is_available($db, $screen['id'], $t30, $t30) ? 'YES' : 'no - blocked');
        $lines[] = 'bookings on this screen:';
        $bk = hl_screen_bookings($db, $screen['id']);
        if (!$bk) $lines[] = '  (none)';
        foreach ($bk as $b) {
            $lines[] = sprintf('  #%s  %s / pay=%s / ref=%s  %s -> %s',
                $b['id'] ?? '?', $b['status'] ?? '?', $b['payment_status'] ?? '?',
                ($b['payment_ref'] ?? '') === '' ? '(none)' : $b['payment_ref'],
                $b['start_date'] ?? '?', $b['end_date'] ?? '?');
        }
        $lines[] = 'NOTE: the bot loads this file once per ~14-min poll cycle. If the';
        $lines[] = 'fix shows YES here but the bot still says booked, wait for the next';
        $lines[] = 'cron cycle (~15 min) for the poller to reload it.';
    }

    echo implode("\n", $lines) . "\n";
    exit;
}

// --- JSON transport the TV polls every 60s (attract-mode playlist) -----------
if ($wantJson) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'ok'       => (bool) $screen,
        'name'     => $screen['name'] ?? null,
        'playlist' => $playlist,
        'paused'   => $screen ? (($screen['status'] ?? '') === 'paused') : false,
        'ts'       => $today,
    ]);
    exit;
}

// --- Kiosk config ------------------------------------------------------------
$host         = $_SERVER['HTTP_HOST'] ?? 'habeshalist.com';
$defaultSite  = 'https://' . $host . '/';
// In preview mode nothing but the ad should ever show: no tap-to-website, no
// pause redirect, no kiosk lock. The customer just watches their own ad loop.
$interactive  = ($screen && !$previewMode) ? !empty($screen['interactive']) : false;
// A website URL is always resolvable now (blank -> the site itself) so PAUSE mode
// can show it full-screen instead of a screensaver.
$siteUrl      = $screen ? trim($screen['website_url'] ?? '') : '';
if ($siteUrl === '') $siteUrl = $defaultSite;
$paused       = ($screen && !$previewMode) ? (($screen['status'] ?? '') === 'paused') : false;
$kioskLock    = ($screen && !$previewMode) ? !empty($screen['kiosk_lock']) : false;
$adAudio      = $screen ? !empty($screen['ad_audio']) : false;
$attractSecs  = $screen ? max(15, (int) ($screen['attract_seconds'] ?? 180)) : 180;
$idleSecs     = $screen ? max(10, (int) ($screen['idle_return_seconds'] ?? 60)) : 60;
$portrait     = ($screen['orientation'] ?? 'portrait') === 'portrait';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$screenName = htmlspecialchars($screen['name'] ?? 'HabeshaList Screen', ENT_QUOTES, 'UTF-8');
$boot = json_encode([
    'slug'        => $slug,
    'playlist'    => $playlist,
    'interactive' => $interactive,
    'websiteUrl'  => $siteUrl,
    'paused'      => $paused,       // when true: show the website full-screen, no ad loop
    'kioskLock'   => $kioskLock,    // lock full-screen + block the obvious exits
    'adAudio'     => $adAudio,      // play video ads with sound when the device allows
    'attractMs'   => $attractSecs * 1000,
    'idleMs'      => $idleSecs * 1000,
    'preview'     => $previewMode,  // one-ad preview: loop it, don't poll for the live playlist
], JSON_UNESCAPED_SLASHES);
$previewLabel = $previewMode
    ? htmlspecialchars(trim($previewName) !== '' ? $previewName : 'Your ad', ENT_QUOTES, 'UTF-8')
    : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<title><?= $screenName ?> - HabeshaList Screen</title>
<style>
  html,body{margin:0;height:100%;background:#000;overflow:hidden;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
  #stage{position:fixed;inset:0;background:#000}
  #stage img,#stage video{position:absolute;inset:0;width:100%;height:100%;
    object-fit:<?= $portrait ? 'cover' : 'contain' ?>;opacity:0;transition:opacity .6s ease}
  #stage .on{opacity:1}
  #idle{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;
    justify-content:center;color:#e7edf3;
    background:radial-gradient(1200px 600px at 50% 40%,#16351f,#0b0f14 70%)}
  #idle .logo{font-size:6vmin;font-weight:800;letter-spacing:.5px}
  #idle .logo b{color:#3fb950}
  #idle .sub{margin-top:1.4vmin;font-size:2.4vmin;color:#93a1b3}
  #idle .dot{margin-top:4vmin;width:9px;height:9px;border-radius:50%;background:#3fb950;
    animation:pulse 1.6s infinite ease-in-out}
  @keyframes pulse{0%,100%{opacity:.25;transform:scale(.8)}50%{opacity:1;transform:scale(1.25)}}
  /* "Tap to explore" hint shown over the ad loop on interactive screens */
  #hint{position:fixed;left:0;right:0;bottom:0;padding:2.6vmin 2vmin;text-align:center;
    color:#fff;font-size:2.6vmin;font-weight:700;letter-spacing:.3px;
    background:linear-gradient(0deg,rgba(0,0,0,.72),rgba(0,0,0,0));display:none}
  #hint .pill{display:inline-block;background:#238636;border-radius:40px;padding:1.4vmin 3.2vmin;
    animation:bob 2.4s infinite ease-in-out}
  @keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
  /* Interactive website mode */
  #site{position:fixed;inset:0;background:#fff;display:none}
  #frame{width:100%;height:100%;border:0}
  #home{position:fixed;right:3vmin;bottom:3vmin;z-index:10;background:#238636;color:#fff;
    border:0;border-radius:40px;padding:2vmin 3.4vmin;font-size:2.6vmin;font-weight:700;
    box-shadow:0 6px 24px rgba(0,0,0,.4);display:none}
  #bar{position:fixed;top:0;left:0;right:0;z-index:10;display:none;align-items:center;gap:2vmin;
    padding:1.4vmin 2.4vmin;background:#0b0f14;color:#e7edf3;font-weight:700;font-size:2.4vmin}
  #bar b{color:#3fb950}
  #bar .back{margin-left:auto;background:#238636;border:0;color:#fff;border-radius:30px;
    padding:1.2vmin 3vmin;font-size:2.4vmin;font-weight:700}
  /* Preview banner - only shown when a single ad is opened via ?ad= */
  #pvw{position:fixed;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;
    justify-content:center;gap:1.2vmin;padding:1.4vmin 2vmin;text-align:center;
    color:#fff;font-size:2.2vmin;font-weight:700;letter-spacing:.3px;
    background:linear-gradient(180deg,rgba(0,0,0,.75),rgba(0,0,0,0))}
  #pvw .tag{background:#238636;border-radius:30px;padding:.8vmin 2.4vmin}
</style>
</head>
<body>
<div id="stage"></div>
<?php if ($previewMode): ?>
<div id="pvw"><span class="tag">&#128064; Preview</span><span>This is how <?= $previewLabel ?> will appear on the screen</span></div>
<?php endif; ?>
<div id="idle">
  <div class="logo">Habesha<b>List</b></div>
  <div class="sub"><?= $screenName ?></div>
  <div class="dot"></div>
</div>
<div id="hint"><span class="pill">&#128075; Tap the screen to explore HabeshaList</span></div>

<div id="site">
  <div id="bar">Habesha<b>List</b>&nbsp;Community
    <button class="back" id="back">Back to ads</button>
  </div>
  <iframe id="frame" allow="autoplay; fullscreen; encrypted-media" referrerpolicy="no-referrer-when-downgrade"></iframe>
</div>

<script>
(function () {
  var BOOT = <?= $boot ?>;
  var stage = document.getElementById('stage');
  var idle  = document.getElementById('idle');
  var hint  = document.getElementById('hint');
  var site  = document.getElementById('site');
  var bar   = document.getElementById('bar');
  var frame = document.getElementById('frame');

  var playlist = Array.isArray(BOOT.playlist) ? BOOT.playlist : [];
  var interactive = !!BOOT.interactive;
  var preview = !!BOOT.preview;     // single-ad preview: loop this ad only, no polling
  var paused = !!BOOT.paused;       // paused screen: show the website, no ad loop
  var mode = 'ads';                 // 'ads' | 'site'
  var idx = -1, cur = null, adTimer = null, attractTimer = null;
  var idleTimer = null, capTimer = null;
  var MAX_SITE_MS = Math.max(BOOT.idleMs * 4, 120000); // hard cap so a screen never sticks on the site

  function showIdle(on){ idle.style.display = on ? 'flex' : 'none'; }
  function showHint(on){ hint.style.display = (on && interactive) ? 'block' : 'none'; }

  function clearAd(){
    if (adTimer){ clearTimeout(adTimer); adTimer = null; }
    if (cur){ try{ if(cur.tagName==='VIDEO') cur.pause(); }catch(e){} cur.remove(); cur = null; }
  }

  function advance(){
    if (mode !== 'ads') return;
    if (!playlist.length){ clearAd(); showIdle(true); showHint(interactive); return; }
    showIdle(false); showHint(interactive);
    idx = (idx + 1) % playlist.length;
    var item = playlist[idx], el;
    if (item.type === 'video'){
      el = document.createElement('video');
      el.src = item.path;
      // Play with sound when the screen allows it; browsers that block
      // autoplay-with-audio fall back to muted so the ad still plays.
      el.muted = !BOOT.adAudio; el.autoplay = true;
      el.playsInline = true; el.setAttribute('playsinline','');
      el.onended = advance;
      el.onerror = function(){ setTimeout(advance, 500); };
    } else {
      el = document.createElement('img');
      el.src = item.path;
      el.onerror = function(){ setTimeout(advance, 500); };
    }
    stage.appendChild(el);
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('on'); }); });
    var prev = cur; cur = el;
    if (prev){ setTimeout(function(){ prev.remove(); }, 700); }
    if (item.type !== 'video'){
      var secs = (item.dwell && item.dwell > 0) ? item.dwell : 10;
      adTimer = setTimeout(advance, secs * 1000);
    } else {
      var p = el.play();
      if (p && p.catch) p.catch(function(){
        // Retry muted once (audio autoplay may be blocked); then skip on failure.
        if (!el.muted){ el.muted = true; var p2 = el.play(); if (p2 && p2.catch) p2.catch(function(){ setTimeout(advance, 500); }); }
        else { setTimeout(advance, 500); }
      });
    }
  }

  function startAttractTimer(){
    if (attractTimer) clearTimeout(attractTimer);
    if (interactive) attractTimer = setTimeout(goSite, BOOT.attractMs);
  }

  // --- switch to the interactive HabeshaList website ---
  function goSite(){
    if (!interactive || mode === 'site') return;
    mode = 'site';
    clearAd(); if (attractTimer){ clearTimeout(attractTimer); attractTimer = null; }
    showIdle(false); showHint(false);
    frame.src = BOOT.websiteUrl;
    site.style.display = 'block';
    bar.style.display = 'flex';
    resetIdle();
    if (capTimer) clearTimeout(capTimer);
    capTimer = setTimeout(goAds, MAX_SITE_MS);
    attachActivity();
  }

  // --- return to the passive ad loop ---
  function goAds(){
    if (paused) return;             // a paused screen stays on the website
    if (mode === 'ads') return;
    mode = 'ads';
    if (idleTimer){ clearTimeout(idleTimer); idleTimer = null; }
    if (capTimer){ clearTimeout(capTimer); capTimer = null; }
    site.style.display = 'none';
    bar.style.display = 'none';
    frame.src = 'about:blank';       // stop any audio/video in the site
    idx = -1;
    advance();
    startAttractTimer();
  }

  function resetIdle(){
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(goAds, BOOT.idleMs);
  }

  // Detect touches INSIDE the same-origin site iframe so active browsing keeps
  // the session alive; falls back silently to the hard cap if cross-origin.
  function attachActivity(){
    frame.onload = function(){
      try {
        var w = frame.contentWindow, d = w.document;
        ['pointerdown','touchstart','click','scroll','keydown'].forEach(function(ev){
          d.addEventListener(ev, resetIdle, {passive:true, capture:true});
        });
      } catch(e){ /* cross-origin: rely on the hard cap + on-chrome taps */ }
    };
  }

  // --- PAUSE mode: show the website full-screen instead of a screensaver ---
  function showPausedSite(){
    clearAd();
    if (attractTimer){ clearTimeout(attractTimer); attractTimer = null; }
    showIdle(false); showHint(false);
    mode = 'site';
    frame.src = BOOT.websiteUrl;
    site.style.display = 'block';
    bar.style.display = 'none';        // no "back to ads" - the screen is paused
  }

  // --- Kiosk lock: full-screen + block the obvious ways out (best effort). A
  // truly tamper-proof lock still needs the DEVICE set to kiosk mode. ---
  function kioskLock(){
    if (!BOOT.kioskLock) return;
    document.addEventListener('contextmenu', function(e){ e.preventDefault(); });
    document.addEventListener('selectstart', function(e){ e.preventDefault(); });
    document.addEventListener('dragstart',   function(e){ e.preventDefault(); });
    document.addEventListener('gesturestart',function(e){ e.preventDefault(); });
    document.body.style.userSelect = 'none';
    document.body.style.webkitUserSelect = 'none';
    document.body.style.touchAction = 'manipulation';
    window.addEventListener('beforeunload', function(e){ e.preventDefault(); e.returnValue = ''; });
    var goFs = function(){
      var el = document.documentElement;
      if (el.requestFullscreen) { el.requestFullscreen().catch(function(){}); }
      else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
      document.removeEventListener('pointerdown', goFs);
      document.removeEventListener('touchstart', goFs);
    };
    document.addEventListener('pointerdown', goFs, {passive:true});
    document.addEventListener('touchstart', goFs, {passive:true});
  }

  // Re-pull every 20s: refresh the playlist, and reload if the screen was
  // paused/unpaused from the admin (so the display switches with no one at the TV).
  function refresh(){
    fetch('player.php?json=1&s=' + encodeURIComponent(BOOT.slug), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        if (!!d.paused !== paused){ location.reload(); return; }
        if (!Array.isArray(d.playlist)) return;
        var a = JSON.stringify(d.playlist), b = JSON.stringify(playlist);
        if (a !== b){ playlist = d.playlist; if (mode === 'ads'){ idx = -1; clearAd(); advance(); } }
      })
      .catch(function(){});
  }

  // Boot
  kioskLock();
  if (preview){
    // Just loop this one ad. No tap-to-site, no attract switch, no polling.
    if (playlist.length){ advance(); }
    else {
      showIdle(true);
      var sub = idle.querySelector('.sub');
      if (sub) sub.textContent = 'Preview not available';
    }
  } else if (paused){
    showPausedSite();
  } else {
    // Tap anywhere on the ad loop opens the interactive site immediately.
    ['pointerdown','touchstart'].forEach(function(ev){
      document.addEventListener(ev, function(){
        if (mode === 'ads') goSite(); else resetIdle();
      }, {passive:true});
    });
    document.getElementById('back').addEventListener('click', function(e){ e.stopPropagation(); goAds(); });
    bar.addEventListener('pointerdown', resetIdle, {passive:true});
    if (playlist.length) advance(); else { showIdle(true); showHint(interactive); }
    startAttractTimer();
  }
  if (!preview) setInterval(refresh, 20000);
})();
</script>
</body>
</html>
