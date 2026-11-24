<?php
/**
 * test_listing_fees.php - paid posting for classified ads, end to end.
 *
 * Drives the REAL webhook.php handlers with a mock Telegram, a temporary
 * database, a fake Stripe (__HL_STRIPE_STUB) and a fake website bridge
 * (__HL_BRIDGE_STUB). Nothing touches the network. Run:  php test_listing_fees.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = []; $GLOBALS['API'] = []; $GLOBALS['API_FAIL'] = [];
require __DIR__ . '/includes/telegram.php';
class MockTelegram extends Telegram {
    public function __construct() {}
    private function rec($uid, $text, $rows = []) { $GLOBALS['__OUT'][] = ['uid' => $uid, 'text' => $text, 'rows' => $rows]; return ['ok' => true, 'result' => ['message_id' => 1]]; }
    public function sendMessage($uid, $text, $extra = null) { return $this->rec($uid, $text); }
    public function sendInlineButtons($uid, $text, $rows) { return $this->rec($uid, $text, $rows); }
    public function sendPhoto($uid, $f, $c = null) { return $this->rec($uid, (string) $c); }
    public function sendMediaGroup($uid, $i) { return ['ok' => true]; }
    public function editMessageText($uid, $mid, $text, $m = null) { return $this->rec($uid, $text); }
    public function editMessageReplyMarkup($uid, $mid, $m = null) { return ['ok' => true]; }
    public function answerCallbackQuery($id, $t = null) { return ['ok' => true]; }
    public function deleteMessage($c, $m) { return ['ok' => true]; }
    public function callApi($m, $p = []) {
        if ($m === 'getMe') return ['ok' => true, 'result' => ['username' => 'HabeshaListBot']];
        $GLOBALS['API'][] = ['method' => $m, 'params' => $p];
        if (in_array($m, $GLOBALS['API_FAIL'], true)) return ['ok' => false, 'description' => "Bad Request: mock $m failure"];
        return ['ok' => true, 'result' => ['message_id' => 4242]];
    }
}

$dbPath = sys_get_temp_dir() . '/hl_lf_test_' . getmypid() . '.sqlite';
@unlink($dbPath);
require __DIR__ . '/includes/database.php';
$db = new Database($dbPath);
$tg = new MockTelegram();

// ---- Fake Stripe: sessions live in $GLOBALS['SESS'] ----------------------------
$GLOBALS['SESS'] = []; $GLOBALS['CREATED'] = 0; $GLOBALS['EXPIRED'] = [];
$GLOBALS['__HL_STRIPE_STUB'] = function ($verb, $url, $params) {
    if ($verb === 'POST' && preg_match('#/checkout/sessions$#', $url)) {
        $GLOBALS['CREATED']++;
        $id = 'cs_test_' . $GLOBALS['CREATED'];
        $GLOBALS['SESS'][$id] = ['id' => $id, 'url' => 'https://checkout.stripe.test/' . $id, 'status' => 'open', 'payment_status' => 'unpaid',
            'amount_total' => (int) $params['line_items[0][price_data][unit_amount]'], 'currency' => $params['line_items[0][price_data][currency]'],
            'metadata' => ['lf_payment_id' => $params['metadata[lf_payment_id]'] ?? null], 'payment_intent' => null];
        return $GLOBALS['SESS'][$id];
    }
    if (preg_match('#/checkout/sessions/([^/]+)/expire$#', $url, $m)) {
        $GLOBALS['EXPIRED'][] = $m[1];
        $GLOBALS['SESS'][$m[1]]['status'] = 'expired';
        return $GLOBALS['SESS'][$m[1]];
    }
    if (preg_match('#/checkout/sessions/([^/?]+)$#', $url, $m)) {
        return $GLOBALS['SESS'][urldecode($m[1])] ?? ['error' => ['message' => 'No such session']];
    }
    return ['error' => ['message' => 'unexpected ' . $url]];
};
function pay_session($id, $amountCents = null) {
    $GLOBALS['SESS'][$id]['payment_status'] = 'paid';
    $GLOBALS['SESS'][$id]['status'] = 'complete';
    $GLOBALS['SESS'][$id]['payment_intent'] = 'pi_TEST' . strtoupper(substr(md5($id), 0, 8));
    if ($amountCents !== null) $GLOBALS['SESS'][$id]['amount_total'] = $amountCents;
}

// ---- Fake website bridge --------------------------------------------------------
$GLOBALS['BRIDGE'] = []; $GLOBALS['BRIDGE_FAIL'] = false;
$GLOBALS['__HL_BRIDGE_STUB'] = function ($payload) {
    if (($payload['action'] ?? '') !== 'create_listing') return ['success' => true];
    $GLOBALS['BRIDGE'][] = $payload;
    if ($GLOBALS['BRIDGE_FAIL']) return ['success' => false, 'error' => 'mock site down'];
    $GLOBALS['LAST_OID'] = 7000 + count($GLOBALS['BRIDGE']);
    $GLOBALS['BRIDGE'][count($GLOBALS['BRIDGE']) - 1]['_id'] = $GLOBALS['LAST_OID'];
    return ['success' => true, 'osclass_id' => $GLOBALS['LAST_OID']];
};

require __DIR__ . '/webhook.php';
$db->setSetting('stripe_key', 'sk_test_FAKE');

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; } }
function reset_out() { $GLOBALS['__OUT'] = []; }
function last() { $o = $GLOBALS['__OUT']; return end($o) ?: ['text' => '', 'rows' => []]; }
function all_text() { return implode("\n", array_column($GLOBALS['__OUT'], 'text')); }
function buttons() { $out = []; foreach ((last()['rows'] ?? []) as $row) foreach ($row as $b) $out[] = $b; return $out; }
function button_url($label) { foreach (buttons() as $b) if (strpos($b['text'], $label) !== false) return $b['url'] ?? ($b['callback_data'] ?? null); return null; }
function tap($uid, $data) { handleCallbackQuery(['id' => 'q', 'data' => $data, 'from' => ['id' => $uid], 'message' => ['chat' => ['id' => $uid], 'message_id' => 5]]); }
function say($uid, $text) { handleMessage(['message_id' => 9, 'chat' => ['id' => $uid, 'type' => 'private'], 'from' => ['id' => $uid], 'text' => $text]); }
function rows($where = '1=1') { $out = []; $r = lf_db()->query("SELECT * FROM listing_payments WHERE $where ORDER BY id"); while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out[] = $x; return $out; }
/** Put a finished ad on the review screen, as the real flow does. */
function ad_ready($uid, $cat, $sub, $title) {
    global $db, $config;
    $c = $config['categories'][$cat];
    $data = ['category' => $cat, 'category_name' => $c['name'], 'title' => $title, 'description' => 'Nice', 'price' => '500',
             'location_name' => 'Washington, DC', 'loc_country' => 'United States', 'loc_city' => 'Washington', 'photos' => []];
    if ($sub !== null) { $data['subcategory'] = $sub; $data['subcategory_name'] = $c['subcategories'][$sub]; }
    $db->setState($uid, 'awaiting_review', $data);
}

