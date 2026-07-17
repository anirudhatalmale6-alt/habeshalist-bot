<?php
/**
 * preview.php - full visual preview of a promotion, exactly as it will appear
 * in the Telegram group, so the admin can review before approve/reject.
 *
 * Two jobs:
 *   ?id=<promoId>            -> the preview page (post text, logo, images, schedule)
 *   ?img=<fileId>&id=<pid>   -> server-side image proxy: streams a Telegram photo
 *                               through the panel so the bot token never appears
 *                               in the browser. Only file_ids that actually
 *                               belong to a promotion are served.
 */
require __DIR__ . '/lib.php';
hl_require_login();

$promoId = (int) ($_GET['id'] ?? 0);

// Load the promotion up front (needed by both the image proxy and the page).
$stmt = hl_db()->prepare('SELECT * FROM promotions WHERE id = :id');
$stmt->bindValue(':id', $promoId, SQLITE3_INTEGER);
$promo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

// Collect every file_id this promotion legitimately owns (logo + images).
function hl_promo_file_ids($promo) {
    $ids = [];
    if (!empty($promo['logo'])) $ids[] = $promo['logo'];
    if (!empty($promo['images'])) {
        $imgs = json_decode($promo['images'], true);
        if (is_array($imgs)) foreach ($imgs as $i) { if ($i) $ids[] = $i; }
    }
    return $ids;
}

// ---- Image proxy ----
if (isset($_GET['img'])) {
    $fileId = (string) $_GET['img'];
    if (!$promo || !in_array($fileId, hl_promo_file_ids($promo), true)) {
        http_response_code(404); exit('Not found');
    }
    $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
    if ($token === '') { http_response_code(503); exit('Bot token unavailable'); }

    $gf = hl_tg_api($token, 'getFile', ['file_id' => $fileId]);
    $path = $gf['result']['file_path'] ?? '';
    if ($path === '') { http_response_code(404); exit('File not found'); }

    $url = 'https://api.telegram.org/file/bot' . $token . '/' . $path;
    $bin = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $bin = curl_exec($ch);
        curl_close($ch);
    }
    if ($bin === false) { $bin = @file_get_contents($url); }
    if ($bin === false) { http_response_code(502); exit('Could not fetch image'); }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=300');
    echo $bin;
    exit;
}

// ---- Preview page ----
require __DIR__ . '/view.php';

// Act on approve/reject right from the preview (same handler as the queue).
$mod = hl_process_moderation();
$flash = $mod[0] ?? null; $flashType = $mod[1] ?? 'ok';
// Re-read the promotion after a possible status change.
if ($mod) {
    $stmt = hl_db()->prepare('SELECT * FROM promotions WHERE id = :id');
    $stmt->bindValue(':id', $promoId, SQLITE3_INTEGER);
    $promo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

// Build the exact group-post text (mirrors HL_Scheduler::renderPostText).
function hl_preview_post_text($p) {
    $e = function ($s) { return h($s); };
    $tag = function ($s) {
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $s);
        $parts = array_filter(explode(' ', trim($s)), 'strlen');
        $out = '';
        foreach ($parts as $part) { $out .= function_exists('mb_convert_case') ? mb_convert_case($part, MB_CASE_TITLE, 'UTF-8') : ucfirst($part); }
        return $out === '' ? '' : '#' . $out;
    };

    $divider = str_repeat("\xE2\x94\x81", 18);
    $name = function_exists('mb_strtoupper') ? mb_strtoupper((string) ($p['business_name'] ?: 'Featured Business'), 'UTF-8') : strtoupper($p['business_name'] ?: 'Featured Business');

    $lines = [];
    $lines[] = $divider;
    $lines[] = "\xF0\x9F\x93\xA2 <b>" . $e($name) . "</b>";
    $lines[] = $divider;
    $lines[] = '';
    if (!empty($p['business_category'])) { $lines[] = "\xF0\x9F\x8F\xB7\xEF\xB8\x8F Category: " . $e($p['business_category']); $lines[] = ''; }
    if (!empty($p['description']))       { $lines[] = "\xF0\x9F\x93\x9D " . $e($p['description']); $lines[] = ''; }
    if (!empty($p['address'])) { $lines[] = "\xF0\x9F\x93\x8D Location: " . $e($p['address']); $lines[] = ''; }
    if (!empty($p['phone']))   { $lines[] = "\xF0\x9F\x93\x9E Contact: " . $e($p['phone']); $lines[] = ''; }
    if (!empty($p['website'])) { $lines[] = "\xF0\x9F\x8C\x90 " . $e($p['website']); $lines[] = ''; }
    if (!empty($p['social']))  { $lines[] = "\xF0\x9F\x94\x97 " . $e($p['social']); $lines[] = ''; }
    if (!empty($p['hours']))   { $lines[] = "\xF0\x9F\x95\x92 " . $e($p['hours']); $lines[] = ''; }
    if (!empty($p['cta']))     { $lines[] = "\xF0\x9F\x91\x89 " . $e($p['cta']); $lines[] = ''; }

    $tags = [];
    if (!empty($p['business_category'])) { $t = $tag($p['business_category']); if ($t) $tags[] = $t; }
    if (!empty($p['address']))           { $t = $tag($p['address']);           if ($t) $tags[] = $t; }
    $tags[] = '#HabeshaList';
    $lines[] = implode(' ', array_unique($tags));

    return trim(implode("\n", $lines));
}

