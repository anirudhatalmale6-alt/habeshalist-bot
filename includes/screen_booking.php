<?php
/**
 * screen_booking.php - Milestone 2 of the Digital Screen Advertising module:
 * the in-bot booking flow that lets a customer advertise on a physical screen.
 *
 * FLOW (mirrors the proven "Promote My Business" flow in promotion.php):
 *   pick a screen -> choose a start date -> choose how many days -> pay
 *   (card via Stripe, or Zelle / Cash App with a screenshot) -> upload the
 *   flyer (image or video) -> review -> submit. The booking lands as 'pending'
 *   in the admin approval queue; on approval it flips to approved+paid and the
 *   player shows it automatically from its start date (the playlist already
 *   filters approved+paid+in-date bookings).
 *
 * SELF-CONTAINED: every state is prefixed 'scr_' and every callback 'scr...',
 * so this never collides with the classifieds bot or the promotions engine. It
 * writes only to the screens module's own screen_bookings table.
 *
 * The screens data layer (includes/screens.php) is required by webhook.php
 * before this file; we open our own SQLite3 handle to the same database (exactly
 * as HL_Scheduler / HL_Referral do) because those functions take a raw handle.
 */

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** Raw SQLite3 handle to the bot DB (cached), with the screens schema ensured. */
function scr_db() {
    global $db;
    static $sdb = null;
    if ($sdb instanceof SQLite3) return $sdb;
    try {
        $sdb = new SQLite3($db->path(), SQLITE3_OPEN_READWRITE);
        $sdb->busyTimeout(4000);
        $sdb->exec('PRAGMA journal_mode=WAL');
        if (function_exists('hl_screens_ensure_schema')) hl_screens_ensure_schema($sdb);
    } catch (\Throwable $e) { $sdb = null; }
    return $sdb;
}

/** Where the bot saves uploaded flyer files (served by player.php?media=). */
function scr_uploads_dir() {
    $dir = dirname(__DIR__) . '/uploads/screens';   // bot-root/uploads/screens
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Is the customer booking flow switched on? (Admin can pause it.) */
function scr_enabled() {
    global $db;
    return $db->getSetting('screens_booking_enabled', '1') === '1';
}

/** "$25", "$25.50" - matches the money style used elsewhere. */
function scr_fmt_price($p) {
    $p = (float) $p;
    $s = number_format($p, 2);
    return '$' . (substr($s, -3) === '.00' ? substr($s, 0, -3) : $s);
}

/** Timezone the screens run in (same setting the player uses). */
function scr_tz() {
    global $db;
    $tz = $db->getSetting('sched_tz', 'America/New_York');
    return $tz ?: 'America/New_York';
}

/** Deep link back into this bot's chat (Stripe success/cancel return URL). */
function scr_return_link($payload) {
    global $config;
    $user = function_exists('getBotUsername') ? getBotUsername() : '';
    if ($user && $user !== 'bot') return 'https://t.me/' . $user . '?start=' . rawurlencode($payload);
    return $config['website_url'] ?? 'https://habeshalist.com';
}

/** Download a Telegram file to the uploads dir; returns a relative path or null. */
function scr_download_media($fileId, $type) {
    global $tg;
    try {
        $info = $tg->getFile($fileId);
        $tgPath = $info['result']['file_path'] ?? '';
        if ($tgPath === '') return null;
        $bytes = $tg->downloadFile($tgPath);
        if ($bytes === false || $bytes === null || strlen($bytes) === 0) return null;
        if (strlen($bytes) > 60 * 1024 * 1024) return null; // 60 MB safety cap
        $ext = strtolower(pathinfo($tgPath, PATHINFO_EXTENSION));
        $imgExt = defined('HL_SCREEN_IMAGE_EXT') ? HL_SCREEN_IMAGE_EXT : ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $vidExt = defined('HL_SCREEN_VIDEO_EXT') ? HL_SCREEN_VIDEO_EXT : ['mp4', 'webm', 'mov'];
        if (!in_array($ext, array_merge($imgExt, $vidExt), true)) {
            $ext = ($type === 'video') ? 'mp4' : 'jpg';
        }
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents(scr_uploads_dir() . '/' . $fname, $bytes) === false) return null;
        return 'uploads/screens/' . $fname;
    } catch (\Throwable $e) {
        return null;
    }
}

// How far ahead a customer can book a start date (matches the promo calendar feel).
const SCR_BOOK_HORIZON_DAYS = 90;

// ---------------------------------------------------------------------------
// Step 1 - pick a STATE (then the screens in that state)
// ---------------------------------------------------------------------------

/** Active, bookable screens (helper shared by the state + screen pickers). */
function scr_active_screens($sdb) {
    $screens = $sdb ? hl_screens_all($sdb) : [];
    return array_values(array_filter($screens, function ($s) { return ($s['status'] ?? '') === 'active'; }));
}

/** Distinct, sorted state list from the active screens (screens with no state
 *  are grouped under an empty-string key so they are still bookable). */
