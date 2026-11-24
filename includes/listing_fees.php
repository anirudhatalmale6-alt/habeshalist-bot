<?php
/**
 * listing_fees.php - paid posting for classified ads posted through the bot.
 *
 * The owner sets, per category and per subcategory, whether posting is free or
 * costs a fee (admin panel > Listing Fees). When a user publishes an ad in a
 * paid category, the bot asks for the fee by card (Stripe Checkout, verified
 * OUTBOUND like every other payment in this bot) and only sends the ad to the
 * website once Stripe confirms it was paid.
 *
 * Self-contained: owns two tables (listing_fees, listing_payments) and two
 * settings (lf_enabled, lf_currency). webhook.php only calls lfGate() before
 * publishing and lfAfterPublish() after, both behind function_exists(), so the
 * bot keeps working exactly as before if this file is not uploaded.
 *
 * Rules (listing_fees rows):
 *   category row (sub_key = '')  mode 'free' | 'paid' (+ price)   - no row = free
 *   subcategory row               mode 'inherit' | 'free' | 'paid' - no row = inherit
 * Nothing is charged while the main switch (lf_enabled) is off.
 */

// ---------------------------------------------------------------------------
// Data layer (pure: takes a SQLite3 handle, shared by the bot and the panel)
// ---------------------------------------------------------------------------

function hl_lf_ensure_schema(SQLite3 $h) {
    $h->exec("CREATE TABLE IF NOT EXISTS listing_fees (
        cat_key    TEXT NOT NULL,
        sub_key    TEXT NOT NULL DEFAULT '',
        mode       TEXT NOT NULL,
        price      REAL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (cat_key, sub_key)
    )");
    $h->exec("CREATE TABLE IF NOT EXISTS listing_payments (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id    INTEGER NOT NULL,
        cat_key        TEXT,
        sub_key        TEXT,
        category_label TEXT,
        title          TEXT,
        ad_data        TEXT,
        amount         REAL NOT NULL,
        currency       TEXT NOT NULL DEFAULT 'usd',
        status         TEXT NOT NULL DEFAULT 'pending',
        stripe_session TEXT,
        stripe_url     TEXT,
        payment_ref    TEXT,
        osclass_id     TEXT,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        paid_at        DATETIME,
        published_at   DATETIME
    )");
    $h->exec("CREATE INDEX IF NOT EXISTS idx_lp_user ON listing_payments (telegram_id, status)");
}

/** All rules as ['cat' => ['' => [mode, price], 'sub' => [mode, price]]]. */
function hl_lf_rules(SQLite3 $h) {
    $out = [];
    $res = $h->query("SELECT cat_key, sub_key, mode, price FROM listing_fees");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $out[$r['cat_key']][$r['sub_key']] = ['mode' => $r['mode'], 'price' => $r['price'] !== null ? (float) $r['price'] : null];
    }
    return $out;
}

function hl_lf_save_rule(SQLite3 $h, $cat, $sub, $mode, $price) {
    $st = $h->prepare("INSERT OR REPLACE INTO listing_fees (cat_key, sub_key, mode, price, updated_at) VALUES (:c, :s, :m, :p, CURRENT_TIMESTAMP)");
    $st->bindValue(':c', (string) $cat, SQLITE3_TEXT);
    $st->bindValue(':s', (string) $sub, SQLITE3_TEXT);
    $st->bindValue(':m', (string) $mode, SQLITE3_TEXT);
    if ($price === null) $st->bindValue(':p', null, SQLITE3_NULL); else $st->bindValue(':p', round((float) $price, 2), SQLITE3_FLOAT);
    $st->execute();
}

/**
 * The fee for posting in $cat / $sub (0.0 = free). $enabled is the main switch.
 * Anything under Stripe's 50-cent minimum counts as free.
 */
