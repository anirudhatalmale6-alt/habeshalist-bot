<?php
/**
 * test_transporter.php - end-to-end test of the Verified Transporter module,
 * driving the REAL bot code with a mock Telegram and a temporary database.
 *
 * It walks the whole Phase 1 journey:
 *   apply -> admin approves -> create @username -> website profile data ->
 *   post available space -> preview -> pay (card + screenshot) -> publish ->
 *   customer rates -> 3 distinct low ratings -> admin review flag
 *
 * and asserts every one of the developer acceptance tests in section 19 of the
 * specification. Run:  php test_transporter.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT']   = [];   // everything shown to a user (text + buttons)
$GLOBALS['__SENT']  = [];   // photos/messages by destination chat
$GLOBALS['__GROUP'] = [];   // what actually landed in the HabeshaList group

require __DIR__ . '/includes/telegram.php';

class MockTelegram extends Telegram {
    public $failNextSend = false;   // fail ONE send (used to test the photo->text fallback)
    public $failAllSends = false;   // fail every send to the group (publish failure)
    public function __construct() {}
    private function record($uid, $text, $rows = []) {
        $GLOBALS['__OUT'][] = ['uid' => $uid, 'text' => $text, 'rows' => $rows];
        if ((string) $uid === (string) $GLOBALS['GROUP_CHAT']) {
            $GLOBALS['__GROUP'][] = $text;
        }
    }
    public function sendMessage($uid, $text, $extra = null) {
        $this->record($uid, $text);
        $GLOBALS['__SENT'][] = ['kind' => 'message', 'uid' => $uid, 'ref' => $text];
        if ($this->failAllSends && (string) $uid === (string) $GLOBALS['GROUP_CHAT']) {
            array_pop($GLOBALS['__GROUP']);
            return ['ok' => false, 'description' => 'mock group failure'];
        }
        if ($this->failNextSend) { $this->failNextSend = false; return ['ok' => false, 'description' => 'mock failure']; }
        return ['ok' => true, 'result' => ['message_id' => rand(10000, 99999)]];
    }
    public function sendInlineButtons($uid, $text, $rows) { $this->record($uid, $text, $rows); return ['ok' => true]; }
    public function sendPhoto($uid, $file, $cap = null) {
        $this->record($uid, (string) $cap);
        $GLOBALS['__SENT'][] = ['kind' => 'photo', 'uid' => $uid, 'ref' => $file];
        if ($this->failAllSends && (string) $uid === (string) $GLOBALS['GROUP_CHAT']) {
            array_pop($GLOBALS['__GROUP']);
            return ['ok' => false, 'description' => 'mock group failure'];
        }
        if ($this->failNextSend) { $this->failNextSend = false; array_pop($GLOBALS['__GROUP']); return ['ok' => false, 'description' => 'mock failure']; }
        return ['ok' => true, 'result' => ['message_id' => rand(10000, 99999)]];
    }
    public function sendVideo($uid, $f, $c = null) { return ['ok' => true, 'result' => ['message_id' => 1]]; }
    public function sendMediaGroup($uid, $i) { return ['ok' => true]; }
    public function editMessageText($uid, $mid, $text, $m = null) { $this->record($uid, $text); return ['ok' => true]; }
    public function editMessageReplyMarkup($uid, $mid, $m = null) { return ['ok' => true]; }
    public function answerCallbackQuery($id, $t = null) { return ['ok' => true]; }
    public function callApi($m, $p = []) { return ['ok' => true, 'result' => []]; }
    // The application photo is downloaded to disk; give it real bytes.
    public function getFile($id) { return ['ok' => true, 'result' => ['file_path' => 'photos/' . $id . '.jpg']]; }
    public function downloadFile($p) { return str_repeat("\x00", 512); }
}

$dbPath = sys_get_temp_dir() . '/hl_transporter_test_' . getmypid() . '.sqlite';
@unlink($dbPath);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/transporters.php';

$db = new Database($dbPath);
$tg = new MockTelegram();

$GLOBALS['GROUP_CHAT'] = '-1009999000111';
$db->setSetting('sched_group_chat_id', $GLOBALS['GROUP_CHAT']);
$db->setSetting('tr_site_url', 'https://habeshalist.com');

$ADMIN = (int) $config['admin_ids'][0];
$UID   = 880011;          // the transporter
$CUST  = 990022;          // a customer

// The bot module expects these webhook.php helpers to exist.
if (!function_exists('isAdmin'))       { function isAdmin($uid) { global $config; return in_array($uid, $config['admin_ids']); } }
if (!function_exists('showMainMenu'))  { function showMainMenu($uid, $n = '') { $GLOBALS['__OUT'][] = ['uid' => $uid, 'text' => 'MAIN MENU', 'rows' => []]; } }
if (!function_exists('getBotUsername')) { function getBotUsername() { return 'HabeshaListBot'; } }

// Stand in for includes/stripe.php so the card path is exercised without
// touching the network. A session id starting cs_paid_ is treated as settled.
if (!function_exists('hl_stripe_get_session')) {
    function hl_stripe_get_session($key, $id) {
        return ['id' => $id, 'payment_status' => strpos($id, 'cs_paid_') === 0 ? 'paid' : 'unpaid',
                'payment_intent' => 'pi_MOCK1234'];
    }
    function hl_stripe_session_paid($s) { return is_array($s) && ($s['payment_status'] ?? '') === 'paid'; }
    function hl_stripe_create_session($k, $cents, $label, $ok, $cancel, $meta = []) {
        return ['id' => 'cs_paid_mock', 'url' => 'https://checkout.stripe.test/cs_paid_mock'];
    }
}

require __DIR__ . '/includes/transport.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else       { $fail++; echo "  FAIL  {$label}\n"; }
}
function reset_out() { $GLOBALS['__OUT'] = []; $GLOBALS['__SENT'] = []; $GLOBALS['__GROUP'] = []; }
function lastText() { $o = $GLOBALS['__OUT']; $l = end($o); return $l['text'] ?? ''; }
/** Every button label currently on screen. */
function labels() {
    $o = $GLOBALS['__OUT']; $l = end($o); $out = [];
    foreach (($l['rows'] ?? []) as $row) foreach ($row as $b) { if (isset($b['text'])) $out[] = $b['text']; }
    return implode(' | ', $out);
}
/** Text of everything shown since the last reset, concatenated. */
function allText() {
    $s = '';
    foreach ($GLOBALS['__OUT'] as $o) { $s .= $o['text'] . "\n"; }
    return $s;
}
/** Send a text message as the user, through the real state router. */
function say($uid, $text) {
    global $db;
    trHandleStateInput($uid, ['text' => $text], $db->getState($uid));
}