function scr_states_of(array $active) {
    $states = [];
    foreach ($active as $s) { $states[trim($s['state'] ?? '')] = true; }
    $keys = array_keys($states);
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    return $keys;
}

function scrStart($userId) {
    global $tg, $db;

    if (!scr_enabled()) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x96\xA5\xEF\xB8\x8F Screen advertising isn't open for booking right now. Please check back soon!",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        return;
    }

    $sdb = scr_db();
    $active = scr_active_screens($sdb);

    if (!$active) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x96\xA5\xEF\xB8\x8F <b>Advertise on a Screen</b>\n\n" .
            "There are no screens available to book just yet. Please check back soon!",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        return;
    }

    $states = scr_states_of($active);
    // Only one state (or none are labelled): skip the state step, list screens directly.
    if (count($states) <= 1) {
        scrShowScreens($userId, $states[0] ?? '');
        return;
    }

    // Show the states; the user picks one, then sees only that state's screens.
    $db->setState($userId, 'scr_pickstate', ['states' => $states]);
    $buttons = [];
    foreach ($states as $i => $st) {
        $label = ($st === '') ? "\xF0\x9F\x93\x8D Other locations" : "\xF0\x9F\x93\x8D {$st}";
        $buttons[] = [['text' => $label, 'callback_data' => 'scrstate_' . $i]];
    }
    $buttons[] = [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']];
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x96\xA5\xEF\xB8\x8F <b>Advertise on a Screen</b>\n\n" .
        "Show your business on our digital screens - people see it on the spot, at real locations in the community.\n\n" .
        "First, choose a <b>state</b>:",
        $buttons);
}

/** User tapped a state - list the active screens in it. */
function scrPickState($userId, $idx, $state) {
    $states = $state['data']['states'] ?? [];
    if (!isset($states[$idx])) { scrStart($userId); return; }
    scrShowScreens($userId, $states[$idx], count($states) > 1);
}