function hl_lf_fee_from_rules(array $rules, $enabled, $cat, $sub) {
    if (!$enabled) return 0.0;
    $catRule = $rules[$cat][''] ?? ['mode' => 'free', 'price' => null];
    $subRule = ($sub !== null && $sub !== '') ? ($rules[$cat][$sub] ?? ['mode' => 'inherit', 'price' => null]) : ['mode' => 'inherit', 'price' => null];
    $rule = $subRule['mode'] === 'inherit' ? $catRule : $subRule;
    if (($rule['mode'] ?? 'free') !== 'paid') return 0.0;
    $p = round((float) ($rule['price'] ?? 0), 2);
    return $p >= 0.5 ? $p : 0.0;
}

function hl_lf_money($amount, $currency = 'usd') {
    $sym = strtolower($currency) === 'usd' ? '$' : strtoupper($currency) . ' ';
    $a = (float) $amount;
    return $sym . (fmod($a, 1.0) == 0.0 ? number_format($a, 0) : number_format($a, 2));
}

// ---------------------------------------------------------------------------
// Bot side
// ---------------------------------------------------------------------------

/** Raw handle to the bot DB with this module's schema ensured. */
function lf_db() {
    global $db;
    static $h = null;
    if ($h instanceof SQLite3) return $h;
    try {
        $h = new SQLite3($db->path(), SQLITE3_OPEN_READWRITE);
        $h->busyTimeout(4000);
        $h->exec('PRAGMA journal_mode=WAL');
        hl_lf_ensure_schema($h);
    } catch (\Throwable $e) { $h = null; }
    return $h;
}

function lf_currency() {
    global $db;
    return strtolower((string) $db->getSetting('lf_currency', 'usd')) ?: 'usd';
}

/**
 * Keep a copy of the bot's category list in the settings table, so the admin
 * panel can list them without loading the bot's config (which needs the bot's
 * secrets). Cheap: one read, and a write only when the categories changed.
 */
function lf_sync_categories() {
    global $db, $config;
    if (!isset($db) || !is_array($config['categories'] ?? null)) return;
    $cats = [];
    foreach ($config['categories'] as $k => $c) {
        $cats[$k] = ['name' => (string) ($c['name'] ?? $k), 'subcategories' => is_array($c['subcategories'] ?? null) ? $c['subcategories'] : []];
    }
    $json = json_encode($cats);
    if ($db->getSetting('lf_categories', '') !== $json) $db->setSetting('lf_categories', $json);
}

/** Fee for an ad's category/subcategory right now (0.0 = free). */
function lfFeeFor(array $adData) {
    global $db;
    $h = lf_db();
    if (!$h) return 0.0;
    return hl_lf_fee_from_rules(hl_lf_rules($h), $db->getSetting('lf_enabled', '0') === '1',
        (string) ($adData['category'] ?? ''), (string) ($adData['subcategory'] ?? ''));
}