echo "\n=====================================================\n";
echo "  HabeshaList Verified Transporter - Phase 1 tests\n";
echo "=====================================================\n";

// ---------------------------------------------------------------------------
echo "\n[1] Menu\n";
reset_out();
trMenu($UID);
check('menu shows Become a Verified Transporter', strpos(labels(), 'Become a Verified Transporter') !== false);
check('menu shows Post Available Space',          strpos(labels(), 'Post Available Space') !== false);
check('menu shows Find a Transporter',            strpos(labels(), 'Find a Transporter') !== false);
check('menu shows Rate a Transporter',            strpos(labels(), 'Rate a Transporter') !== false);
check('menu shows Safety Notice',                 strpos(labels(), 'Safety Notice') !== false);
check('menu carries the disclaimer',              strpos(lastText(), 'do not receive, transport') !== false);

reset_out();
trSafety($UID);
check('safety notice lists what to confirm', strpos(lastText(), 'confirm the route') !== false);
check('safety notice says we are not the carrier', strpos(lastText(), 'not the carrier') !== false);

// ---------------------------------------------------------------------------
echo "\n[2] ACCEPTANCE: unverified user tries to post -> blocked, directed to verification\n";
reset_out();
trPostStart($UID);
check('posting blocked for an unverified user', strpos(lastText(), "not a Verified Transporter") !== false);
check('directed to the application', strpos(labels(), 'Become a Verified Transporter') !== false);
check('nothing was published', count($GLOBALS['__GROUP']) === 0);

// ---------------------------------------------------------------------------
echo "\n[3] Application - one question at a time\n";
reset_out();
trApplyIntro($UID);
check('intro explains verification is not a guarantee', strpos(lastText(), 'does <b>not</b> mean HabeshaList guarantees') !== false);

trApplyStart($UID, ['username' => 'abebe_tg', 'first_name' => 'Abebe', 'last_name' => 'Kebede']);
check('step 1 asks for full name', strpos(lastText(), 'full name') !== false);
check('Telegram id captured automatically', (int) ($db->getState($UID)['data']['telegram_id'] ?? 0) === $UID);
check('Telegram username captured automatically', ($db->getState($UID)['data']['tg_username'] ?? '') === 'abebe_tg');

say($UID, 'A');
check('one-character name rejected', strpos(lastText(), 'at least 2 characters') !== false);
say($UID, 'Abebe Kebede');
check('step 2 asks for phone', strpos(lastText(), 'phone number') !== false);
say($UID, 'abc');
check('invalid phone rejected', strpos(lastText(), 'valid phone number') !== false);
say($UID, '+1 202 555 0101');
check('step 3 asks for contact method', strpos(lastText(), 'contact</b> you') !== false);
say($UID, '@abebe_tg');
check('step 4 asks for a profile photo', strpos(lastText(), 'profile photo') !== false);

