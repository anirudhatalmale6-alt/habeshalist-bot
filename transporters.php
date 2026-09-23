<?php
/**
 * transporters.php - the PUBLIC HabeshaList Verified Transporter directory
 * (spec section 6).
 *
 *   /transporters            -> the directory of approved, active transporters
 *   /transporters/{username} -> one transporter's public profile
 *
 * Drop this file in the website root. It works with or without pretty URLs:
 *   transporters.php                 (directory)
 *   transporters.php/AbebeTravel     (profile, PATH_INFO)
 *   transporters.php?u=AbebeTravel   (profile, query string - always works)
 * To get the pretty /transporters URLs, add the rewrite rules printed in
 * docs/TRANSPORTERS.md to the site's .htaccess.
 *
 * SAME DATA SOURCE AS THE BOT: it reads the bot's own SQLite database directly,
 * so a new rating recalculates the average here the moment it is saved in
 * Telegram - there is no sync job to fall behind.
 *
 * PRIVACY: this page renders ONLY hl_tr_public(), which deliberately drops the
 * Telegram user id, the internal transporter id, admin notes, the phone number
 * and every payment field. A suspended transporter is not in the directory at
 * all, and their profile URL returns "not available" rather than their details.
 *
 * It deliberately stays OUTSIDE the admin bootstrap: no lib.php, no session, no
 * login - it is a public page and opens the database READ-ONLY.
 */

// --- Locate the transporter module wherever this file was dropped -----------
$__tr_lib = '';
foreach ([
    __DIR__ . '/includes/transporters.php',        // inside the bot folder
    __DIR__ . '/bot/includes/transporters.php',    // web root, bot in /bot
    __DIR__ . '/../bot/includes/transporters.php', // beside the bot folder
    __DIR__ . '/../includes/transporters.php',
] as $__c) {
    if (is_file($__c)) { $__tr_lib = $__c; break; }
}
if ($__tr_lib === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Transporter directory not fully installed: includes/transporters.php was not found. '
       . 'Upload it into the bot folder (next to includes/ and data/).');
}
require $__tr_lib;

// The bot folder that owns the module - profile photos live under its uploads/.
$__tr_botroot = dirname(dirname($__tr_lib));

// --- Locate the shared bot database (read-only) -----------------------------
$__tr_db_path = '';
foreach ([
    getenv('BOT_DB_PATH') ?: null,
    $__tr_botroot . '/data/bot.sqlite',
    __DIR__ . '/data/bot.sqlite',
    __DIR__ . '/bot/data/bot.sqlite',
    __DIR__ . '/../bot/data/bot.sqlite',
] as $__c) {
    if ($__c && file_exists($__c)) { $__tr_db_path = $__c; break; }
}