/** List the active screens in $stateName (empty = the unlabelled group). */
function scrShowScreens($userId, $stateName, $hadStates = false) {
    global $tg, $db;

    $sdb = scr_db();
    $active = scr_active_screens($sdb);
    $inState = array_values(array_filter($active, function ($s) use ($stateName) {
        return trim($s['state'] ?? '') === $stateName;
    }));

    if (!$inState) {
        $tg->sendInlineButtons($userId, "No screens available there right now. Please pick another.",
            [[['text' => "\xF0\x9F\x96\xA5\xEF\xB8\x8F Back", 'callback_data' => 'scr_start']]]);
        return;
    }

    $buttons = [];
    foreach ($inState as $s) {
        $rate = hl_screen_rate($sdb, $s['id']);
        $priceTxt = $rate ? scr_fmt_price($rate['rate']) . '/' . ($rate['unit'] ?? 'day') : 'ask';
        $loc = trim($s['city'] ?? '') ?: trim($s['location'] ?? '');
        $label = $s['name'] . ($loc !== '' ? " - {$loc}" : '') . " ({$priceTxt})";
        $buttons[] = [['text' => $label, 'callback_data' => 'scrpick_' . (int) $s['id']]];
    }
    $buttons[] = $hadStates
        ? [['text' => "\xE2\xAC\x85\xEF\xB8\x8F States", 'callback_data' => 'scr_start'], ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']]
        : [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']];

    $db->setState($userId, 'scr_pick', []);
    $head = ($stateName !== '') ? " in <b>{$stateName}</b>" : '';
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x96\xA5\xEF\xB8\x8F <b>Screens{$head}</b>\n\nPick a screen to advertise on:",
        $buttons);
}

// ---------------------------------------------------------------------------
// Step 2 - choose a start date (monthly calendar, like Promote My Business)
// ---------------------------------------------------------------------------

function scrPickScreen($userId, $screenId) {
    global $tg, $db;

    $sdb = scr_db();
    $screen = $sdb ? hl_screen_by_id($sdb, $screenId) : null;
    if (!$screen || ($screen['status'] ?? '') !== 'active') {
        $tg->sendInlineButtons($userId, "Sorry, that screen isn't available. Please pick another.",
            [[['text' => "\xF0\x9F\x96\xA5\xEF\xB8\x8F Back to screens", 'callback_data' => 'scr_start']]]);
        return;
    }

    $rate = hl_screen_rate($sdb, $screenId);
    if (!$rate) {
        // Pricing not set on this screen or as a default - can't quote a price.
        $tg->sendInlineButtons($userId,
            "Pricing for this screen isn't set yet. Please contact us and we'll help you book it.",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        foreach (($GLOBALS['config']['admin_ids'] ?? []) as $aid) {
            $tg->sendMessage((int) $aid,
                "\xE2\x9A\xA0\xEF\xB8\x8F A customer tried to book screen \"" . ($screen['name'] ?? '') .
                "\" but it has no price set. Add a price in Admin -> Digital Screens.");
        }
        return;
    }

    $data = [
        'screen_id'   => (int) $screenId,
        'screen_name' => $screen['name'] ?? 'Screen',
        'screen_loc'  => $screen['location'] ?? '',
        'screen_state'=> trim($screen['state'] ?? ''),
        'dwell'       => (int) ($screen['dwell_seconds'] ?? 10),
        'rate'        => (float) $rate['rate'],
        'unit'        => $rate['unit'] ?? 'day',
        'capacity'    => (int) ($screen['max_ads'] ?? 0),
    ];
    $db->setState($userId, 'scr_pickdate', $data);
    scrShowCalendar($userId, $data, null);
}

/**
 * Render a month calendar (Mon-first) for the start date, mirroring the proven
 * Promote My Business picker. Bookable days within the horizon are tappable;
 * fully-booked days (a capped screen at capacity) are marked and disabled.
 */
function scrShowCalendar($userId, $data, $ym) {
    global $tg;

    $sdb = scr_db();
    try { $tz = new DateTimeZone(scr_tz()); } catch (\Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $today = new DateTime('now', $tz); $today->setTime(0, 0);
    $maxDate = (clone $today)->modify('+' . (SCR_BOOK_HORIZON_DAYS - 1) . ' day');

    // Month being shown (default: current month), clamped to the bookable window.
    $month = null;
    if ($ym && preg_match('/^(\d{4})(\d{2})$/', $ym, $m)) {
        $month = DateTime::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-01", $tz);
    }
    if (!($month instanceof DateTime)) { $month = (clone $today); }
    $month->setTime(0, 0); $month->modify('first day of this month');

    $firstOfMonth = (clone $month);
    $daysInMonth  = (int) $month->format('t');
    $lastOfMonth  = (clone $month)->modify('last day of this month');
    $leadBlanks   = ((int) $firstOfMonth->format('N')) - 1;   // Mon=1..Sun=7

    // Which days this month are fully booked (only meaningful for a capped screen).
    $full = [];
    if ($sdb && (int) ($data['capacity'] ?? 0) > 0) {
        $full = hl_screen_full_days($sdb, (int) $data['screen_id'],
            $firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'));
    }

    $rows = [];
    // Header: ◀  Month Year  ▶
    $prevMonthLast  = (clone $firstOfMonth)->modify('-1 day');
    $nextMonthFirst = (clone $lastOfMonth)->modify('+1 day');
    $hasPrev = ($prevMonthLast >= $today);
    $hasNext = ($nextMonthFirst <= $maxDate);
    $rows[] = [
        $hasPrev ? ['text' => "\xE2\x97\x80", 'callback_data' => 'scrcal_' . $prevMonthLast->format('Ym')]
                 : ['text' => "\xC2\xA0", 'callback_data' => 'scrnop'],
        ['text' => $month->format('F Y'), 'callback_data' => 'scrnop'],
        $hasNext ? ['text' => "\xE2\x96\xB6", 'callback_data' => 'scrcal_' . $nextMonthFirst->format('Ym')]
                 : ['text' => "\xC2\xA0", 'callback_data' => 'scrnop'],
    ];
    $rows[] = array_map(function ($w) { return ['text' => $w, 'callback_data' => 'scrnop']; },
        ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']);

    $row = [];
    for ($i = 0; $i < $leadBlanks; $i++) { $row[] = ['text' => "\xC2\xA0", 'callback_data' => 'scrnop']; }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $d = DateTime::createFromFormat('Y-m-d', $month->format('Y-m-') . sprintf('%02d', $day), $tz);
        $d->setTime(0, 0);
        $ymd = $d->format('Y-m-d');
        $inWindow = ($d >= $today && $d <= $maxDate);
        if ($inWindow && empty($full[$ymd])) {
            $row[] = ['text' => (string) $day, 'callback_data' => 'scrday_' . $d->format('Ymd')];
        } elseif ($inWindow && !empty($full[$ymd])) {
            $row[] = ['text' => "\xF0\x9F\x9A\xAB", 'callback_data' => 'scrnop'];   // fully booked
        } else {
            $row[] = ['text' => "\xC2\xB7", 'callback_data' => 'scrnop'];           // outside window
        }
        if (count($row) === 7) { $rows[] = $row; $row = []; }
    }
    if (!empty($row)) {
        while (count($row) < 7) { $row[] = ['text' => "\xC2\xA0", 'callback_data' => 'scrnop']; }
        $rows[] = $row;
    }
    $rows[] = [
        ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Screens", 'callback_data' => 'scr_start'],
        ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel'],
    ];

    $priceTxt = scr_fmt_price((float) $data['rate']) . ' per ' . ($data['unit'] ?? 'day');
    $capNote  = ((int) ($data['capacity'] ?? 0) > 0)
        ? "\n\xF0\x9F\x9A\xAB = fully booked that day. \xC2\xB7 = outside the booking window."
        : "\n\xC2\xB7 = outside the booking window.";
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x96\xA5\xEF\xB8\x8F <b>{$data['screen_name']}</b>" .
        ($data['screen_loc'] !== '' ? " - {$data['screen_loc']}" : '') . "\n" .
        "Price: <b>{$priceTxt}</b>\n\n" .
        "\xF0\x9F\x93\x85 <b>Pick the day your ad should start:</b>" . $capNote,
        $rows);
}

/** ◀/▶ month navigation on the start-date calendar. */
function scrCalendarNav($userId, $ym, $state) {
    scrShowCalendar($userId, $state['data'] ?? [], $ym);
}

// ---------------------------------------------------------------------------
// Step 3 - choose duration
// ---------------------------------------------------------------------------

function scrPickDate($userId, $ymd, $state) {
    global $tg, $db;

    // The calendar sends a compact Ymd; convert to Y-m-d.
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m)) { scrPickScreen($userId, $state['data']['screen_id'] ?? 0); return; }
    $dateStr = "{$m[1]}-{$m[2]}-{$m[3]}";
    $data = $state['data'];

    // Re-check the chosen start day is still free (a capped screen may have filled).
    $sdb = scr_db();
    if ($sdb && !hl_screen_is_available($sdb, (int) ($data['screen_id'] ?? 0), $dateStr, $dateStr)) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x86 Sorry, that day just filled up. Please pick another date.",
            [[['text' => "\xF0\x9F\x93\x85 Choose another date", 'callback_data' => 'scrpick_' . (int) ($data['screen_id'] ?? 0)]],
             [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']]]);
        return;
    }

    $data['start_date'] = $dateStr;
    $db->setState($userId, 'scr_pickdur', $data);

    // Durations spanning day / week / month / year, priced at the screen's rate.
    $opts = [1 => '1 day', 7 => '1 week', 30 => '1 month', 90 => '3 months', 365 => '1 year'];
    $rate = (float) ($data['rate'] ?? 0);
    $unit = $data['unit'] ?? 'day';
    $rows = [];
    foreach ($opts as $days => $label) {
        $price = hl_screen_price_calc($rate, $unit, $days);
        $rows[] = [['text' => "{$label}  -  " . scr_fmt_price($price), 'callback_data' => 'scrdur_' . $days]];
    }
    $rows[] = [
        ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'scrpick_' . (int) ($data['screen_id'] ?? 0)],
        ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel'],
    ];

    $pretty = date('D, M j Y', strtotime($dateStr));
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x85 Start date: <b>{$pretty}</b>\n\n" .
        "How long should your ad run?",
        $rows);
}