// Text at the photo step must re-prompt, not skip the photo.
say($UID, 'here it is');
check('text at the photo step re-prompts', strpos(lastText(), 'send a <b>photo</b>') !== false);

check('photo accepted', trHandlePhoto($UID, ['photo' => [['file_id' => 'SMALL'], ['file_id' => 'PHOTO_BIG']]]) === true);
check('largest photo size kept', ($db->getState($UID)['data']['photo_file_id'] ?? '') === 'PHOTO_BIG');
check('step 5 asks usual origin', strpos(lastText(), 'usual <b>origin</b>') !== false);
say($UID, 'DMV');
check('step 6 asks usual destination', strpos(lastText(), 'usual <b>destination</b>') !== false);
say($UID, 'Addis Ababa');

check('agreement step reached', strpos(lastText(), 'I Agree & Submit') !== false || strpos(labels(), 'I Agree & Submit') !== false);
check('agreement restates the disclaimer', strpos(lastText(), 'does <b>not</b> guarantee transportation') !== false);
check('agreement shows the entered details', strpos(lastText(), 'Abebe Kebede') !== false);

$tdb = tr_db();
check('nothing saved before the agreement is accepted', hl_tr_by_telegram($tdb, $UID) === null);

reset_out();
trApplySubmit($UID, $db->getState($UID));
$me = hl_tr_by_telegram($tdb, $UID);
check('application saved', $me !== null);
check('verification_status = pending', $me['verification_status'] === 'pending');
check('agreement timestamped', !empty($me['agreed_at']));
check('profile photo saved to disk for the website', $me['photo_path'] !== '' && is_file(__DIR__ . '/' . $me['photo_path']));
check('user told it is under review', strpos(allText(), 'Under review') !== false);

$adminSaw = '';
foreach ($GLOBALS['__OUT'] as $o) { if ((int) $o['uid'] === $ADMIN) $adminSaw .= $o['text'] . "\n"; }
check('admins notified of the application', strpos($adminSaw, 'New Verified Transporter application') !== false);
check('admin notice carries the internal transporter id', strpos($adminSaw, '#' . $me['id']) !== false);

$TRID = (int) $me['id'];

// ---------------------------------------------------------------------------
echo "\n[4] ACCEPTANCE: pending user tries to post -> pending message, no posting\n";
reset_out();
trPostStart($UID);
check('pending user sees the under-review message', strpos(lastText(), 'under review') !== false);
check('pending user cannot post', count($GLOBALS['__GROUP']) === 0);

// ---------------------------------------------------------------------------
echo "\n[5] Admin approval\n";
reset_out();
trModerate($CUST, $TRID, 'approve');          // a NON-admin must not be able to
check('non-admin cannot approve', hl_tr_by_id($tdb, $TRID)['verification_status'] === 'pending');

trModerate($ADMIN, $TRID, 'approve');
check('approved', hl_tr_by_id($tdb, $TRID)['verification_status'] === 'approved');
check('approved_at stamped', !empty(hl_tr_by_id($tdb, $TRID)['approved_at']));
check('transporter prompted to create a username', strpos(allText(), 'Choose your public HabeshaList username') !== false);

reset_out();
trModerate($ADMIN, $TRID, 'approve');
check('double approval is refused', strpos(lastText(), 'already handled') !== false);

// ---------------------------------------------------------------------------
echo "\n[6] ACCEPTANCE: approved user without a username -> username creation required\n";
reset_out();
trPostStart($UID);
check('posting blocked until a username exists', strpos(lastText(), 'Create your public HabeshaList username') !== false);
check('offered the username step', strpos(labels(), 'Create my username') !== false);

// ---------------------------------------------------------------------------
echo "\n[7] Username rules\n";
reset_out();
trPromptUsername($UID);
say($UID, 'ab');             check('too short rejected',   strpos(lastText(), 'at least 4') !== false);
say($UID, 'abebe travel');   check('spaces rejected',      strpos(lastText(), 'No spaces') !== false);
say($UID, 'abebe-travel');   check('hyphen rejected',      strpos(lastText(), 'letters, numbers and underscore') !== false);
say($UID, 'admin');          check('reserved rejected',    strpos(lastText(), 'reserved') !== false);
say($UID, 'pornstar1');      check('inappropriate rejected', strpos(lastText(), 'not allowed') !== false);
say($UID, '@AbebeTravel');
$me = hl_tr_by_id($tdb, $TRID);
check('username claimed', $me['username'] === 'AbebeTravel');
check('lowercase key stored for CI uniqueness', $me['username_lower'] === 'abebetravel');
check('confirmation mentions the website', strpos(allText(), 'HabeshaList.com') !== false);
check('website profile link offered', strpos(allText(), 'habeshalist.com/transporters/AbebeTravel') !== false
     || strpos(json_encode($GLOBALS['__OUT'], JSON_UNESCAPED_SLASHES), 'transporters/AbebeTravel') !== false);

