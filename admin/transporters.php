<?php
/**
 * transporters.php - Admin > Transporters for the Verified Transporter module.
 *
 * Tabs: Pending | Approved | Rejected | Suspended | Needs Review (spec s.4)
 *   - Pending      : approve or reject an application, with the full details.
 *   - Needs Review : the 3-distinct-low-rater flag, with the ratings that caused
 *                    it and the three allowed decisions - Keep Active,
 *                    Send Warning, Suspend. NEVER an automatic suspension.
 * Plus Settings > Transporter Advertising (spec s.10): on/off, the fee amount,
 * currency and the free introductory post allowance. Nothing is hard-coded.
 *
 * Uses the SAME bot database as the rest of the panel (hl_db()); the transporter
 * module (includes/transporters.php) owns its three tables and never touches the
 * bot's own tables.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';

// Locate the transporter module. The panel folder may live next to the bot
// folder (bizadmin + bot as siblings) or inside it, so we derive the bot root
// from the shared DB path (hl_bot_root) and fall back to the known layouts.
$__tr_lib = '';
foreach ([
    (function_exists('hl_bot_root') && hl_bot_root() !== '') ? hl_bot_root() . '/includes/transporters.php' : null,
    __DIR__ . '/../includes/transporters.php',      // panel inside the bot folder
    __DIR__ . '/../bot/includes/transporters.php',  // panel is a sibling of the bot folder
    __DIR__ . '/../../bot/includes/transporters.php',
] as $__c) {
    if ($__c && is_file($__c)) { $__tr_lib = $__c; break; }
}
if ($__tr_lib === '') {
    hl_require_login();
    hl_shell_head('Transporters', 'transporters');
    echo '<div class="card"><h2>Verified Transporters</h2>'
       . '<p class="muted">The transporter module file <code>includes/transporters.php</code> was not '
       . 'found next to the bot. Upload <code>includes/transporters.php</code> into the bot folder '
       . '(the same folder that has <code>webhook.php</code> and <code>data/bot.sqlite</code>), '
       . 'then reload this page.</p></div>';
    hl_shell_foot();
    exit;
}
require $__tr_lib;

// The bot folder that owns the module (…/bot/includes/transporters.php → …/bot).
// Profile photos are saved there by the bot, so the website serves them from the
// same place this panel reads them.
$__bot_root = dirname(dirname($__tr_lib));

// --- Photo proxy -----------------------------------------------------------
// Profile photos are written by the bot into <bot>/uploads/transporters/, which
// is NOT reachable from the panel's own folder by a relative path (the panel is
// usually a sibling of the bot folder). So the panel serves them itself, from
// the bot root it already resolved above. Handled before hl_require_login() ...
// no: photos are a private-ish asset, so the login check comes first.
hl_require_login();

if (isset($_GET['photo'])) {
    $name = basename((string) $_GET['photo']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $file = $__bot_root . '/uploads/transporters/' . $name;
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || !isset($types[$ext]) || !is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=3600');
    readfile($file);
    exit;
}

/** Thumbnail URL for a transporter photo, served by this page. */
function tr_admin_photo_url($relPath) {
    $relPath = trim((string) $relPath);
    if ($relPath === '') return '';
    return 'transporters.php?photo=' . rawurlencode(basename($relPath));
}

$db = hl_db();
hl_tr_ensure_schema($db);

/** Best-effort Telegram DM to a transporter, mirroring admin/screens.php. */
function tr_admin_dm($telegramId, $html) {
    $tid = (int) $telegramId;
    if ($tid <= 0 || !function_exists('hl_effective_secret') || !function_exists('hl_tg_api')) return '';
    $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
    if ($token === '') return ' (saved; bot token not available here, so they were not messaged)';
    $r = hl_tg_api($token, 'sendMessage', ['chat_id' => $tid, 'text' => $html, 'parse_mode' => 'HTML']);
    return empty($r['ok']) ? ' (saved; Telegram notice failed: ' . h($r['description'] ?? 'unknown') . ')' : '';
}