$U = 5550101; $U2 = 5550202;
$db->createUser($U, 'Saba Tesfaye', '+1 202 555 0101', 'saba@example.com');
$db->createUser($U2, 'Dawit K', '+1 202 555 0202', 'dawit@example.com');

// Fees: Housing $5 (Rooms for Rent inherits, Sale free), Community $3 with Others $2, the rest free.
$h = lf_db();
hl_lf_save_rule($h, 'housing', '', 'paid', 5);
hl_lf_save_rule($h, 'housing', 'sale', 'free', null);
hl_lf_save_rule($h, 'community', '', 'paid', 3);
hl_lf_save_rule($h, 'community', 'others', 'paid', 2);
hl_lf_save_rule($h, 'jobs', '', 'paid', 0.3);     // below Stripe's minimum = free

echo "Category list shared with the admin panel\n";
$snap = json_decode((string) $db->getSetting('lf_categories', ''), true);
check('bot saved its categories for the panel', ($snap['housing']['subcategories']['rent'] ?? '') === 'Houses & Rooms for Rent' && count($snap) === count($config['categories']));

echo "Fee rules\n";
$db->setSetting('lf_enabled', '0');
check('switch OFF: nothing is charged', lfFeeFor(['category' => 'housing', 'subcategory' => 'rent']) == 0.0);
$db->setSetting('lf_enabled', '1');
check('Rooms for Rent inherits Housing $5', lfFeeFor(['category' => 'housing', 'subcategory' => 'rent']) == 5.0);
check('a subcategory set to Free is free', lfFeeFor(['category' => 'housing', 'subcategory' => 'sale']) == 0.0);
check('a subcategory with its own price', lfFeeFor(['category' => 'community', 'subcategory' => 'others']) == 2.0);
check('a category with no rule is free', lfFeeFor(['category' => 'services', 'subcategory' => 'restaurant']) == 0.0);
check('under $0.50 counts as free', lfFeeFor(['category' => 'jobs', 'subcategory' => 'technology']) == 0.0);