function lf_row($id) {
    $h = lf_db();
    if (!$h) return null;
    $st = $h->prepare("SELECT * FROM listing_payments WHERE id = :id");
    $st->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $r = $st->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

function lf_update($id, array $fields) {
    $h = lf_db();
    if (!$h || !$fields) return;
    $sets = []; foreach ($fields as $k => $v) $sets[] = "$k = :$k";
    $st = $h->prepare("UPDATE listing_payments SET " . implode(', ', $sets) . " WHERE id = :id");
    foreach ($fields as $k => $v) $st->bindValue(":$k", $v, $v === null ? SQLITE3_NULL : (is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
    $st->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $st->execute();
}

function lf_stripe_key() {
    global $db, $config;
    return preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key'] ?? ''));
}

function lf_return_link($payload) {
    global $config;
    $user = function_exists('getBotUsername') ? getBotUsername() : '';
    if ($user && $user !== 'bot') return 'https://t.me/' . $user . '?start=' . rawurlencode($payload);
    return $config['website_url'] ?? 'https://habeshalist.com';
}

function lf_label(array $adData) {
    return trim(($adData['category_name'] ?? '') . (!empty($adData['subcategory_name']) ? ' > ' . $adData['subcategory_name'] : ''));
}

/**
 * Paid, in full, in the right currency, for THIS payment row. Never trust the
 * user coming back to the bot - only Stripe's own answer.
 */
function lf_session_paid_for($session, array $row) {
    return is_array($session)
        && ($session['payment_status'] ?? '') === 'paid'
        && ($session['id'] ?? '') === ($row['stripe_session'] ?? '')
        && (string) ($session['metadata']['lf_payment_id'] ?? '') === (string) $row['id']
        && strtolower((string) ($session['currency'] ?? $row['currency'])) === strtolower((string) $row['currency'])
        && (int) ($session['amount_total'] ?? -1) === (int) round(((float) $row['amount']) * 100);
}

function lf_menu($userId) {
    global $db;
    $db->setState($userId, 'idle', []);
    $user = $db->getUser($userId);
    showMainMenu($userId, $user['name'] ?? 'there');
}

/** Atomically pending -> paid. Returns true only for the request that did it. */
function lf_mark_paid(array $row, $session) {
    $h = lf_db();
    $pi = is_array($session) && is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '';
    $ref = 'HLL-CARD-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $pi !== '' ? $pi : (string) $row['stripe_session']), -6));
    $st = $h->prepare("UPDATE listing_payments SET status = 'paid', paid_at = CURRENT_TIMESTAMP, payment_ref = :r WHERE id = :id AND status = 'pending'");
    $st->bindValue(':r', $ref, SQLITE3_TEXT);
    $st->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
    $st->execute();
    return $h->changes() === 1;
}

/** Asks Stripe whether the row's session is paid (and records it). */
function lf_check_stripe(array $row) {
    if (($row['status'] ?? '') === 'paid') return true;
    if (($row['status'] ?? '') !== 'pending' || empty($row['stripe_session']) || !function_exists('hl_stripe_get_session')) return false;
    $session = hl_stripe_get_session(lf_stripe_key(), $row['stripe_session']);
    if (!lf_session_paid_for($session, $row)) return false;
    lf_mark_paid($row, $session);
    $fresh = lf_row($row['id']);
    return ($fresh['status'] ?? '') === 'paid';
}

/**
 * Called by handlePublish() before anything is sent to the website.
 * Returns true when it took over (payment needed); false = go ahead and publish.
 */
function lfGate($userId, array &$adData) {
    $fee = lfFeeFor($adData);
    $h = lf_db();

    // Already paid for this ad (coming back from Stripe, or a retry after a
    // website hiccup): publish it, never charge twice.
    if (!empty($adData['_lf_paid'])) {
        $row = lf_row($adData['_lf_paid']);
        if ($row && (int) $row['telegram_id'] === (int) $userId && $row['status'] === 'paid') return false;
        unset($adData['_lf_paid']);
    }
    if ($fee <= 0 || !$h) return false;

    // A payment already started for this ad (user edited it, or tapped Publish again).
    $row = !empty($adData['_lf_payment_id']) ? lf_row($adData['_lf_payment_id']) : null;
    if ($row && ((int) $row['telegram_id'] !== (int) $userId || !in_array($row['status'], ['pending', 'paid'], true))) $row = null;
    if ($row && lf_check_stripe($row)) {
        // Paid meanwhile (e.g. in the Stripe tab), or paid before an edit: publish
        // the latest version of the ad. A paid fee is honoured even if the price
        // changed since.
        lf_update($row['id'], ['ad_data' => json_encode(lf_clean($adData)), 'title' => (string) ($adData['title'] ?? '')]);
        $adData['_lf_paid'] = (int) $row['id'];
        return false;
    }
    if ($row && (float) $row['amount'] != $fee) {
        lf_cancel_row($row);
        $row = null;
    }

    if (!$row) {
        $st = $h->prepare("INSERT INTO listing_payments (telegram_id, cat_key, sub_key, category_label, title, ad_data, amount, currency, status)
                           VALUES (:t, :c, :s, :l, :ti, :d, :a, :cur, 'pending')");
        $st->bindValue(':t', (int) $userId, SQLITE3_INTEGER);
        $st->bindValue(':c', (string) ($adData['category'] ?? ''), SQLITE3_TEXT);
        $st->bindValue(':s', (string) ($adData['subcategory'] ?? ''), SQLITE3_TEXT);
        $st->bindValue(':l', lf_label($adData), SQLITE3_TEXT);
        $st->bindValue(':ti', (string) ($adData['title'] ?? ''), SQLITE3_TEXT);
        $st->bindValue(':d', json_encode(lf_clean($adData)), SQLITE3_TEXT);
        $st->bindValue(':a', $fee, SQLITE3_FLOAT);
        $st->bindValue(':cur', lf_currency(), SQLITE3_TEXT);
        $st->execute();
        $row = lf_row($h->lastInsertRowID());
    } else {
        lf_update($row['id'], ['ad_data' => json_encode(lf_clean($adData)), 'title' => (string) ($adData['title'] ?? '')]);
        $row = lf_row($row['id']);
    }
    $adData['_lf_payment_id'] = (int) $row['id'];
    lfShowPayment($userId, $row);
    return true;
}

/** Ad data without our own bookkeeping keys. */
function lf_clean(array $adData) {
    unset($adData['_lf_paid'], $adData['_lf_payment_id']);
    return $adData;
}

/** The "pay the posting fee" screen (creates a Stripe session when needed). */
function lfShowPayment($userId, array $row) {
    global $tg, $db, $config;

    $adData = json_decode((string) $row['ad_data'], true) ?: [];
    $adData['_lf_payment_id'] = (int) $row['id'];
    $db->setState($userId, 'lf_awaiting_payment', $adData);
    $amount = hl_lf_money($row['amount'], $row['currency']);
    $title = htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars((string) $row['category_label'], ENT_QUOTES, 'UTF-8');

    // Reuse an open session (a double tap never makes two); otherwise make one.
    $url = '';
    if (!empty($row['stripe_session']) && !empty($row['stripe_url']) && function_exists('hl_stripe_get_session')) {
        $s = hl_stripe_get_session(lf_stripe_key(), $row['stripe_session']);
        if (lf_session_paid_for($s, $row)) { lfCheck($userId); return; }
        if (is_array($s) && ($s['status'] ?? '') === 'open') $url = $row['stripe_url'];
    }
    if ($url === '') {
        $key = lf_stripe_key();
        $sess = ($key !== '' && function_exists('hl_stripe_create_session'))
            ? hl_stripe_create_session($key, (int) round(((float) $row['amount']) * 100),
                'HabeshaList posting fee - ' . ($row['category_label'] ?: 'listing'),
                lf_return_link('lfpaid'), lf_return_link('lfpaycancel'),
                ['lf_payment_id' => (int) $row['id'], 'telegram_id' => (int) $userId, 'kind' => 'listing_fee'], $row['currency'])
            : ['error' => $key === '' ? 'no Stripe key set' : 'stripe.php not uploaded'];
        if (empty($sess['url'])) {
            $err = $sess['error'] ?? 'unknown';
            error_log('listing fee stripe session failed: ' . $err);
            foreach (($config['admin_ids'] ?? []) as $aid) {
                $tg->sendMessage((int) $aid, "\xE2\x9A\xA0\xEF\xB8\x8F <b>Card checkout failed for a listing fee.</b>\nStripe said: <b>"
                    . htmlspecialchars((string) $err, ENT_QUOTES, 'UTF-8') . "</b>\nCheck the Stripe key on the panel Keys page.");
            }
            $tg->sendInlineButtons($userId,
                "\xE2\x9A\xA0\xEF\xB8\x8F Card payment is temporarily unavailable, so your ad is not published yet. Your details are saved - please try again in a little while.",
                [
                    [['text' => "\xF0\x9F\x94\x84 Try again", 'callback_data' => 'lf_pay']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'lf_cancel']],
                ]);
            return;
        }
        lf_update($row['id'], ['stripe_session' => $sess['id'], 'stripe_url' => $sess['url']]);
        $url = $sess['url'];
    }

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x92\xB3 <b>Posting fee: {$amount}</b>\n\n" .
        "Ads in <b>{$label}</b> have a posting fee.\n" .
        "\xF0\x9F\x93\x9D Your ad: <b>{$title}</b>\n\n" .
        "Pay securely by card, then come back here. Your ad is published on HabeshaList.com as soon as the payment is confirmed.",
        [
            [['text' => "\xF0\x9F\x94\x92 Pay {$amount} by card", 'url' => $url]],
            [['text' => "\xF0\x9F\x94\x84 I've paid - check", 'callback_data' => 'lf_check']],
            [
                ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit ad", 'callback_data' => 'lf_edit'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'lf_cancel'],
            ],
        ]);
}

/** The user's current payment row: from their state, else their latest open one. */
function lf_current_row($userId) {
    global $db;
    $state = $db->getState($userId);
    $id = (int) ($state['data']['_lf_payment_id'] ?? 0);
    $row = $id ? lf_row($id) : null;
    if ($row && (int) $row['telegram_id'] === (int) $userId) return $row;
    return lf_latest_open($userId);
}

/** Latest payment of this user that is waiting for payment or paid-but-not-published. */
function lf_latest_open($userId) {
    $h = lf_db();
    if (!$h) return null;
    $st = $h->prepare("SELECT * FROM listing_payments WHERE telegram_id = :t AND status IN ('pending', 'paid')
                       AND created_at >= datetime('now', '-14 days') ORDER BY id DESC LIMIT 1");
    $st->bindValue(':t', (int) $userId, SQLITE3_INTEGER);
    $r = $st->execute();
    $row = $r ? $r->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

/** "I've paid" / back from Stripe: verify with Stripe, then publish. */
function lfCheck($userId) {
    global $tg;
    $row = lf_current_row($userId);
    if (!$row) { lf_menu($userId); return; }
    if (lf_check_stripe($row)) { lfPublishPaid($userId, lf_row($row['id'])); return; }

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x95\x92 I couldn't confirm your payment yet. If you just paid, wait a few seconds and tap Check again.",
        [
            [['text' => "\xF0\x9F\x94\x84 Check again", 'callback_data' => 'lf_check']],
            [['text' => "\xF0\x9F\x92\xB3 Back to payment", 'callback_data' => 'lf_pay']],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'lf_cancel']],
        ]);
}

/** Publish a paid ad through the normal publish path (no second charge). */
function lfPublishPaid($userId, array $row) {
    $adData = json_decode((string) $row['ad_data'], true) ?: [];
    $adData['_lf_paid'] = (int) $row['id'];
    handlePublish($userId, ['data' => $adData]);
}

/** After handlePublish() tried the website: remember the result on the payment. */
function lfAfterPublish(array $adData, $published, $osclassId) {
    if (empty($adData['_lf_paid'])) return;
    if ($published) {
        lf_update((int) $adData['_lf_paid'], ['status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
            'osclass_id' => $osclassId !== null ? (string) $osclassId : null]);
    }
}

function lf_cancel_row(array $row) {
    lf_update($row['id'], ['status' => 'cancelled']);
    // Close the checkout page so it can no longer be paid (best effort).
    if (!empty($row['stripe_session']) && function_exists('hl_stripe_expire_session')) {
        hl_stripe_expire_session(lf_stripe_key(), $row['stripe_session']);
    }
}

function lfCancel($userId) {
    global $tg, $db;
    $row = lf_current_row($userId);
    if ($row && $row['status'] === 'pending') {
        // Paid in the meantime? Then publish instead of dropping a paid ad.
        if (lf_check_stripe($row)) { lfPublishPaid($userId, lf_row($row['id'])); return; }
        lf_cancel_row($row);
    }
    $db->setState($userId, 'idle', []);
    $tg->sendMessage($userId, "\xE2\x9D\x8C Cancelled. Your ad was not published and you were not charged.");
    $user = $db->getUser($userId);
    showMainMenu($userId, $user['name'] ?? 'there');
}

/** Back to the review screen to change the ad (the payment is kept and updated). */
function lfEdit($userId) {
    global $db;
    $row = lf_current_row($userId);
    if (!$row) { lf_menu($userId); return; }
    $adData = json_decode((string) $row['ad_data'], true) ?: [];
    $adData['_lf_payment_id'] = (int) $row['id'];
    $db->setState($userId, 'awaiting_review', $adData);
    showEditOptions($userId, ['data' => $adData]);
}

/** Show the payment screen again (Try again / Back to payment / return from a cancelled checkout). */
function lfPay($userId) {
    $row = lf_current_row($userId);
    if (!$row) { lf_menu($userId); return; }
    if ($row['status'] === 'paid') { lfPublishPaid($userId, $row); return; }
    lfShowPayment($userId, $row);
}

/**
 * Before a new ad: remind about one waiting for payment (or paid but not yet
 * published). Returns true when the reminder was shown.
 */
function lfPendingReminder($userId) {
    global $tg;
    $row = lf_latest_open($userId);
    if (!$row) return false;
    $title = htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8');
    if ($row['status'] === 'paid') {
        $tg->sendInlineButtons($userId,
            "\xE2\x9C\x85 Your payment for <b>{$title}</b> was received, but the ad is not published yet.",
            [
                [['text' => "\xF0\x9F\x9A\x80 Publish it now", 'callback_data' => 'lf_resume_' . $row['id']]],
                [['text' => "\xE2\x9E\x95 Post a different ad", 'callback_data' => 'lf_newad']],
            ]);
        return true;
    }
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x92\xB3 Your ad <b>{$title}</b> is waiting for its " . hl_lf_money($row['amount'], $row['currency']) . " posting fee.",
        [
            [['text' => "\xF0\x9F\x92\xB3 Pay and publish it", 'callback_data' => 'lf_resume_' . $row['id']]],
            [['text' => "\xF0\x9F\x97\x91 Discard it and post a new ad", 'callback_data' => 'lf_discard_' . $row['id']]],
        ]);
    return true;
}