/** Publish an approved post to the group from the panel (same guard as the bot). */
function tr_admin_publish($db, $postId) {
    $post = hl_tr_post_by_id($db, $postId);
    if (!$post) return ['ok' => false, 'error' => 'Post not found.'];
    $tr = hl_tr_by_id($db, (int) $post['transporter_id']);
    $block = hl_tr_publish_block_reason($db, $post, $tr);
    if ($block !== '') return ['ok' => false, 'error' => $block];

    $chat = (string) hl_get_setting('sched_group_chat_id', '');
    if ($chat === '') return ['ok' => false, 'error' => 'No Telegram group is configured (Schedule Settings).'];
    if (!function_exists('hl_effective_secret') || !function_exists('hl_tg_api')) {
        return ['ok' => false, 'error' => 'Telegram helpers unavailable in the panel.'];
    }
    $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
    if ($token === '') return ['ok' => false, 'error' => 'Bot token not available in the panel.'];

    $site  = rtrim((string) hl_get_setting('tr_site_url', 'https://habeshalist.com'), '/');
    $uname = hl_tr_normalize_username($tr['username'] ?? '');
    $text  = "\xF0\x9F\xA7\xB3 <b>Luggage Space Available</b>\n\n"
        . "\xE2\x9C\x85 <b>Verified Transporter</b>  <b>@" . h($uname) . "</b>\n"
        . ((int) ($tr['rating_count'] ?? 0) > 0 ? hl_tr_stars(hl_tr_avg($tr)) . ' ' . hl_tr_rating_label($tr) . "\n" : '')
        . "\n\xF0\x9F\x93\x8D Route: <b>" . h($post['origin']) . " \xE2\x86\x92 " . h($post['destination']) . "</b>\n"
        . "\xF0\x9F\x93\x85 Date: <b>" . h($post['travel_date']) . "</b>\n"
        . "\xF0\x9F\x93\xA6 Available space: <b>" . h($post['space']) . "</b>\n"
        . "\xF0\x9F\x92\xB5 Price: <b>" . h($post['price']) . "</b>\n"
        . "\xF0\x9F\x93\x9E Contact: <b>" . h($post['contact_method']) . "</b>\n"
        . ($uname !== '' ? "\n\xF0\x9F\x8C\x90 Profile: {$site}/transporters/" . rawurlencode($uname) . "\n" : '')
        . "\n" . hl_tr_disclaimer();

    $photo = (string) ($tr['photo_file_id'] ?? '');
    $r = $photo !== ''
        ? hl_tg_api($token, 'sendPhoto', ['chat_id' => $chat, 'photo' => $photo, 'caption' => $text, 'parse_mode' => 'HTML'])
        : hl_tg_api($token, 'sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']);
    if (empty($r['ok']) && $photo !== '') {
        $r = hl_tg_api($token, 'sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']);
    }
    if (empty($r['ok']) || empty($r['result']['message_id'])) {
        return ['ok' => false, 'error' => $r['description'] ?? 'Telegram send failed.'];
    }
    if (!hl_tr_mark_published($db, $postId, (int) $r['result']['message_id'])) {
        return ['ok' => false, 'error' => 'That post had already been published.'];
    }
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
$flash = ''; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $form = $_POST['form'] ?? '';
    $id   = (int) ($_POST['id'] ?? 0);
    $tr   = $id ? hl_tr_by_id($db, $id) : null;

    if ($form === 'settings') {
        hl_set_setting('tr_module_enabled', isset($_POST['module_enabled']) ? '1' : '0');
        hl_set_setting('tr_ads_enabled',    isset($_POST['ads_enabled']) ? '1' : '0');
        $fee = $_POST['ad_fee'] ?? '';
        // An admin-changeable decimal amount - blank is not the same as zero, so
        // only write a value we can actually parse as a number.
        if (is_numeric($fee) && (float) $fee >= 0) hl_set_setting('tr_ad_fee', (string) round((float) $fee, 2));
        $cur = strtoupper(trim($_POST['currency'] ?? 'USD'));
        if (preg_match('/^[A-Z]{3}$/', $cur)) hl_set_setting('tr_currency', $cur);
        hl_set_setting('tr_free_posts', (string) max(0, (int) ($_POST['free_posts'] ?? 0)));
        $site = trim($_POST['site_url'] ?? '');
        if ($site !== '' && preg_match('~^https?://~i', $site)) hl_set_setting('tr_site_url', rtrim($site, '/'));
        $flash = 'Transporter advertising settings saved.';

    } elseif ($form === 'decide' && $tr) {
        $action = $_POST['action'] ?? '';
        $name = $tr['full_name'] ?: ('#' . $id);
        $site = rtrim((string) hl_get_setting('tr_site_url', 'https://habeshalist.com'), '/');

        if ($action === 'approve') {
            if (($tr['verification_status'] ?? '') !== 'pending') {
                $flash = 'That application was already handled.'; $flashType = 'err';
            } else {
                hl_tr_set_status($db, $id, 'approved');
                $flash = 'Approved: ' . $name . '.';
                $flash .= tr_admin_dm($tr['telegram_id'],
                    "\xF0\x9F\x8E\x89 <b>Congratulations - you're a Verified Transporter!</b>\n\n"
                    . "Open the bot and tap <b>Luggage &amp; Transport</b> to choose your public HabeshaList "
                    . "username. As soon as you do, your profile goes live on {$site}/transporters.");
            }

        } elseif ($action === 'reject') {
            hl_tr_set_status($db, $id, 'rejected', trim($_POST['admin_notes'] ?? '') ?: null);
            $flash = 'Rejected: ' . $name . '.';
            $flash .= tr_admin_dm($tr['telegram_id'],
                "Thank you for applying to become a Verified Transporter. Unfortunately your application "
                . "wasn't approved this time. You're welcome to apply again with updated details, or contact support.");

        } elseif ($action === 'keep') {
            hl_tr_clear_review($db, $id, false);
            $flash = 'Kept active: ' . $name . '. Review flag cleared.';

        } elseif ($action === 'warn') {
            hl_tr_clear_review($db, $id, true);
            $flash = 'Warning sent to ' . $name . '.';
            $flash .= tr_admin_dm($tr['telegram_id'],
                "\xE2\x9A\xA0\xEF\xB8\x8F <b>A note from the HabeshaList team</b>\n\n"
                . "We've received some low ratings from customers about your recent trips. Your profile is "
                . "still active and you can keep posting.\n\nPlease confirm the route, date, price and handover "
                . "clearly with every customer. Repeated complaints may lead to suspension.");

        } elseif ($action === 'suspend') {
            hl_tr_set_status($db, $id, 'suspended', trim($_POST['admin_notes'] ?? '') ?: null);
            hl_tr_clear_review($db, $id, false);
            $flash = 'Suspended: ' . $name . '. New posts blocked and the website profile is hidden. Past posts and ratings are kept.';
            $flash .= tr_admin_dm($tr['telegram_id'],
                "\xE2\x9B\x94 <b>Your transporter account has been suspended.</b>\n\n"
                . "New transportation posts are paused and your public profile is hidden for now. "
                . "Please contact support if you'd like to discuss this.");

        } elseif ($action === 'reinstate') {
            hl_tr_set_status($db, $id, 'approved');
            $flash = 'Reinstated: ' . $name . '. Their profile is public again.';
            $flash .= tr_admin_dm($tr['telegram_id'],
                "\xE2\x9C\x85 <b>Your transporter account is active again.</b>\n\n"
                . "Your public profile is back on HabeshaList.com and you can post available space as usual.");

        } elseif ($action === 'save_notes') {
            hl_tr_update($db, $id, ['admin_notes' => trim($_POST['admin_notes'] ?? '')]);
            $flash = 'Notes saved for ' . $name . '.';

        } elseif ($action === 'uname_approve' || $action === 'uname_reject') {
            $wanted = hl_tr_normalize_username($tr['username_pending'] ?? '');
            $err = hl_tr_resolve_username_change($db, $id, $action === 'uname_approve');
            if ($err !== '') { $flash = $err; $flashType = 'err'; }
            elseif ($action === 'uname_approve') {
                $flash = 'Username changed to @' . $wanted . '.';
                $flash .= tr_admin_dm($tr['telegram_id'],
                    "\xE2\x9C\x85 Your public username is now <b>@" . h($wanted) . "</b>. Your profile link has been updated.");
            } else {
                $flash = 'Username change declined.';
                $flash .= tr_admin_dm($tr['telegram_id'],
                    "Your username change request wasn't approved. Your current username stays active.");
            }
        }

    } elseif ($form === 'post_decide') {
        $pid = (int) ($_POST['post_id'] ?? 0);
        $post = $pid ? hl_tr_post_by_id($db, $pid) : null;
        if (!$post) { $flash = 'That post could not be found.'; $flashType = 'err'; }
        elseif ((int) ($post['tg_message_id'] ?? 0) > 0) {
            $flash = 'That post has already been published.'; $flashType = 'err';
        } elseif (($_POST['action'] ?? '') === 'publish') {
            hl_tr_update_post($db, $pid, ['payment_status' => 'approved']);
            $res = tr_admin_publish($db, $pid);
            if ($res['ok']) {
                $flash = 'Published post #' . $pid . ' to the group.';
                $ptr = hl_tr_by_id($db, (int) $post['transporter_id']);
                $flash .= tr_admin_dm($ptr['telegram_id'] ?? 0,
                    "\xF0\x9F\x8E\x89 <b>Your payment is verified and your post is live!</b>\n\n"
                    . "It has been published to the HabeshaList group. Customers will contact you directly.");
            } else {
                // Keep the payment approved so it can be retried - never silently drop a paid ad.
                $flash = 'Could not publish: ' . $res['error']; $flashType = 'err';
            }
        } else {
            hl_tr_update_post($db, $pid, ['payment_status' => 'rejected', 'post_status' => 'rejected']);
            $flash = 'Rejected post #' . $pid . '.';
            $ptr = hl_tr_by_id($db, (int) $post['transporter_id']);
            $flash .= tr_admin_dm($ptr['telegram_id'] ?? 0,
                "Your transporter advertisement wasn't approved, so it has not been published. "
                . "Please contact support if you'd like to resubmit.");
        }
    }

    $qs = 'tab=' . rawurlencode($_POST['tab'] ?? 'pending');
    if (!empty($_POST['stay_open'])) $qs .= '&open=' . (int) ($_POST['stay_open']);
    header('Location: transporters.php?' . $qs . '&m=' . rawurlencode($flash) . '&t=' . $flashType);
    exit;
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'pending';
$validTabs = ['pending', 'approved', 'rejected', 'suspended', 'needs_review'];
if (!in_array($tab, $validTabs, true)) $tab = 'pending';

