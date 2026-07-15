<?php
/**
 * test_stripe.php - verifies the Stripe card checkout flow end to end without
 * touching the network (uses the __HL_STRIPE_STUB test seam).
 * Run:  /usr/local/bin/php test_stripe.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = [];

class MockTelegram {
    public function sendMessage($uid, $text, $extra = null) { $GLOBALS['__OUT'][] = ['text'=>$text,'rows'=>[]]; }
    public function sendInlineButtons($uid, $text, $rows) { $GLOBALS['__OUT'][] = ['text'=>$text,'rows'=>$rows]; }
    public function sendPhoto($uid, $file, $cap = '') { $GLOBALS['__OUT'][] = ['text'=>'[photo]','rows'=>[]]; }
    public function sendMediaGroup($uid, $imgs) { $GLOBALS['__OUT'][] = ['text'=>'[album]','rows'=>[]]; }
    public function callApi($m, $p = []) { return ['ok'=>true,'result'=>[]]; }
}

$dbPath = sys_get_temp_dir() . '/hl_stripe_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/promotion.php';
require __DIR__ . '/includes/stripe.php';

$db = new Database($dbPath);
$tg = new MockTelegram();

$pass = 0; $fail = 0;
function check($l,$c){ global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";} }
function lastText(){ $o=$GLOBALS['__OUT']; return end($o)['text']??''; }
function lastRows(){ $o=$GLOBALS['__OUT']; return end($o)['rows']??[]; }
function findUrlButton(){ foreach(lastRows() as $r) foreach($r as $b){ if(isset($b['url'])) return $b['url']; } return ''; }
function hasCb($s){ foreach(lastRows() as $r) foreach($r as $b){ if(isset($b['callback_data'])&&strpos($b['callback_data'],$s)!==false) return true; } return false; }
function reset_out(){ $GLOBALS['__OUT']=[]; }

// --- Stripe network stub: controllable via globals ---
$GLOBALS['__paid'] = false;
$GLOBALS['__failCreate'] = false;
$GLOBALS['__HL_STRIPE_STUB'] = function($verb, $url, $params) {
    if ($verb === 'POST' && strpos($url, '/checkout/sessions') !== false) {
        if ($GLOBALS['__failCreate']) return ['error' => ['message' => 'bad key']];
        return ['id' => 'cs_test_ABC', 'url' => 'https://checkout.stripe.com/pay/cs_test_ABC'];
    }
    if ($verb === 'GET' && strpos($url, '/checkout/sessions/') !== false) {
        return [
            'id' => 'cs_test_ABC',
            'payment_status' => $GLOBALS['__paid'] ? 'paid' : 'unpaid',
            'payment_intent' => $GLOBALS['__paid'] ? 'pi_test_XYZ987' : null,
        ];
    }
    return ['error' => ['message' => 'unexpected']];
};

$UID = 771001;
$state = ['state'=>'promo_payment','data'=>['package_key'=>'monthly','price'=>50]];

echo "\n[1] No Stripe key -> falls back to manual methods\n";
$db->setSetting('stripe_key', '');
reset_out();
promoHandlePayMethod($UID, 'card', $state);
check('mentions card being set up', stripos(lastText(),'being set up') !== false);
check('offers Zelle', hasCb('promopay_zelle'));

echo "\n[2] With key -> creates Checkout Session, shows Pay URL + I've paid\n";
$db->setSetting('stripe_key', 'sk_test_dummy');
reset_out();
promoHandlePayMethod($UID, 'card', $state);
check('Pay button carries a checkout URL', strpos(findUrlButton(),'checkout.stripe.com') !== false);
check('has an I\'ve paid button', hasCb('promo_check_card'));
check('state is promo_awaiting_card', $db->getState($UID)['state'] === 'promo_awaiting_card');
check('session id stored', ($db->getState($UID)['data']['_stripe_session'] ?? '') === 'cs_test_ABC');

echo "\n[3] I've paid but NOT paid yet -> retry prompt, stays awaiting\n";
$GLOBALS['__paid'] = false;
reset_out();
promoCheckCard($UID, $db->getState($UID));
check('says could not confirm', stripos(lastText(),"couldn't confirm") !== false);
check('still awaiting card', $db->getState($UID)['state'] === 'promo_awaiting_card');
check('offers retry', hasCb('promo_check_card'));

echo "\n[4] I've paid AND paid -> confirmed, advances to ad form\n";
$GLOBALS['__paid'] = true;
reset_out();
promoCheckCard($UID, $db->getState($UID));
$st = $db->getState($UID);
check('advanced to business_name step', $st['state'] === 'promo_business_name');
check('payment_status = paid', ($st['data']['payment_status'] ?? '') === 'paid');
check('method = card', ($st['data']['payment_method'] ?? '') === 'card');
check('receipt from payment_intent (HL-CARD-...987)', ($st['data']['receipt'] ?? '') === 'HL-CARD-XYZ987');
check('session id cleared', empty($st['data']['_stripe_session']));

echo "\n[5] Session creation fails -> graceful fallback to manual\n";
$GLOBALS['__failCreate'] = true;
reset_out();
promoHandlePayMethod($UID, 'card', $state);
check('says temporarily unavailable', stripos(lastText(),'temporarily unavailable') !== false);
check('offers Cash App', hasCb('promopay_cashapp'));
check('state back to promo_payment', $db->getState($UID)['state'] === 'promo_payment');
$GLOBALS['__failCreate'] = false;

echo "\n[6] Sub-50-cent price skips payment (Stripe minimum)\n";
reset_out();
promoHandlePayMethod($UID, 'card', ['state'=>'promo_payment','data'=>['package_key'=>'one_time','price'=>0]]);
$st = $db->getState($UID);
check('advanced to ad form', $st['state'] === 'promo_business_name');
check('marked paid (no charge)', ($st['data']['payment_status'] ?? '') === 'paid');

echo "\n=====================================\n";
echo "PASS: $pass   FAIL: $fail\n";
@unlink($dbPath); @unlink($dbPath.'-wal'); @unlink($dbPath.'-shm');
exit($fail === 0 ? 0 : 1);