echo "Main menu\n";
reset_out(); showMainMenu($U, 'Saba');
check('menu button is "Post to HabeshaList"', strpos(json_encode(last()['rows'], JSON_UNESCAPED_UNICODE), 'Post to HabeshaList') !== false && strpos(json_encode(last()['rows']), 'Post to Website') === false);
reset_out(); sendGroupButtons(-100123);
check('group menu button is "Post to HabeshaList" too', strpos(json_encode(last()['rows'], JSON_UNESCAPED_UNICODE), 'Post to HabeshaList') !== false && strpos(json_encode(last()['rows']), 'Post to Website') === false);

echo "Paid category: pay first, then publish\n";
ad_ready($U, 'housing', 'rent', 'Room near Silver Spring metro');
reset_out(); tap($U, 'confirm_publish');
check('nothing sent to the website before payment', count($GLOBALS['BRIDGE']) === 0);
check('payment screen shows the $5 fee', strpos(last()['text'], 'Posting fee: $5') !== false && strpos(last()['text'], 'Houses &amp; Rooms for Rent') !== false);
$payUrl = button_url('Pay $5 by card');
check('a Stripe card button for $5', $payUrl === 'https://checkout.stripe.test/cs_test_1');
check('Stripe was asked for 500 cents with the payment id', $GLOBALS['SESS']['cs_test_1']['amount_total'] === 500 && $GLOBALS['SESS']['cs_test_1']['metadata']['lf_payment_id'] == rows()[0]['id']);
check('payment row is pending', rows()[0]['status'] === 'pending' && (float) rows()[0]['amount'] === 5.0);
check('state waits for payment', $db->getState($U)['state'] === 'lf_awaiting_payment');

reset_out(); tap($U, 'lf_check');
check('not paid yet: still not published', count($GLOBALS['BRIDGE']) === 0 && strpos(last()['text'], "couldn't confirm") !== false);
reset_out(); tap($U, 'confirm_publish');                      // a double tap on Publish
check('tapping Publish again reuses the same checkout', $GLOBALS['CREATED'] === 1 && count(rows()) === 1);
reset_out(); say($U, 'hello?');                                // typed text while waiting
check('typing shows the payment screen again', strpos(last()['text'], 'Posting fee: $5') !== false);

pay_session('cs_test_1');
reset_out(); say($U, '/start lfpaid');                         // back from Stripe
check('published once after Stripe confirms', count($GLOBALS['BRIDGE']) === 1 && $GLOBALS['BRIDGE'][0]['title'] === 'Room near Silver Spring metro');
check('user told it is live', strpos(all_text(), 'now live') !== false);
$r = rows()[0];
check('payment marked published with the website id and a reference', $r['status'] === 'published' && $r['osclass_id'] === '7001' && strpos($r['payment_ref'], 'HLL-CARD-') === 0);
reset_out(); tap($U, 'lf_check'); say($U, '/start lfpaid');
check('coming back again never publishes twice', count($GLOBALS['BRIDGE']) === 1);

echo "Stripe answers that must NOT publish\n";
ad_ready($U, 'housing', 'rent', 'Second room'); tap($U, 'confirm_publish');
$all = rows(); $row2 = end($all); $sid = $row2['stripe_session'];
pay_session($sid, 100);                                        // paid, but only $1
reset_out(); tap($U, 'lf_check');
check('wrong amount: not published', count($GLOBALS['BRIDGE']) === 1 && lf_row($row2['id'])['status'] === 'pending');
$GLOBALS['SESS'][$sid]['amount_total'] = 500; $GLOBALS['SESS'][$sid]['metadata']['lf_payment_id'] = '999';
reset_out(); tap($U, 'lf_check');
check('session of another payment: not published', count($GLOBALS['BRIDGE']) === 1);
$GLOBALS['SESS'][$sid]['metadata']['lf_payment_id'] = (string) $row2['id'];