$counts   = hl_tr_counts($db);
$settings = hl_tr_settings('hl_get_setting');
$rows     = hl_tr_list($db, $tab);
$queue    = hl_tr_pending_payment_posts($db);
$open     = (int) ($_GET['open'] ?? 0);
$site     = $settings['site_url'];

hl_shell_head('Transporters', 'transporters', hl_pending_count());
if (!empty($_GET['m'])) hl_flash($_GET['m'], ($_GET['t'] ?? 'ok') === 'err' ? 'err' : 'ok');

$total = $counts['pending'] + $counts['approved'] + $counts['rejected'] + $counts['suspended'];
?>
<div class="stats">
  <div class="stat"><div class="ico" style="background:var(--pendbg);color:var(--pendfg)">&#9203;</div>
    <div><div class="n"><?= (int) $counts['pending'] ?></div><div class="l">Pending applications</div></div></div>
  <div class="stat"><div class="ico" style="background:var(--okbg);color:var(--okfg)">&#9989;</div>
    <div><div class="n"><?= (int) $counts['approved'] ?></div><div class="l">Approved transporters</div></div></div>
  <div class="stat"><div class="ico" style="background:var(--dangerbg);color:var(--danger)">&#9888;</div>
    <div><div class="n"><?= (int) $counts['needs_review'] ?></div><div class="l">Need review</div></div></div>
  <div class="stat"><div class="ico" style="background:var(--chip)">&#128176;</div>
    <div><div class="n"><?= count($queue) ?></div><div class="l">Payments to verify</div></div></div>
