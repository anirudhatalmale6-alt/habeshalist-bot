<?php
/**
 * scheduled.php - upcoming (and recently posted) scheduled posts.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

// ---------------------------------------------------------------------------
// Cleanup actions (handy while testing): wipe scheduled posts in one click so
// you can re-run an approval from a clean slate. "Reset test data" also cancels
// every active/pending promotion and zeroes its used-posts counter, so an
// exclusive plan (like Business of the Week) is no longer held by a leftover
// test ad and a fresh one can book its full run again.
// ---------------------------------------------------------------------------
$flash = null; $flashType = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    hl_csrf_check();
    $action = $_POST['action'] ?? '';
    $db = hl_db();
    if ($action === 'delete_upcoming') {
        $n = 0;
        $r = $db->querySingle("SELECT COUNT(*) FROM scheduled_posts WHERE status='scheduled'");
        $n = (int) $r;
        $db->exec("DELETE FROM scheduled_posts WHERE status='scheduled'");
        $flash = "Deleted {$n} upcoming scheduled post" . ($n === 1 ? '' : 's') . '.';
    } elseif ($action === 'reset_tests') {
        $posts = (int) $db->querySingle("SELECT COUNT(*) FROM scheduled_posts");
        $promos = (int) $db->querySingle("SELECT COUNT(*) FROM promotions WHERE status IN ('approved','pending_review')");
        $db->exec("DELETE FROM scheduled_posts");
        $db->exec("UPDATE promotions SET status='canceled', posts_used=0 WHERE status IN ('approved','pending_review')");
        $flash = "Test data reset: removed {$posts} scheduled post" . ($posts === 1 ? '' : 's')
               . " and cancelled {$promos} active/pending promotion" . ($promos === 1 ? '' : 's')
               . '. You can now submit and approve a fresh ad from a clean slate.';
    } elseif ($action === 'delete_one') {
        $sid = (int) ($_POST['sched_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $db->prepare("DELETE FROM scheduled_posts WHERE id=:id");
            $stmt->bindValue(':id', $sid, SQLITE3_INTEGER);
            $stmt->execute();
            $flash = 'Scheduled post removed.';
        }
    }
}
$csrf = h(hl_csrf_token());

// Order by the effective posting time: the user-chosen post_time if present,
// otherwise the named slot's configured time.
$mT = hl_sched('sched_slot_morning'); $lT = hl_sched('sched_slot_lunch'); $eT = hl_sched('sched_slot_evening');
$effTime = "COALESCE(NULLIF(post_time,''), CASE slot WHEN 'morning' THEN '$mT' WHEN 'lunch' THEN '$lT' WHEN 'evening' THEN '$eT' ELSE '99:99' END)";
$upcoming = [];
$res = hl_db()->query("SELECT * FROM scheduled_posts WHERE status='scheduled'
                       ORDER BY post_date ASC, $effTime ASC LIMIT 200");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $upcoming[] = $r; }

$recent = [];
$res = hl_db()->query("SELECT * FROM scheduled_posts WHERE status IN ('posted','failed')
                       ORDER BY COALESCE(posted_at, created_at) DESC LIMIT 30");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $recent[] = $r; }

// Show the exact time this post goes out: the user-chosen time if set, else the
// configured slot time. A friendly slot name is appended when it matches one.
function slot_cell($row) {
    $names = ['morning' => 'Morning', 'lunch' => 'Lunch', 'evening' => 'Evening'];
    $slot = is_array($row) ? ($row['slot'] ?? '') : $row;
    $pt   = is_array($row) ? trim((string) ($row['post_time'] ?? '')) : '';
    $time = $pt !== '' ? $pt : hl_sched('sched_slot_' . $slot);
    $out  = '<b>' . h(hl_fmt_time($time)) . '</b>';
    if (isset($names[$slot])) $out .= ' <span class="muted small">' . h($names[$slot]) . '</span>';
    return $out;
}

hl_shell_head('Scheduled Posts', 'scheduled', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <div class="hd"><h2>Upcoming Scheduled Posts<?= $upcoming ? ' (' . count($upcoming) . ')' : '' ?></h2>
    <div class="actions">
      <a class="btn ghost sm" href="calendar.php">Calendar view</a>
      <?php if ($upcoming): ?>
      <form method="post" onsubmit="return confirm('Delete ALL upcoming (not-yet-posted) scheduled posts? This cannot be undone.')">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="delete_upcoming">
        <button class="btn sm red" type="submit">Delete upcoming</button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('RESET TEST DATA?\n\nThis deletes every scheduled post and cancels all active/pending promotions. Use this only to clean up test ads - it clears the board so a fresh Business of the Week (or any plan) can book from scratch.')">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="reset_tests">
        <button class="btn sm red" type="submit">Reset test data</button></form>
    </div></div>
  <p class="sub">Booked automatically when you approve a promotion, following each plan's rules. The bot posts each one to the group at its slot time. Use Reset test data to wipe test ads and start clean.</p>
  <?php if (!$upcoming): ?>
    <div class="empty">No upcoming posts scheduled yet. Approve a promotion and it will be booked here automatically.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Business</th><th>Plan</th><th>Pinned</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $s): ?>
      <tr>
        <td><?= h(hl_fmt_date($s['post_date'])) ?></td>
        <td><?= slot_cell($s) ?></td>
        <td><b><?= h($s['business_name'] ?: '-') ?></b></td>
        <td><span class="plan"><?= h(hl_plan_name($s['package_key'])) ?></span></td>
        <td><?= ((int) $s['pin'] === 1) ? '<span class="pill ok">Pinned</span>' : '<span class="muted small">-</span>' ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this one scheduled post?')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_one">
            <input type="hidden" name="sched_id" value="<?= (int) $s['id'] ?>">
            <button class="btn sm ghost" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($recent): ?>
<div class="card">
  <div class="hd"><h2>Recently posted</h2></div>
  <div class="tblwrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Business</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $s): list($pc, $pl) = hl_status_meta($s['status']); ?>
      <tr>
        <td><?= h(hl_fmt_date($s['post_date'])) ?></td>
        <td><?= slot_cell($s) ?></td>
        <td><?= h($s['business_name'] ?: '-') ?></td>
        <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span><?= $s['error'] ? ' <span class="muted small">' . h($s['error']) . '</span>' : '' ?></td>
        <td class="muted small"><?= h($s['posted_at'] ? $s['posted_at'] . ' UTC' : '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php hl_shell_foot();