// A second transporter cannot take it in any casing.
$TR2 = hl_tr_apply($tdb, ['telegram_id' => 880022, 'full_name' => 'Marta T', 'origin' => 'Seattle', 'destination' => 'Addis Ababa']);
hl_tr_set_status($tdb, $TR2, 'approved');
check('case-insensitive uniqueness holds', hl_tr_set_username($tdb, $TR2, 'ABEBETRAVEL') !== '');
hl_tr_set_username($tdb, $TR2, 'MartaGo');

// ---------------------------------------------------------------------------
echo "\n[8] Website profile data (privacy)\n";
$pub = hl_tr_public(hl_tr_by_id($tdb, $TRID), $tdb);
check('public shape exposes @username', $pub['username'] === 'AbebeTravel');
check('public shape exposes the route',  $pub['origin'] === 'DMV' && $pub['destination'] === 'Addis Ababa');
check('public shape HIDES telegram id',  !isset($pub['telegram_id']));
check('public shape HIDES internal id',  !isset($pub['id']));
check('public shape HIDES phone number', !isset($pub['phone_number']));
check('public shape HIDES admin notes',  !isset($pub['admin_notes']));

// ---------------------------------------------------------------------------
echo "\n[9] ACCEPTANCE: approved user creates a post -> preview generated correctly\n";
$db->setSetting('tr_ads_enabled', '1');
$db->setSetting('tr_ad_fee', '5');
$db->setSetting('tr_free_posts', '0');

reset_out();
trPostStart($UID);
check('post flow starts at origin', strpos(lastText(), 'travelling <b>from</b>') !== false);
check('usual origin offered as a hint', strpos(lastText(), 'DMV') !== false);
say($UID, 'DMV');            check('asks destination', strpos(lastText(), 'travelling <b>to</b>') !== false);
say($UID, 'Addis Ababa');    check('asks travel date', strpos(lastText(), 'travel date') !== false);
say($UID, '15 October 2026'); check('asks available space', strpos(lastText(), '<b>space</b>') !== false);
say($UID, '2 suitcases, up to 20kg'); check('asks their price', strpos(lastText(), 'your price') !== false);
say($UID, '$8 per kg');      check('asks contact method', strpos(lastText(), 'contact</b> you') !== false);

reset_out();
say($UID, '@abebe_tg');
$preview = allText();
check('preview shows the Verified Transporter badge', strpos($preview, 'Verified Transporter') !== false);
check('preview shows the @username',      strpos($preview, '@AbebeTravel') !== false);
check('preview shows the route',          strpos($preview, 'DMV') !== false && strpos($preview, 'Addis Ababa') !== false);
check('preview shows the date',           strpos($preview, '15 October 2026') !== false);
check('preview shows available space',    strpos($preview, '2 suitcases') !== false);
check('preview shows the transporter price', strpos($preview, '$8 per kg') !== false);
check('preview shows the contact method', strpos($preview, '@abebe_tg') !== false);
check('preview shows the website profile link', strpos($preview, 'transporters/AbebeTravel') !== false);
check('preview shows the disclaimer',     strpos($preview, 'do not receive, transport') !== false);
check('preview offers Edit | Promote This Post | Cancel',
      strpos(labels(), 'Edit') !== false && strpos(labels(), 'Promote This Post') !== false && strpos(labels(), 'Cancel') !== false);
check('nothing published from the preview', count($GLOBALS['__GROUP']) === 0);

echo "\n[10] Edit from the preview\n";
reset_out();
trEditMenu($UID, $db->getState($UID));
check('edit menu lists every field', strpos(labels(), 'Date') !== false && strpos(labels(), 'Price') !== false);
trEditField($UID, 'price', $db->getState($UID));
say($UID, '$10 per kg');
check('edited price appears in the new preview', strpos(allText(), '$10 per kg') !== false);
check('other fields survived the edit', strpos(allText(), '2 suitcases') !== false);

// ---------------------------------------------------------------------------
echo "\n[11] Advertising fee is admin-controlled and frozen at checkout\n";
reset_out();
trShowPayment($UID, $db->getState($UID)['data']);
check('fee shown from admin settings ($5)', strpos(lastText(), '$5') !== false);
check('states the fee is advertising, not shipping', strpos(lastText(), 'not</b> a transportation') !== false);
check('offers Pay by Card',              strpos(labels(), 'Pay by Card') !== false);
check('offers Submit Payment Screenshot', strpos(labels(), 'Submit Payment Screenshot') !== false);
check('fee frozen into the session', (float) ($db->getState($UID)['data']['ad_fee'] ?? 0) === 5.0);

