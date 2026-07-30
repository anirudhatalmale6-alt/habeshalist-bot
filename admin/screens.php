<?php
/**
 * screens.php - manage Digital Screen Advertising screens: add/edit/pause a
 * screen, set the per-screen or default price, and get each screen's public
 * player URL (the link you point the physical TV at).
 *
 * Uses the SAME bot database as the rest of the panel (hl_db()); the screens
 * module (includes/screens.php) owns its three tables and never touches the
 * bot's own tables.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';

// Locate the screens module. The panel folder may live next to the bot folder
// (bizadmin + bot as siblings) or inside it, so we derive the bot root from the
// shared DB path (hl_bot_root) and fall back to a few known layouts.
$__screens_lib = '';
foreach ([
    (function_exists('hl_bot_root') && hl_bot_root() !== '') ? hl_bot_root() . '/includes/screens.php' : null,
    __DIR__ . '/../includes/screens.php',      // panel inside the bot folder
    __DIR__ . '/../bot/includes/screens.php',  // panel is a sibling of the bot folder
    __DIR__ . '/../../bot/includes/screens.php',
] as $__c) {
    if ($__c && is_file($__c)) { $__screens_lib = $__c; break; }
}
if ($__screens_lib === '') {
    hl_require_login();
    hl_shell_head('Digital Screens');
    echo '<div class="card"><div class="bd"><h2>Digital Screens</h2>'
       . '<p class="muted">The screens module file <code>includes/screens.php</code> was not '
       . 'found next to the bot. Upload <code>includes/screens.php</code> into the bot folder '
       . '(the same folder that has <code>webhook.php</code> and <code>data/bot.sqlite</code>), '
       . 'then reload this page.</p></div></div>';
    hl_shell_foot();
    exit;
}
require $__screens_lib;
hl_require_login();

// The bot folder that owns the module (…/bot/includes/screens.php → …/bot).
// House-ad uploads are written here, under uploads/screens/, so the public
// player (which lives in this same bot folder) serves them same-origin.
$__bot_root = dirname(dirname($__screens_lib));

$db = hl_db();
hl_screens_ensure_schema($db);

/** Absolute path to the house-ad uploads folder, creating it on first use. */
function hl_screen_upload_dir() {
    global $__bot_root;
    $dir = $__bot_root . '/uploads/screens';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

$flash = null; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $form = $_POST['form'] ?? '';

    if ($form === 'default_rate') {
        $rate = $_POST['rate'] ?? '';
        $unit = $_POST['unit'] ?? 'day';
        if (is_numeric($rate) && $rate >= 0) {
            hl_screen_set_rate($db, null, (float) $rate, $unit);
            $flash = 'Default screen price saved.';
        } else { $flash = 'Enter a valid price.'; $flashType = 'err'; }

    } elseif ($form === 'player_base') {
        hl_set_setting('screen_player_url', trim($_POST['player_base'] ?? ''));
        $flash = 'Player URL saved. Each screen link below now uses it.';

    } elseif ($form === 'add_screen') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $flash = 'Give the screen a name.'; $flashType = 'err'; }
        else {
            $id = hl_screen_create($db, [
                'name'                => $name,
                'location'            => $_POST['location'] ?? '',
                'orientation'         => $_POST['orientation'] ?? 'portrait',
                'resolution'          => $_POST['resolution'] ?? '1080x1920',
                'dwell_seconds'       => $_POST['dwell_seconds'] ?? 10,
                'interactive'         => isset($_POST['interactive']) ? 1 : 0,
                'website_url'         => $_POST['website_url'] ?? '',
                'attract_seconds'     => $_POST['attract_seconds'] ?? 180,
                'idle_return_seconds' => $_POST['idle_return_seconds'] ?? 60,
            ]);
            if (isset($_POST['rate']) && is_numeric($_POST['rate']) && $_POST['rate'] !== '') {
                hl_screen_set_rate($db, $id, (float) $_POST['rate'], $_POST['unit'] ?? 'day');
            }
            $flash = 'Screen added. Point the TV at its player link below.';
        }

    } elseif ($form === 'edit_screen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && hl_screen_by_id($db, $id)) {
            hl_screen_update($db, $id, [
                'name'                => $_POST['name'] ?? '',
                'location'            => $_POST['location'] ?? '',
                'orientation'         => $_POST['orientation'] ?? 'portrait',
                'resolution'          => $_POST['resolution'] ?? '1080x1920',
                'dwell_seconds'       => $_POST['dwell_seconds'] ?? 10,
                'status'              => $_POST['status'] ?? 'active',
                'interactive'         => isset($_POST['interactive']) ? 1 : 0,
                'website_url'         => $_POST['website_url'] ?? '',
                'attract_seconds'     => $_POST['attract_seconds'] ?? 180,
                'idle_return_seconds' => $_POST['idle_return_seconds'] ?? 60,
            ]);
            if (isset($_POST['rate']) && $_POST['rate'] !== '' && is_numeric($_POST['rate'])) {
                hl_screen_set_rate($db, $id, (float) $_POST['rate'], $_POST['unit'] ?? 'day');
            }
            $flash = 'Screen updated.';
        }

    } elseif ($form === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $s = $id ? hl_screen_by_id($db, $id) : null;
        if ($s) {
            $new = ($s['status'] === 'active') ? 'paused' : 'active';
            $s['status'] = $new;
            hl_screen_update($db, $id, $s);
            $flash = 'Screen ' . ($new === 'active' ? 'activated' : 'paused') . '.';
        }

    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $n = 0;
        $st = $db->prepare("SELECT COUNT(*) AS n FROM screen_bookings WHERE screen_id = :id");
        $st->bindValue(':id', $id, SQLITE3_INTEGER);
        $r = $st->execute(); $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : ['n' => 0];
        $n = (int) ($row['n'] ?? 0);
        if ($n > 0) {
            $flash = 'That screen has ' . $n . ' booking(s). Pause it instead of deleting so ad history is kept.';
            $flashType = 'err';
        } else {
            $db->exec("DELETE FROM screen_pricing WHERE screen_id = " . $id);
            $st = $db->prepare("DELETE FROM screens WHERE id = :id");
            $st->bindValue(':id', $id, SQLITE3_INTEGER);
            $st->execute();
            $flash = 'Screen deleted.';
        }

    } elseif ($form === 'add_ad') {
        // Put the owner's own image/video straight onto a screen ("house ad").
        // Accepts either an uploaded file or a media URL. Stored paid+approved so
        // it is live right away - this is how the player finally shows something.
        $sid = (int) ($_POST['screen_id'] ?? 0);
        $sc  = $sid ? hl_screen_by_id($db, $sid) : null;
        if (!$sc) { $flash = 'Unknown screen.'; $flashType = 'err'; }
        else {
            $dwell = max(3, (int) ($_POST['dwell'] ?? $sc['dwell_seconds'] ?? 10));
            $path = ''; $type = '';
            $allowed = array_merge(HL_SCREEN_IMAGE_EXT, HL_SCREEN_VIDEO_EXT);

            if (!empty($_FILES['adfile']['name']) && ($_FILES['adfile']['error'] ?? 4) === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['adfile']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $flash = 'That file type is not supported. Use JPG, PNG, WEBP, GIF or MP4/WEBM/MOV.'; $flashType = 'err';
                } elseif (($_FILES['adfile']['size'] ?? 0) > 60 * 1024 * 1024) {
                    $flash = 'That file is over 60 MB. Please upload a smaller image or a compressed video.'; $flashType = 'err';
                } else {
                    $dir = hl_screen_upload_dir();
                    $fname = bin2hex(random_bytes(8)) . '.' . $ext;
                    if (@move_uploaded_file($_FILES['adfile']['tmp_name'], $dir . '/' . $fname)) {
                        // Relative to the bot folder, where player.php also lives, so
                        // the browser resolves it against the player's own URL.
                        $path = 'uploads/screens/' . $fname;
                        $type = in_array($ext, HL_SCREEN_VIDEO_EXT, true) ? 'video' : 'image';
                    } else {
                        $flash = 'Could not save the upload. Make sure the bot folder is writable, or paste a media URL instead.'; $flashType = 'err';
                    }
                }
            } elseif (trim($_POST['media_url'] ?? '') !== '') {
                $path = trim($_POST['media_url']);
                $type = hl_screen_media_type($path);
            } else {
                $flash = 'Choose a file to upload or paste an image/video URL.'; $flashType = 'err';
            }

            if ($path !== '' && $flashType !== 'err') {
                $start = date('Y-m-d', time() - 2 * 86400);   // backdate so it is live regardless of screen timezone
                $end   = date('Y-m-d', time() + 730 * 86400); // ~2 years
                $label = trim($_POST['ad_label'] ?? '') ?: 'House ad';
                $bid = hl_screen_add_house_ad($db, $sid, [['path' => $path, 'type' => $type, 'dwell' => $dwell]], $start, $end, $label);
                $flash = $bid ? 'Ad added - it is playing on the screen now. Open its player link to see it.' : 'Could not add the ad.';
                if (!$bid) $flashType = 'err';
            }
        }

    } elseif ($form === 'del_ad') {
        $bid = (int) ($_POST['booking_id'] ?? 0);
        if ($bid) {
            $media = hl_screen_delete_booking($db, $bid);
            // Remove any local upload files this booking owned (never touch URLs).
            foreach ($media as $m) {
                $p = (string) ($m['path'] ?? '');
                if (strpos($p, 'uploads/screens/') === 0) {
                    $abs = $__bot_root . '/' . $p;
                    if (is_file($abs)) @unlink($abs);
                }
            }
            $flash = 'Ad removed.';
        }

    } elseif ($form === 'booking_toggle') {
        $on = isset($_POST['enabled']) ? '1' : '0';
        hl_set_setting('screens_booking_enabled', $on);
        $flash = $on === '1'
            ? 'Customer booking is ON - people can book screens from the bot.'
            : 'Customer booking is paused - the bot won\'t take new screen bookings.';

    } elseif ($form === 'approve_booking' || $form === 'reject_booking') {
        // Web mirror of the Telegram approve/reject buttons.
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $b = $bid ? hl_screen_booking_by_id($db, $bid) : null;
        if (!$b) { $flash = 'That booking could not be found.'; $flashType = 'err'; }
        elseif (($b['status'] ?? '') !== 'pending') {
            $flash = 'That booking was already handled (status: ' . h($b['status']) . ').'; $flashType = 'err';
        } else {
            $bname = $b['business_name'] ?: 'the ad';
            if ($form === 'approve_booking') {
                hl_screen_set_booking_status($db, $bid, 'approved', 'paid');
                $userText = "\xF0\x9F\x8E\x89 <b>Great news!</b> Your screen ad for <b>" . htmlspecialchars($bname, ENT_QUOTES)
                          . "</b> has been approved. It will show on the screen for your booked dates. Thank you!";
                $flash = 'Approved: ' . $bname . '.';
            } else {
                hl_screen_set_booking_status($db, $bid, 'rejected');
                $userText = "Regarding your screen ad for <b>" . htmlspecialchars($bname, ENT_QUOTES)
                          . "</b> - unfortunately it wasn't approved this time. Please contact support if you have questions or would like to resubmit.";
                $flash = 'Rejected: ' . $bname . '.';
            }
            // Best-effort Telegram notice to the customer.
            $tid = (int) ($b['telegram_id'] ?? 0);
            if ($tid && function_exists('hl_effective_secret') && function_exists('hl_tg_api')) {
                $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
                if ($token !== '') {
                    $r = hl_tg_api($token, 'sendMessage', ['chat_id' => $tid, 'text' => $userText, 'parse_mode' => 'HTML']);
                    if (empty($r['ok'])) $flash .= ' (saved; Telegram notice failed: ' . h($r['description'] ?? 'unknown') . ')';
                } else {
                    $flash .= ' (saved; bot token not available here, so the customer was not messaged)';
                }
            }
        }
    }
}

