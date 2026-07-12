<?php
/**
 * calendar.php - a 14-day view of the three daily slots and what is booked.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

$DAYS = 14;

// booked map: post_date => slot => row
$booked = [];
$res = hl_db()->query("SELECT post_date, slot, business_name, package_key, status
                       FROM scheduled_posts WHERE status IN ('scheduled','posted')
                       AND post_date >= date('now','-1 day')");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $booked[$r['post_date']][$r['slot']] = $r;
}

try { $tz = new DateTimeZone(hl_sched('sched_tz')); } catch (\Throwable $e) { $tz = new DateTimeZone('America/New_York'); }
$cur = new DateTime('now', $tz); $cur->setTime(0, 0);
$slots = ['morning', 'lunch', 'evening'];

hl_shell_head('Calendar & Slots', 'calendar', hl_pending_count());
?>

<div class="card">
  <div class="hd"><h2>Calendar &amp; Daily Slots</h2>
    <a class="btn ghost sm" href="scheduled.php">List view</a></div>
  <p class="sub">Next <?= $DAYS ?> days in <?= h(hl_sched('sched_tz')) ?>. Max 3 posts a day, one per slot. Green = booked, grey = open.</p>
  <div class="tblwrap"><table>
    <thead><tr><th>Date</th>
      <?php foreach ($slots as $sl): ?><th><?= h(hl_slot_label($sl)) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $DAYS; $i++):
        $d = $cur->format('Y-m-d');
        $dow = $cur->format('D'); ?>
      <tr>
        <td><b><?= h($cur->format('M j')) ?></b> <span class="muted small"><?= h($dow) ?></span></td>
        <?php foreach ($slots as $sl):
            $cell = $booked[$d][$sl] ?? null; ?>
          <td>
            <?php if ($cell): ?>
              <span class="pill ok"><?= h($cell['business_name'] ?: 'Booked') ?></span>
              <div class="muted small"><?= h(hl_plan_name($cell['package_key'])) ?><?= $cell['status'] === 'posted' ? ' &middot; posted' : '' ?></div>
            <?php else: ?>
              <span class="muted small">open</span>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php $cur->modify('+1 day'); endfor; ?>
    </tbody>
  </table></div>
</div>

<?php hl_shell_foot();