// Admin raises the price mid-flow; the in-progress checkout must NOT change.
$db->setSetting('tr_ad_fee', '20');
check('in-flight checkout keeps the frozen $5', (float) ($db->getState($UID)['data']['ad_fee'] ?? 0) === 5.0);

// ---------------------------------------------------------------------------
echo "\n[12] ACCEPTANCE: screenshot payment submitted -> waits for admin approval\n";
reset_out();
trHandlePayMethod($UID, 'manual', $db->getState($UID));
check('manual payment instructions shown', strpos(lastText(), 'screenshot') !== false);

// Tapping "I've sent the payment" with no screenshot must ask for one.
trManualProceed($UID, $db->getState($UID), null);
check('screenshot required before submitting', strpos(lastText(), 'send the payment <b>screenshot</b>') !== false);

reset_out();
check('screenshot accepted', trHandlePhoto($UID, ['photo' => [['file_id' => 'PROOF_IMG']]]) === true);
check('user told it awaits verification', strpos(allText(), 'Awaiting verification') !== false);
check('NOT published while awaiting verification', count($GLOBALS['__GROUP']) === 0);

$posts = hl_tr_posts($tdb, $TRID);
$post  = $posts[0];
check('post saved as pending_review', $post['post_status'] === 'pending_review');
check('payment_status = pending_review', $post['payment_status'] === 'pending_review');
check('frozen fee stored on the post ($5, not the new $20)', (float) $post['ad_fee'] === 5.0);
check('payment proof stored', $post['payment_proof'] === 'PROOF_IMG');
check('post keyed on the INTERNAL transporter id', (int) $post['transporter_id'] === $TRID);

$adminSaw = '';
foreach ($GLOBALS['__OUT'] as $o) { if ((int) $o['uid'] === $ADMIN) $adminSaw .= $o['text'] . "\n"; }
check('admins notified with Approve & Publish', strpos($adminSaw, 'awaiting payment verification') !== false);

$POSTID = (int) $post['id'];

// ---------------------------------------------------------------------------
echo "\n[13] ACCEPTANCE: admin approves screenshot -> post publishes ONCE\n";
reset_out();
trModeratePost($CUST, $POSTID, true);          // non-admin
check('non-admin cannot publish', count($GLOBALS['__GROUP']) === 0);

trModeratePost($ADMIN, $POSTID, true);
check('published to the group exactly once', count($GLOBALS['__GROUP']) === 1);
$g = $GLOBALS['__GROUP'][0];
check('group post has the Verified badge', strpos($g, 'Verified Transporter') !== false);
check('group post has the @username',      strpos($g, '@AbebeTravel') !== false);
check('group post has the route',          strpos($g, 'Addis Ababa') !== false);
check('group post has the profile link',   strpos($g, 'transporters/AbebeTravel') !== false);
check('group post has the disclaimer',     strpos($g, 'do not receive, transport') !== false);

$post = hl_tr_post_by_id($tdb, $POSTID);
check('post_status = published', $post['post_status'] === 'published');
check('telegram message id stored', (int) $post['tg_message_id'] > 0);
check('published_at stamped', !empty($post['published_at']));
check('transporter notified', strpos(allText(), 'your post is live') !== false);

// The duplicate guard.
reset_out();
trModeratePost($ADMIN, $POSTID, true);
check('DUPLICATE publish refused', count($GLOBALS['__GROUP']) === 0);
check('admin told it was already published', strpos(lastText(), 'already been published') !== false);

// ---------------------------------------------------------------------------
echo "\n[14] ACCEPTANCE: card payment succeeds -> post publishes once\n";
reset_out();
$db->setSetting('tr_ad_fee', '5');
$db->setSetting('stripe_key', 'sk_test_mock_key');   // without a key the flow correctly falls back to manual
$postsBeforeCard = count(hl_tr_posts($tdb, $TRID));
$db->setState($UID, 'tr_p_payment', [
    'transporter_id' => $TRID, 'origin' => 'DMV', 'destination' => 'Addis Ababa',
    'travel_date' => '20 November 2026', 'space' => '1 suitcase', 'price' => '$9 per kg',
    'contact_method' => '@abebe_tg', 'ad_fee' => 5.0,
]);
trHandlePayMethod($UID, 'card', $db->getState($UID));
check('checkout link offered', strpos(json_encode($GLOBALS['__OUT'], JSON_UNESCAPED_SLASHES), 'checkout.stripe.test') !== false);
check('nothing published before payment is confirmed', count($GLOBALS['__GROUP']) === 0);