// Human-readable schedule from the stored JSON.
function hl_preview_schedule($p) {
    $sched = !empty($p['schedule']) ? json_decode($p['schedule'], true) : null;
    if (!is_array($sched) || empty($sched['mode'])) return 'Not set (will use automatic scheduling)';
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $fmtTime = function ($hm) { $d = DateTime::createFromFormat('H:i', $hm); return $d ? $d->format('g:i A') : $hm; };
    if ($sched['mode'] === 'single') {
        $d = DateTime::createFromFormat('Y-m-d', $sched['date'] ?? '');
        return ($d ? $d->format('l, M j, Y') : ($sched['date'] ?? '?')) . ' at ' . $fmtTime($sched['time'] ?? '');
    }
    if ($sched['mode'] === 'recurring') {
        $parts = [];
        foreach (($sched['slots'] ?? []) as $s) {
            $parts[] = ($names[$s['dow'] ?? 0] ?? '?') . ' ' . $fmtTime($s['time'] ?? '');
        }
        return 'Every ' . implode(' and ', $parts) . ' (auto-scheduled)';
    }
    return '-';
}

hl_session_start();
$csrf = h(hl_csrf_token());
hl_shell_head('Ad Preview', 'pending', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);

if (!$promo) {
    echo '<div class="card"><div class="empty">That promotion could not be found.</div>'
       . '<p><a class="btn" href="pending.php">Back to Pending Ads</a></p></div>';
    hl_shell_foot();
    exit;
}

