<?php
/**
 * test_menu.php - the main menu, group menu, Travel Habesha (Mini App) and
 * Shop Habesha buttons (owner, 2026-11-28). Real webhook.php, mock Telegram.
 * Run:  php test_menu.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$GLOBALS['__OUT'] = [];
require __DIR__ . '/includes/telegram.php';
class MockTelegram extends Telegram {
    public function __construct() {}
    private function rec($uid, $text, $rows = []) { $GLOBALS['__OUT'][] = ['uid' => $uid, 'text' => $text, 'rows' => $rows]; return ['ok' => true, 'result' => ['message_id' => 1]]; }
    public function sendMessage($uid, $text, $extra = null) { return $this->rec($uid, $text, $extra['inline_keyboard'] ?? []); }
    public function sendInlineButtons($uid, $text, $rows) { return $this->rec($uid, $text, $rows); }
    public function editMessageReplyMarkup($uid, $mid, $m = null) { return ['ok' => true]; }
    public function answerCallbackQuery($id, $t = null) { return ['ok' => true]; }
    public function deleteMessage($c, $m) { return ['ok' => true]; }
    public function callApi($m, $p = []) { return ['ok' => true, 'result' => ['username' => 'HabeshaListBot']]; }
}
$dbPath = sys_get_temp_dir() . '/hl_menu_test_' . getmypid() . '.sqlite';
@unlink($dbPath);
require __DIR__ . '/includes/database.php';
$db = new Database($dbPath);
$tg = new MockTelegram();
require __DIR__ . '/webhook.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; } }
function last() { $o = $GLOBALS['__OUT']; return end($o) ?: ['text' => '', 'rows' => []]; }
function btns() { $out = []; foreach (last()['rows'] as $row) foreach ($row as $b) $out[] = $b; return $out; }
function labels() { return array_map(fn ($b) => $b['text'], btns()); }
function tap($uid, $data) { handleCallbackQuery(['id' => 'q', 'data' => $data, 'from' => ['id' => $uid], 'message' => ['chat' => ['id' => $uid], 'message_id' => 5]]); }
function say($uid, $text) { handleMessage(['message_id' => 9, 'chat' => ['id' => $uid, 'type' => 'private'], 'from' => ['id' => $uid], 'text' => $text]); }

$U = 6660101;
$db->createUser($U, 'Saba Tesfaye', '+1 202 555 0101', 'saba@example.com');

echo "Main menu\n";
showMainMenu($U, 'Saba');
check('exactly the 6 buttons, in order', labels() === ["\xF0\x9F\x93\x9D Post to HabeshaList", "\xF0\x9F\x93\xA2 Promote My Business", "\xE2\x9C\x88\xEF\xB8\x8F Travel Habesha",
    "\xF0\x9F\x9B\x8D\xEF\xB8\x8F Shop Habesha", "\xF0\x9F\x8E\x81 Invite & Earn", "\xF0\x9F\x93\x9E Contact Us"]);
check('no Luggage & Transport / My Dashboard on the main menu', !preg_grep('/Luggage|Dashboard/', labels()));
check('Shop Habesha opens shop.habeshalist.com', (btns()[3]['url'] ?? '') === 'https://shop.habeshalist.com');

echo "Travel Habesha\n";
tap($U, 'travel_menu');
$b = btns();
check('travel menu: 5 Mini App buttons + Main Menu', count($b) === 6 && strpos(last()['text'], 'Travel Habesha') !== false);
$want = ['send' => 'Send a Package', 'trip' => 'Post a Trip', 'travelers' => 'Find a Traveler', 'packages' => 'Packages Waiting', 'requests' => 'My Requests'];
$i = 0; $ok = true;
foreach ($want as $to => $label) {
    $ok = $ok && strpos($b[$i]['text'], $label) !== false && ($b[$i]['web_app']['url'] ?? '') === "https://travel.habeshalist.com/tg?to=$to" && !isset($b[$i]['url']);
    $i++;
}
check('each opens travel.habeshalist.com/tg?to=... as a Mini App (web_app)', $ok);
$db->setSetting('travel_url', 'https://staging.example.test/');
tap($U, 'travel_menu');
check('travel address can be changed by the admin setting', (btns()[0]['web_app']['url'] ?? '') === 'https://staging.example.test/tg?to=send');
$db->setSetting('travel_url', '');

echo "From the group\n";
sendGroupButtons(-1001234);
$gl = labels(); $gb = btns();
check('group menu: same 6 buttons', count($gl) === 6 && strpos($gl[2], 'Travel Habesha') !== false && strpos($gl[3], 'Shop Habesha') !== false && !preg_grep('/Luggage|Dashboard/', $gl));
check('group buttons are plain links (Mini Apps cannot open from a group)', count(array_filter($gb, fn ($x) => isset($x['web_app']))) === 0
    && $gb[2]['url'] === 'https://t.me/HabeshaListBot?start=travel' && $gb[3]['url'] === 'https://shop.habeshalist.com');
$NEW = 6660202;   // never registered in the bot
say($NEW, '/start travel');
check('/start travel works without bot registration', strpos(last()['text'], 'Travel Habesha') !== false && count(btns()) === 6 && !$db->getUser($NEW));
say($NEW, '/start post_ad');
check('other buttons still ask a new person to register first', strpos(json_encode(last()), 'Travel Habesha') === false);

echo "Promote My Business\n";
tap($U, 'promote');
check('My Dashboard is inside Promote My Business', in_array("\xF0\x9F\x93\x8A My Dashboard", labels(), true) && (array_values(array_filter(btns(), fn ($x) => strpos($x['text'], 'My Dashboard') !== false))[0]['callback_data'] ?? '') === 'promo_dashboard');

echo "\n$pass passed, $fail failed\n";
@unlink($dbPath); @unlink($dbPath . '-wal'); @unlink($dbPath . '-shm');
exit($fail ? 1 : 0);
