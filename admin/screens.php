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

/** Collect the screen add/edit fields from POST into the shape the data layer wants. */
function screen_fields_from_post(array $p) {
    return [
        'name'                => $p['name'] ?? '',
        'business_name'       => $p['business_name'] ?? '',
        'address'             => $p['address'] ?? '',
        'city'                => $p['city'] ?? '',
        'state'               => $p['state'] ?? '',
        'zip'                 => $p['zip'] ?? '',
        'screen_size'         => $p['screen_size'] ?? '',
        'orientation'         => $p['orientation'] ?? 'portrait',
        'resolution'          => $p['resolution'] ?? '1080x1920',
        'dwell_seconds'       => $p['dwell_seconds'] ?? 10,
        'max_ads'             => $p['max_ads'] ?? 0,
        'ad_audio'            => isset($p['ad_audio']) ? 1 : 0,
        'kiosk_lock'          => isset($p['kiosk_lock']) ? 1 : 0,
        'interactive'         => isset($p['interactive']) ? 1 : 0,
        'website_url'         => $p['website_url'] ?? '',
        'attract_seconds'     => $p['attract_seconds'] ?? 180,
        'idle_return_seconds' => $p['idle_return_seconds'] ?? 60,
    ];
}

/**
 * Store an uploaded media file (or accept a pasted URL) for a screen ad. Returns
 * ['path'=>rel, 'type'=>'image'|'video'] on success or ['error'=>msg] on failure.
 * Shared by the house-ad and the booking-media-edit forms.
 */
function screen_store_upload($fileKey = 'adfile', $urlKey = 'media_url') {
    $allowed = array_merge(HL_SCREEN_IMAGE_EXT, HL_SCREEN_VIDEO_EXT);
    if (!empty($_FILES[$fileKey]['name']) && ($_FILES[$fileKey]['error'] ?? 4) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['error' => 'That file type is not supported. Use JPG, PNG, WEBP, GIF or MP4/WEBM/MOV.'];
        }
        if (($_FILES[$fileKey]['size'] ?? 0) > 60 * 1024 * 1024) {
            return ['error' => 'That file is over 60 MB. Please upload a smaller image or a compressed video.'];
        }
        $dir = hl_screen_upload_dir();
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        if (@move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dir . '/' . $fname)) {
            return ['path' => 'uploads/screens/' . $fname,
                    'type' => in_array($ext, HL_SCREEN_VIDEO_EXT, true) ? 'video' : 'image'];
        }
        return ['error' => 'Could not save the upload. Make sure the bot folder is writable, or paste a media URL instead.'];
    }
    if (trim($_POST[$urlKey] ?? '') !== '') {
        $p = trim($_POST[$urlKey]);
        return ['path' => $p, 'type' => hl_screen_media_type($p)];
    }
    return ['error' => 'Choose a file to upload or paste an image/video URL.'];
}

