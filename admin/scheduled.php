<?php
/**
 * scheduled.php - upcoming (and recently posted) scheduled posts.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

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
?>

<div class="card">
  <div class="hd"><h2>Upcoming Scheduled Posts<?= $upcoming ? ' (' . count($upcoming) . ')' : '' ?></h2>
    <a class="btn ghost sm" href="calendar.php">Calendar view</a></div>
  <p class="sub">Booked automatically when you approve a promotion, following each plan's rules. The bot posts each one to the group at its slot time.</p>
  <?php if (!$upcoming): ?>
    <div class="empty">No upcoming posts scheduled yet. Approve a promotion and it will be booked here automatically.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Business</th><th>Plan</th><th>Pinned</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $s): ?>
      <tr>
        <td><?= h(hl_fmt_date($s['post_date'])) ?></td>
        <td><?= slot_cell($s) ?></td>
        <td><b><?= h($s['business_name'] ?: '-') ?></b></td>
        <td><span class="plan"><?= h(hl_plan_name($s['package_key'])) ?></span></td>
        <td><?= ((int) $s['pin'] === 1) ? '<span class="pill ok">Pinned</span>' : '<span class="muted small">-</span>' ?></td>
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