</div>

<?php if ($queue): ?>
<div class="card">
  <div class="hd"><h2>&#128181; Payments awaiting verification</h2></div>
  <p class="sub">A transporter paid by screenshot. Check the proof, then publish the advertisement to the group.</p>
  <div class="tblwrap"><table>
    <tr><th>Transporter</th><th>Route</th><th>Date</th><th>Space</th><th>Fee paid</th><th>Reference</th><th></th></tr>
    <?php foreach ($queue as $p): ?>
    <tr>
      <td><b>@<?= h($p['username']) ?></b><div class="muted small"><?= h($p['full_name']) ?></div></td>
      <td><?= h($p['origin']) ?> &rarr; <?= h($p['destination']) ?></td>
      <td><?= h($p['travel_date']) ?></td>
      <td><?= h($p['space']) ?></td>
      <td><?= h(hl_tr_money($p['ad_fee'], $settings['currency'])) ?></td>
      <td class="mono small"><?= h($p['payment_ref']) ?></td>
      <td>
        <form method="post" class="actions" style="justify-content:flex-end">
          <input type="hidden" name="csrf" value="<?= h(hl_csrf_token()) ?>">
          <input type="hidden" name="form" value="post_decide">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>">
          <button class="btn sm" name="action" value="publish">Approve &amp; Publish</button>
          <button class="btn sm red" name="action" value="reject"
                  onclick="return confirm('Reject this advertisement? It will not be published.')">Reject</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted small" style="margin-top:10px">
    The payment screenshot itself is sent to you in Telegram when the transporter submits it.
  </p>