/** "just now" / "5 min ago" / "3 hours ago" from a UTC 'Y-m-d H:i:s' heartbeat. */
function screen_last_seen_human($lastSeen) {
    $lastSeen = trim((string) $lastSeen);
    if ($lastSeen === '') return ['never', false];
    $t = strtotime($lastSeen . ' UTC');
    if (!$t) return ['unknown', false];
    $secs = time() - $t;
    $online = $secs <= 180;                 // player re-pulls ~every 20-60s; 3 min = healthy
    if ($secs < 60)      $txt = 'just now';
    elseif ($secs < 3600) $txt = floor($secs / 60) . ' min ago';
    elseif ($secs < 86400) $txt = floor($secs / 3600) . ' hr ago';
    else                 $txt = floor($secs / 86400) . ' day(s) ago';
    return [$txt, $online];
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
            $id = hl_screen_create($db, screen_fields_from_post($_POST));
            // Every screen carries its own price now; default to $5/day when blank.
            $rate = (isset($_POST['rate']) && is_numeric($_POST['rate']) && $_POST['rate'] !== '')
                ? (float) $_POST['rate'] : HL_SCREEN_DEFAULT_RATE;
            hl_screen_set_rate($db, $id, $rate, $_POST['unit'] ?? 'day');
            $flash = 'Screen added. Point the TV at its player link below.';
        }

    } elseif ($form === 'edit_screen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && hl_screen_by_id($db, $id)) {
            $d = screen_fields_from_post($_POST);
            $d['status'] = $_POST['status'] ?? 'active';
            hl_screen_update($db, $id, $d);
            $rate = (isset($_POST['rate']) && is_numeric($_POST['rate']) && $_POST['rate'] !== '')
                ? (float) $_POST['rate'] : HL_SCREEN_DEFAULT_RATE;
            hl_screen_set_rate($db, $id, $rate, $_POST['unit'] ?? 'day');
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

    } elseif ($form === 'edit_booking_dates') {
        // Admin edits a booking's scheduled dates directly from the panel.
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $b = $bid ? hl_screen_booking_by_id($db, $bid) : null;
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date'] ?? '');
        $okDate = function ($x) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $x); };
        if (!$b) { $flash = 'That booking could not be found.'; $flashType = 'err'; }
        elseif (!$okDate($start) || !$okDate($end) || $end < $start) {
            $flash = 'Enter a valid start and end date (end on or after start).'; $flashType = 'err';
        } else {
            hl_screen_update_booking_dates($db, $bid, $start, $end);
            $flash = 'Booking dates updated.';
        }

    } elseif ($form === 'add_booking_media') {
        // Admin adds an image/video to an existing booking.
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $b = $bid ? hl_screen_booking_by_id($db, $bid) : null;
        if (!$b) { $flash = 'That booking could not be found.'; $flashType = 'err'; }
        else {
            $up = screen_store_upload();
            if (isset($up['error'])) { $flash = $up['error']; $flashType = 'err'; }
            else {
                $media = json_decode((string) $b['media'], true) ?: [];
                $dwell = max(3, (int) ($_POST['dwell'] ?? 10));
                $media[] = ['path' => $up['path'], 'type' => $up['type'], 'dwell' => $dwell];
                hl_screen_set_booking_media($db, $bid, $media);
                $flash = 'Media added to the booking.';
            }
        }

    } elseif ($form === 'del_booking_media') {
        // Admin removes one media item (by index) from a booking.
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $idx = (int) ($_POST['idx'] ?? -1);
        $b = $bid ? hl_screen_booking_by_id($db, $bid) : null;
        if (!$b) { $flash = 'That booking could not be found.'; $flashType = 'err'; }
        else {
            $media = json_decode((string) $b['media'], true) ?: [];
            if (isset($media[$idx])) {
                $gone = $media[$idx];
                array_splice($media, $idx, 1);
                hl_screen_set_booking_media($db, $bid, $media);
                // Clean up the local file if nothing else references it.
                $p = (string) ($gone['path'] ?? '');
                if (strpos($p, 'uploads/screens/') === 0) {
                    $stillUsed = false;
                    foreach ($media as $m) { if (($m['path'] ?? '') === $p) { $stillUsed = true; break; } }
                    if (!$stillUsed) { $abs = $__bot_root . '/' . $p; if (is_file($abs)) @unlink($abs); }
                }
                $flash = 'Media removed from the booking.';
            } else { $flash = 'That media item no longer exists.'; $flashType = 'err'; }
        }
    }
}

$screens   = hl_screens_all($db);
$playerBase = hl_get_setting('screen_player_url', '');
$editId    = (int) ($_GET['edit'] ?? 0);
$editing   = $editId ? hl_screen_by_id($db, $editId) : null;
$csrf      = h(hl_csrf_token());
$pendingBookings = function_exists('hl_screen_pending_bookings') ? hl_screen_pending_bookings($db) : [];
$bookingOn = hl_get_setting('screens_booking_enabled', '1') === '1';

// Booking editor (edit dates + media) opened via ?editbooking=<id>.
$editBookingId = (int) ($_GET['editbooking'] ?? 0);
$editBooking   = $editBookingId ? hl_screen_booking_by_id($db, $editBookingId) : null;

// "Today" in the screens timezone, for the live-ad counts + fully-booked badges.
$screensTz = hl_get_setting('sched_tz', 'America/New_York') ?: 'America/New_York';
try { $screensToday = (new DateTime('now', new DateTimeZone($screensTz)))->format('Y-m-d'); }
catch (\Throwable $e) { $screensToday = date('Y-m-d'); }

/** Unit label helper: day/week/month/year -> "/day" style suffix. */
function screen_unit_options($sel) {
    $labels = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];
    $out = '';
    foreach ($labels as $v => $lab) {
        $out .= '<option value="' . $v . '"' . ($sel === $v ? ' selected' : '') . '>' . $lab . '</option>';
    }
    return $out;
}

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