function scrPickDuration($userId, $days, $state) {
    global $tg, $db;

    $data = $state['data'];
    $days = max(1, (int) $days);
    $start = $data['start_date'] ?? '';
    if ($start === '') { scrPickScreen($userId, $data['screen_id'] ?? 0); return; }

    $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' day'));
    $sdb = scr_db();

    // Is the screen free for the whole range?
    if ($sdb && !hl_screen_is_available($sdb, $data['screen_id'], $start, $end)) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x86 Sorry, <b>{$data['screen_name']}</b> is already booked for some of those dates. " .
            "Please choose a different start date or a shorter run.",
            [
                [['text' => "\xF0\x9F\x93\x85 Choose another date", 'callback_data' => 'scrpick_' . (int) $data['screen_id']]],
                [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
            ]);
        return;
    }

    $price = hl_screen_price_calc((float) $data['rate'], $data['unit'] ?? 'day', $days);

    $data['days']     = $days;
    $data['end_date'] = $end;
    $data['price']    = $price;
    scrShowPayment($userId, $data);
}

// ---------------------------------------------------------------------------
// Step 4 - payment (mirrors promoShowPayment / promoHandlePayMethod)
// ---------------------------------------------------------------------------

function scrShowPayment($userId, $data) {
    global $tg, $db;

    $db->setState($userId, 'scr_payment', $data);
    $startPretty = date('M j', strtotime($data['start_date']));
    $endPretty   = date('M j, Y', strtotime($data['end_date']));

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\xA7\xBE <b>Booking summary</b>\n\n" .
        "Screen: <b>{$data['screen_name']}</b>" . ($data['screen_loc'] !== '' ? " - {$data['screen_loc']}" : '') . "\n" .
        "Dates: <b>{$startPretty} - {$endPretty}</b> ({$data['days']} day" . ($data['days'] > 1 ? 's' : '') . ")\n" .
        "Total: <b>" . scr_fmt_price($data['price']) . "</b>\n\n" .
        "Choose how you'd like to pay:",
        [
            [['text' => "\xF0\x9F\x92\xB3 Pay with Card", 'callback_data' => 'scrpay_card']],
            [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'scrpay_zelle']],
            [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'scrpay_cashapp']],
            [
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'scrpick_' . (int) $data['screen_id']],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel'],
            ],
        ]);
}

