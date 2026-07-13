<?php
/**
 * calendar.php - a rolling day-by-day view of what is booked at what time.
 *
 * Posts can be booked at the three named slot times OR at a user-chosen time
 * (from the bot's scheduler), so this lists each day's booked posts by their
 * actual time rather than assuming three fixed columns.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

$DAYS = 30;

$mT = hl_sched('sched_slot_morning'); $lT = hl_sched('sched_slot_lunch'); $eT = hl_sched('sched_slot_evening');
$slotTime = ['morning' => $mT, 'lunch' => $lT, 'evening' => $eT];

// Group bookings by day: post_date => [ {time, label, row}, ... ]
$byDay = [];
$res = hl_db()->query("SELECT post_date, slot, post_time, business_name, package_key, status, pin
                       FROM scheduled_posts WHERE status IN ('scheduled','posted')
                       AND post_date >= date('now','-1 day')");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $pt = trim((string) ($r['post_time'] ?? ''));
    $time = $pt !== '' ? $pt : ($slotTime[$r['slot']] ?? '99:99');
    $byDay[$r['post_date']][] = ['time' => $time] + $r;
}
foreach ($byDay as &$list) {
    usort($list, function ($a, $b) { return strcmp($a['time'], $b['time']); });
}
unset($list);

try { $tz = new DateTimeZone(hl_sched('sched_tz')); } catch (\Throwable $e) { $tz = new DateTimeZone('America/New_York'); }
$cur = new DateTime('now', $tz); $cur->setTime(0, 0);

hl_shell_head('Calendar & Slots', 'calendar', hl_pending_count());
?>

<div class="card">
  <div class="hd"><h2>Calendar &amp; Daily Slots</h2>
    <a class="btn ghost sm" href="scheduled.php">List view</a></div>
  <p class="sub">Next <?= $DAYS ?> days in <?= h(hl_sched('sched_tz')) ?>. Each booked post shows at its exact posting time. <span class="pill ok">Pinned</span> posts are marked.</p>
  <div class="tblwrap"><table>
    <thead><tr><th style="width:150px">Day</th><th>Booked posts</th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $DAYS; $i++):
        $d = $cur->format('Y-m-d');
        $items = $byDay[$d] ?? []; ?>
      <tr>
        <td><b><?= h($cur->format('M j')) ?></b> <span class="muted small"><?= h($cur->format('D')) ?></span></td>
        <td>
          <?php if (!$items): ?>
            <span class="muted small">open</span>
          <?php else: foreach ($items as $it): ?>
            <span class="pill ok" style="margin:2px 6px 2px 0;display:inline-block">
              <b><?= h(hl_fmt_time($it['time'])) ?></b> &middot; <?= h($it['business_name'] ?: 'Booked') ?>
              <span style="opacity:.75"><?= h(hl_plan_name($it['package_key'])) ?></span>
              <?= ((int) $it['pin'] === 1) ? ' &#128204;' : '' ?>
              <?= $it['status'] === 'posted' ? ' &middot; posted' : '' ?>
            </span>
          <?php endforeach; endif; ?>
        </td>
      </tr>
      <?php $cur->modify('+1 day'); endfor; ?>
    </tbody>
  </table></div>
</div>

<?php hl_shell_foot();