<?php if ($editBooking):
    $ebScreen = hl_screen_by_id($db, $editBooking['screen_id']);
    $ebMedia  = json_decode((string) $editBooking['media'], true) ?: []; ?>
<div class="card" style="border:1px solid var(--accent, #3fb950)">
  <div class="hd"><h2>Edit booking &middot; <?= h($editBooking['business_name'] ?: 'Ad') ?></h2>
    <a class="btn ghost sm" href="screens.php">Close</a></div>
  <p class="sub" style="margin:0 0 12px">
    Screen: <b><?= h($ebScreen['name'] ?? ('#' . $editBooking['screen_id'])) ?></b>
    &middot; Status: <b><?= h(ucfirst($editBooking['status'])) ?></b>
    &middot; Ref: <span class="mono small"><?= h($editBooking['payment_ref'] ?: '-') ?></span>
  </p>

  <h3 style="margin:6px 0">Scheduled dates</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="edit_booking_dates">
    <input type="hidden" name="booking_id" value="<?= (int) $editBooking['id'] ?>">
    <div class="row">
      <div class="field" style="max-width:190px"><label>Start date</label>
        <input type="date" name="start_date" value="<?= h($editBooking['start_date']) ?>"></div>
      <div class="field" style="max-width:190px"><label>End date</label>
        <input type="date" name="end_date" value="<?= h($editBooking['end_date']) ?>"></div>
    </div>
    <button type="submit">Save dates</button>
  </form>

  <h3 style="margin:20px 0 6px">Ad content</h3>
  <?php if ($ebMedia): ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Preview</th><th>Type</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ebMedia as $i => $mi):
        $u = screen_media_url($playerBase, $mi['path'] ?? '');
        $t = $mi['type'] ?? 'image'; ?>
      <tr>
        <td>
          <?php if ($u && $t === 'image'): ?>
            <a href="#" class="scr-prev" data-media="<?= h(json_encode([['url' => $u, 'type' => 'image']])) ?>"><img src="<?= h($u) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block"></a>
          <?php elseif ($u): ?>
            <a href="#" class="scr-prev pill mut" data-media="<?= h(json_encode([['url' => $u, 'type' => 'video']])) ?>">&#9654; Video</a>
          <?php else: ?><span class="muted small">-</span><?php endif; ?>
        </td>
        <td><?= h($t) ?></td>
        <td class="actions">
          <form method="post" style="display:inline" onsubmit="return confirm('Remove this item from the booking?');">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="del_booking_media">
            <input type="hidden" name="booking_id" value="<?= (int) $editBooking['id'] ?>">
            <input type="hidden" name="idx" value="<?= (int) $i ?>">
            <button class="btn red sm" type="submit"<?= count($ebMedia) <= 1 ? ' disabled title="A booking needs at least one item"' : '' ?>>Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <div class="empty">No media on this booking.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="add_booking_media">
    <input type="hidden" name="booking_id" value="<?= (int) $editBooking['id'] ?>">
    <div class="row">
      <div class="field"><label>Add image or video</label>
        <input type="file" name="adfile" accept="image/*,video/*"
               style="width:100%;padding:8px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text)"></div>
      <div class="field"><label>...or paste a media URL</label>
        <input type="text" name="media_url" placeholder="https://... .jpg / .mp4"></div>
      <div class="field" style="max-width:150px"><label>Show for (seconds)</label>
        <input type="number" name="dwell" min="3" value="10"></div>
    </div>
    <button type="submit">Add media</button>
  </form>