reset_out();
trCheckCard($UID, $db->getState($UID));        // Stripe reports paid
check('card-paid post published immediately', count($GLOBALS['__GROUP']) === 1);
check('user told the post is live', strpos(allText(), 'post is live') !== false);
check('exactly ONE new post created by the card flow', count(hl_tr_posts($tdb, $TRID)) === $postsBeforeCard + 1);
$cardPost = hl_tr_posts($tdb, $TRID)[0];
check('card post marked published', $cardPost['post_status'] === 'published');
check('card payment reference stored', strpos((string) $cardPost['payment_ref'], 'HLT-CARD-') === 0);
check('card post payment_status = approved', $cardPost['payment_status'] === 'approved');

echo "\n[15] Card payment FAILS -> do NOT publish\n";
reset_out();
$before = count(hl_tr_posts($tdb, $TRID));
trCheckCard($UID, ['state' => 'tr_p_card', 'data' => ['transporter_id' => $TRID, '_stripe_session' => 'cs_unpaid_xyz']]);
check('unconfirmed card payment does not publish', count($GLOBALS['__GROUP']) === 0);
check('no post created for an unpaid checkout', count(hl_tr_posts($tdb, $TRID)) === $before);
check('user offered to re-check', strpos(labels(), 'Check payment status') !== false);

// ---------------------------------------------------------------------------
echo "\n[16] Free introductory posts (pilot)\n";
reset_out();
$db->setSetting('tr_free_posts', '2');
$fresh = hl_tr_by_id($tdb, $TR2);
$f = hl_tr_fee_for($fresh, tr_settings());
check('intro post is free while the allowance lasts', $f['fee'] == 0 && $f['free_left'] === 2);
trFinalize(880022, [
    'transporter_id' => $TR2, 'origin' => 'Seattle', 'destination' => 'Addis Ababa',
    'travel_date' => '1 Dec 2026', 'space' => '1 bag', 'price' => '$7/kg',
    'contact_method' => '@marta', 'payment_status' => 'free', 'payment_method' => 'free',
    '_free_reason' => 'intro', 'ad_fee' => 0,
]);
check('free post published without payment', count($GLOBALS['__GROUP']) === 1);
check('free allowance consumed', (int) hl_tr_by_id($tdb, $TR2)['free_posts_used'] === 1);
$db->setSetting('tr_free_posts', '0');

// ---------------------------------------------------------------------------
echo "\n[17] Find a Transporter\n";
reset_out();
trFindStart($CUST);
check('asks From', strpos(lastText(), 'from</b>') !== false);
say($CUST, 'DMV');
check('asks To', strpos(lastText(), 'to</b>') !== false);
say($CUST, 'Addis Ababa');
$res = allText();
check('finds the matching transporter', strpos($res, '@AbebeTravel') !== false);
check('result shows the route', strpos($res, 'Addis Ababa') !== false);
check('result shows the rating', strpos($res, 'No ratings yet') !== false || strpos($res, 'rating') !== false);
check('result offers a View Profile link', strpos(json_encode($GLOBALS['__OUT'], JSON_UNESCAPED_SLASHES), 'transporters/AbebeTravel') !== false);

reset_out();
trFindStart($CUST); say($CUST, 'Tokyo'); say($CUST, 'Oslo');
check('no match returns a friendly empty state', strpos(allText(), 'No Verified Transporters match') !== false);

// ---------------------------------------------------------------------------
echo "\n[18] ACCEPTANCE: customer rates @username -> saves and the average updates\n";
reset_out();
trRateStart($CUST);
check('asks for the @username', strpos(lastText(), 'username') !== false);
say($CUST, '@NoSuchPerson');
check('unknown username handled kindly', strpos(lastText(), "couldn't find") !== false);
say($CUST, '@abebetravel');                   // case-insensitive
check('shows WHO is being rated', strpos(lastText(), '@AbebeTravel') !== false);
check('offers 1-5 stars', strpos(labels(), '5') !== false && strpos(labels(), '1') !== false);

reset_out();
trRateStars($CUST, 5, $db->getState($CUST));
check('rating saved', (int) hl_tr_by_id($tdb, $TRID)['rating_count'] === 1);
check('average updated for the website', hl_tr_avg(hl_tr_by_id($tdb, $TRID)) === 5.0);
check('comment ALWAYS offered after a rating', strpos(lastText(), 'leave a comment') !== false);
check('comment is clearly optional', strpos(lastText(), 'optional') !== false);

