<?php
/**
 * player.php - the public per-screen PLAYOUT page (Option A playout).
 *
 * A physical screen (any smart-TV browser, a Raspberry Pi, an Android TV box in
 * kiosk mode) is pointed once at:
 *     https://habeshalist.com/.../player.php?s=<screen-slug>
 * and left running. The page then:
 *   - shows a full-viewport, black, rotating loop of the currently-live media;
 *   - RE-PULLS the playlist every 60s (as JSON) so newly-approved ads appear on
 *     the screen with no one touching the TV, and removed ads drop off;
 *   - plays videos MUTED (browsers block autoplay WITH sound - muted is the only
 *     reliable way to autoplay on a public screen), advancing when they end;
 *   - keeps showing the last good playlist if the network drops (resilience);
 *   - falls back to a branded idle card when nothing is booked.
 *
 * IMPORTANT: this endpoint is PUBLIC (the TV loads it with no login), so it is
 * deliberately kept OUTSIDE the admin bootstrap - it never loads lib.php (which
 * would force the admin HTTPS redirect + headers) and it only ever exposes
 * approved+paid media for one screen. No admin data, no listing of other
 * screens, and the screen is addressed by an unguessable slug.
 *
 * WHY THIS IS "GET pull", NOT a webhook: the TV FETCHES from us; nothing has to
 * reach IN to our server. That is why the whole feature runs fine on this host
 * even though inbound webhooks are blocked - see the note the module ships with.
 *
 * WHERE TO PUT THIS FILE: anywhere web-served on the site. It auto-detects the
 * bot database; if your layout is unusual, hard-set SCREENS_DB_PATH below.
 */

require __DIR__ . '/includes/screens.php';

// --- Locate the shared bot database (read-only use here) --------------------
if (!defined('SCREENS_DB_PATH')) {
    $cands = [
        getenv('BOT_DB_PATH') ?: null,
        __DIR__ . '/data/bot.sqlite',        // player sits in the bot folder
        __DIR__ . '/bot/data/bot.sqlite',    // player sits beside the bot folder
        __DIR__ . '/../bot/data/bot.sqlite',
        __DIR__ . '/../data/bot.sqlite',
    ];
    $found = '';
    foreach ($cands as $c) { if ($c && file_exists($c)) { $found = $c; break; } }
    define('SCREENS_DB_PATH', $found);
}

$slug = isset($_GET['s']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['s']) : '';
$wantJson = isset($_GET['json']);

// Open DB (best-effort; a dead DB should show the idle card, never a 500 on a TV)
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

// Timezone: reuse the schedule timezone the admin already configures.
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

$playlist = ($screen && ($screen['status'] ?? '') === 'active')
    ? hl_screen_playlist($db, $screen['id'], $today, (int) ($screen['dwell_seconds'] ?? 10))
    : [];

// Heartbeat: record that this screen pulled (proof-of-play foundation).
if ($screen && $db) {
    try { hl_screen_touch($db, $screen['id'], gmdate('Y-m-d H:i:s')); } catch (\Throwable $e) {}
}

// --- JSON transport the TV polls every 60s ----------------------------------
if ($wantJson) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'ok'       => (bool) $screen,
        'name'     => $screen['name'] ?? null,
        'playlist' => $playlist,
        'ts'       => $today,
    ]);
    exit;
}

// --- The kiosk page (rendered once; refreshes itself from the JSON above) ----
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$screenName = htmlspecialchars($screen['name'] ?? 'HabeshaList Screen', ENT_QUOTES, 'UTF-8');
$portrait   = ($screen['orientation'] ?? 'landscape') === 'portrait';
$bootData   = json_encode(['slug' => $slug, 'playlist' => $playlist], JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $screenName ?> - HabeshaList Screen</title>
<style>
  html,body{margin:0;height:100%;background:#000;overflow:hidden}
  #stage{position:fixed;inset:0;background:#000}
  #stage img,#stage video{position:absolute;inset:0;width:100%;height:100%;
    object-fit:<?= $portrait ? 'cover' : 'contain' ?>;opacity:0;transition:opacity .6s ease}
  #stage .on{opacity:1}
  #idle{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;
    justify-content:center;color:#e7edf3;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    background:radial-gradient(1200px 600px at 50% 40%,#16351f,#0b0f14 70%)}
  #idle .logo{font-size:6vmin;font-weight:800;letter-spacing:.5px}
  #idle .logo b{color:#3fb950}
  #idle .sub{margin-top:1.4vmin;font-size:2.4vmin;color:#93a1b3}
  #idle .dot{margin-top:4vmin;width:9px;height:9px;border-radius:50%;background:#3fb950;
    animation:pulse 1.6s infinite ease-in-out}
  @keyframes pulse{0%,100%{opacity:.25;transform:scale(.8)}50%{opacity:1;transform:scale(1.25)}}
</style>
</head>
<body>
<div id="stage"></div>
<div id="idle">
  <div class="logo">Habesha<b>List</b></div>
  <div class="sub"><?= $screenName ?></div>
  <div class="dot"></div>
</div>
<script>
(function () {
  var BOOT = <?= $bootData ?>;
  var stage = document.getElementById('stage');
  var idle  = document.getElementById('idle');
  var playlist = Array.isArray(BOOT.playlist) ? BOOT.playlist : [];
  var idx = -1, cur = null, timer = null;

  function showIdle(on){ idle.style.display = on ? 'flex' : 'none'; }

  function clearStage(){
    if (timer){ clearTimeout(timer); timer = null; }
    if (cur){ try{ if(cur.tagName==='VIDEO'){cur.pause();} }catch(e){} cur.remove(); cur = null; }
  }

  function advance(){
    if (!playlist.length){ clearStage(); showIdle(true); return; }
    showIdle(false);
    idx = (idx + 1) % playlist.length;
    var item = playlist[idx];
    var el;
    if (item.type === 'video'){
      el = document.createElement('video');
      el.src = item.path; el.muted = true; el.autoplay = true;
      el.playsInline = true; el.setAttribute('playsinline','');
      el.onended = advance;
      // If a video can't load/play, don't freeze the screen - skip it.
      el.onerror = function(){ setTimeout(advance, 500); };
    } else {
      el = document.createElement('img');
      el.src = item.path;
      el.onerror = function(){ setTimeout(advance, 500); };
    }
    stage.appendChild(el);
    // Fade in on next frame.
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('on'); }); });
    var prev = cur; cur = el;
    if (prev){ setTimeout(function(){ prev.remove(); }, 700); }
    if (item.type !== 'video'){
      var secs = (item.dwell && item.dwell > 0) ? item.dwell : 10;
      timer = setTimeout(advance, secs * 1000);
    }
    if (el.tagName === 'VIDEO'){ var p = el.play(); if (p && p.catch) p.catch(function(){ setTimeout(advance, 500); }); }
  }

  // Re-pull the playlist every 60s so approvals/removals reflect on the screen.
  function refresh(){
    fetch('player.php?json=1&s=' + encodeURIComponent(BOOT.slug), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok || !Array.isArray(d.playlist)) return;      // keep last good on bad data
        var a = JSON.stringify(d.playlist), b = JSON.stringify(playlist);
        if (a !== b){                                               // only restart the loop if it changed
          playlist = d.playlist; idx = -1; clearStage(); advance();
        }
      })
      .catch(function(){ /* network blip: keep showing what we have */ });
  }

  if (playlist.length) advance(); else showIdle(true);
  setInterval(refresh, 60000);
})();
</script>
</body>
</html>