$db = null;
if ($__tr_db_path !== '') {
    try {
        $db = new SQLite3($__tr_db_path, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(3000);
    } catch (\Throwable $e) { $db = null; }
}

/** One setting, read straight from the bot's settings table (read-only safe). */
function tr_site_setting(SQLite3 $db = null, $key = '', $default = '') {
    if (!$db) return $default;
    try {
        $st = $db->prepare('SELECT value FROM settings WHERE key = :k');
        $st->bindValue(':k', $key, SQLITE3_TEXT);
        $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
        return ($r && $r['value'] !== '') ? $r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

$SITE     = rtrim((string) tr_site_setting($db, 'tr_site_url', 'https://habeshalist.com'), '/');
$BOT_USER = (string) tr_site_setting($db, 'bot_username', 'HabeshaListBot');
$BOT_LINK = 'https://t.me/' . ltrim($BOT_USER, '@');

// --- Photo proxy: serve a profile photo from the bot's uploads folder -------
// The bot writes photos under <bot>/uploads/transporters/. This file may sit
// somewhere the browser cannot reach that folder by a relative path, so we serve
// it through this page, which resolves the bot folder independently.
if (isset($_GET['photo'])) {
    $name = basename((string) $_GET['photo']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $file = $__tr_botroot . '/uploads/transporters/' . $name;
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || !isset($types[$ext]) || !is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

/** Public URL for a transporter photo, or '' when they have none. */
function tr_photo_url($relPath) {
    $relPath = trim((string) $relPath);
    if ($relPath === '') return '';
    return '?photo=' . rawurlencode(basename($relPath));
}

/** Which transporter is being asked for: PATH_INFO or ?u= (both supported). */
$wanted = '';
if (!empty($_SERVER['PATH_INFO'])) $wanted = trim($_SERVER['PATH_INFO'], '/');
if ($wanted === '' && isset($_GET['u'])) $wanted = (string) $_GET['u'];
$wanted = hl_tr_normalize_username($wanted);

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$profile = null;
$list    = [];
if ($db) {
    try {
        hl_tr_migrate($db);   // read-only: a no-op unless the panel already made the columns
    } catch (\Throwable $e) { /* read-only handle - schema is the panel's job */ }

    if ($wanted !== '') {
        $row = hl_tr_by_username($db, $wanted);
        // A suspended / pending / rejected transporter has no public profile.
        if ($row && ($row['verification_status'] ?? '') === 'approved') {
            $profile = ['row' => $row, 'pub' => hl_tr_public($row, $db), 'id' => (int) $row['id']];
        }
    } else {
        $all = hl_tr_directory($db, 300);
        foreach ($all as $row) {
            $pub = hl_tr_public($row, $db);
            if ($q !== '') {
                $hay = strtolower($pub['username'] . ' ' . $pub['display_name'] . ' ' . $pub['origin'] . ' ' . $pub['destination']);
                if (strpos($hay, strtolower($q)) === false) continue;
            }
            $list[] = $pub;
        }
    }
}

if ($wanted !== '' && !$profile) http_response_code(404);

$pageTitle = $profile
    ? '@' . $profile['pub']['username'] . ' - Verified Transporter | HabeshaList'
    : 'Verified Transporters | HabeshaList';
$pageDesc = $profile
    ? 'Verified HabeshaList transporter @' . $profile['pub']['username'] . ' - ' . $profile['pub']['route']
    : 'Find a Verified Transporter on HabeshaList - approved community members travelling with available luggage space.';

/** Star row as markup (filled + empty). */
function tr_star_html($avg) {
    $n = (int) round((float) $avg);
    $n = max(0, min(5, $n));
    return '<span class="stars">' . str_repeat('&#9733;', $n) . '<span class="dim">' . str_repeat('&#9733;', 5 - $n) . '</span></span>';
}
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<style>
  :root{
    --bg:#f5f7fa; --card:#fff; --line:#e4e9f0; --text:#16202b; --muted:#66748a;
    --accent:#2ea043; --accent2:#1f7a33; --star:#f5a623; --chip:#eef2f7; --shadow:0 1px 2px rgba(16,24,40,.05);
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0f1419; --card:#161b22; --line:#252d38; --text:#e7edf3; --muted:#93a1b3;
           --accent:#3fb950; --accent2:#2ea043; --chip:#1d2530; --shadow:none; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
       font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  a{color:inherit}
  .wrap{max-width:1040px;margin:0 auto;padding:22px 18px 60px}
  header.hero{padding:34px 0 8px}
  header.hero h1{margin:0 0 8px;font-size:30px;letter-spacing:-.4px}
  header.hero p{margin:0;color:var(--muted);max-width:660px}
  .crumb{font-size:13.5px;color:var(--muted);margin-bottom:14px}
  .crumb a{text-decoration:none;color:var(--accent2)}
  .searchbar{display:flex;gap:10px;margin:22px 0 26px;flex-wrap:wrap}
  .searchbar input{flex:1;min-width:220px;padding:12px 14px;border:1px solid var(--line);
    border-radius:10px;background:var(--card);color:var(--text);font-size:15px}
  .searchbar button{padding:12px 20px;border:0;border-radius:10px;background:var(--accent2);
    color:#fff;font-weight:600;font-size:15px;cursor:pointer}
  .searchbar button:hover{background:var(--accent)}
  .grid{display:grid;gap:16px;grid-template-columns:1fr}
  @media(min-width:640px){.grid{grid-template-columns:1fr 1fr}}
  @media(min-width:960px){.grid{grid-template-columns:1fr 1fr 1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:var(--shadow)}
  .tcard{display:flex;flex-direction:column;gap:12px}
  .tcard .top{display:flex;gap:13px;align-items:center}
  .avatar{width:58px;height:58px;border-radius:50%;object-fit:cover;background:var(--chip);flex:0 0 58px;
    display:flex;align-items:center;justify-content:center;font-size:24px;color:var(--muted)}
  .uname{font-weight:700;font-size:17px;line-height:1.2;word-break:break-word}
  .badge{display:inline-flex;align-items:center;gap:5px;background:var(--accent);color:#fff;
    font-size:11px;font-weight:700;border-radius:20px;padding:3px 10px;letter-spacing:.2px;
    text-transform:uppercase;margin-top:5px}
  .route{font-size:14.5px;color:var(--muted)}
  .route b{color:var(--text);font-weight:600}
  .stars{color:var(--star);letter-spacing:1px}
  .stars .dim{opacity:.26}
  .ratingline{font-size:13.5px;color:var(--muted);display:flex;align-items:center;gap:7px;flex-wrap:wrap}
  .btn{display:inline-block;text-align:center;padding:10px 16px;border-radius:10px;background:var(--accent2);
    color:#fff;text-decoration:none;font-weight:600;font-size:14.5px}
  .btn:hover{background:var(--accent)}
  .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}
  .btn.ghost:hover{background:var(--chip)}
  .empty{background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:46px 22px;
    text-align:center;color:var(--muted)}
  /* Profile page */
  .profile{display:grid;gap:20px;grid-template-columns:1fr}
  @media(min-width:860px){.profile{grid-template-columns:340px 1fr}}
  .phero{display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px}
  .phero .avatar{width:132px;height:132px;flex:0 0 132px;font-size:52px}
  .phero .uname{font-size:23px}
  .kv{width:100%;border-collapse:collapse;font-size:15px}
  .kv td{padding:11px 0;border-bottom:1px solid var(--line);vertical-align:top}
  .kv td:first-child{color:var(--muted);width:44%;padding-right:14px}
  .kv tr:last-child td{border-bottom:0}
  .avail{background:var(--chip);border-radius:12px;padding:15px 16px;font-size:15px}
  .avail .lbl{font-size:11.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;
    color:var(--muted);margin-bottom:7px}
  .rev{border-bottom:1px solid var(--line);padding:13px 0}
  .rev:last-child{border-bottom:0}
  .rev .meta{font-size:12.5px;color:var(--muted);margin-top:3px}
  .notice{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--accent);
    border-radius:12px;padding:16px 18px;margin-top:26px;font-size:14px;color:var(--muted)}
  .notice b{color:var(--text)}
  .notice ul{margin:9px 0 0;padding-left:20px}
  .notice li{margin-bottom:5px}
  h2.sec{font-size:18px;margin:0 0 14px}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$db): ?>
  <header class="hero"><h1>Verified Transporters</h1></header>
  <div class="empty">This directory is temporarily unavailable. Please try again shortly.</div>

<?php elseif ($wanted !== '' && !$profile): ?>
  <div class="crumb"><a href="?">&larr; All Verified Transporters</a></div>
  <header class="hero"><h1>Profile not available</h1>
    <p>We couldn't find an active Verified Transporter with the username
       <b>@<?= e($wanted) ?></b>. They may have changed their username, or their profile may not be active right now.</p>
  </header>
  <p style="margin-top:20px"><a class="btn" href="?">Browse all transporters</a></p>

<?php elseif ($profile):
    $p   = $profile['pub'];
    $row = $profile['row'];
    $photo = tr_photo_url($p['photo_path']);
    $reviews = hl_tr_ratings($db, $profile['id'], 8);
?>
  <div class="crumb"><a href="?">Verified Transporters</a> &rsaquo; @<?= e($p['username']) ?></div>

  <div class="profile">
    <div class="card phero">
      <?php if ($photo !== ''): ?>
        <img class="avatar" src="<?= e($photo) ?>" alt="<?= e($p['username']) ?>">
      <?php else: ?>
        <div class="avatar">&#128100;</div>
      <?php endif; ?>
      <div>
        <div class="uname">@<?= e($p['username']) ?></div>
        <?php if ($p['display_name'] !== ''): ?>
          <div class="route" style="margin-top:3px"><?= e($p['display_name']) ?></div>
        <?php endif; ?>
        <div><span class="badge">&#10003; Verified Transporter</span></div>
      </div>
      <div class="ratingline">
        <?= tr_star_html($p['rating']) ?>
        <span><?= e(hl_tr_rating_label($row)) ?></span>
      </div>
      <a class="btn" href="<?= e($BOT_LINK) ?>?start=transport" target="_blank" rel="noopener">Rate this transporter</a>
    </div>

    <div>
      <div class="card">
        <h2 class="sec">Transporter details</h2>
        <table class="kv">
          <tr><td>Usual route</td><td><b><?= e($p['origin']) ?></b> &rarr; <b><?= e($p['destination']) ?></b></td></tr>
          <tr><td>Rating</td><td><?= tr_star_html($p['rating']) ?> <?= e(hl_tr_rating_label($row)) ?></td></tr>
          <?php if ($p['contact_method'] !== ''): ?>
          <tr><td>Contact</td><td><?= e($p['contact_method']) ?></td></tr>
          <?php endif; ?>
          <?php if (trim((string) $p['availability']) !== ''): ?>
          <tr><td>Availability</td><td><?= e($p['availability']) ?></td></tr>
          <?php endif; ?>
        </table>

        <?php if (!empty($p['latest_trip'])): $t = $p['latest_trip']; ?>
        <div class="avail" style="margin-top:18px">
          <div class="lbl">Currently advertised trip</div>
          <div><b><?= e($t['origin']) ?></b> &rarr; <b><?= e($t['destination']) ?></b></div>
          <?php if ($t['travel_date'] !== ''): ?><div>Travelling: <b><?= e($t['travel_date']) ?></b></div><?php endif; ?>
          <?php if ($t['space'] !== ''): ?><div>Available space: <b><?= e($t['space']) ?></b></div><?php endif; ?>
          <?php if ($t['price'] !== ''): ?><div>Their price: <b><?= e($t['price']) ?></b></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($reviews): ?>
      <div class="card">
        <h2 class="sec">What customers say</h2>
        <?php foreach ($reviews as $r): if (trim((string) $r['comment']) === '' && (int) $r['rating'] === 0) continue; ?>
          <div class="rev">
            <?= tr_star_html((int) $r['rating']) ?>
            <?php if (trim((string) $r['comment']) !== ''): ?>
              <div style="margin-top:5px"><?= e($r['comment']) ?></div>
            <?php endif; ?>
            <div class="meta"><?= e(substr((string) $r['created_at'], 0, 10)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <header class="hero">
    <h1>Verified Transporters</h1>
    <p>Community members approved by HabeshaList who travel with available luggage space.
       Browse their routes and ratings, then contact them directly.</p>
  </header>

  <form class="searchbar" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by route, name or @username - e.g. DMV, Addis Ababa">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="?" style="align-self:center">Clear</a><?php endif; ?>
  </form>

  <?php if (!$list): ?>
    <div class="empty">
      <?php if ($q !== ''): ?>
        No Verified Transporters match &ldquo;<b><?= e($q) ?></b>&rdquo; yet.
        <div style="margin-top:16px"><a class="btn ghost" href="?">Show all transporters</a></div>
      <?php else: ?>
        No Verified Transporters are listed yet. Travelling with spare space?
        <div style="margin-top:16px">
          <a class="btn" href="<?= e($BOT_LINK) ?>?start=transport" target="_blank" rel="noopener">Become a Verified Transporter</a>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($list as $p): $photo = tr_photo_url($p['photo_path']); ?>
      <div class="card tcard">
        <div class="top">
          <?php if ($photo !== ''): ?>
            <img class="avatar" src="<?= e($photo) ?>" alt="<?= e($p['username']) ?>">
          <?php else: ?>
            <div class="avatar">&#128100;</div>
          <?php endif; ?>
          <div>
            <div class="uname">@<?= e($p['username']) ?></div>
            <span class="badge">&#10003; Verified</span>
          </div>
        </div>
        <div class="route"><b><?= e($p['origin']) ?></b> &rarr; <b><?= e($p['destination']) ?></b></div>
        <div class="ratingline">
          <?= tr_star_html($p['rating']) ?>
          <span><?= $p['rating_count'] > 0
              ? e(number_format($p['rating'], 1)) . ' (' . (int) $p['rating_count'] . ')'
              : 'No ratings yet' ?></span>
        </div>
        <a class="btn" href="?u=<?= rawurlencode($p['username']) ?>">View Profile</a>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:28px;text-align:center">
      <a class="btn ghost" href="<?= e($BOT_LINK) ?>?start=transport" target="_blank" rel="noopener">
        Travelling soon? Become a Verified Transporter</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="notice">
  <b>Before you arrange anything</b>
  <ul>
    <li>Confirm the route, travel date, destination, price, contact details and how the handover will happen.</li>
    <li>Keep your own records - messages, receipts and proof of any payment you make.</li>
    <li>Meet in a safe, public place where possible.</li>
  </ul>
  <p style="margin:12px 0 0"><?= e(hl_tr_disclaimer(false)) ?></p>
</div>

</div>
</body>
</html>