$screens   = hl_screens_all($db);
$defRate   = hl_screen_rate($db, -1); // -1 has no own row -> returns the global default (or null)
$playerBase = hl_get_setting('screen_player_url', '');
$editId    = (int) ($_GET['edit'] ?? 0);
$editing   = $editId ? hl_screen_by_id($db, $editId) : null;
$csrf      = h(hl_csrf_token());
$pendingBookings = function_exists('hl_screen_pending_bookings') ? hl_screen_pending_bookings($db) : [];
$bookingOn = hl_get_setting('screens_booking_enabled', '1') === '1';

function screen_player_link($base, $slug) {
    $base = trim($base);
    if ($base === '') return '';
    $sep = (strpos($base, '?') !== false) ? '&' : '?';
    return $base . $sep . 's=' . $slug;
}

/** Public URL for a media item so the admin can preview it. Absolute URLs pass
 *  through; a relative upload path is resolved against the player's folder. */
function screen_media_url($base, $path) {
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) return $path;
    $base = trim($base);
    if ($base === '') return $path; // still valid relative to player.php on the screen
    return preg_replace('#/[^/]*$#', '/', $base) . $path;
}

hl_shell_head('Digital Screens', 'screens', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<?php if ($pendingBookings): ?>
<div class="card" style="border:1px solid var(--accent, #3fb950)">
  <div class="hd"><h2>🔔 Screen bookings awaiting approval (<?= count($pendingBookings) ?>)</h2></div>
  <p class="sub" style="margin:0 0 10px">Customers booked and paid from the Telegram bot. Approve to put the ad live on the screen for its dates, or reject.</p>
  <div class="tblwrap"><table>
    <thead><tr><th>Ad</th><th>Screen</th><th>Dates</th><th>Total</th><th>Payment</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pendingBookings as $b):
        $item = $b['media_items'][0] ?? null;
        $murl = $item ? screen_media_url($playerBase, $item['path'] ?? '') : '';
        $mtype = $item['type'] ?? 'image';
        $payLabel = $b['payment_status'] === 'paid' ? 'Paid (card)' : ($b['payment_status'] === 'awaiting_verification' ? 'Verify screenshot' : $b['payment_status']); ?>
      <tr>
        <td style="display:flex;align-items:center;gap:10px">
          <?php if ($murl && $mtype === 'image'): ?>
            <img src="<?= h($murl) ?>" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
          <?php elseif ($murl): ?>
            <a class="pill mut" href="<?= h($murl) ?>" target="_blank" rel="noopener">Video</a>
          <?php endif; ?>
          <span><b><?= h($b['business_name'] ?: 'Ad') ?></b><br><span class="muted small">ref <?= h($b['payment_ref'] ?: '-') ?></span></span>
        </td>
        <td><?= h($b['screen_name'] ?: ('#' . $b['screen_id'])) ?><?php if (!empty($b['screen_location'])): ?><div class="muted small"><?= h($b['screen_location']) ?></div><?php endif; ?></td>
        <td class="mono small"><?= h($b['start_date']) ?> &rarr; <?= h($b['end_date']) ?></td>
        <td><?= h(hl_money((float) $b['price'])) ?></td>
        <td><span class="pill <?= $b['payment_status'] === 'paid' ? 'ok' : 'mut' ?>"><?= h($payLabel) ?></span></td>
        <td class="actions">
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="approve_booking">
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <button class="btn sm" type="submit">Approve</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Reject this booking?');">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="reject_booking">
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <button class="btn red sm" type="submit">Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="hd"><h2>How this works</h2></div>
  <p class="sub" style="margin:0">
    Add each physical screen once, set a price, then point that screen's browser at its
    player link. The screen shows a rotating loop of the currently-live ads for that screen and
    re-checks every 60 seconds - so when you approve a new ad it appears automatically, no need to
    touch the TV. Ads reach a screen only after they are paid and approved.
  </p>
