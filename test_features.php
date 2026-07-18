<?php
/**
 * test_features.php - verifies the batch of feature fixes:
 *   - new group-post format (divider, UPPERCASE name, labelled fields, hashtags)
 *   - video attachments (accepted on the media step, stored, sent when posting)
 *   - Business of the Week actually posts AND pins via the scheduler
 *   - calendar month navigation edits the SAME message in place (no new msg)
 * Run:  php test_features.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = [];
$GLOBALS['__SENT'] = [];     // {kind,uid,ref} for each thing "sent" to a chat
$GLOBALS['__EDITS'] = [];    // editMessageText calls
$GLOBALS['__CALLS'] = [];    // raw callApi calls (pin/unpin)

require __DIR__ . '/includes/telegram.php';
class MockTelegram extends Telegram {
    public function __construct() {}
    public function sendMessage($uid, $text, $extra = null) {
        $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>[]];
        $GLOBALS['__SENT'][] = ['kind'=>'message','uid'=>$uid,'ref'=>$text];
        return ['ok'=>true,'result'=>['message_id'=>rand(1000,9999)]];
    }
    public function sendInlineButtons($uid, $text, $rows) {
        $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>$rows];
    }
    public function sendPhoto($uid, $file, $cap = null) {
        $GLOBALS['__SENT'][] = ['kind'=>'photo','uid'=>$uid,'ref'=>$file];
        return ['ok'=>true,'result'=>['message_id'=>rand(1000,9999)]];
    }
    public function sendVideo($uid, $file, $cap = null) {
        $GLOBALS['__SENT'][] = ['kind'=>'video','uid'=>$uid,'ref'=>$file];
        return ['ok'=>true,'result'=>['message_id'=>rand(1000,9999)]];
    }
    public function sendMediaGroup($uid, $imgs) {
        $GLOBALS['__SENT'][] = ['kind'=>'album','uid'=>$uid,'ref'=>$imgs];
        return ['ok'=>true];
    }
    public function editMessageText($uid, $mid, $text, $markup = null) {
        $rows = is_array($markup) ? ($markup['inline_keyboard'] ?? []) : [];
        $GLOBALS['__EDITS'][] = ['uid'=>$uid,'mid'=>$mid,'text'=>$text,'rows'=>$rows];
        $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>$rows];
        return ['ok'=>true];
    }
    public function callApi($m, $p = []) {
        $GLOBALS['__CALLS'][] = ['method'=>$m,'params'=>$p];
        return ['ok'=>true,'result'=>[]];
    }
}

$dbPath = sys_get_temp_dir() . '/hl_features_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/promotion.php';
require __DIR__ . '/includes/scheduler.php';

if (!function_exists('isAdmin')) {
    function isAdmin($uid) { global $config; return in_array($uid, $config['admin_ids']); }
}

$db = new Database($dbPath);
$tg = new MockTelegram();

$ADMIN = (int) $config['admin_ids'][0];
$UID = 771133;

$db->setSetting('sched_tz', 'America/New_York');
$db->setSetting('sched_enabled', '1');
$tz = new DateTimeZone('America/New_York');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$in2   = (new DateTime('now', $tz))->modify('+2 day')->format('Y-m-d');

$pass = 0; $fail = 0;
function check($l,$c){ global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} }
function lastRows(){ $o=$GLOBALS['__OUT']; return end($o)['rows']??[]; }
function allLabels(){ $out=[]; foreach(lastRows() as $r) foreach($r as $b){ if(isset($b['text'])) $out[]=$b['text']; } return implode(' | ', $out); }
function reset_out(){ $GLOBALS['__OUT']=[]; $GLOBALS['__SENT']=[]; $GLOBALS['__EDITS']=[]; $GLOBALS['__CALLS']=[]; }

echo "\n[1] New group-post format\n";
$sample = [
    'business_name' => 'Abojeda School',
    'business_category' => 'Education',
    'description' => "Virginia's first Ethiopian cultural school.",
    'address' => 'Virginia',
    'phone' => '578-928-7897',
];
$txt = HL_Scheduler::renderPostText($sample);
check('has the divider rule', strpos($txt, "\xE2\x94\x81\xE2\x94\x81") !== false);
check('business name is UPPERCASED', strpos($txt, 'ABOJEDA SCHOOL') !== false);
check('labelled Category line', strpos($txt, 'Category: Education') !== false);
check('labelled Location line', strpos($txt, 'Location: Virginia') !== false);
check('labelled Contact line', strpos($txt, 'Contact: 578-928-7897') !== false);
check('category hashtag', strpos($txt, '#Education') !== false);
check('location hashtag', strpos($txt, '#Virginia') !== false);
check('brand hashtag', strpos($txt, '#HabeshaList') !== false);
// multi-word fields collapse into one CamelCase tag
check('multi-word location -> CamelCase tag', strpos(HL_Scheduler::hashtagify('New York'), '#NewYork') === 0);

echo "\n[2] Video attachment on the media step\n";
reset_out();
$data = ['package_key'=>'one_time','images'=>[],'videos'=>[]];
$db->setState($UID, 'promo_images', $data);
$ok = promoHandleVideo($UID, ['video'=>['file_id'=>'VID_1']]);
$after = $db->getState($UID);
check('video handler accepted the video', $ok === true);
check('video stored in session', in_array('VID_1', $after['data']['videos'] ?? [], true));
check('got a video-received confirmation', strpos($GLOBALS['__OUT'][count($GLOBALS['__OUT'])-1]['text'], 'Video received') !== false);

echo "\n[3] A promo with a video actually sends the video when posted\n";
reset_out();
$vpid = $db->createPromotion($UID, [
    'package_key'=>'one_time','price'=>5,'payment_status'=>'paid','business_name'=>'Reel Diner',
    'business_category'=>'Food','description'=>'Now open','phone'=>'555','logo'=>'',
    'images'=>[], 'videos'=>['VID_POST'],
    'posts_total'=>1,'posts_used'=>0,'status'=>'approved',
    'start_date'=>$today,'end_date'=>$today,
    'schedule'=>['mode'=>'single','date'=>$today,'time'=>'00:01'],
]);
$stored = $db->getPromotion($vpid);
check('videos column persisted', strpos($stored['videos'] ?? '', 'VID_POST') !== false);
$sched = new HL_Scheduler($dbPath, $tg, $config);
$sched->run();  // book + post (time already passed today)
$sentVideo = false; foreach ($GLOBALS['__SENT'] as $s) { if ($s['kind']==='video' && $s['ref']==='VID_POST') $sentVideo = true; }
check('scheduler sent the video to the group', $sentVideo);

echo "\n[4] Business of the Week posts AND pins\n";
reset_out();
$bpid = $db->createPromotion($UID, [
    'package_key'=>'botw','price'=>50,'payment_status'=>'paid','business_name'=>'Week Star',
    'business_category'=>'Retail','description'=>'Featured business','phone'=>'555','logo'=>'LOGO_B',
    'images'=>[], 'videos'=>[],
    'posts_total'=>7,'posts_used'=>0,'status'=>'pending_review',
    'start_date'=>$in2,'end_date'=>$in2,
    'schedule'=>['mode'=>'single','date'=>$in2,'time'=>'09:00'],
]);
promoModerate($ADMIN, $bpid, 'approve');
$up = $db->getUpcomingPosts($bpid, 20, $today);
// Business of the Week now books one post a day for 7 consecutive days.
check('BOTW booked 7 daily posts on approval', count($up) === 7);
$allPinned = $up && count($up) === 7;
foreach ($up as $u) { if ((int)$u['pin'] !== 1) $allPinned = false; }
check('every BOTW daily post is flagged to pin', $allPinned);
$botwDates = array_map(function($u){ return $u['post_date']; }, $up);
check('BOTW dates are 7 distinct consecutive days', count(array_unique($botwDates)) === 7);
// move it into the past and run the engine
$pdo = new SQLite3($dbPath);
$pdo->exec("UPDATE scheduled_posts SET post_date='" . SQLite3::escapeString($today) . "', post_time='00:01' WHERE promotion_id=" . (int)$bpid);
$pdo->close();
reset_out();
$sched2 = new HL_Scheduler($dbPath, $tg, $config);
$sched2->run();
$row = (new SQLite3($dbPath))->querySingle("SELECT status, pin_until FROM scheduled_posts WHERE promotion_id=" . (int)$bpid, true);
check('BOTW post marked posted', ($row['status'] ?? '') === 'posted');
check('BOTW post has a pin window set', !empty($row['pin_until']));
$pinned = false; foreach ($GLOBALS['__CALLS'] as $c) { if ($c['method']==='pinChatMessage') $pinned = true; }
check('scheduler called pinChatMessage', $pinned);

echo "\n[5] Calendar month navigation edits the SAME message in place\n";
reset_out();
$nextYm = (new DateTime('now', $tz))->modify('first day of next month')->format('Ym');
promoCalendarNav($UID, $nextYm, ['data'=>['package_key'=>'one_time']], 4242);
check('navigation used editMessageText (no new message)', count($GLOBALS['__EDITS']) === 1);
check('edit targeted the clicked message id', ($GLOBALS['__EDITS'][0]['mid'] ?? 0) === 4242);
$nextTitle = DateTime::createFromFormat('Ym-d', $nextYm . '-01', $tz)->format('F Y');
check('edited calendar shows the next month', strpos(allLabels(), $nextTitle) !== false);

echo "\n=====================================\n";
echo "PASS: $pass   FAIL: $fail\n";
@unlink($dbPath); @unlink($dbPath.'-wal'); @unlink($dbPath.'-shm');
exit($fail === 0 ? 0 : 1);