</div>
<?php endif; ?>

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
        $mediaList = [];
        foreach (($b['media_items'] ?? []) as $mi) {
            $u = screen_media_url($playerBase, $mi['path'] ?? '');
            if ($u !== '') $mediaList[] = ['url' => $u, 'type' => $mi['type'] ?? 'image'];
        }
        $mediaAttr = h(json_encode($mediaList));
        $payLabel = $b['payment_status'] === 'paid' ? 'Paid (card)' : ($b['payment_status'] === 'awaiting_verification' ? 'Verify screenshot' : $b['payment_status']); ?>
      <tr>
        <td style="display:flex;align-items:center;gap:10px">
          <?php if ($mediaList): ?>
            <a href="#" class="scr-prev" data-media="<?= $mediaAttr ?>" title="Preview ad" style="flex:0 0 auto">
              <?php if ($murl && $mtype === 'image'): ?>
                <img src="<?= h($murl) ?>" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block">
              <?php else: ?>
                <span class="pill mut">&#9654; Video</span>
              <?php endif; ?>
            </a>
          <?php endif; ?>
          <span><b><?= h($b['business_name'] ?: 'Ad') ?></b>
            <?php if (count($mediaList) > 1): ?><span class="muted small"> (<?= count($mediaList) ?> items)</span><?php endif; ?>
            <br><span class="muted small">ref <?= h($b['payment_ref'] ?: '-') ?></span></span>
        </td>
        <td><?= h($b['screen_name'] ?: ('#' . $b['screen_id'])) ?><?php if (!empty($b['screen_location'])): ?><div class="muted small"><?= h($b['screen_location']) ?></div><?php endif; ?></td>
        <td class="mono small"><?= h($b['start_date']) ?> &rarr; <?= h($b['end_date']) ?></td>
        <td><?= h(hl_money((float) $b['price'])) ?></td>
        <td><span class="pill <?= $b['payment_status'] === 'paid' ? 'ok' : 'mut' ?>"><?= h($payLabel) ?></span></td>
        <td class="actions">
          <?php if ($mediaList): ?>
            <a class="btn ghost sm scr-prev" href="#" data-media="<?= $mediaAttr ?>">Preview</a>
          <?php endif; ?>
          <a class="btn ghost sm" href="screens.php?editbooking=<?= (int) $b['id'] ?>">Edit</a>
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