$fileIds = hl_promo_file_ids($promo);
$logoId = $promo['logo'] ?? '';
$imageIds = [];
if (!empty($promo['images'])) { $d = json_decode($promo['images'], true); if (is_array($d)) $imageIds = array_filter($d); }
$postText = nl2br(hl_preview_post_text($promo));
list($pillCls, $pillLabel) = hl_status_meta($promo['status'] ?? '');
?>
<style>
  .pv-wrap { display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start; }
  .pv-col { flex:1 1 320px; min-width:300px; }
  .tg-bubble { background:#effaf0; color:#0b1f16; border-radius:14px; padding:14px 16px; max-width:520px;
               box-shadow:0 1px 4px rgba(0,0,0,.12); line-height:1.5; word-break:break-word; }
  .tg-shell { background:#cfe3d6; padding:22px; border-radius:14px; }
  .tg-bubble img { max-width:100%; border-radius:10px; margin-bottom:10px; display:block; }
  .tg-imgs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
  .tg-imgs img { width:calc(50% - 3px); border-radius:8px; margin:0; }
  .kv { width:100%; border-collapse:collapse; }
  .kv td { padding:7px 8px; border-bottom:1px solid rgba(128,128,128,.18); vertical-align:top; }
  .kv td.k { color:#6b7280; white-space:nowrap; width:130px; }
  @media (prefers-color-scheme: dark) { .tg-bubble { background:#12303a; color:#e6f0ee; } .tg-shell { background:#0b2027; } }
  :root[data-theme="dark"] .tg-bubble { background:#12303a; color:#e6f0ee; }
  :root[data-theme="dark"] .tg-shell { background:#0b2027; }
  :root[data-theme="light"] .tg-bubble { background:#effaf0; color:#0b1f16; }
  :root[data-theme="light"] .tg-shell { background:#cfe3d6; }
</style>

<div class="card">
  <div class="hd"><h2>Ad Preview <span class="pill <?= $pillCls ?>"><?= h($pillLabel) ?></span></h2></div>
  <p class="sub">This is exactly how <b><?= h($promo['business_name'] ?: '(no name)') ?></b> will appear in the group. Review it, then approve or reject.</p>

  <div class="pv-wrap">
    <div class="pv-col">
      <div class="tg-shell">
        <div class="tg-bubble">
          <?php if ($logoId): ?>
            <img src="preview.php?id=<?= $promoId ?>&amp;img=<?= urlencode($logoId) ?>" alt="logo">
          <?php elseif ($imageIds): ?>
            <div class="tg-imgs">
              <?php foreach ($imageIds as $iid): ?>
                <img src="preview.php?id=<?= $promoId ?>&amp;img=<?= urlencode($iid) ?>" alt="image">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div><?= $postText ?></div>
        </div>
        <?php if ($logoId && $imageIds): ?>
          <div class="tg-imgs" style="margin-top:8px;">
            <?php foreach ($imageIds as $iid): ?>
              <img src="preview.php?id=<?= $promoId ?>&amp;img=<?= urlencode($iid) ?>" alt="image">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="pv-col">
      <table class="kv">
        <tr><td class="k">Plan</td><td><?= h(hl_plan_name($promo['package_key'])) ?> &middot; <?= h(hl_money($promo['price'])) ?></td></tr>
        <tr><td class="k">Schedule</td><td><?= h(hl_preview_schedule($promo)) ?></td></tr>
        <tr><td class="k">Posts</td><td><?= (int) ($promo['posts_used'] ?? 0) ?> / <?= (int) ($promo['posts_total'] ?? 0) ?></td></tr>
        <tr><td class="k">Payment</td><td><?= h(strtoupper($promo['payment_method'] ?: 'n/a')) ?> &middot; <?= h($promo['payment_status'] ?: '-') ?></td></tr>
        <tr><td class="k">Receipt</td><td class="mono"><?= h($promo['receipt'] ?: '-') ?></td></tr>
        <tr><td class="k">Phone</td><td><?= h($promo['phone'] ?: '-') ?></td></tr>
        <tr><td class="k">Submitted</td><td class="muted small"><?= h($promo['created_at']) ?></td></tr>
      </table>

      <?php if (($promo['status'] ?? '') === 'pending_review'): ?>
      <div class="actions" style="margin-top:16px;">
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="approve"><input type="hidden" name="promo_id" value="<?= $promoId ?>">
          <button class="btn" type="submit">Approve &amp; Schedule</button></form>
        <form method="post" onsubmit="return confirm('Reject this promotion? The customer will be told in Telegram.')">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="reject"><input type="hidden" name="promo_id" value="<?= $promoId ?>">
          <button class="btn red" type="submit">Reject</button></form>
      </div>
      <?php endif; ?>
      <p style="margin-top:14px;"><a class="btn ghost" href="pending.php">&larr; Back to Pending Ads</a></p>
    </div>
  </div>
</div>
<?php hl_shell_foot();
