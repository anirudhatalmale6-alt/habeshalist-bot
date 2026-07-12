<?php
/**
 * scheduled.php - upcoming (and recently posted) scheduled posts.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

$slotOrder = "CASE slot WHEN 'morning' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END";
$upcoming = [];
$res = hl_db()->query("SELECT * FROM scheduled_posts WHERE status='scheduled'
                       ORDER BY post_date ASC, $slotOrder ASC LIMIT 200");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $upcoming[] = $r; }

$recent = [];
$res = hl_db()->query("SELECT * FROM scheduled_posts WHERE status IN ('posted','failed')
                       ORDER BY COALESCE(posted_at, created_at) DESC LIMIT 30");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $recent[] = $r; }

function slot_cell($slot) {
    $names = ['morning' => 'Morning', 'lunch' => 'Lunch', 'evening' => 'Evening'];
    $t = hl_sched('sched_slot_' . $slot);
    return h($names[$slot] ?? ucfirst($slot)) . ' <span class="muted small">' . h($t) . '</span>';
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
    <thead><tr><th>Date</th><th>Slot</th><th>Business</th><th>Plan</th><th>Pinned</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $s): ?>
      <tr>
        <td><?= h($s['post_date']) ?></td>
        <td><?= slot_cell($s['slot']) ?></td>
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
    <thead><tr><th>Date</th><th>Slot</th><th>Business</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $s): list($pc, $pl) = hl_status_meta($s['status']); ?>
      <tr>
        <td><?= h($s['post_date']) ?></td>
        <td><?= slot_cell($s['slot']) ?></td>
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
