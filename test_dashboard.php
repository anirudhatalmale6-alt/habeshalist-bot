<?php
/**
 * test_dashboard.php - verifies the user-dashboard filtering / edit fixes.
 * Run:  /usr/local/bin/php test_dashboard.php
 * Uses a throwaway SQLite file and a mock Telegram client (captures messages).
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = [];

// --- Mock Telegram: capture every outgoing message's text + button data ---
class MockTelegram {
    public function sendMessage($uid, $text, $extra = null) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>[]]; }
    public function sendInlineButtons($uid, $text, $rows) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>$rows]; }
    public function sendPhoto($uid, $file, $cap = '') { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[photo] '.$cap,'rows'=>[]]; }
    public function sendMediaGroup($uid, $imgs) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[album]','rows'=>[]]; }
    public function callApi($m, $p = []) { return ['ok'=>true,'result'=>[]]; }
}

$dbPath = sys_get_temp_dir() . '/hl_dash_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/promotion.php';

$db = new Database($dbPath);
$tg = new MockTelegram();

// Fix the schedule timezone so date math is deterministic.
$db->setSetting('sched_tz', 'America/New_York');
$tz = new DateTimeZone('America/New_York');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$past  = (new DateTime('now', $tz))->modify('-10 day')->format('Y-m-d');
$soon  = (new DateTime('now', $tz))->modify('+3 day')->format('Y-m-d');
$later = (new DateTime('now', $tz))->modify('+9 day')->format('Y-m-d');
$yest  = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');

$UID = 555001;
$OTHER = 999999;

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}
function lastText() { $o = $GLOBALS['__OUT']; return end($o)['text'] ?? ''; }
function lastRows() { $o = $GLOBALS['__OUT']; return end($o)['rows'] ?? []; }
function hasButton($cbSubstr) {
    foreach (lastRows() as $row) foreach ($row as $b) {
        if (isset($b['callback_data']) && strpos($b['callback_data'], $cbSubstr) !== false) return true;
    }
    return false;
}
function reset_out() { $GLOBALS['__OUT'] = []; }

// ---- Seed promotions ----
// 1) An OLD cancelled plan (should never surface)
$cancelled = $db->createPromotion($UID, [
    'package_key'=>'monthly','price'=>50,'payment_method'=>'zelle','payment_status'=>'paid',
    'receipt'=>'HL-OLD1','business_name'=>'Old Cancelled Biz','business_category'=>'Food',
    'description'=>'x','phone'=>'111','start_date'=>$past,'end_date'=>$yest,
    'posts_total'=>8,'posts_used'=>8,'status'=>'canceled',
]);
// 2) An EXPIRED (approved but end_date in the past) plan (should never surface)
$expired = $db->createPromotion($UID, [
    'package_key'=>'monthly','price'=>50,'business_name'=>'Expired Biz','business_category'=>'Retail',
    'description'=>'x','phone'=>'222','start_date'=>$past,'end_date'=>$yest,
    'posts_total'=>8,'posts_used'=>8,'status'=>'approved',
]);
// 3) A DRAFT (never submitted) (should never surface)
$draft = $db->createPromotion($UID, [
    'package_key'=>'one_time','price'=>10,'business_name'=>'Draft Biz','status'=>'draft',
]);
// 4) The CURRENT active plan (approved, not expired)
$active = $db->createPromotion($UID, [
    'package_key'=>'monthly','price'=>50,'payment_method'=>'cashapp','payment_status'=>'paid',
    'receipt'=>'HL-NEW9','business_name'=>'Active Coffee House','business_category'=>'Food',
    'description'=>'Best coffee','phone'=>'333','website'=>'ex.com','start_date'=>$today,'end_date'=>$later,
    'posts_total'=>8,'posts_used'=>2,'status'=>'approved',
]);
// 5) Another user's active plan (isolation check)
$otherActive = $db->createPromotion($OTHER, [
    'package_key'=>'monthly','price'=>50,'business_name'=>'Someone Else','status'=>'approved',
    'start_date'=>$today,'end_date'=>$later,'posts_total'=>8,'posts_used'=>0,
]);

// ---- Seed scheduled_posts for the active plan: 1 stale past + 2 future ----
$pdo = new SQLite3($dbPath);
$stmt = $pdo->prepare("INSERT INTO scheduled_posts (promotion_id,business_name,package_key,post_date,slot,post_time,pin,status) VALUES (:p,:b,:k,:d,:s,:t,:pin,:st)");
$seedPost = function($pid,$date,$time,$status,$pin=0) use ($stmt) {
    $stmt->reset();
    $stmt->bindValue(':p',$pid,SQLITE3_INTEGER);
    $stmt->bindValue(':b','Active Coffee House',SQLITE3_TEXT);
    $stmt->bindValue(':k','monthly',SQLITE3_TEXT);
    $stmt->bindValue(':d',$date,SQLITE3_TEXT);
    $stmt->bindValue(':s','morning',SQLITE3_TEXT);
    $stmt->bindValue(':t',$time,SQLITE3_TEXT);
    $stmt->bindValue(':pin',$pin,SQLITE3_INTEGER);
    $stmt->bindValue(':st',$status,SQLITE3_TEXT);
    $stmt->execute();
};
$seedPost($active,$past,'08:30','scheduled');    // STALE past 'scheduled' - must be hidden
$seedPost($active,$soon,'12:30','scheduled');    // future
$seedPost($active,$later,'18:00','scheduled',1); // future pinned
$seedPost($active,$yest,'08:30','posted');       // already posted - not "upcoming"
$pdo->close();

echo "\n[1] promoActivePromotion picks the current active plan (not cancelled/expired/draft)\n";
$ap = promoActivePromotion($UID);
check('returns a plan', $ap !== null);
check('it is the active one', $ap && (int)$ap['id'] === (int)$active);
check('not the cancelled one', !$ap || (int)$ap['id'] !== (int)$cancelled);
check('not the expired one', !$ap || (int)$ap['id'] !== (int)$expired);

echo "\n[2] getNextPost / getUpcomingPosts hide stale past dates\n";
$next = $db->getNextPost($active, $today);
check('next post is in the future', $next && $next['post_date'] >= $today);
check('next post is the soonest future ('.$soon.')', $next && $next['post_date'] === $soon);
$up = $db->getUpcomingPosts($active, 20, $today);
$dates = array_map(function($r){ return $r['post_date']; }, $up);
check('no past date in upcoming', !in_array($past, $dates, true));
check('both future dates present', in_array($soon,$dates,true) && in_array($later,$dates,true));
check('exactly 2 upcoming', count($up) === 2);

echo "\n[3] My Ads shows only the active plan's ad\n";
reset_out();
promoDashMyAds($UID);
check('shows active business name', strpos(lastText(),'Active Coffee House') !== false);
check('does NOT show cancelled biz', strpos(lastText(),'Old Cancelled Biz') === false);
check('does NOT show expired biz', strpos(lastText(),'Expired Biz') === false);
check('does NOT show draft biz', strpos(lastText(),'Draft Biz') === false);
check('offers an Edit Ad button', hasButton('dash_edit_'));

echo "\n[4] Payment shows amount/date/method/status for the active plan only\n";
reset_out();
promoDashPayments($UID);
$pt = lastText();
check('shows amount', strpos($pt,'50') !== false);
check('shows method (CASHAPP)', stripos($pt,'CASHAPP') !== false);
check('shows status', stripos($pt,'paid') !== false);
check('shows a date line', strpos($pt,'Date:') !== false);
check('shows active receipt', strpos($pt,'HL-NEW9') !== false);
check('does NOT show old receipt', strpos($pt,'HL-OLD1') === false);

echo "\n[5] Dashboard renders for active plan with correct start date + next post\n";
reset_out();
promoShowDashboard($UID);
$dt = lastText();
$startFmt = (new DateTime($today,$tz))->format('M j, Y');
check('shows plan name', strpos($dt,'Monthly') !== false || strpos($dt,'Plan:') !== false);
check('start date is the active plan start ('.$startFmt.')', strpos($dt,$startFmt) !== false);
check('does not error out', $dt !== '');
check('has Edit Ad button', hasButton('dash_edit_'));

echo "\n[6] Edit recovery: stale Edit tap (idle state) reloads saved ad, never blank\n";
$db->setState($UID,'idle',[]); // simulate flow was reset
reset_out();
promoEditMenu($UID, $db->getState($UID));
check('edit menu opened (not bounced blank)', strpos(lastText(),'edit') !== false || hasButton('promoedit_'));
// Now edit a field and confirm it PERSISTS to the DB (updates the active plan)
$recovered = promoRecoverEditData($UID, ['state'=>'idle','data'=>[]]);
check('recovered data is the active plan', $recovered && (int)$recovered['_edit_promo_id'] === (int)$active);
check('recovered data pre-filled', $recovered && $recovered['business_name'] === 'Active Coffee House');
promoPersistEditField(array_merge($recovered,['phone'=>'999-000']),'phone');
$reload = $db->getPromotion($active);
check('field change persisted to DB', $reload['phone'] === '999-000');

echo "\n[7] User isolation: another user's plan never leaks\n";
$apOther = promoActivePromotion($OTHER);
check('other user gets their own plan', $apOther && (int)$apOther['id'] === (int)$otherActive);
check('active user unaffected', (int)promoActivePromotion($UID)['id'] === (int)$active);

echo "\n=====================================\n";
echo "PASS: $pass   FAIL: $fail\n";
@unlink($dbPath); @unlink($dbPath.'-wal'); @unlink($dbPath.'-shm');
exit($fail === 0 ? 0 : 1);