</div>
<?php endif; ?>

<div class="card">
  <div class="hd">
    <h2>Verified Transporters</h2>
    <a class="btn ghost sm" href="<?= h($site) ?>/transporters" target="_blank" rel="noopener">View public directory &#8599;</a>
  </div>
  <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px">
    <?php
    $tabs = [
        'pending'      => ['Pending',      $counts['pending']],
        'approved'     => ['Approved',     $counts['approved']],
        'rejected'     => ['Rejected',     $counts['rejected']],
        'suspended'    => ['Suspended',    $counts['suspended']],
        'needs_review' => ['Needs Review', $counts['needs_review']],
    ];
    foreach ($tabs as $k => $meta) {
        $cls = ($k === $tab) ? 'btn sm' : 'btn ghost sm';
        echo '<a class="' . $cls . '" href="?tab=' . $k . '">' . h($meta[0])
           . ' <span class="muted">(' . (int) $meta[1] . ')</span></a>';
    }
    ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">
      <?php if ($tab === 'pending' && $total === 0): ?>
        No transporter applications yet. They arrive here when someone taps
        <b>Luggage &amp; Transport &rarr; Become a Verified Transporter</b> in the bot.
      <?php else: ?>
        Nothing in this tab.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tblwrap"><table>
      <tr>
        <th></th><th>Name</th><th>@Username</th><th>Usual route</th>
        <th>Rating</th><th>Posts</th><th>Status</th><th></th>
      </tr>
      <?php foreach ($rows as $t):
          $tid    = (int) $t['id'];
          $uname  = hl_tr_normalize_username($t['username'] ?? '');
          $photo  = trim((string) $t['photo_path']);
          $posts  = hl_tr_published_count($db, $tid);
          $lowCnt = hl_tr_low_distinct_count($db, $tid);
          $pillMap = ['approved' => 'ok', 'pending' => 'pend', 'rejected' => 'rej', 'suspended' => 'rej'];
          $pill = $pillMap[$t['verification_status']] ?? 'mut';
          $isOpen = ($open === $tid) || $tab === 'pending' || $tab === 'needs_review';
      ?>
      <tr>
        <td>
          <?php if ($photo !== ''): ?>
            <img src="<?= h(tr_admin_photo_url($photo)) ?>" alt="" class="thumb" style="object-fit:cover">
          <?php else: ?>
            <div class="thumb">&#128100;</div>
          <?php endif; ?>
        </td>
        <td>
          <b><?= h($t['full_name'] ?: '(no name)') ?></b>
          <div class="muted small">#<?= $tid ?><?= $t['phone_number'] ? ' &middot; ' . h($t['phone_number']) : '' ?></div>
        </td>
        <td>
          <?php if ($uname !== ''): ?>
            <a href="<?= h($site . '/transporters/' . rawurlencode($uname)) ?>" target="_blank" rel="noopener"><b>@<?= h($uname) ?></b></a>
          <?php else: ?>
            <span class="muted small">not created yet</span>
          <?php endif; ?>
          <?php if (hl_tr_normalize_username($t['username_pending'] ?? '') !== ''): ?>
            <div class="small"><span class="pill pend">change &rarr; @<?= h($t['username_pending']) ?></span></div>
          <?php endif; ?>
        </td>
        <td class="small"><?= h($t['origin']) ?> &rarr; <?= h($t['destination']) ?></td>
        <td class="small"><?= h(hl_tr_rating_label($t)) ?><?php if ($lowCnt > 0): ?>
            <div class="muted small"><?= (int) $lowCnt ?> low rater<?= $lowCnt === 1 ? '' : 's' ?></div><?php endif; ?></td>
        <td><?= (int) $posts ?></td>
        <td>
          <span class="pill <?= $pill ?>"><?= h(ucfirst($t['verification_status'])) ?></span>
          <?php if ((int) $t['admin_review_required'] === 1): ?>
            <div style="margin-top:4px"><span class="pill rej">Needs review</span></div>
          <?php endif; ?>
        </td>
        <td style="text-align:right">
          <a class="btn ghost sm" href="?tab=<?= h($tab) ?>&amp;open=<?= $isOpen && $open === $tid ? 0 : $tid ?>#t<?= $tid ?>">
            <?= $isOpen ? 'Hide' : 'Review' ?></a>
        </td>
      </tr>

      <?php if ($isOpen): ?>
      <tr id="t<?= $tid ?>"><td colspan="8" style="background:var(--chip);border-radius:10px">
        <div style="display:grid;gap:16px;grid-template-columns:1fr;padding:6px 2px">
          <div>
            <div class="muted small" style="margin-bottom:6px">APPLICATION DETAILS</div>
            <table style="font-size:13px">
              <tr><td class="muted" style="width:170px">Full name</td><td><?= h($t['full_name']) ?></td></tr>
              <tr><td class="muted">Phone number</td><td><?= h($t['phone_number']) ?></td></tr>
              <tr><td class="muted">Contact method (public)</td><td><?= h($t['contact_method']) ?></td></tr>
              <tr><td class="muted">Telegram</td><td>
                <?= $t['tg_username'] ? '@' . h($t['tg_username']) : '<span class="muted">no @username</span>' ?>
                <span class="muted small">(id <?= (int) $t['telegram_id'] ?>)</span></td></tr>
              <tr><td class="muted">Usual origin</td><td><?= h($t['origin']) ?></td></tr>
              <tr><td class="muted">Usual destination</td><td><?= h($t['destination']) ?></td></tr>
              <tr><td class="muted">Agreement accepted</td><td>
                <?= $t['agreed_at'] ? h($t['agreed_at']) . ' UTC' : '<span class="muted">not recorded</span>' ?></td></tr>
              <tr><td class="muted">Applied</td><td><?= h($t['created_at']) ?></td></tr>
              <?php if ($t['approved_at']): ?>
              <tr><td class="muted">Approved</td><td><?= h($t['approved_at']) ?></td></tr>
              <?php endif; ?>
              <?php if ($t['warned_at']): ?>
              <tr><td class="muted">Last warning</td><td><?= h($t['warned_at']) ?></td></tr>
              <?php endif; ?>
            </table>
          </div>

          <?php $ratings = hl_tr_ratings($db, $tid, 10); if ($ratings): ?>
          <div>
            <div class="muted small" style="margin-bottom:6px">RECENT RATINGS</div>
            <table style="font-size:13px">
              <?php foreach ($ratings as $r): ?>
              <tr>
                <td style="width:110px"><?= str_repeat('&#11088;', max(1, (int) $r['rating'])) ?></td>
                <td><?= $r['comment'] !== '' ? h($r['comment']) : '<span class="muted">no comment</span>' ?></td>
                <td class="muted small" style="width:150px;text-align:right"><?= h($r['created_at']) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php if ((int) $t['admin_review_required'] === 1): ?>
            <p class="small" style="margin-top:8px;color:var(--danger)">
              <b>Flagged:</b> <?= (int) $lowCnt ?> different customers have left a 1- or 2-star rating.
              This transporter has <b>not</b> been suspended - the decision is yours.
            </p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(hl_csrf_token()) ?>">
            <input type="hidden" name="form" value="decide">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <input type="hidden" name="id" value="<?= $tid ?>">
            <input type="hidden" name="stay_open" value="<?= $tid ?>">
            <label for="n<?= $tid ?>">Admin notes (private - never shown publicly)</label>
            <input type="text" id="n<?= $tid ?>" name="admin_notes" value="<?= h($t['admin_notes']) ?>"
                   placeholder="Reason for a rejection or suspension, internal reminders...">
            <div class="actions" style="margin-top:12px;flex-wrap:wrap">
              <?php if ($t['verification_status'] === 'pending'): ?>
                <button class="btn" name="action" value="approve">&#9989; Approve</button>
                <button class="btn red" name="action" value="reject"
                        onclick="return confirm('Reject this application?')">&#10060; Reject</button>
              <?php elseif ($t['verification_status'] === 'approved'): ?>
                <?php if ((int) $t['admin_review_required'] === 1): ?>
                  <button class="btn" name="action" value="keep">&#9989; Keep Active</button>
                  <button class="btn ghost" name="action" value="warn">&#9888; Send Warning</button>
                <?php endif; ?>
                <button class="btn red" name="action" value="suspend"
                        onclick="return confirm('Suspend this transporter? New posts are blocked and the website profile is hidden. Past posts and ratings are kept.')">
                  &#9940; Suspend</button>
                <button class="btn ghost" name="action" value="save_notes">Save notes</button>
              <?php elseif ($t['verification_status'] === 'suspended'): ?>
                <button class="btn" name="action" value="reinstate">&#9989; Reinstate</button>
                <button class="btn ghost" name="action" value="save_notes">Save notes</button>
              <?php else: ?>
                <button class="btn" name="action" value="approve">&#9989; Approve anyway</button>
                <button class="btn ghost" name="action" value="save_notes">Save notes</button>
              <?php endif; ?>

              <?php if (hl_tr_normalize_username($t['username_pending'] ?? '') !== ''): ?>
                <span style="flex:1"></span>
                <button class="btn" name="action" value="uname_approve">Approve @<?= h($t['username_pending']) ?></button>
                <button class="btn ghost" name="action" value="uname_reject">Decline change</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="hd"><h2>&#128176; Transporter Advertising</h2></div>
  <p class="sub">
    What a transporter pays to publish one availability post. The amount is never hard-coded - whatever you
    set here is what the bot charges. A change only affects <b>new</b> checkouts: the fee is frozen onto each
    transaction at the moment it is paid.
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(hl_csrf_token()) ?>">
    <input type="hidden" name="form" value="settings">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">

    <div class="row">
      <div class="field">
        <label for="ad_fee">Single post advertising fee</label>
        <div class="prefix"><span class="sym">$</span>
          <input type="number" step="0.01" min="0" id="ad_fee" name="ad_fee" value="<?= h(number_format($settings['fee'], 2, '.', '')) ?>">
        </div>
      </div>
      <div class="field">
        <label for="currency">Currency</label>
        <input type="text" id="currency" name="currency" maxlength="3" value="<?= h($settings['currency']) ?>">
      </div>
      <div class="field">
        <label for="free_posts">Free introductory posts (per transporter)</label>
        <input type="number" min="0" step="1" id="free_posts" name="free_posts" value="<?= (int) $settings['free_posts'] ?>">
      </div>
    </div>

    <div class="row">
      <div class="field" style="flex:2">
        <label for="site_url">Website base URL (used for profile links)</label>
        <input type="text" id="site_url" name="site_url" value="<?= h($settings['site_url']) ?>" placeholder="https://habeshalist.com">
      </div>
    </div>

    <div style="display:flex;gap:22px;flex-wrap:wrap;margin:6px 0 14px">
      <label style="display:flex;gap:8px;align-items:center;color:var(--text)">
        <input type="checkbox" name="module_enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
        Luggage &amp; Transport is open
      </label>
      <label style="display:flex;gap:8px;align-items:center;color:var(--text)">
        <input type="checkbox" name="ads_enabled" value="1" <?= $settings['ads_enabled'] ? 'checked' : '' ?>>
        Charge for posts (uncheck to run a free pilot)
      </label>
    </div>

    <button class="btn">Save settings</button>
  </form>

  <p class="muted small" style="margin-top:14px">
    <b>Running the pilot:</b> leave <i>Charge for posts</i> unchecked, or set free introductory posts to 1-2.
    Transporters then publish without paying and you can measure whether the posts generate real enquiries
    before switching charging on.
  </p>
</div>

<div class="card">
  <div class="hd"><h2>How this works</h2></div>
  <ol class="small" style="margin:0;padding-left:20px;line-height:1.9">
    <li>A transporter applies in the bot under <b>Luggage &amp; Transport &rarr; Become a Verified Transporter</b>.</li>
    <li>You approve them here. They then choose a public <b>@username</b>, which creates their profile at
        <span class="mono"><?= h($site) ?>/transporters/{username}</span> automatically.</li>
    <li>They post available space, see an exact preview, and pay the advertising fee above (card, or a
        screenshot you verify in the <b>Payments awaiting verification</b> box at the top of this page).</li>
    <li>The advertisement publishes to your Telegram group. It can never publish twice - the Telegram message
        id is stored the moment it goes out.</li>
    <li>Customers rate transporters by <b>@username</b>. When three <i>different</i> customers leave a 1- or
        2-star rating, the transporter appears in <b>Needs Review</b>. Nothing is suspended automatically.</li>
    <li>Suspending blocks new posts and hides the public profile. Past posts, ratings and history are never deleted.</li>
  </ol>
</div>
<?php
hl_shell_foot();