function scrHandlePayMethod($userId, $method, $state) {
    global $tg, $db, $config;

    $data = $state['data'];

    if ($method === 'card') {
        $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key']));
        if (empty($key) || !function_exists('hl_stripe_create_session')) {
            $db->setState($userId, 'scr_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB3 Card payments are being set up. For now, please pay with Zelle or Cash App and send a screenshot - your booking is reviewed and scheduled right after.",
                [
                    [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'scrpay_zelle']],
                    [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'scrpay_cashapp']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
                ]);
            return;
        }

        $amountCents = (int) round(((float) ($data['price'] ?? 0)) * 100);
        if ($amountCents < 50) {
            $data['payment_method'] = 'card';
            $data['payment_status'] = 'paid';
            $data['receipt'] = 'HLS-FREE-' . strtoupper(substr(md5($userId . microtime()), 0, 4));
            scrStartContent($userId, $data, "\xE2\x9C\x85 <b>No payment required.</b>");
            return;
        }

        $successUrl = scr_return_link('scrpaid');
        $cancelUrl  = scr_return_link('scrpaycancel');
        $sess = hl_stripe_create_session(
            $key, $amountCents,
            'HabeshaList screen ad - ' . ($data['screen_name'] ?? 'Screen'),
            $successUrl, $cancelUrl,
            ['telegram_id' => $userId, 'screen_id' => $data['screen_id'] ?? 0, 'kind' => 'screen_ad']
        );

        if (empty($sess['url'])) {
            $stripeErr = $sess['error'] ?? 'unknown';
            error_log('screen booking stripe create session failed: ' . $stripeErr);
            foreach (($config['admin_ids'] ?? []) as $aid) {
                $tg->sendMessage((int) $aid,
                    "\xE2\x9A\xA0\xEF\xB8\x8F <b>Card checkout failed for a screen booking.</b>\n" .
                    "Stripe said: <b>" . htmlspecialchars($stripeErr, ENT_QUOTES) . "</b>\n" .
                    "Fix the key on the panel Keys page (sk_live_ / sk_test_).");
            }
            $db->setState($userId, 'scr_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xE2\x9A\xA0\xEF\xB8\x8F Sorry, card checkout is temporarily unavailable. Please pay with Zelle or Cash App instead.",
                [
                    [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'scrpay_zelle']],
                    [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'scrpay_cashapp']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
                ]);
            return;
        }

        $data['payment_method'] = 'card';
        $data['_stripe_session'] = $sess['id'];
        $db->setState($userId, 'scr_awaiting_card', $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x94\x92 <b>Secure Card Payment</b>\n\n" .
            "Tap below to pay " . scr_fmt_price($data['price']) . " on our secure payment page, then come back here.",
            [
                [['text' => "Continue to Payment", 'url' => $sess['url']]],
                [['text' => "\xF0\x9F\x94\x84 I've paid - check", 'callback_data' => 'scr_check_card']],
                [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
            ]);
        return;
    }

    // Manual: Zelle / Cash App
    $data['payment_method'] = $method;
    $methodName = ($method === 'zelle') ? 'Zelle' : 'Cash App';
    $settingKey = ($method === 'zelle') ? 'pay_zelle' : 'pay_cashapp';
    $handle  = $db->getSetting($settingKey, $config['payment_defaults'][$settingKey] ?? '');
    $support = $db->getSetting('pay_support', $config['payment_defaults']['pay_support'] ?? '@Habesha_list');

    $msg = "\xF0\x9F\x92\xB3 <b>Pay " . scr_fmt_price($data['price']) . " via {$methodName}</b>\n\n";
    if (!empty($handle)) $msg .= "Send payment to:\n<b>{$handle}</b>\n\n";
    else $msg .= "Please contact {$support} for the {$methodName} details.\n\n";
    $msg .= "After paying, send a <b>screenshot</b> of your confirmation here, then tap <b>Submit Payment Proof</b>.";

    $db->setState($userId, 'scr_awaiting_proof', $data);
    $tg->sendInlineButtons($userId, $msg, [
        [['text' => "\xF0\x9F\x93\xA4 Submit Payment Proof", 'callback_data' => 'scr_paid_manual']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'scrpick_' . (int) $data['screen_id']],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel'],
        ],
    ]);
}

// User tapped "I've paid" after Stripe checkout - verify OUTBOUND with Stripe.
function scrCheckCard($userId, $state) {
    global $tg, $db, $config;

    $data = $state['data'];
    $sessionId = $data['_stripe_session'] ?? '';
    if ($sessionId === '') { scrShowPayment($userId, $data); return; }

    $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key']));
    $session = hl_stripe_get_session($key, $sessionId);

    if (hl_stripe_session_paid($session)) {
        $pi = is_array($session) ? (is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '') : '';
        $ref = preg_replace('/[^a-zA-Z0-9]/', '', $pi !== '' ? $pi : $sessionId);
        $data['payment_method'] = 'card';
        $data['payment_status'] = 'paid';
        $data['receipt'] = 'HLS-CARD-' . strtoupper(substr($ref, -6));
        unset($data['_stripe_session']);
        scrStartContent($userId, $data, "\xE2\x9C\x85 <b>Payment confirmed!</b>");
        return;
    }

    $db->setState($userId, 'scr_awaiting_card', $data);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x95\x92 I couldn't confirm your payment yet. If you just completed it, give it a few seconds and tap Check again.",
        [
            [['text' => "\xF0\x9F\x94\x84 Check payment status", 'callback_data' => 'scr_check_card']],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
        ]);
}

