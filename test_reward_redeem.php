<?php
/**
 * test_reward_redeem.php - end-to-end reward redemption -> scheduled promotion.
 * A redeemed reward becomes a payment-free, auto-approved, booked promotion.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);
$GLOBALS['__OUT'] = [];

require __DIR__ . '/includes/telegram.php';
class MockTelegram extends Telegram {
    public function __construct() {}
    public function sendMessage($uid, $text, $extra = null) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>[]]; }
    public function sendInlineButtons($uid, $text, $rows) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>$text,'rows'=>$rows]; }
    public function sendPhoto($uid, $file, $cap = null) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[photo]','rows'=>[]]; }
    public function sendMediaGroup($uid, $imgs) { $GLOBALS['__OUT'][] = ['uid'=>$uid,'text'=>'[album]','rows'=>[]]; }
    public function callApi($m, $p = []) { return ['ok'=>true,'result'=>[]]; }
}

$dbPath = sys_get_temp_dir() . '/hl_redeem_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/scheduler.php';
require __DIR__ . '/includes/promotion.php';
require __DIR__ . '/includes/referral.php';

$db  = new Database($dbPath);
$tg  = new MockTelegram();
$referral = new HL_Referral($dbPath);
$db->setSetting('sched_tz', 'America/New_York');
$referral->setSetting('referral_require_group_join', '0');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  PASS  $m\n"; } else { $F++; echo "  FAIL  $m\n"; } }
function lastText() { $o = $GLOBALS['__OUT']; return end($o)['text'] ?? ''; }
function outText() { return implode("\n", array_map(fn($x)=>$x['text'], $GLOBALS['__OUT'])); }
function hasCb($sub) { foreach ($GLOBALS['__OUT'] as $o) foreach ($o['rows'] as $row) foreach ($row as $b) if (isset($b['callback_data']) && strpos($b['callback_data'],$sub)!==false) return true; return false; }
function reset_out() { $GLOBALS['__OUT'] = []; }

$UID = 700100;
$db->createUser($UID, 'Redeemer', '555', '');
$tiers = $referral->tiers(true);
$monthlyTier = $tiers[0]['id'];   // 20 -> monthly
$bundleTier  = $tiers[2]['id'];   // 100 -> manual bundle

echo "\n[1] Redeeming a package-backed reward starts the ad form (payment skipped)\n";
$rw = $referral->grantReward($UID, $monthlyTier, 1);
ok($rw['status'] === 'earned', 'granted reward is earned/redeemable');
reset_out();
inviteRedeemReward($UID, (int)$rw['id']);
$st = $db->getState($UID);
ok($st['state'] === 'promo_business_name', 'redeem dropped user into the ad form');
ok(($st['data']['_reward_id'] ?? 0) == $rw['id'], 'reward id threaded into the form');
ok(($st['data']['payment_status'] ?? '') === 'reward', 'payment marked as reward (no charge)');
ok(($st['data']['package_key'] ?? '') === 'monthly', 'package preset to monthly');
ok(strpos(outText(), 'no payment needed') !== false, 'user told no payment needed');

echo "\n[2] Completing the form auto-approves + books the reward promotion\n";
$tz = new DateTimeZone('America/New_York');
$d1 = (new DateTime('now',$tz))->modify('+2 day');
$data = $st['data'];
$data['business_name'] = 'Reward Coffee';
$data['business_category'] = 'Food';
$data['description'] = 'Great coffee';
$data['phone'] = '555';
$data['images'] = [];
$data['videos'] = [];
$data['schedule'] = ['mode'=>'recurring','slots'=>[
    ['dow'=>(int)$d1->format('N'),'time'=>'10:15'],
    ['dow'=>(((int)$d1->format('N'))%7)+1,'time'=>'18:00'],
]];
reset_out();
promoSubmit($UID, ['state'=>'promo_review','data'=>$data]);

// Find the created promotion.
$all = $db->getUserPromotions($UID);
$promo = null; foreach ($all as $pp) { if (($pp['business_name']??'')==='Reward Coffee') { $promo = $pp; break; } }
ok($promo !== null, 'a promotion row was created');
ok(($promo['status'] ?? '') === 'approved', 'promotion is auto-approved (no manual review)');
ok(($promo['payment_status'] ?? '') === 'reward', 'promotion payment_status = reward');
ok(($promo['package_key'] ?? '') === 'monthly', 'promotion uses the monthly package');

$rwAfter = $referral->reward((int)$rw['id']);
ok($rwAfter['status'] === 'redeemed', 'reward marked redeemed');
ok((int)$rwAfter['promo_id'] === (int)$promo['id'], 'reward linked to the promotion');

$booked = (int)(new SQLite3($dbPath))->querySingle("SELECT COUNT(*) FROM scheduled_posts WHERE promotion_id=".(int)$promo['id']." AND status='scheduled'");
ok($booked > 0, "schedule booked immediately ($booked posts) - shows in dashboard");
ok(strpos(outText(),'scheduled') !== false && hasCb('promo_dashboard'), 'user gets a scheduled confirmation + dashboard button');

echo "\n[3] Redeeming again is blocked (no duplicate promotion)\n";
$before = count($db->getUserPromotions($UID));
reset_out();
inviteRedeemReward($UID, (int)$rw['id']);
ok(strpos(outText(),'already redeemed') !== false, 'second redeem tells user it is already redeemed');
ok(count($db->getUserPromotions($UID)) === $before, 'no extra promotion created');

echo "\n[4] A bundle reward (no single package) is handed to the team, no promo\n";
$rb = $referral->grantReward($UID, $bundleTier, 1);
$before = count($db->getUserPromotions($UID));
reset_out();
inviteRedeemReward($UID, (int)$rb['id']);
ok($referral->reward((int)$rb['id'])['status'] === 'redeemed', 'bundle reward marked redeemed');
ok(count($db->getUserPromotions($UID)) === $before, 'no promotion created for a manual bundle');
ok(strpos(outText(),'team will set it up') !== false, 'user told the team will arrange it');

echo "\n=====================================\n";
echo "PASS: $P   FAIL: $F\n";
@unlink($dbPath);
exit($F ? 1 : 0);