say($CUST, 'Very reliable, arrived on time.');
check('comment attached to the rating', hl_tr_ratings($tdb, $TRID)[0]['comment'] === 'Very reliable, arrived on time.');
check('thank-you shown', strpos(allText(), 'Thank you for your feedback') !== false);
check('rating stored against the INTERNAL id', (int) hl_tr_ratings($tdb, $TRID)[0]['transporter_id'] === $TRID);
check('reviewer telegram id stored', (int) hl_tr_ratings($tdb, $TRID)[0]['reviewer_tid'] === $CUST);

// Skipping the comment must still complete cleanly.
reset_out();
trRateStart($CUST); say($CUST, '@AbebeTravel'); trRateStars($CUST, 4, $db->getState($CUST));
trRateDone($CUST, $db->getState($CUST)['data']);
check('comment can be skipped', strpos(allText(), 'Thank you for your feedback') !== false);
check('average recalculated to 4.5', hl_tr_avg(hl_tr_by_id($tdb, $TRID)) === 4.5);

// ---------------------------------------------------------------------------
echo "\n[19] ACCEPTANCE: 3 low ratings from 3 users -> review flag, NO auto-suspend\n";
$LOW = hl_tr_apply($tdb, ['telegram_id' => 880033, 'full_name' => 'Test Low', 'origin' => 'NY', 'destination' => 'Addis']);
hl_tr_set_status($tdb, $LOW, 'approved');
hl_tr_set_username($tdb, $LOW, 'TestLow01');

reset_out();
foreach ([[701, 3], [701, 1], [702, 2]] as $i => $pair) {
    list($rid, $stars) = $pair;
    $db->setState($rid, 'tr_r_stars', ['transporter_id' => $LOW, 'username' => 'TestLow01']);
    trRateStars($rid, $stars, $db->getState($rid));
}
check('3-star does not count as low', (int) hl_tr_by_id($tdb, $LOW)['admin_review_required'] === 0);
check('two distinct low raters do not trigger', hl_tr_low_distinct_count($tdb, $LOW) === 2);

// A third low rating from an ALREADY-counted reviewer must NOT trigger.
$db->setState(701, 'tr_r_stars', ['transporter_id' => $LOW, 'username' => 'TestLow01']);
trRateStars(701, 1, $db->getState(701));
check('repeat low rating from the SAME user does not trigger', (int) hl_tr_by_id($tdb, $LOW)['admin_review_required'] === 0);

reset_out();
$db->setState(703, 'tr_r_stars', ['transporter_id' => $LOW, 'username' => 'TestLow01']);
trRateStars(703, 2, $db->getState(703));
check('THIRD DISTINCT low rater raises the flag', (int) hl_tr_by_id($tdb, $LOW)['admin_review_required'] === 1);
check('NO automatic suspension', hl_tr_by_id($tdb, $LOW)['verification_status'] === 'approved');

$adminSaw = '';
foreach ($GLOBALS['__OUT'] as $o) { if ((int) $o['uid'] === $ADMIN) $adminSaw .= $o['text'] . "\n"; }
check('admins alerted', strpos($adminSaw, 'flagged for review') !== false);
check('admin told they are NOT suspended', strpos($adminSaw, 'have <b>not</b> been suspended') !== false);

$adminRows = '';
foreach ($GLOBALS['__OUT'] as $o) { if ((int) $o['uid'] === $ADMIN) $adminRows .= json_encode($o['rows']); }
check('offers Keep Active',  strpos($adminRows, 'Keep Active') !== false);
check('offers Send Warning', strpos($adminRows, 'Send Warning') !== false);
check('offers Suspend',      strpos($adminRows, 'Suspend') !== false);

echo "\n[20] Admin review actions\n";
reset_out();
trReviewAction($ADMIN, $LOW, 'warn');
check('Send Warning clears the flag', (int) hl_tr_by_id($tdb, $LOW)['admin_review_required'] === 0);
check('warning timestamped', !empty(hl_tr_by_id($tdb, $LOW)['warned_at']));
check('still approved after a warning', hl_tr_by_id($tdb, $LOW)['verification_status'] === 'approved');
check('transporter received the warning', strpos(allText(), 'note from the HabeshaList team') !== false);

// ---------------------------------------------------------------------------
echo "\n[21] ACCEPTANCE: suspended -> new posts blocked, website profile hidden\n";
$beforeRatings = count(hl_tr_ratings($tdb, $LOW));
$beforePosts   = count(hl_tr_posts($tdb, $LOW));
reset_out();
trReviewAction($ADMIN, $LOW, 'suspend');
check('status = suspended', hl_tr_by_id($tdb, $LOW)['verification_status'] === 'suspended');

$dirNames = array_map(function ($t) { return $t['username']; }, hl_tr_directory($tdb));
check('hidden from the public directory', !in_array('TestLow01', $dirNames, true));
check('hidden from search', count(hl_tr_search($tdb, 'NY', 'Addis')) === 0);

