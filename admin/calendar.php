<?php
/**
 * calendar.php - full monthly posting calendar.
 *
 * Shows one month at a time in a 7-column grid (Mon-first). Every booked post
 * appears in its day cell, colour-coded by status, and can be cancelled right
 * from the calendar. Pending-approval promotions (not yet booked) show on their
 * chosen start date so admins see what's coming. A legend explains the colours.
 *
 * Legend:
 *   Available        - a future day in the bookable window with nothing on it
 *   Pending Approval - an ad waiting for review (shown on its chosen date)
 *   Scheduled        - approved & booked, waiting to be posted
 *   Posted           - already published to the group
 *   Canceled         - a booking that was cancelled
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_ensure_scheduled_table();

// --- Cancel a single scheduled post straight from the calendar -------------
$flash = null; $flashType = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    hl_csrf_check();
    $action = $_POST['action'] ?? '';
    $db = hl_db();
    if ($action === 'cancel_sched') {
        $sid = (int) ($_POST['sched_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $db->prepare("UPDATE scheduled_posts SET status='canceled' WHERE id=:id AND status='scheduled'");
            $stmt->bindValue(':id', $sid, SQLITE3_INTEGER);
            $stmt->execute();
            $flash = $db->changes() > 0 ? 'Scheduled post cancelled.' : 'That post could not be cancelled (already posted or removed).';
            if ($db->changes() === 0) $flashType = 'err';
        }
    } elseif ($action === 'cancel_day') {
        // Cancel every still-scheduled post on one day.
        $d = preg_replace('/[^0-9\-]/', '', (string) ($_POST['day'] ?? ''));
        if ($d !== '') {
            $stmt = $db->prepare("UPDATE scheduled_posts SET status='canceled' WHERE post_date=:d AND status='scheduled'");
            $stmt->bindValue(':d', $d, SQLITE3_TEXT);
            $stmt->execute();
            $n = $db->changes();
            $flash = "Cancelled {$n} scheduled post" . ($n === 1 ? '' : 's') . ' on ' . h(hl_fmt_date($d)) . '.';
            if ($n === 0) $flashType = 'err';
        }
    }
}
$csrf = h(hl_csrf_token());

// --- Which month are we showing? (?ym=YYYY-MM, defaults to current) --------
try { $tz = new DateTimeZone(hl_sched('sched_tz')); } catch (\Throwable $e) { $tz = new DateTimeZone('America/New_York'); }
$today = new DateTime('now', $tz); $today->setTime(0, 0);
$todayYmd = $today->format('Y-m-d');

$month = null;
if (isset($_GET['ym']) && preg_match('/^(\d{4})-(\d{2})$/', (string) $_GET['ym'], $m)) {
    $month = DateTime::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-01", $tz);
}
if (!($month instanceof DateTime)) { $month = clone $today; }
$month->setTime(0, 0); $month->modify('first day of this month');

$firstOfMonth = clone $month;
$lastOfMonth  = (clone $month)->modify('last day of this month');
$prevYm = (clone $firstOfMonth)->modify('-1 day')->format('Y-m');
$nextYm = (clone $lastOfMonth)->modify('+1 day')->format('Y-m');

// Grid spans the Monday on/before the 1st .. the Sunday on/after the last day.
$gridStart = clone $firstOfMonth;
$lead = ((int) $gridStart->format('N')) - 1;           // Mon=1..Sun=7
if ($lead > 0) $gridStart->modify("-{$lead} day");
$gridEnd = clone $lastOfMonth;
$trail = 7 - ((int) $gridEnd->format('N'));
if ($trail > 0) $gridEnd->modify("+{$trail} day");

// --- Load everything landing inside the grid window ------------------------
$mT = hl_sched('sched_slot_morning'); $lT = hl_sched('sched_slot_lunch'); $eT = hl_sched('sched_slot_evening');
$slotTime = ['morning' => $mT, 'lunch' => $lT, 'evening' => $eT];
$startYmd = $gridStart->format('Y-m-d');
$endYmd   = $gridEnd->format('Y-m-d');

// Booked posts (scheduled / posted / canceled / failed).
$byDay = [];   // 'Y-m-d' => [ item, ... ]
$stmt = hl_db()->prepare("SELECT id, post_date, slot, post_time, business_name, package_key, status, pin
                          FROM scheduled_posts
                          WHERE post_date >= :a AND post_date <= :b
                          ORDER BY post_date ASC");
$stmt->bindValue(':a', $startYmd, SQLITE3_TEXT);
$stmt->bindValue(':b', $endYmd, SQLITE3_TEXT);
$res = $stmt->execute();
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $pt = trim((string) ($r['post_time'] ?? ''));
    $time = $pt !== '' ? $pt : ($slotTime[$r['slot']] ?? '');
    $byDay[$r['post_date']][] = [
        'kind' => 'sched', 'id' => (int) $r['id'], 'time' => $time,
        'name' => $r['business_name'] ?: 'Booked', 'plan' => $r['package_key'],
        'status' => $r['status'], 'pin' => (int) $r['pin'],
    ];
}

// Pending-review promotions that have picked a start date (not yet booked).
$stmt = hl_db()->prepare("SELECT id, business_name, package_key, start_date
                          FROM promotions
                          WHERE status='pending_review' AND start_date IS NOT NULL
                          AND start_date >= :a AND start_date <= :b");
$stmt->bindValue(':a', $startYmd, SQLITE3_TEXT);
$stmt->bindValue(':b', $endYmd, SQLITE3_TEXT);
$res = $stmt->execute();
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $byDay[$r['start_date']][] = [
        'kind' => 'pending', 'id' => (int) $r['id'], 'time' => '',
        'name' => $r['business_name'] ?: 'Pending ad', 'plan' => $r['package_key'],
        'status' => 'pending_review', 'pin' => 0,
    ];
}
// Sort each day's items by time (untimed pending first).
foreach ($byDay as &$list) {
    usort($list, function ($a, $b) { return strcmp($a['time'], $b['time']); });
}
unset($list);

// Map an item to [cssClass, dotColor, label] for its pill.
function cal_style($it) {
    if ($it['kind'] === 'pending') return ['pending', '#d29922', 'Pending'];
    switch ($it['status']) {
        case 'posted':   return ['posted',   '#2ea043', 'Posted'];
        case 'canceled': return ['canceled', '#c62828', 'Canceled'];
        case 'failed':   return ['canceled', '#c62828', 'Failed'];
        default:         return ['scheduled','#1f6feb', 'Scheduled'];
    }
}

hl_shell_head('Calendar & Slots', 'calendar', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>
<style>
  .calwrap{overflow-x:auto}
  .caltop{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .calnav{display:flex;align-items:center;gap:8px}
  .calnav .mlabel{font-size:17px;font-weight:700;min-width:150px;text-align:center}
  .legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--muted)}
  .legend span{display:inline-flex;align-items:center;gap:5px}
  .legend i{width:11px;height:11px;border-radius:3px;display:inline-block}
  table.cal{width:100%;border-collapse:separate;border-spacing:6px;table-layout:fixed;min-width:720px}
  table.cal th{padding:4px 2px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;text-align:center}
  td.cell{vertical-align:top;background:var(--chip);border:1px solid var(--line);border-radius:9px;
    height:104px;padding:6px 6px 5px;position:relative}
  td.cell.out{opacity:.42}
  td.cell.past{opacity:.62}
  td.cell.today{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}
  .daynum{font-size:12px;font-weight:700;color:var(--text);margin-bottom:3px;display:flex;justify-content:space-between;align-items:center}
  .daynum .open{font-size:10px;font-weight:600;color:#2ea043;background:rgba(46,160,67,.14);
    padding:1px 6px;border-radius:20px}
  .todaytag{font-size:9px;font-weight:700;color:var(--accent)}
  .ev{display:block;font-size:11px;line-height:1.25;border-radius:6px;padding:3px 6px;margin:3px 0;
    border:1px solid transparent;position:relative}
  .ev.cancelable{padding-right:16px}
  .ev b{font-weight:700}
  .ev .x{position:absolute;top:1px;right:2px;color:inherit;opacity:.55;font-weight:700;
    background:none;border:0;padding:0 2px;margin:0;cursor:pointer;
    text-decoration:none;font-size:13px;line-height:1}
  .ev .x:hover{opacity:1;background:none;color:var(--danger)}
  .ev.scheduled{background:rgba(31,111,235,.14);color:#4c8dff;border-color:rgba(31,111,235,.35)}
  .ev.posted{background:rgba(46,160,67,.14);color:#3fb950;border-color:rgba(46,160,67,.32)}
  .ev.pending{background:rgba(210,153,34,.14);color:#d29922;border-color:rgba(210,153,34,.35)}
  .ev.canceled{background:rgba(198,40,40,.12);color:#f27171;border-color:rgba(198,40,40,.3);text-decoration:line-through;opacity:.85}
  .ev form{display:inline}
  .ev .pintag{opacity:.8}
  .caldaybtn{background:none;border:0;padding:0;margin:0;color:var(--muted);font-size:10px;cursor:pointer;text-decoration:underline}
  .caldaybtn:hover{color:var(--danger);background:none}
</style>

<div class="card">
  <div class="caltop">
    <div class="calnav">
      <a class="btn ghost sm" href="?ym=<?= h($prevYm) ?>">&#9664;</a>
      <div class="mlabel"><?= h($month->format('F Y')) ?></div>
      <a class="btn ghost sm" href="?ym=<?= h($nextYm) ?>">&#9654;</a>
      <?php if ($month->format('Y-m') !== $today->format('Y-m')): ?>
        <a class="btn ghost sm" href="calendar.php">Today</a>
      <?php endif; ?>
    </div>
    <div class="legend">
      <span><i style="background:#2ea043"></i>&#128994; Available</span>
      <span><i style="background:#d29922"></i>&#128993; Pending</span>
      <span><i style="background:#1f6feb"></i>&#128309; Scheduled</span>
      <span><i style="background:#2ea043"></i>&#9989; Posted</span>
      <span><i style="background:#c62828"></i>&#128308; Canceled</span>
    </div>
  </div>

  <p class="sub">Monthly posting schedule in <?= h(hl_sched('sched_tz')) ?>. Cancel any scheduled post with the &times; on it, or cancel a whole day. <a class="" href="scheduled.php">List view</a></p>

  <div class="calwrap"><table class="cal">
    <thead><tr>
      <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $w): ?><th><?= $w ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php
    $cur = clone $gridStart;
    while ($cur <= $gridEnd):
        echo '<tr>';
        for ($c = 0; $c < 7; $c++):
            $ymd = $cur->format('Y-m-d');
            $inMonth = ($cur->format('Y-m') === $month->format('Y-m'));
            $isToday = ($ymd === $todayYmd);
            $isPast  = ($ymd < $todayYmd);
            $items = $byDay[$ymd] ?? [];
            $hasSchedulable = false;
            foreach ($items as $it) { if ($it['kind'] === 'sched' && $it['status'] === 'scheduled') { $hasSchedulable = true; break; } }
            $cls = 'cell';
            if (!$inMonth) $cls .= ' out';
            elseif ($isPast) $cls .= ' past';
            if ($isToday) $cls .= ' today';
            ?>
            <td class="<?= $cls ?>">
              <div class="daynum">
                <span><?= (int) $cur->format('j') ?><?= $isToday ? ' <span class="todaytag">TODAY</span>' : '' ?></span>
                <?php if ($inMonth && !$isPast && !$items): ?><span class="open">Open</span><?php endif; ?>
              </div>
              <?php foreach ($items as $it): list($evCls, $dot, $lbl) = cal_style($it); ?>
                <?php $cancelable = ($it['kind'] === 'sched' && $it['status'] === 'scheduled'); ?>
                <span class="ev <?= $evCls ?><?= $cancelable ? ' cancelable' : '' ?>" title="<?= h($lbl . ' - ' . hl_plan_name($it['plan'])) ?>">
                  <?php if ($it['time'] !== ''): ?><b><?= h(hl_fmt_time($it['time'])) ?></b> <?php endif; ?><?= h($it['name']) ?><?php if ($it['pin']): ?> <span class="pintag">&#128204;</span><?php endif; ?><?php if ($cancelable): ?>
                  <form method="post" onsubmit="return confirm('Cancel this scheduled post?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="cancel_sched"><input type="hidden" name="sched_id" value="<?= $it['id'] ?>"><button class="x" type="submit" title="Cancel">&times;</button></form>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
              <?php if ($hasSchedulable): ?>
                <form method="post" onsubmit="return confirm('Cancel ALL scheduled posts on this day?')" style="margin-top:2px">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="cancel_day">
                  <input type="hidden" name="day" value="<?= h($ymd) ?>">
                  <button class="caldaybtn" type="submit">cancel day</button>
                </form>
              <?php endif; ?>
            </td>
            <?php $cur->modify('+1 day');
        endfor;
        echo '</tr>';
    endwhile;
    ?>
    </tbody>
  </table></div>
</div>

<?php hl_shell_foot();