echo "Cancel\n";
reset_out(); tap($U, 'lf_cancel');
check('paid meanwhile: cancel publishes instead of dropping a paid ad', count($GLOBALS['BRIDGE']) === 2 && lf_row($row2['id'])['status'] === 'published');
ad_ready($U, 'community', 'others', 'Lost cat'); tap($U, 'confirm_publish');
$all = rows(); $row3 = end($all);
check('Community > Others charges its own $2', (float) $row3['amount'] === 2.0 && strpos(last()['text'], 'Posting fee: $2') !== false);
reset_out(); tap($U, 'lf_cancel');
check('unpaid cancel: not published, not charged, checkout closed', count($GLOBALS['BRIDGE']) === 2 && lf_row($row3['id'])['status'] === 'cancelled'
    && in_array($row3['stripe_session'], $GLOBALS['EXPIRED'], true) && strpos(all_text(), 'not charged') !== false);

echo "Free categories publish straight away\n";
ad_ready($U, 'housing', 'sale', 'House for sale'); reset_out(); tap($U, 'confirm_publish');
check('free subcategory: published, no payment row', count($GLOBALS['BRIDGE']) === 3 && count(rows("title = 'House for sale'")) === 0);
$db->setSetting('lf_enabled', '0');
ad_ready($U, 'housing', 'rent', 'Room while switch is off'); reset_out(); tap($U, 'confirm_publish');
check('switch OFF: paid category publishes for free', count($GLOBALS['BRIDGE']) === 4 && count(rows("title = 'Room while switch is off'")) === 0);
$db->setSetting('lf_enabled', '1');

echo "Pay later: reminder before the next ad\n";
ad_ready($U2, 'housing', 'rent', 'Basement room'); tap($U2, 'confirm_publish');
$all = rows(); $row4 = end($all); $created4 = $GLOBALS['CREATED'];
tap($U2, 'main_menu');
reset_out(); tap($U2, 'post_ad');
check('reminder about the unpaid ad', strpos(last()['text'], 'Basement room') !== false && strpos(last()['text'], 'waiting for its $5 posting fee') !== false);
reset_out(); tap($U2, 'lf_resume_' . $row4['id']);
check('Pay and publish reopens the payment (same checkout)', strpos(last()['text'], 'Posting fee: $5') !== false && $GLOBALS['CREATED'] === $created4);

echo "Edit after the payment screen\n";
reset_out(); tap($U2, 'lf_edit');
check('Edit opens the edit options', strpos(last()['text'], 'What would you like to edit?') !== false);
tap($U2, 'edit_title'); say($U2, 'Bright basement room');
reset_out(); tap($U2, 'confirm_publish');
check('same payment kept, title updated, same checkout', count(rows("telegram_id = $U2")) === 1 && lf_row($row4['id'])['title'] === 'Bright basement room' && $GLOBALS['CREATED'] === $created4);

echo "Website down after payment: retry without paying again\n";
pay_session($row4['stripe_session']);
$GLOBALS['BRIDGE_FAIL'] = true;
reset_out(); tap($U2, 'lf_check');
check('publish failed: payment kept as paid', lf_row($row4['id'])['status'] === 'paid' && strpos(all_text(), 'without paying again') !== false);
check('Try Again resumes this paid ad', button_url('Try Again') === 'lf_resume_' . $row4['id']);
$GLOBALS['BRIDGE_FAIL'] = false;
reset_out(); tap($U2, 'post_ad');
check('next visit: reminder to publish the paid ad', strpos(last()['text'], 'payment for <b>Bright basement room</b> was received') !== false);
reset_out(); tap($U2, 'lf_resume_' . $row4['id']);
$last = end($GLOBALS['BRIDGE']);
check('published on retry, no new checkout', $last['title'] === 'Bright basement room' && lf_row($row4['id'])['status'] === 'published' && $GLOBALS['CREATED'] === $created4);