reset_out();
trPostStart(880033);
check('new posts blocked', strpos(lastText(), 'suspended') !== false);
check('nothing published', count($GLOBALS['__GROUP']) === 0);

check('past ratings NOT deleted', count(hl_tr_ratings($tdb, $LOW)) === $beforeRatings);
check('past posts NOT deleted',   count(hl_tr_posts($tdb, $LOW)) === $beforePosts);

// Even a fully paid post must not publish for a suspended transporter.
reset_out();
$pid = hl_tr_create_post($tdb, ['transporter_id' => $LOW, 'origin' => 'NY', 'destination' => 'Addis',
                                'payment_status' => 'approved', 'post_status' => 'pending_review']);
trModeratePost($ADMIN, $pid, true);
check('paid post still blocked while suspended', count($GLOBALS['__GROUP']) === 0);

// ---------------------------------------------------------------------------
echo "\n[22] Username change needs admin approval\n";
reset_out();
trPromptUsername($UID);
check('offers a change for an existing username', strpos(lastText(), 'public username is') !== false);
say($UID, 'AbebeExpress');
check('current username unchanged while pending', hl_tr_by_id($tdb, $TRID)['username'] === 'AbebeTravel');
check('change recorded as pending', hl_tr_by_id($tdb, $TRID)['username_pending'] === 'AbebeExpress');
check('user told it is under review', strpos(allText(), 'review the change') !== false);

reset_out();
trModerateUsername($ADMIN, $TRID, true);
check('approved change applied', hl_tr_by_id($tdb, $TRID)['username'] === 'AbebeExpress');
check('history still keyed on the internal id', (int) hl_tr_posts($tdb, $TRID)[0]['transporter_id'] === $TRID);
check('ratings survive the username change', (int) hl_tr_by_id($tdb, $TRID)['rating_count'] === 2);

// ---------------------------------------------------------------------------
echo "\n[23] Module switch + publish failure safety net\n";
reset_out();
$db->setSetting('tr_module_enabled', '0');
trPostStart($UID);
check('module can be switched off', strpos(lastText(), 'temporarily unavailable') !== false);
$db->setSetting('tr_module_enabled', '1');

// A dead photo file_id must not lose a paid ad - it falls back to a text post.
reset_out();
$tg->failNextSend = true;
trFinalize($UID, [
    'transporter_id' => $TRID, 'origin' => 'DMV', 'destination' => 'Addis Ababa',
    'travel_date' => '5 Jan 2027', 'space' => '1 bag', 'price' => '$9/kg',
    'contact_method' => '@abebe_tg', 'payment_status' => 'approved',
    'payment_method' => 'card', 'payment_ref' => 'HLT-CARD-FB01', 'ad_fee' => 5.0,
]);
check('photo send failure falls back to a text post', count($GLOBALS['__GROUP']) === 1);
check('fallback post still carries the ad', strpos($GLOBALS['__GROUP'][0] ?? '', '@AbebeExpress') !== false);
check('fallback post marked published', hl_tr_posts($tdb, $TRID)[0]['post_status'] === 'published');

// When the group is genuinely unreachable, the PAID post must be kept and the
// admins told - a customer must never pay and silently get nothing.
reset_out();
$tg->failAllSends = true;
$postsBefore = count(hl_tr_posts($tdb, $TRID));
trFinalize($UID, [
    'transporter_id' => $TRID, 'origin' => 'DMV', 'destination' => 'Addis Ababa',
    'travel_date' => '9 Feb 2027', 'space' => '1 bag', 'price' => '$9/kg',
    'contact_method' => '@abebe_tg', 'payment_status' => 'approved',
    'payment_method' => 'card', 'payment_ref' => 'HLT-CARD-FAIL01', 'ad_fee' => 5.0,
]);
$tg->failAllSends = false;
check('paid post is NOT lost when publishing fails', count(hl_tr_posts($tdb, $TRID)) === $postsBefore + 1);
check('failed post is NOT marked published', hl_tr_posts($tdb, $TRID)[0]['post_status'] !== 'published');
check('user is reassured rather than blamed', strpos(allText(), 'payment is confirmed') !== false);
$adminSaw = '';
foreach ($GLOBALS['__OUT'] as $o) { if ((int) $o['uid'] === $ADMIN) $adminSaw .= $o['text'] . "\n"; }
check('admins alerted to the failed publish', strpos($adminSaw, 'could not be published') !== false);

echo "\n=====================================================\n";
echo "  PASS: {$pass}   FAIL: {$fail}\n";
echo "=====================================================\n";

@unlink($dbPath);
exit($fail > 0 ? 1 : 0);
