<?php
/**
 * listing_share.php - share new listings in the HabeshaList Telegram group.
 *
 * When a classified ad posted through the bot goes live on the website, the
 * bot also posts it in the group (the same group promotions go to, setting
 * sched_group_chat_id): first photo, title, price, category, location, a short
 * part of the description and a "View on HabeshaList" button.
 *
 * Settings (admin panel > Listing Fees > Share in the Telegram group):
 *   ls_enabled  '1' / '0'  (default off)
 *   ls_scope    'all' | 'paid'
 *
 * Self-contained and optional-at-load: webhook.php calls lsShareListing()
 * behind function_exists(), so without this file nothing changes. A group post
 * that fails never affects the listing itself - it stays live on the website.
 */

function hl_ls_ensure_schema(SQLite3 $h) {
    $h->exec("CREATE TABLE IF NOT EXISTS listing_shares (
        osclass_id  TEXT PRIMARY KEY,
        chat_id     TEXT,
        message_id  INTEGER,
        status      TEXT NOT NULL,
        error       TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function ls_db() {
    global $db;
    static $h = null;
    if ($h instanceof SQLite3) return $h;
    try {
        $h = new SQLite3($db->path(), SQLITE3_OPEN_READWRITE);
        $h->busyTimeout(4000);
        $h->exec('PRAGMA journal_mode=WAL');
        hl_ls_ensure_schema($h);
    } catch (\Throwable $e) { $h = null; }
    return $h;
}

function ls_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Cut to $max characters on a word boundary, with "..." when shortened. */
function ls_excerpt($text, $max) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,.;:-") . '...';
}

/** The group post text (HTML). Kept under Telegram's 1024-character photo caption limit. */
function lsPostText(array $adData) {
    $cat = trim(($adData['category_name'] ?? '') . (!empty($adData['subcategory_name']) ? ' > ' . $adData['subcategory_name'] : ''));
    $head = "\xF0\x9F\x86\x95 <b>NEW ON HABESHALIST</b>\n\n" .
        "<b>" . ls_h(ls_excerpt($adData['title'] ?? '', 120)) . "</b>\n";
    $price = trim((string) ($adData['price'] ?? ''));
    if ($price !== '') $head .= "\xF0\x9F\x92\xB0 Price: <b>" . ls_h(ls_excerpt($price, 40)) . "</b>\n";
    if ($cat !== '') $head .= "\xF0\x9F\x93\x82 " . ls_h($cat) . "\n";
    $loc = trim((string) ($adData['location_name'] ?? ''));
    if ($loc !== '') $head .= "\xF0\x9F\x93\x8D " . ls_h(ls_excerpt($loc, 80)) . "\n";
    $desc = ls_excerpt($adData['description'] ?? '', 350);
    return $head . ($desc !== '' ? "\n" . ls_h($desc) : '');
}

/** Should this listing be shared? */
function ls_wanted($isPaid) {
    global $db;
    if ($db->getSetting('ls_enabled', '0') !== '1') return false;
    if ($db->getSetting('ls_scope', 'all') === 'paid' && !$isPaid) return false;
    return trim((string) $db->getSetting('sched_group_chat_id', '')) !== '';
}

/**
 * Post a just-published listing in the group. Called by handlePublish() after
 * the website accepted the ad. Returns ['ok' => bool, ...]; never throws.
 */
function lsShareListing(array $adData, $osclassId, $isPaid) {
    global $tg, $db, $config;
    try {
        if (!$osclassId || !ls_wanted($isPaid)) return ['ok' => false, 'skipped' => true];
        $h = ls_db();
        if (!$h) return ['ok' => false, 'error' => 'database'];

        // Once per listing, even if publishing is retried.
        $st = $h->prepare("INSERT OR IGNORE INTO listing_shares (osclass_id, status) VALUES (:id, 'sending')");
        $st->bindValue(':id', (string) $osclassId, SQLITE3_TEXT);
        $st->execute();
        if ($h->changes() !== 1) return ['ok' => false, 'skipped' => true, 'error' => 'already shared'];

        $chat = trim((string) $db->getSetting('sched_group_chat_id', ''));
        $site = rtrim((string) ($config['website_url'] ?? 'https://www.habeshalist.com'), '/');
        $rows = [[['text' => "\xF0\x9F\x94\x97 View on HabeshaList", 'url' => $site . '/index.php?page=item&id=' . rawurlencode((string) $osclassId)]]];
        $bot = function_exists('getBotUsername') ? getBotUsername() : '';
        if ($bot && $bot !== 'bot') $rows[] = [['text' => "\xF0\x9F\x93\x9D Post your own ad", 'url' => 'https://t.me/' . $bot . '?start=post_ad']];
        $markup = ['inline_keyboard' => $rows];
        $text = lsPostText($adData);

        $photo = (string) (($adData['photos'] ?? [])[0] ?? '');
        $resp = null;
        if ($photo !== '') {
            $resp = $tg->callApi('sendPhoto', ['chat_id' => $chat, 'photo' => $photo, 'caption' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $markup]);
        }
        // No photo, or the photo failed: the text post still goes out.
        if (empty($resp['ok'])) {
            $resp = $tg->callApi('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $markup,
                'disable_web_page_preview' => true]);
        }

        $ok = !empty($resp['ok']);
        $up = $h->prepare("UPDATE listing_shares SET chat_id = :c, message_id = :m, status = :s, error = :e WHERE osclass_id = :id");
        $up->bindValue(':c', $chat, SQLITE3_TEXT);
        $up->bindValue(':m', (int) ($resp['result']['message_id'] ?? 0), SQLITE3_INTEGER);
        $up->bindValue(':s', $ok ? 'posted' : 'failed', SQLITE3_TEXT);
        $up->bindValue(':e', $ok ? null : (string) ($resp['description'] ?? 'send failed'), $ok ? SQLITE3_NULL : SQLITE3_TEXT);
        $up->bindValue(':id', (string) $osclassId, SQLITE3_TEXT);
        $up->execute();

        if (!$ok) {
            $why = (string) ($resp['description'] ?? 'no answer from Telegram');
            foreach (($config['admin_ids'] ?? []) as $aid) {
                $tg->sendMessage((int) $aid, "\xE2\x9A\xA0\xEF\xB8\x8F <b>A new listing could not be shared in the group.</b>\n" .
                    "Listing: <b>" . ls_h($adData['title'] ?? '') . "</b> (#" . ls_h($osclassId) . ") - it is live on the website.\n" .
                    "Telegram said: " . ls_h($why) . "\nCheck that the bot is still an admin of the group and the group ID in Schedule Settings.");
            }
            return ['ok' => false, 'error' => $why];
        }
        return ['ok' => true, 'message_id' => (int) ($resp['result']['message_id'] ?? 0)];
    } catch (\Throwable $e) {
        error_log('listing share failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