function scrReturnFromCheckout($userId) {
    global $db;
    $state = $db->getState($userId);
    if (($state['state'] ?? '') === 'scr_awaiting_card' && !empty($state['data']['_stripe_session'])) {
        scrCheckCard($userId, $state);
        return;
    }
    // Nothing pending - go home.
    $user = $db->getUser($userId);
    showMainMenu($userId, $user['name'] ?? 'there');
}

function scrReturnFromCancel($userId) {
    global $db;
    $state = $db->getState($userId);
    if (strpos($state['state'] ?? '', 'scr_') === 0 && !empty($state['data']['screen_id'])) {
        scrShowPayment($userId, $state['data']);
        return;
    }
    $user = $db->getUser($userId);
    showMainMenu($userId, $user['name'] ?? 'there');
}

// Manual payment: proof screenshot received (or "submit" tapped without one).
function scrManualProceed($userId, $state, $proofFileId) {
    global $db;
    $data = $state['data'];
    $data['payment_status'] = 'awaiting_verification';
    if ($proofFileId) $data['payment_proof'] = $proofFileId;
    $data['receipt'] = 'HLS-' . strtoupper(($data['payment_method'] ?? 'MAN')) . '-' . strtoupper(substr(md5($userId . microtime()), 0, 4));
    scrStartContent($userId, $data, "\xE2\x9C\x85 <b>Got it - we'll verify your payment.</b>");
}

// ---------------------------------------------------------------------------
// Step 5 - collect the ad content (business name + flyer)
// ---------------------------------------------------------------------------

function scrStartContent($userId, $data, $prefix = '') {
    global $tg, $db;
    $db->setState($userId, 'scr_bizname', $data);
    $tg->sendMessage($userId,
        ($prefix ? $prefix . "\n\n" : '') .
        "\xF0\x9F\x93\x9B Now let's build your ad.\n\n" .
        "What's the <b>business name</b> to show it under? (This is just a label for you and our team.)");
}

// Text input for the scr_ states.
function scrHandleStateInput($userId, $msg, $state) {
    global $tg, $db;
    $st = $state['state'];
    $data = $state['data'];
    $text = trim($msg['text'] ?? '');

    if ($st === 'scr_awaiting_card') { scrCheckCard($userId, $state); return; }

    if ($st === 'scr_bizname') {
        if (strlen($text) < 2) { $tg->sendMessage($userId, "Please enter the business name (at least 2 characters):"); return; }
        $data['business_name'] = mb_substr($text, 0, 80);
        $data['media'] = [];
        $data['_preview'] = [];
        $db->setState($userId, 'scr_media', $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Great. Now <b>send the image or video</b> you want shown on the screen.\n\n" .
            "You can send up to 5. Portrait (tall) works best. Tap Done when finished.",
            [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'scr_media_done']]]);
        return;
    }

    if ($st === 'scr_media') {
        $tg->sendInlineButtons($userId,
            "Please send an <b>image or video</b> for the screen, or tap Done.",
            [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'scr_media_done']]]);
        return;
    }

    // Any other scr_ text - gentle nudge.
    if ($st === 'scr_pick' || $st === 'scr_pickdate' || $st === 'scr_pickdur' || $st === 'scr_payment') {
        $tg->sendMessage($userId, "Please tap one of the buttons above to continue. \xF0\x9F\x91\x86");
    }
}

// Photo received during a scr_ flow. Returns true if handled.
function scrHandlePhoto($userId, $msg) {
    global $tg, $db;
    $state = $db->getState($userId);
    $st = $state['state'];
    if (strpos($st, 'scr_') !== 0) return false;
    $data = $state['data'];
    $photo = end($msg['photo']);

    if ($st === 'scr_awaiting_proof') {
        scrManualProceed($userId, $state, $photo['file_id']);
        return true;
    }
    if ($st === 'scr_media') {
        return scrAcceptMedia($userId, $state, $photo['file_id'], 'image', $msg);
    }
    return false;
}

// Video received during a scr_ flow. Returns true if handled.
function scrHandleVideo($userId, $msg) {
    global $tg, $db;
    $state = $db->getState($userId);
    $st = $state['state'];
    if (strpos($st, 'scr_') !== 0) return false;

    $fileId = '';
    if (isset($msg['video']['file_id'])) $fileId = $msg['video']['file_id'];
    elseif (isset($msg['document']['file_id']) && isset($msg['document']['mime_type']) &&
            strpos($msg['document']['mime_type'], 'video/') === 0) $fileId = $msg['document']['file_id'];
    if ($fileId === '') return false;

    if ($st === 'scr_media') return scrAcceptMedia($userId, $state, $fileId, 'video', $msg);
    if ($st === 'scr_awaiting_proof') {
        $tg->sendMessage($userId, "Please send your payment screenshot as a photo (image), not a video.");
        return true;
    }
    return false;
}