</div>

<div class="grid2">
  <div class="card">
    <div class="hd"><h2>Default price</h2></div>
    <p class="sub">Used for any screen without its own price. You can override per screen below.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="form" value="default_rate">
      <div class="row">
        <div class="field" style="max-width:150px">
          <label>Price</label>
          <div class="prefix"><span class="sym">$</span>
            <input type="number" name="rate" min="0" step="0.01"
                   value="<?= $defRate ? h($defRate['rate']) : '' ?>" placeholder="e.g. 25">
          </div>
        </div>
        <div class="field" style="max-width:130px">
          <label>Per</label>
          <select name="unit" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px">
            <option value="day"  <?= ($defRate['unit'] ?? 'day') === 'day' ? 'selected' : '' ?>>Day</option>
            <option value="week" <?= ($defRate['unit'] ?? '') === 'week' ? 'selected' : '' ?>>Week</option>
          </select>
        </div>
      </div>
      <button type="submit">Save default price</button>
    </form>
  </div>

  <div class="card">
    <div class="hd"><h2>Player URL base</h2></div>
    <p class="sub">Where you uploaded player.php (public web address). Each screen link is built from this.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="form" value="player_base">
      <div class="row">
        <div class="field">
          <label>Full URL to player.php</label>
          <input type="text" name="player_base" value="<?= h($playerBase) ?>"
                 placeholder="https://habeshalist.com/bot/player.php">
        </div>
      </div>
      <button type="submit">Save</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="hd"><h2>Customer booking from the bot</h2></div>
  <p class="sub" style="margin:0 0 10px">When ON, customers can book and pay for a screen right inside the Telegram bot (Advertise on a Screen). Their booking lands in the approval queue above. Turn OFF to pause new bookings without affecting anything already live.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="booking_toggle">
    <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-weight:600">
      <input type="checkbox" name="enabled" value="1" style="width:17px;height:17px" <?= $bookingOn ? 'checked' : '' ?>>
      Allow customers to book screens from the bot
    </label>
    <button type="submit" style="margin-top:12px">Save</button>
  </form>
