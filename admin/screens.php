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
require __DIR__ . '/../includes/screens.php';
hl_require_login();

$db = hl_db();
hl_screens_ensure_schema($db);

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
                'name'          => $name,
                'location'      => $_POST['location'] ?? '',
                'orientation'   => $_POST['orientation'] ?? 'landscape',
                'resolution'    => $_POST['resolution'] ?? '1920x1080',
                'dwell_seconds' => $_POST['dwell_seconds'] ?? 10,
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
                'name'          => $_POST['name'] ?? '',
                'location'      => $_POST['location'] ?? '',
                'orientation'   => $_POST['orientation'] ?? 'landscape',
                'resolution'    => $_POST['resolution'] ?? '1920x1080',
                'dwell_seconds' => $_POST['dwell_seconds'] ?? 10,
                'status'        => $_POST['status'] ?? 'active',
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
    }
}

$screens   = hl_screens_all($db);
$defRate   = hl_screen_rate($db, -1); // -1 has no own row -> returns the global default (or null)
$playerBase = hl_get_setting('screen_player_url', '');
$editId    = (int) ($_GET['edit'] ?? 0);
$editing   = $editId ? hl_screen_by_id($db, $editId) : null;
$csrf      = h(hl_csrf_token());

function screen_player_link($base, $slug) {
    $base = trim($base);
    if ($base === '') return '';
    $sep = (strpos($base, '?') !== false) ? '&' : '?';
    return $base . $sep . 's=' . $slug;
}

hl_shell_head('Digital Screens', 'screens', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

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
          <option value="landscape" <?= ($editing['orientation'] ?? 'landscape') === 'landscape' ? 'selected' : '' ?>>Landscape</option>
          <option value="portrait"  <?= ($editing['orientation'] ?? '') === 'portrait' ? 'selected' : '' ?>>Portrait</option>
        </select></div>
      <div class="field" style="max-width:150px"><label>Resolution</label>
        <input type="text" name="resolution" value="<?= h($editing['resolution'] ?? '1920x1080') ?>" placeholder="1920x1080"></div>
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
    <button type="submit"><?= $editing ? 'Save changes' : 'Add screen' ?></button>
  </form>
</div>

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