// Shared: download an uploaded flyer, add it to the booking's media list.
function scrAcceptMedia($userId, $state, $fileId, $type, $msg) {
    global $tg, $db;
    $data = $state['data'];
    $data['media']    = $data['media'] ?? [];
    $data['_preview'] = $data['_preview'] ?? [];

    if (count($data['media']) >= 5) {
        if (empty($data['_media_max'])) {
            $data['_media_max'] = true;
            $db->setState($userId, 'scr_media', $data);
            $tg->sendInlineButtons($userId, "\xF0\x9F\x93\xB8 That's the maximum of 5. Tap Done to continue.",
                [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'scr_media_done']]]);
        }
        return true;
    }

    $path = scr_download_media($fileId, $type);
    if ($path === null) {
        $tg->sendMessage($userId, "\xE2\x9A\xA0\xEF\xB8\x8F I couldn't save that file (it may be too large). Please try a smaller image or a compressed video.");
        return true;
    }

    $data['media'][]    = ['path' => $path, 'type' => $type, 'dwell' => (int) ($data['dwell'] ?? 10) ?: 10];
    $data['_preview'][] = ['type' => $type, 'media' => $fileId];
    $count = count($data['media']);

    // De-dupe album confirmations (Telegram sends each album item separately).
    $mgid = $msg['media_group_id'] ?? null;
    $sayIt = !($mgid && $mgid === ($data['_last_group'] ?? null));
    if ($mgid) $data['_last_group'] = $mgid;

    $db->setState($userId, 'scr_media', $data);
    if ($sayIt) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Added! ({$count}/5)\n\nSend more, or tap Done.",
            [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'scr_media_done']]]);
    }
    return true;
}

// ---------------------------------------------------------------------------
// Step 6 - review & submit
// ---------------------------------------------------------------------------

function scrMediaDone($userId, $state) {
    global $tg, $db;
    $data = $state['data'];
    if (empty($data['media'])) {
        $tg->sendInlineButtons($userId,
            "You haven't added any image or video yet. Please send at least one for the screen.",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']]]);
        return;
    }
    scrShowReview($userId, $data);
}

function scrShowReview($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'scr_review', $data);

    $startPretty = date('M j', strtotime($data['start_date']));
    $endPretty   = date('M j, Y', strtotime($data['end_date']));
    $nImg = 0; $nVid = 0;
    foreach ($data['media'] as $m) { if (($m['type'] ?? '') === 'video') $nVid++; else $nImg++; }
    $mediaTxt = trim(($nImg ? "{$nImg} image" . ($nImg > 1 ? 's' : '') : '') . ' ' . ($nVid ? "{$nVid} video" . ($nVid > 1 ? 's' : '') : ''));

    $payTxt = ($data['payment_status'] ?? '') === 'paid' ? 'Paid' : 'Payment to be verified';

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8B <b>Review your booking</b>\n\n" .
        "Business: <b>{$data['business_name']}</b>\n" .
        "Screen: <b>{$data['screen_name']}</b>" . ($data['screen_loc'] !== '' ? " - {$data['screen_loc']}" : '') . "\n" .
        "Dates: <b>{$startPretty} - {$endPretty}</b> ({$data['days']} day" . ($data['days'] > 1 ? 's' : '') . ")\n" .
        "Content: <b>{$mediaTxt}</b>\n" .
        "Total: <b>" . scr_fmt_price($data['price']) . "</b> ({$payTxt})\n\n" .
        "Submit it for review?",
        [
            [['text' => "\xE2\x9C\x85 Submit booking", 'callback_data' => 'scr_submit']],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'scr_cancel']],
        ]);
}

function scrSubmit($userId, $state) {
    global $tg, $db;
    $data = $state['data'];

    if (empty($data['business_name']) || empty($data['media']) || empty($data['screen_id'])) {
        $tg->sendInlineButtons($userId,
            "That booking is no longer in progress. Tap below to start again.",
            [[['text' => "\xF0\x9F\x96\xA5\xEF\xB8\x8F Advertise on a Screen", 'callback_data' => 'scr_start']]]);
        return;
    }

    $sdb = scr_db();
    // Final availability re-check (someone may have booked it while this user filled the form).
    if ($sdb && !hl_screen_is_available($sdb, $data['screen_id'], $data['start_date'], $data['end_date'])) {
        $db->setState($userId, 'idle', []);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x86 Sorry - that screen was just booked for those dates by someone else. Please pick different dates. " .
            "If you've already paid, contact us and we'll sort out a refund or a new slot.",
            [[['text' => "\xF0\x9F\x96\xA5\xEF\xB8\x8F Try again", 'callback_data' => 'scr_start']]]);
        return;
    }

    $bookingId = hl_screen_book($sdb, [
        'screen_id'      => (int) $data['screen_id'],
        'telegram_id'    => (int) $userId,
        'business_name'  => $data['business_name'],
        'media'          => $data['media'],
        'start_date'     => $data['start_date'],
        'end_date'       => $data['end_date'],
        'price'          => (float) ($data['price'] ?? 0),
        'payment_status' => ($data['payment_status'] ?? 'awaiting_verification'),
        'payment_ref'    => ($data['receipt'] ?? ''),
        'status'         => 'pending',
    ]);

    $db->setState($userId, 'idle', []);

    if (!$bookingId) {
        $tg->sendMessage($userId, "Sorry, something went wrong saving your booking. Please contact support and we'll help.");
        return;
    }

    $receipt = $data['receipt'] ?? '';
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Booking submitted for review!</b>\n\n" .
        ($receipt ? "Reference: <b>{$receipt}</b>\n" : '') .
        "Status: <b>Pending review</b>\n\n" .
        "Our team will verify and approve it (usually within 24 hours). Once approved, your ad appears on <b>{$data['screen_name']}</b> from <b>" .
        date('M j', strtotime($data['start_date'])) . "</b>. You'll be notified here.",
        [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);

    scrNotifyAdmins($bookingId, $data, $db->getUser($userId));
}