</div>

<div class="card">
  <div class="hd"><h2><?= $editing ? 'Edit screen' : 'Add a screen' ?></h2>
    <?php if ($editing): ?><a class="btn ghost sm" href="screens.php">Cancel edit</a><?php endif; ?></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="<?= $editing ? 'edit_screen' : 'add_screen' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <div class="field"><label>Name</label>
        <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" placeholder="e.g. Cafe Lalibela - Main Screen"></div>
      <div class="field"><label>Location</label>
        <input type="text" name="location" value="<?= h($editing['location'] ?? '') ?>" placeholder="e.g. Washington DC"></div>
    </div>
    <div class="row">
      <div class="field" style="max-width:170px"><label>Orientation</label>
        <select name="orientation" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px">
          <option value="portrait"  <?= ($editing['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' ?>>Portrait</option>
          <option value="landscape" <?= ($editing['orientation'] ?? '') === 'landscape' ? 'selected' : '' ?>>Landscape</option>
        </select></div>
      <div class="field" style="max-width:150px"><label>Resolution</label>
        <input type="text" name="resolution" value="<?= h($editing['resolution'] ?? '1080x1920') ?>" placeholder="1080x1920"></div>
      <div class="field" style="max-width:150px"><label>Image seconds</label>
        <input type="number" name="dwell_seconds" min="3" value="<?= h($editing['dwell_seconds'] ?? 10) ?>"></div>
    </div>
    <div class="row">
      <div class="field" style="max-width:150px"><label>Price (optional)</label>
        <div class="prefix"><span class="sym">$</span>
          <?php $er = $editing ? hl_screen_rate($db, $editing['id']) : null; ?>
          <input type="number" name="rate" min="0" step="0.01" value="<?= $er ? h($er['rate']) : '' ?>" placeholder="uses default"></div></div>
      <div class="field" style="max-width:130px"><label>Per</label>
        <select name="unit" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px">
          <option value="day"  <?= ($er['unit'] ?? 'day') === 'day' ? 'selected' : '' ?>>Day</option>
          <option value="week" <?= ($er['unit'] ?? '') === 'week' ? 'selected' : '' ?>>Week</option>
        </select></div>
      <?php if ($editing): ?>
      <div class="field" style="max-width:150px"><label>Status</label>
        <select name="status" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="paused" <?= ($editing['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
        </select></div>
      <?php endif; ?>
    </div>

    <div style="margin:18px 0 6px;padding-top:14px;border-top:1px solid var(--line)">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-weight:600">
        <input type="checkbox" name="interactive" value="1" style="width:17px;height:17px"
          <?= (!$editing || !empty($editing['interactive'])) ? 'checked' : '' ?>>
        Interactive touchscreen mode
      </label>
      <div class="muted small" style="margin:4px 0 0 26px">
        The screen runs the ad loop, then periodically opens the HabeshaList website
        so visitors can tap to browse posts, events and videos with sound. It returns
        to the ads on its own after a period of no touching.
      </div>
    </div>
    <div class="row">
      <div class="field"><label>Website to open (optional)</label>
        <input type="text" name="website_url" value="<?= h($editing['website_url'] ?? '') ?>" placeholder="blank = habeshalist.com"></div>
      <div class="field" style="max-width:180px"><label>Open site after (seconds)</label>
        <input type="number" name="attract_seconds" min="15" value="<?= h($editing['attract_seconds'] ?? 180) ?>"></div>
      <div class="field" style="max-width:180px"><label>Return to ads after idle (seconds)</label>
        <input type="number" name="idle_return_seconds" min="10" value="<?= h($editing['idle_return_seconds'] ?? 60) ?>"></div>
    </div>

    <button type="submit"><?= $editing ? 'Save changes' : 'Add screen' ?></button>
  </form>
</div>

<?php if ($editing):
    $ads = hl_screen_bookings($db, $editing['id']);
    $adLink = screen_player_link($playerBase, $editing['slug']); ?>
<div class="card">
  <div class="hd"><h2>Content on this screen</h2></div>
  <p class="sub" style="margin:0 0 4px">
    Put your own image or short video on <b><?= h($editing['name']) ?></b> right now (a promo, menu, or
    welcome slide). It starts playing immediately and rotates with any paid ads. This is how you get
    something on the screen today - the advertiser self-booking flow comes in the next milestone.
  </p>

  <form method="post" enctype="multipart/form-data" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="add_ad">
    <input type="hidden" name="screen_id" value="<?= (int) $editing['id'] ?>">
    <div class="row">
      <div class="field"><label>Upload image or video</label>
        <input type="file" name="adfile" accept="image/*,video/*"
               style="width:100%;padding:8px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text)"></div>
      <div class="field" style="max-width:150px"><label>Show for (seconds)</label>
        <input type="number" name="dwell" min="3" value="<?= h($editing['dwell_seconds'] ?? 10) ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label>...or paste a media URL</label>
        <input type="text" name="media_url" placeholder="https://... .jpg / .png / .mp4"></div>
      <div class="field"><label>Label (optional)</label>
        <input type="text" name="ad_label" placeholder="e.g. Weekend special"></div>
    </div>
    <div class="muted small" style="margin:2px 0 12px">Images: JPG, PNG, WEBP, GIF. Video: MP4, WEBM, MOV (kept muted in the ad loop). Max 60 MB.</div>
    <button type="submit">Add to screen</button>
  </form>

  <?php if ($ads): ?>
  <div class="tblwrap" style="margin-top:16px"><table>
    <thead><tr><th>Preview</th><th>Details</th><th>Dates</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ads as $b):
        $item = $b['media_items'][0] ?? null;
        $murl = $item ? screen_media_url($playerBase, $item['path'] ?? '') : '';
        $mtype = $item['type'] ?? 'image'; ?>
      <tr>
        <td>
          <?php if ($murl && $mtype === 'image'): ?>
            <img src="<?= h($murl) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
          <?php elseif ($murl): ?>
            <span class="pill mut">Video</span>
          <?php else: ?>
            <span class="muted small">-</span>
          <?php endif; ?>
        </td>
        <td>
          <b><?= h($b['business_name'] ?: 'Ad') ?></b>
          <div class="muted small"><?= count($b['media_items']) ?> item(s) &middot; <?= h($mtype) ?>
            <?php if ($murl): ?>&middot; <a class="small" href="<?= h($murl) ?>" target="_blank" rel="noopener">open</a><?php endif; ?></div>
        </td>
        <td class="mono small"><?= h($b['start_date']) ?> &rarr; <?= h($b['end_date']) ?></td>
        <td>
          <?php $live = ($b['status'] === 'approved' || $b['status'] === 'live') && $b['payment_status'] === 'paid'; ?>
          <?php if ($live): ?><span class="pill ok">Live</span><?php else: ?><span class="pill mut"><?= h(ucfirst($b['status'])) ?></span><?php endif; ?>
        </td>
        <td class="actions">
          <form method="post" style="display:inline" onsubmit="return confirm('Remove this ad from the screen?');">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="del_ad">
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <button class="btn red sm" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <div class="empty" style="margin-top:14px">Nothing on this screen yet - add an image or video above and it plays instantly.</div>
  <?php endif; ?>

  <?php if ($adLink): ?>
    <p class="sub" style="margin:12px 0 0">Screen link: <a class="mono small" href="<?= h($adLink) ?>" target="_blank" rel="noopener">Open player</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="hd"><h2>Screens<?= $screens ? ' (' . count($screens) . ')' : '' ?></h2></div>
  <?php if (!$screens): ?>
    <div class="empty">No screens yet. Add your first one above.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Screen</th><th>Price</th><th>Status</th><th>Player link</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($screens as $s):
        $rate = hl_screen_rate($db, $s['id']);
        $link = screen_player_link($playerBase, $s['slug']); ?>
      <tr>
        <td>
          <b><?= h($s['name']) ?></b>
          <div class="muted small"><?= h($s['location'] ?: '-') ?> &middot; <?= h(ucfirst($s['orientation'])) ?> &middot; <?= h($s['resolution']) ?></div>
        </td>
        <td><?= $rate ? h(hl_money($rate['rate'])) . '<span class="muted small">/' . h($rate['unit']) . '</span>' : '<span class="muted">default</span>' ?></td>
        <td><?php if ($s['status'] === 'active'): ?><span class="pill ok">Active</span><?php else: ?><span class="pill mut">Paused</span><?php endif; ?></td>
        <td>
          <?php if ($link): ?>
            <a class="mono small" href="<?= h($link) ?>" target="_blank" rel="noopener"><?= h($s['slug']) ?></a>
            &nbsp;<a class="small" href="<?= h($link) ?>" target="_blank" rel="noopener">Preview</a>
          <?php else: ?>
            <span class="muted small mono">?s=<?= h($s['slug']) ?></span>
            <div class="muted small">set Player URL base above</div>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a class="btn ghost sm" href="screens.php?edit=<?= (int) $s['id'] ?>">Edit</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn ghost sm" type="submit"><?= $s['status'] === 'active' ? 'Pause' : 'Activate' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this screen?');">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="delete">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn red sm" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php hl_shell_foot();
