<?php
/**
 * test_onetime.php - verifies the one-time post fixes:
 *   - approval books the schedule immediately (Next post + Upcoming populate)
 *   - "Remaining posts" and "My Ads" counters count a scheduled post as used
 *   - the scheduler actually publishes a due one-time post and bumps posts_used
 *   - the calendar date picker renders a month grid with tappable dates + nav
 * Run:  /usr/local/bin/php test_onetime.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = [];
$GLOBALS['__SENT'] = [];   // messages "posted" to the group / users by the scheduler

require __DIR__ . '/includes/telegram.php';
class MockTelegram extends Telegram {
    public function __construct() {}
    public function sendMessage($uid, $text, $extra = null) {
        $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>[]];
        $GLOBALS['__SENT'][] = ['uid'=>$uid,'text'=>$text];
        return ['ok'=>true,'result'=>['message_id'=>rand(1000,9999)]];
    }
    public function sendInlineButtons($uid, $text, $rows) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>$rows]; }
    public function sendPhoto($uid, $file, $cap = null) {
        $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[photo] '.$cap,'rows'=>[]];
        $GLOBALS['__SENT'][] = ['uid'=>$uid,'text'=>$cap];
        return ['ok'=>true,'result'=>['message_id'=>rand(1000,9999)]];
    }
    public function sendMediaGroup($uid, $imgs) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[album]','rows'=>[]]; return ['ok'=>true]; }
    public function callApi($m, $p = []) { return ['ok'=>true,'result'=>[]]; }
}

$dbPath = sys_get_temp_dir() . '/hl_onetime_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/promotion.php';
require __DIR__ . '/includes/scheduler.php';

// isAdmin() lives in webhook.php (not loaded here); shim it against admin_ids.
if (!function_exists('isAdmin')) {
    function isAdmin($uid) { global $config; return in_array($uid, $config['admin_ids']); }
}

$db = new Database($dbPath);
$tg = new MockTelegram();

$ADMIN = (int) $config['admin_ids'][0];
$UID = 660022;

$db->setSetting('sched_tz', 'America/New_York');
$db->setSetting('sched_enabled', '1');
$tz = new DateTimeZone('America/New_York');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$in2   = (new DateTime('now', $tz))->modify('+2 day')->format('Y-m-d');

$pass = 0; $fail = 0;
function check($l,$c){ global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} }
function lastText(){ $o=$GLOBALS['__OUT']; return end($o)['text']??''; }
function lastRows(){ $o=$GLOBALS['__OUT']; return end($o)['rows']??[]; }
function allCb(){ $out=[]; foreach(lastRows() as $r) foreach($r as $b){ if(isset($b['callback_data'])) $out[]=$b['callback_data']; } return $out; }
function allLabels(){ $out=[]; foreach(lastRows() as $r) foreach($r as $b){ if(isset($b['text'])) $out[]=$b['text']; } return implode(' | ', $out); }
function reset_out(){ $GLOBALS['__OUT']=[]; }

// Seed a paid one-time promotion pending review, scheduled for a future date.
$pid = $db->createPromotion($UID, [
    'package_key'=>'one_time','price'=>5,'payment_method'=>'card','payment_status'=>'paid',
    'receipt'=>'HL-CARD-TEST','business_name'=>'One Shot Diner','business_category'=>'Food',
    'description'=>'Grand opening','phone'=>'555','logo'=>'',
    'posts_total'=>1,'posts_used'=>0,'status'=>'pending_review',
    'start_date'=>$in2,'end_date'=>$in2,
    'schedule'=>['mode'=>'single','date'=>$in2,'time'=>'10:00'],
]);

echo "\n[1] Before approval: nothing booked yet\n";
check('no upcoming posts pre-approval', count($db->getUpcomingPosts($pid, 20, $today)) === 0);

echo "\n[2] Admin approval books the one-time post IMMEDIATELY\n";
reset_out();
promoModerate($ADMIN, $pid, 'approve');
$after = $db->getUpcomingPosts($pid, 20, $today);
check('promotion is now approved', ($db->getPromotion($pid)['status'] ?? '') === 'approved');
check('exactly one post booked', count($after) === 1);
check('booked on the chosen date', $after && $after[0]['post_date'] === $in2);
check('booked at the chosen time', $after && $after[0]['post_time'] === '10:00');
$next = $db->getNextPost($pid, $today);
check('Next post is populated', $next && $next['post_date'] === $in2);

echo "\n[3] Counters: a scheduled post counts as used\n";
check('committed count is 1', $db->countCommittedPosts($pid) === 1);
reset_out();
promoShowDashboard($UID);
$dash = lastText();
check('dashboard shows Remaining posts: 0 / 1', strpos($dash, 'Remaining posts: <b>0 / 1</b>') !== false);
check('dashboard Next post line filled (not "-")', strpos($dash, "Next post: <b>-</b>") === false);
reset_out();
promoDashMyAds($UID);
check('My Ads shows 1/1 posts (matches dashboard)', strpos(lastText(), '1/1 posts') !== false);
reset_out();
promoDashViewSchedule($UID, $pid);
check('Upcoming Schedule lists the post', strpos(lastText(), 'No upcoming posts') === false);

echo "\n[4] Scheduler publishes a DUE one-time post\n";
// Move the booking into the past so postDue() fires, then run the engine.
$pdo = new SQLite3($dbPath);
$pdo->exec("UPDATE scheduled_posts SET post_date='" . SQLite3::escapeString($today) . "', post_time='00:01' WHERE promotion_id=" . (int)$pid);
$pdo->close();
$GLOBALS['__SENT'] = [];
$sched = new HL_Scheduler($dbPath, $tg, $config);
$sched->run();
$row = (new SQLite3($dbPath))->querySingle("SELECT status FROM scheduled_posts WHERE promotion_id=" . (int)$pid, true);
check('post marked as posted', ($row['status'] ?? '') === 'posted');
check('posts_used incremented to 1', (int) $db->getPromotion($pid)['posts_used'] === 1);
check('something was actually sent to the group', count($GLOBALS['__SENT']) > 0);
// After posting, the allowance is fully used.
reset_out();
promoShowDashboard($UID);
check('dashboard still shows Remaining 0 / 1 after posting', strpos(lastText(), 'Remaining posts: <b>0 / 1</b>') !== false);

echo "\n[5] Calendar date picker renders a month grid\n";
reset_out();
promoDateGrid($UID, ['package_key'=>'one_time']);
$cbs = allCb();
$monthTitle = (new DateTime('now', $tz))->format('F Y');
check('shows the Choose a date header', strpos(lastText(), 'Choose a date') !== false);
check('has the current month + year as a title button', strpos(allLabels(), $monthTitle) !== false);
check('has at least one tappable date (pschd_)', (bool) array_filter($cbs, function($c){ return strpos($c,'pschd_')===0; }));
check('weekday header present (Mo..Su as no-ops)', (function($rows){
    foreach ($rows as $r) { $t = array_map(function($b){return $b['text'];}, $r); if ($t === ['Mo','Tu','We','Th','Fr','Sa','Su']) return true; } return false;
})(lastRows()));
// today is bookable, a date 40 days out is not (window is 30) -> greyed
$far = (new DateTime('now', $tz))->modify('+40 day')->format('Ymd');
check('out-of-window date is NOT tappable', !in_array('pschd_' . $far, $cbs, true));
check('today IS tappable', in_array('pschd_' . (new DateTime('now',$tz))->format('Ymd'), $cbs, true));

echo "\n[6] Calendar navigation renders another month\n";
$nextYm = (new DateTime('now', $tz))->modify('first day of next month')->format('Ym');
reset_out();
promoCalendarNav($UID, $nextYm, ['data'=>['package_key'=>'one_time']]);
$nextTitle = DateTime::createFromFormat('Ym-d', $nextYm . '-01', $tz)->format('F Y');
check('navigated calendar shows next month title', strpos(allLabels(), $nextTitle) !== false);

echo "\n=====================================\n";
echo "PASS: $pass   FAIL: $fail\n";
@unlink($dbPath); @unlink($dbPath.'-wal'); @unlink($dbPath.'-shm');
exit($fail === 0 ? 0 : 1);