<div class="card">
  <div class="hd"><h2>Player URL base</h2></div>
  <p class="sub">Where you uploaded player.php (public web address). Each screen link is built from this. Each screen sets its own price below (new screens default to $5/day).</p>
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
    <?php $selStyle = 'width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px';
          $er = $editing ? hl_screen_rate($db, $editing['id']) : null; ?>
    <div class="row">
      <div class="field"><label>Screen name</label>
        <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" placeholder="e.g. Cafe Lalibela - Main Screen"></div>
      <div class="field"><label>Business name</label>
        <input type="text" name="business_name" value="<?= h($editing['business_name'] ?? '') ?>" placeholder="e.g. Cafe Lalibela"></div>
    </div>
    <div class="row">
      <div class="field"><label>Address</label>
        <input type="text" name="address" value="<?= h($editing['address'] ?? '') ?>" placeholder="Street address"></div>
    </div>
    <div class="row">
      <div class="field"><label>City</label>
        <input type="text" name="city" value="<?= h($editing['city'] ?? '') ?>" placeholder="e.g. Washington"></div>
      <div class="field" style="max-width:150px"><label>State</label>
        <input type="text" name="state" value="<?= h($editing['state'] ?? '') ?>" placeholder="e.g. DC"></div>
      <div class="field" style="max-width:150px"><label>ZIP code</label>
        <input type="text" name="zip" value="<?= h($editing['zip'] ?? '') ?>" placeholder="e.g. 20001"></div>
      <div class="field" style="max-width:150px"><label>Screen size</label>
        <input type="text" name="screen_size" value="<?= h($editing['screen_size'] ?? '') ?>" placeholder='e.g. 55"'></div>
    </div>
    <div class="row">
      <div class="field" style="max-width:150px"><label>Default price</label>
        <div class="prefix"><span class="sym">$</span>
          <input type="number" name="rate" min="0" step="0.01" value="<?= $er ? h($er['rate']) : ($editing ? '' : '5') ?>" placeholder="5"></div></div>
      <div class="field" style="max-width:130px"><label>Per</label>
        <select name="unit" style="<?= $selStyle ?>"><?= screen_unit_options($er['unit'] ?? 'day') ?></select></div>
      <div class="field" style="max-width:170px"><label>Max ads (0 = no limit)</label>
        <input type="number" name="max_ads" min="0" value="<?= h($editing['max_ads'] ?? 0) ?>"></div>
      <?php if ($editing): ?>
      <div class="field" style="max-width:150px"><label>Status</label>
        <select name="status" style="<?= $selStyle ?>">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="paused" <?= ($editing['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
        </select></div>
      <?php endif; ?>
    </div>
    <div class="muted small" style="margin:-4px 0 4px">When Max ads is set, the screen shows <b>Fully Booked</b> and stops taking bookings for any date that reaches that many ads. 0 means unlimited.</div>
    <div class="row">
      <div class="field" style="max-width:170px"><label>Orientation</label>
        <select name="orientation" style="<?= $selStyle ?>">
          <option value="portrait"  <?= ($editing['orientation'] ?? 'portrait') === 'portrait' ? 'selected' : '' ?>>Portrait</option>
          <option value="landscape" <?= ($editing['orientation'] ?? '') === 'landscape' ? 'selected' : '' ?>>Landscape</option>
        </select></div>
      <div class="field" style="max-width:150px"><label>Resolution</label>
        <input type="text" name="resolution" value="<?= h($editing['resolution'] ?? '1080x1920') ?>" placeholder="1080x1920"></div>
      <div class="field" style="max-width:150px"><label>Image seconds</label>
        <input type="number" name="dwell_seconds" min="3" value="<?= h($editing['dwell_seconds'] ?? 10) ?>"></div>
    </div>

    <div style="margin:18px 0 6px;padding-top:14px;border-top:1px solid var(--line)">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-weight:600">
        <input type="checkbox" name="kiosk_lock" value="1" style="width:17px;height:17px"
          <?= (!$editing || !empty($editing['kiosk_lock'])) ? 'checked' : '' ?>>
        Lock the screen (kiosk mode)
      </label>
      <div class="muted small" style="margin:4px 0 8px 26px">
        Runs the player full-screen and blocks the obvious ways out (right-click, text
        select, pinch-zoom). A true tamper-proof lock also needs the device set to kiosk
        mode - see the Kiosk setup guide.
      </div>
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-weight:600">
        <input type="checkbox" name="ad_audio" value="1" style="width:17px;height:17px"
          <?= (!empty($editing['ad_audio'])) ? 'checked' : '' ?>>
        Play video ads with sound
      </label>
      <div class="muted small" style="margin:4px 0 0 26px">
        Video ads try to play with audio when the device allows it (kiosks usually do).
        If the browser blocks autoplay-with-sound, it falls back to muted automatically.
      </div>
    </div>

    <div style="margin:18px 0 6px;padding-top:14px;border-top:1px solid var(--line)">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-weight:600">
        <input type="checkbox" name="interactive" value="1" style="width:17px;height:17px"
          <?= (!$editing || !empty($editing['interactive'])) ? 'checked' : '' ?>>
        Interactive touchscreen mode
      </label>
      <div class="muted small" style="margin:4px 0 0 26px">
        The screen runs the ad loop, then periodically opens the website so visitors can
        tap to browse posts, events and videos with sound. It returns to the ads on its
        own after a period of no touching. <b>When the screen is Paused, it shows this
        website full-screen instead of a screensaver.</b>
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
    <thead><tr><th>Store</th><th>Price</th><th>Status</th><th>Ads</th><th>State</th><th>Last heartbeat</th><th>Player link</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($screens as $s):
        $rate = hl_screen_rate($db, $s['id']);
        $link = screen_player_link($playerBase, $s['slug']);
        $liveCount = hl_screen_live_count($db, $s['id'], $screensToday);
        $cap = (int) ($s['max_ads'] ?? 0);
        $isFull = $cap > 0 && hl_screen_is_full_today($db, $s['id'], $screensToday);
        [$seenTxt, $seenOnline] = screen_last_seen_human($s['last_seen'] ?? ''); ?>
      <tr>
        <td>
          <b><?= h($s['business_name'] ?: $s['name']) ?></b>
          <div class="muted small"><?= h($s['name']) ?> &middot; <?= h(ucfirst($s['orientation'])) ?><?= !empty($s['screen_size']) ? ' &middot; ' . h($s['screen_size']) : '' ?></div>
        </td>
        <td><?= $rate ? h(hl_money($rate['rate'])) . '<span class="muted small">/' . h($rate['unit']) . '</span>' : '<span class="muted">-</span>' ?></td>
        <td>
          <?php if ($s['status'] !== 'active'): ?><span class="pill mut">Paused</span>
          <?php elseif ($isFull): ?><span class="pill" style="background:#8957e5;color:#fff">Fully booked</span>
          <?php else: ?><span class="pill ok">Active</span><?php endif; ?>
        </td>
        <td><?= (int) $liveCount ?><?= $cap > 0 ? '<span class="muted small">/' . $cap . '</span>' : '' ?></td>
        <td><?= h($s['state'] ?: '-') ?></td>
        <td><span class="pill <?= $seenOnline ? 'ok' : 'mut' ?>" title="<?= h($s['last_seen'] ?? '') ?> UTC"><?= h($seenTxt) ?></span></td>
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

<div class="card">
  <div class="hd"><h2>Kiosk setup guide (lock the screen)</h2></div>
  <p class="sub" style="margin:0 0 8px">Turning on "Lock the screen" makes the player run full-screen and blocks right-click, text-selection and pinch-zoom. To make a screen truly tamper-proof so nobody can exit to the device, set the device itself to kiosk mode:</p>
  <details>
    <summary style="cursor:pointer;font-weight:600">Android TV / tablet / TV box</summary>
    <div class="muted small" style="margin:8px 0 0">
      Install a kiosk browser app (e.g. "Fully Kiosk Browser" or "WebView Kiosk"), set the Start URL to this screen's player link, and enable "Kiosk mode / Lock task". Turn on "Launch on boot" so it recovers after a power cut. This stops viewers leaving the ad app.
    </div>
  </details>
  <details style="margin-top:8px">
    <summary style="cursor:pointer;font-weight:600">Windows / mini-PC</summary>
    <div class="muted small" style="margin:8px 0 0">
      Use "Assigned Access" (Settings &rarr; Accounts &rarr; Other users &rarr; Set up a kiosk) with Microsoft Edge in kiosk/full-screen mode pointed at the player link. Or launch Chrome with <span class="mono">--kiosk --incognito</span> and the player URL.
    </div>
  </details>
  <details style="margin-top:8px">
    <summary style="cursor:pointer;font-weight:600">Video sound on the screens</summary>
    <div class="muted small" style="margin:8px 0 0">
      Turn on "Play video ads with sound" per screen above. Kiosk browsers allow autoplay-with-audio out of the box. On desktop Chrome, launch with <span class="mono">--autoplay-policy=no-user-gesture-required</span> so ad videos play with sound automatically.
    </div>
  </details>
</div>

<!-- Ad preview lightbox -->
<div id="scrLightbox" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.85);align-items:center;justify-content:center">
  <div style="position:relative;max-width:92vw;max-height:88vh;text-align:center">
    <div id="scrLbBody" style="max-width:92vw;max-height:80vh"></div>
    <div id="scrLbNav" style="margin-top:10px;color:#fff;font-size:14px"></div>
    <button id="scrLbClose" style="position:absolute;top:-14px;right:-14px;width:36px;height:36px;border-radius:50%;border:0;background:#fff;color:#111;font-size:20px;cursor:pointer">&times;</button>
  </div>
</div>
<script>
(function () {
  var box  = document.getElementById('scrLightbox');
  var body = document.getElementById('scrLbBody');
  var nav  = document.getElementById('scrLbNav');
  var items = [], pos = 0;
  function render() {
    if (!items.length) return;
    var it = items[pos]; body.innerHTML = '';
    var el;
    if (it.type === 'video') {
      el = document.createElement('video');
      el.src = it.url; el.controls = true; el.autoplay = true; el.style.maxWidth = '92vw'; el.style.maxHeight = '80vh';
    } else {
      el = document.createElement('img');
      el.src = it.url; el.style.maxWidth = '92vw'; el.style.maxHeight = '80vh'; el.style.borderRadius = '10px';
    }
    body.appendChild(el);
    nav.textContent = items.length > 1 ? ('Item ' + (pos + 1) + ' of ' + items.length + '  -  tap to see next') : '';
  }
  function open(list) { items = list || []; pos = 0; if (!items.length) return; box.style.display = 'flex'; render(); }
  function close() { box.style.display = 'none'; body.innerHTML = ''; }
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.scr-prev') : null;
    if (t) {
      e.preventDefault();
      try { open(JSON.parse(t.getAttribute('data-media') || '[]')); } catch (err) {}
    }
  });
  document.getElementById('scrLbClose').addEventListener('click', function (e) { e.stopPropagation(); close(); });
  box.addEventListener('click', function (e) {
    if (e.target === box) { close(); return; }
    if (items.length > 1 && e.target.tagName !== 'VIDEO') { pos = (pos + 1) % items.length; render(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>

<?php hl_shell_foot();