// ---------------------------------------------------------------------------
// Admin approval (Telegram) - the web queue in admin/screens.php mirrors this
// ---------------------------------------------------------------------------

function scrNotifyAdmins($bookingId, $data, $user) {
    global $tg, $config;

    $poster = $user['name'] ?? 'Unknown';
    $phone  = $user['phone'] ?? '';
    $startPretty = date('M j', strtotime($data['start_date']));
    $endPretty   = date('M j, Y', strtotime($data['end_date']));
    $payTxt = ($data['payment_status'] ?? '') === 'paid'
        ? 'Paid by card' : 'Manual payment - verify the screenshot';

    $summary = "\xF0\x9F\x94\x94 <b>New screen booking awaiting review</b>\n\n" .
        "Business: <b>" . htmlspecialchars($data['business_name'] ?? '', ENT_QUOTES) . "</b>\n" .
        "Screen: <b>" . htmlspecialchars($data['screen_name'] ?? '', ENT_QUOTES) . "</b>" .
        (($data['screen_loc'] ?? '') !== '' ? " - " . htmlspecialchars($data['screen_loc'], ENT_QUOTES) : '') . "\n" .
        "Dates: <b>{$startPretty} - {$endPretty}</b> ({$data['days']} day" . ($data['days'] > 1 ? 's' : '') . ")\n" .
        "Total: <b>" . scr_fmt_price($data['price'] ?? 0) . "</b>\n" .
        "Payment: <b>{$payTxt}</b>" . (!empty($data['receipt']) ? " (ref {$data['receipt']})" : '') . "\n" .
        "\xF0\x9F\x91\xA4 By: <b>{$poster}</b>" . ($phone ? " ({$phone})" : '') . "\n\n" .
        "Approve to put it live on the screen for those dates, or Reject.";

    $buttons = [[
        ['text' => "\xE2\x9C\x85 Approve", 'callback_data' => "scrapprove_{$bookingId}"],
        ['text' => "\xE2\x9D\x8C Reject",  'callback_data' => "scrreject_{$bookingId}"],
    ]];

    foreach (($config['admin_ids'] ?? []) as $adminId) {
        if (!empty($data['_preview'])) {
            $tg->sendMediaGroup($adminId, $data['_preview']);
        }
        if (!empty($data['payment_proof'])) {
            $tg->sendPhoto($adminId, $data['payment_proof'], "\xF0\x9F\x92\xB3 Payment proof - " . ($data['receipt'] ?? ''));
        }
        $tg->sendInlineButtons($adminId, $summary, $buttons);
    }
}

function scrModerate($adminId, $bookingId, $decision) {
    global $tg;
    if (!isAdmin($adminId)) return;

    $sdb = scr_db();
    $b = $sdb ? hl_screen_booking_by_id($sdb, $bookingId) : null;
    if (!$b) { $tg->sendMessage($adminId, "That booking could not be found."); return; }
    if (($b['status'] ?? '') !== 'pending') {
        $tg->sendMessage($adminId, "This booking was already handled (status: {$b['status']}).");
        return;
    }

    $screen = hl_screen_by_id($sdb, $b['screen_id']);
    $sname  = $screen['name'] ?? 'the screen';
    $bname  = $b['business_name'] ?: 'your ad';
    $posterTid = (int) $b['telegram_id'];

    if ($decision === 'approve') {
        hl_screen_set_booking_status($sdb, $bookingId, 'approved', 'paid');
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Approved: <b>{$bname}</b> on {$sname}.");
        if ($posterTid) {
            $tg->sendInlineButtons($posterTid,
                "\xF0\x9F\x8E\x89 <b>Great news!</b> Your ad for <b>{$bname}</b> has been approved and will show on <b>{$sname}</b> from <b>" .
                date('M j', strtotime($b['start_date'])) . "</b> to <b>" . date('M j, Y', strtotime($b['end_date'])) . "</b>. Thank you!",
                [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        }
    } else {
        hl_screen_set_booking_status($sdb, $bookingId, 'rejected');
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Rejected: <b>{$bname}</b>.");
        if ($posterTid) {
            $tg->sendInlineButtons($posterTid,
                "Regarding your screen ad for <b>{$bname}</b> - unfortunately it wasn't approved this time. " .
                "Please contact support if you have questions or would like to resubmit.",
                [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        }
    }
}

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

function scrCancel($userId) {
    global $tg, $db;
    $db->setState($userId, 'idle', []);
    $tg->sendInlineButtons($userId, "No problem - your screen booking was cancelled.",
        [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
}