/** Callback router for lf_* buttons. Returns true when handled. */
function lfHandleCallback($userId, $data) {
    global $db;
    if ($data === 'lf_check')  { lfCheck($userId); return true; }
    if ($data === 'lf_pay')    { lfPay($userId); return true; }
    if ($data === 'lf_cancel') { lfCancel($userId); return true; }
    if ($data === 'lf_edit')   { lfEdit($userId); return true; }
    if ($data === 'lf_newad')  { $db->setState($userId, 'idle', []); showCategoryPicker($userId); return true; }
    if (strpos($data, 'lf_resume_') === 0 || strpos($data, 'lf_discard_') === 0) {
        $discard = strpos($data, 'lf_discard_') === 0;
        $row = lf_row((int) substr($data, $discard ? 11 : 10));
        if (!$row || (int) $row['telegram_id'] !== (int) $userId || !in_array($row['status'], ['pending', 'paid'], true)) {
            showCategoryPicker($userId);
            return true;
        }
        if ($discard) {
            if (lf_check_stripe($row)) { lfPublishPaid($userId, lf_row($row['id'])); return true; }   // it was paid after all
            lf_cancel_row($row);
            $db->setState($userId, 'idle', []);
            showCategoryPicker($userId);
            return true;
        }
        if (lf_check_stripe($row)) { lfPublishPaid($userId, lf_row($row['id'])); return true; }
        lfShowPayment($userId, $row);
        return true;
    }
    return false;
}