echo "Price raised after paying: a paid fee is honoured\n";
ad_ready($U2, 'housing', 'rent', 'Paid before price change'); tap($U2, 'confirm_publish');
$all = rows(); $row5 = end($all); pay_session($row5['stripe_session']);
hl_lf_save_rule($h, 'housing', '', 'paid', 8);
$n = count($GLOBALS['BRIDGE']);
reset_out(); tap($U2, 'confirm_publish');
check('published with the earlier $5 payment, not cancelled', count($GLOBALS['BRIDGE']) === $n + 1 && lf_row($row5['id'])['status'] === 'published');

echo "Another user's payment cannot be used\n";
ad_ready($U, 'housing', 'rent', 'Sneaky'); tap($U, 'confirm_publish');
$all = rows(); $row6 = end($all); pay_session($row6['stripe_session']);
$n = count($GLOBALS['BRIDGE']);
reset_out(); tap($U2, 'lf_resume_' . $row6['id']);
reset_out(); tap($U2, 'lf_discard_' . $row6['id']);
check('other user cannot publish or discard it', count($GLOBALS['BRIDGE']) === $n && lf_row($row6['id'])['status'] === 'pending');
lf_check_stripe(lf_row($row6['id']));                 // recorded as paid (as if U's publish then failed)
check('row 6 is paid', lf_row($row6['id'])['status'] === 'paid');
ad_ready($U2, 'housing', 'rent', 'Forged'); $s = $db->getState($U2); $s['data']['_lf_paid'] = (int) $row6['id']; $s['data']['_lf_payment_id'] = (int) $row6['id'];
$db->setState($U2, 'awaiting_review', $s['data']);
reset_out(); tap($U2, 'confirm_publish');
check('a forged paid id in the state is ignored (new payment asked)', count($GLOBALS['BRIDGE']) === $n && strpos(last()['text'], 'Posting fee: $8') !== false);

echo "Share new listings in the Telegram group\n";
$GROUP = '-1001234567890';
$db->setSetting('sched_group_chat_id', $GROUP);
function group_posts() { return array_values(array_filter($GLOBALS['API'], fn ($c) => in_array($c['method'], ['sendPhoto', 'sendMessage'], true) && (string) ($c['params']['chat_id'] ?? '') === $GLOBALS['GROUP'])); }
$GLOBALS['GROUP'] = $GROUP;
$GLOBALS['API'] = [];
ad_ready($U, 'housing', 'sale', 'Sharing is off');
tap($U, 'confirm_publish');
check('sharing is OFF by default: nothing posted in the group', count(group_posts()) === 0);

$db->setSetting('ls_enabled', '1'); $db->setSetting('ls_scope', 'all');
ad_ready($U, 'housing', 'sale', 'Condo <for> sale & more');
$s = $db->getState($U); $s['data']['photos'] = ['PHOTO_FILE_1', 'PHOTO_FILE_2'];
$s['data']['description'] = str_repeat('Lovely two bedroom condo with parking and a big balcony. ', 30);
$db->setState($U, 'awaiting_review', $s['data']);
$n = count($GLOBALS['BRIDGE']);
reset_out(); tap($U, 'confirm_publish');
$posts = group_posts(); $post = $posts[0] ?? null; $oid = $GLOBALS['BRIDGE'][$n]['_id'] ?? null;
check('free listing shared once in the group, with its first photo', count($posts) === 1 && $post['method'] === 'sendPhoto' && $post['params']['photo'] === 'PHOTO_FILE_1');
$cap = $post['params']['caption'] ?? '';
check('caption has the title (HTML-escaped), category and location', strpos($cap, 'Condo &lt;for&gt; sale &amp; more') !== false && strpos($cap, 'Houses - Apartments for Sale') !== false && strpos($cap, 'Washington, DC') !== false);
check('long description shortened: caption under 1024 characters', mb_strlen(strip_tags(html_entity_decode($cap))) < 1024 && strpos($cap, '...') !== false);
$btn = $post['params']['reply_markup']['inline_keyboard'][0][0] ?? [];
check('"View on HabeshaList" button opens the website listing', ($btn['text'] ?? '') !== '' && strpos($btn['text'], 'View on HabeshaList') !== false
    && preg_match('#^https://www\.habeshalist\.com/index\.php\?page=item&id=7\d+$#', $btn['url'] ?? ''));
check('the user was told it is live before/regardless of the group post', strpos(all_text(), 'now live') !== false);
$lastId = $GLOBALS['LAST_OID'];
$GLOBALS['API'] = [];
lsShareListing(['title' => 'again'], $lastId, false);
check('the same listing is never shared twice', count(group_posts()) === 0);

echo "  paid listings are shared only after payment\n";
$GLOBALS['API'] = [];
ad_ready($U, 'housing', 'rent', 'Paid room to share'); tap($U, 'confirm_publish');
check('not shared while waiting for payment', count(group_posts()) === 0);
$all = rows(); $rowS = end($all); pay_session($rowS['stripe_session']);
tap($U, 'lf_check');
check('shared once after the payment is confirmed', count(group_posts()) === 1 && strpos(group_posts()[0]['params']['text'] ?? '', 'Paid room to share') !== false);

echo "  only paid listings\n";
$db->setSetting('ls_scope', 'paid'); $GLOBALS['API'] = [];
ad_ready($U, 'housing', 'sale', 'Free one, paid-only scope'); tap($U, 'confirm_publish');
check('scope "paid only": a free listing is not shared', count(group_posts()) === 0);
ad_ready($U, 'housing', 'rent', 'Paid one, paid-only scope'); tap($U, 'confirm_publish');
$all = rows(); $rowP = end($all); pay_session($rowP['stripe_session']); tap($U, 'lf_check');
check('scope "paid only": a paid listing is shared', count(group_posts()) === 1);
$db->setSetting('ls_scope', 'all');

echo "  failures never affect the listing\n";
$GLOBALS['API'] = []; $GLOBALS['API_FAIL'] = ['sendPhoto'];
ad_ready($U, 'housing', 'sale', 'Photo fails'); $s = $db->getState($U); $s['data']['photos'] = ['EXPIRED_FILE']; $db->setState($U, 'awaiting_review', $s['data']);
tap($U, 'confirm_publish');
$gp = group_posts();
check('photo failed: falls back to a text post', count($gp) === 2 && $gp[0]['method'] === 'sendPhoto' && $gp[1]['method'] === 'sendMessage');
$GLOBALS['API'] = []; $GLOBALS['API_FAIL'] = ['sendPhoto', 'sendMessage'];
$n = count($GLOBALS['BRIDGE']);
ad_ready($U, 'housing', 'sale', 'Group unreachable'); reset_out(); tap($U, 'confirm_publish');
$admin = (int) $config['admin_ids'][0];
$alerts = array_filter($GLOBALS['__OUT'], fn ($o) => (int) $o['uid'] === $admin && strpos($o['text'], 'could not be shared in the group') !== false);
check('group unreachable: listing still published, user told it is live', count($GLOBALS['BRIDGE']) === $n + 1 && strpos(all_text(), 'now live') !== false);
check('group unreachable: admin alerted, recorded as failed', count($alerts) === 1 && lf_db()->querySingle("SELECT status FROM listing_shares WHERE osclass_id = '" . $GLOBALS['LAST_OID'] . "'") === 'failed');
$GLOBALS['API_FAIL'] = [];
// Schedule Settings never saved (no row at all): use the same group promotions use.
lf_db()->exec("DELETE FROM settings WHERE key = 'sched_group_chat_id'"); $GLOBALS['API'] = [];
ad_ready($U, 'housing', 'sale', 'Group setting never saved'); tap($U, 'confirm_publish');
$sent = array_values(array_filter($GLOBALS['API'], fn ($c) => in_array($c['method'], ['sendPhoto', 'sendMessage'], true)));
check('group setting never saved: posted to the scheduler\'s default group (where promotions go)',
    count($sent) === 1 && (string) $sent[0]['params']['chat_id'] === HL_Scheduler::DEFAULTS['sched_group_chat_id']);
$db->setSetting('ls_enabled', '0');

echo "\n$pass passed, $fail failed\n";
@unlink($dbPath); @unlink($dbPath . '-wal'); @unlink($dbPath . '-shm');
exit($fail ? 1 : 0);
