<?php
/**
 * transport.php - the Telegram side of the HabeshaList Verified Transporter
 * module (Phase 1 MVP).
 *
 * FLOW (spec sections 2-16)
 *   Luggage & Transport menu
 *     -> Become a Verified Transporter : apply one question at a time, agree,
 *        land as pending; admin approves; transporter then creates a public
 *        @username, which creates the website profile automatically.
 *     -> Post Available Space          : eligibility gate -> collect the trip ->
 *        preview (Edit | Promote This Post | Cancel) -> pay the admin-set
 *        advertising fee (card or screenshot) -> publish to the Telegram group.
 *     -> Find a Transporter            : From? / To? -> matching profiles.
 *     -> Rate a Transporter            : @username -> 1-5 stars -> optional comment.
 *     -> Safety Notice                 : the platform safety + disclaimer copy.
 *
 * SELF-CONTAINED: every state is prefixed 'tr_' and every callback 'tr...', so
 * this can never collide with the classifieds bot, the promotions engine or the
 * screens module. It writes only to the three transporter tables.
 *
 * The data layer (includes/transporters.php) is required by webhook.php before
 * this file; we open our own SQLite3 handle to the same database (exactly as
 * screen_booking.php does) because those functions take a raw handle.
 */

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** Raw SQLite3 handle to the bot DB (cached), with the transporter schema ensured. */
function tr_db() {
    global $db;
    static $tdb = null;
    if ($tdb instanceof SQLite3) return $tdb;
    try {
        $tdb = new SQLite3($db->path(), SQLITE3_OPEN_READWRITE);
        $tdb->busyTimeout(4000);
        $tdb->exec('PRAGMA journal_mode=WAL');
        if (function_exists('hl_tr_ensure_schema')) hl_tr_ensure_schema($tdb);
    } catch (\Throwable $e) { $tdb = null; }
    return $tdb;
}

/** Every transporter setting, read through the bot's settings table. */
function tr_settings() {
    global $db;
    return hl_tr_settings(function ($k, $d = null) use ($db) { return $db->getSetting($k, $d); });
}

/** Where profile photos are saved so the WEBSITE can serve them. */
function tr_uploads_dir() {
    $dir = dirname(__DIR__) . '/uploads/transporters';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Download a Telegram photo to disk; returns a relative path or ''. */
function tr_download_photo($fileId) {
    global $tg;
    try {
        $info = $tg->getFile($fileId);
        $tgPath = $info['result']['file_path'] ?? '';
        if ($tgPath === '') return '';
        $bytes = $tg->downloadFile($tgPath);
        if ($bytes === false || $bytes === null || strlen($bytes) === 0) return '';
        if (strlen($bytes) > 12 * 1024 * 1024) return '';     // 12 MB cap for a profile photo
        $ext = strtolower(pathinfo($tgPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents(tr_uploads_dir() . '/' . $fname, $bytes) === false) return '';
        return 'uploads/transporters/' . $fname;
    } catch (\Throwable $e) {
        return '';
    }
}

/** Public website profile URL for a transporter (or '' if not configured). */
function tr_profile_url($username) {
    $s = tr_settings();
    $u = hl_tr_normalize_username($username);
    if ($u === '' || $s['site_url'] === '') return '';
    return $s['site_url'] . '/transporters/' . rawurlencode($u);
}

/** Deep link back into this bot's chat (Stripe success/cancel return URL). */
function tr_return_link($payload) {
    global $config;
    $user = function_exists('getBotUsername') ? getBotUsername() : '';
    if ($user && $user !== 'bot') return 'https://t.me/' . $user . '?start=' . rawurlencode($payload);
    return $config['website_url'] ?? 'https://habeshalist.com';
}

/** HTML-escape a user-supplied value before it goes into a Telegram message. */
function tr_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** The transporter record for a Telegram user, or null. */
function tr_me($userId) {
    $tdb = tr_db();
    return $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
}

/** Standard "back to the transport menu" button row. */
function tr_home_row() {
    return [['text' => "\xF0\x9F\xA7\xB3 Luggage & Transport", 'callback_data' => 'tr_menu']];
}

// ---------------------------------------------------------------------------
// Section 2 - the Luggage & Transport menu
// ---------------------------------------------------------------------------

function trMenu($userId) {
    global $tg;

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    $elig = hl_tr_eligibility($me);

    // The first button adapts to where this user is in the journey, so nobody is
    // asked to "apply" when what they actually need is to pick a username.
    if ($elig['state'] === 'no_username') {
        $first = ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Create My Public Username", 'callback_data' => 'tr_uname'];
    } elseif ($elig['state'] === 'ready') {
        $first = ['text' => "\xF0\x9F\x91\xA4 My Transporter Profile", 'callback_data' => 'tr_profile'];
    } else {
        $first = ['text' => "\xE2\x9C\x85 Become a Verified Transporter", 'callback_data' => 'tr_apply'];
    }

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\xA7\xB3 <b>Luggage & Transport</b>\n\n" .
        "Travelling with spare luggage space, or looking for someone who is? " .
        "Verified Transporters advertise their route, date and available space here.\n\n" .
        hl_tr_disclaimer(),
        [
            [$first],
            [['text' => "\xF0\x9F\x93\xA6 Post Available Space", 'callback_data' => 'tr_post']],
            [['text' => "\xF0\x9F\x94\x8D Find a Transporter",   'callback_data' => 'tr_find']],
            [['text' => "\xE2\xAD\x90 Rate a Transporter",       'callback_data' => 'tr_rate']],
            [['text' => "\xF0\x9F\x9B\xA1\xEF\xB8\x8F Safety Notice", 'callback_data' => 'tr_safety']],
            [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
        ]);
}

// ---------------------------------------------------------------------------
// Section 16 - Safety Notice
// ---------------------------------------------------------------------------

function trSafety($userId) {
    global $tg;
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x9B\xA1\xEF\xB8\x8F <b>Safety Notice</b>\n\n" .
        hl_tr_safety_notice() . "\n\n" .
        hl_tr_disclaimer(),
        [tr_home_row(), [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
}

// ---------------------------------------------------------------------------
// Section 3 - Verified Transporter application
// ---------------------------------------------------------------------------

/** Step 1: explain the programme, then start collecting. */
function trApplyIntro($userId) {
    global $tg;

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    $status = $me['verification_status'] ?? '';

    if ($status === 'pending') {
        $tg->sendInlineButtons($userId,
            "\xE2\x8F\xB3 <b>Your application is under review.</b>\n\n" .
            "We'll message you right here as soon as it's approved. Thank you for your patience!",
            [tr_home_row()]);
        return;
    }
    if ($status === 'approved') {
        trShowProfile($userId);
        return;
    }
    if ($status === 'suspended') {
        $tg->sendInlineButtons($userId,
            "Your transporter account is currently suspended. Please contact support if you'd like to discuss it.",
            [tr_home_row()]);
        return;
    }

    $again = ($status === 'rejected')
        ? "You can apply again with updated details.\n\n" : '';

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Become a Verified Transporter</b>\n\n" . $again .
        "Verification helps customers find you and keeps the community organised and transparent - " .
        "your route, rating and contact details all in one public profile on HabeshaList.com.\n\n" .
        "\xE2\x9A\xA0\xEF\xB8\x8F <b>Important:</b> being verified does <b>not</b> mean HabeshaList guarantees " .
        "transportation or delivery. " . hl_tr_disclaimer(false) . "\n\n" .
        "It takes about a minute. Ready?",
        [
            [['text' => "\xE2\x9C\x85 Start my application", 'callback_data' => 'tr_apply_go']],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'tr_menu']],
        ]);
}

/** Step 2: collect the MVP fields, one question at a time. */
function trApplyStart($userId, $from = []) {
    global $tg, $db;

    // Telegram identity is captured AUTOMATICALLY - never asked for.
    $data = [
        'telegram_id'     => (int) $userId,
        'tg_username'     => (string) ($from['username'] ?? ''),
        'tg_display_name' => trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? '')),
    ];
    $db->setState($userId, 'tr_ap_name', $data);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x91\xA4 <b>1 of 6</b> - What is your <b>full name</b>?",
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

/** Ask for the profile photo (its own step so it can be re-prompted cleanly). */
function trAskPhoto($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'tr_ap_photo', $data);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\xB7 <b>4 of 6</b> - Send a <b>profile photo</b>.\n\n" .
        "This is the picture customers see on your public profile, so a clear photo of you works best.",
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

/** Step 3: the agreement. Nothing is stored as an application until this is accepted. */
function trShowAgreement($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'tr_ap_agree', $data);

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8B <b>Please confirm your details</b>\n\n" .
        "Name: <b>" . tr_h($data['full_name'] ?? '') . "</b>\n" .
        "Phone: <b>" . tr_h($data['phone_number'] ?? '') . "</b>\n" .
        "Usual route: <b>" . tr_h($data['origin'] ?? '') . " \xE2\x86\x92 " . tr_h($data['destination'] ?? '') . "</b>\n" .
        "Contact method: <b>" . tr_h($data['contact_method'] ?? '') . "</b>\n" .
        "Profile photo: <b>" . (!empty($data['photo_file_id']) ? 'received' : 'missing') . "</b>\n\n" .
        "By tapping <b>I Agree &amp; Submit</b> you confirm that the information above is accurate, " .
        "and you acknowledge that HabeshaList provides advertising and community exposure only and " .
        "does <b>not</b> guarantee transportation, delivery, payment, loss or damage.",
        [
            [['text' => "\xE2\x9C\x85 I Agree & Submit", 'callback_data' => 'tr_ap_submit']],
            [
                ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Start over", 'callback_data' => 'tr_apply_go'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel'],
            ],
        ]);
}

/** Step 4: save with verification_status = pending and tell the admins. */
function trApplySubmit($userId, $state) {
    global $tg, $db;

    $data = $state['data'] ?? [];
    foreach (['full_name', 'phone_number', 'origin', 'destination'] as $req) {
        if (trim((string) ($data[$req] ?? '')) === '') {
            $tg->sendInlineButtons($userId,
                "That application is no longer in progress. Tap below to start again.",
                [[['text' => "\xE2\x9C\x85 Become a Verified Transporter", 'callback_data' => 'tr_apply']]]);
            return;
        }
    }

    $tdb = tr_db();
    if (!$tdb) { $tg->sendMessage($userId, "Sorry, something went wrong. Please try again shortly."); return; }

    // Save a permanent copy of the photo so the website can serve it later.
    if (!empty($data['photo_file_id']) && empty($data['photo_path'])) {
        $data['photo_path'] = tr_download_photo($data['photo_file_id']);
    }
    $data['agreed_at'] = gmdate('Y-m-d H:i:s');

    $id = hl_tr_apply($tdb, $data);
    $db->setState($userId, 'idle', []);

    if (!$id) { $tg->sendMessage($userId, "Sorry, we couldn't save your application. Please contact support."); return; }

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Application submitted!</b>\n\n" .
        "Status: <b>Under review</b>\n\n" .
        "Our team will review your details and get back to you here, usually within 24 hours. " .
        "Once you're approved you'll choose your public HabeshaList username and your profile goes live on the website.",
        [tr_home_row(), [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);

    trNotifyAdminsApplication($id, $data);
}

function trNotifyAdminsApplication($transporterId, array $data) {
    global $tg, $config;

    $tgUser = trim((string) ($data['tg_username'] ?? ''));
    $summary = "\xF0\x9F\x94\x94 <b>New Verified Transporter application</b>\n\n" .
        "Name: <b>" . tr_h($data['full_name'] ?? '') . "</b>\n" .
        "Phone: <b>" . tr_h($data['phone_number'] ?? '') . "</b>\n" .
        "Route: <b>" . tr_h($data['origin'] ?? '') . " \xE2\x86\x92 " . tr_h($data['destination'] ?? '') . "</b>\n" .
        "Contact: <b>" . tr_h($data['contact_method'] ?? '') . "</b>\n" .
        "Telegram: " . ($tgUser !== '' ? '@' . tr_h($tgUser) : 'no @username') .
        " (id <code>" . (int) ($data['telegram_id'] ?? 0) . "</code>)\n" .
        "Internal transporter id: <b>#{$transporterId}</b>\n\n" .
        "Approve to let them create their public username and go live on the website.";

    $buttons = [[
        ['text' => "\xE2\x9C\x85 Approve", 'callback_data' => "trok_{$transporterId}"],
        ['text' => "\xE2\x9D\x8C Reject",  'callback_data' => "trno_{$transporterId}"],
    ]];

    foreach (($config['admin_ids'] ?? []) as $adminId) {
        if (!empty($data['photo_file_id'])) {
            $tg->sendPhoto($adminId, $data['photo_file_id'], "Profile photo - " . ($data['full_name'] ?? ''));
        }
        $tg->sendInlineButtons($adminId, $summary, $buttons);
    }
}

// ---------------------------------------------------------------------------
// Section 4 - admin approval from inside Telegram
// ---------------------------------------------------------------------------

function trModerate($adminId, $transporterId, $decision) {
    global $tg;
    if (!isAdmin($adminId)) return;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, $transporterId) : null;
    if (!$tr) { $tg->sendMessage($adminId, "That application could not be found."); return; }
    if (($tr['verification_status'] ?? '') !== 'pending') {
        $tg->sendMessage($adminId, "This application was already handled (status: {$tr['verification_status']}).");
        return;
    }

    $name = $tr['full_name'] ?: 'the transporter';
    $tid  = (int) $tr['telegram_id'];

    if ($decision === 'approve') {
        hl_tr_set_status($tdb, $transporterId, 'approved');
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Approved: <b>" . tr_h($name) . "</b> (#{$transporterId}).");
        if ($tid) trPromptUsername($tid, true);
    } else {
        hl_tr_set_status($tdb, $transporterId, 'rejected');
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Rejected: <b>" . tr_h($name) . "</b> (#{$transporterId}).");
        if ($tid) {
            $tg->sendInlineButtons($tid,
                "Thank you for applying to become a Verified Transporter. Unfortunately your application " .
                "wasn't approved this time. You're welcome to apply again with updated details, or contact " .
                "support if you have questions.",
                [tr_home_row()]);
        }
    }
}

// ---------------------------------------------------------------------------
// Section 5 - the public @username
// ---------------------------------------------------------------------------

function trPromptUsername($userId, $justApproved = false) {
    global $tg, $db;

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    if (!$me || ($me['verification_status'] ?? '') !== 'approved') {
        trMenu($userId);
        return;
    }

    $existing = hl_tr_normalize_username($me['username'] ?? '');
    if ($existing !== '' && !$justApproved) {
        // Already has one - offer a change, which needs admin approval.
        $pending = hl_tr_normalize_username($me['username_pending'] ?? '');
        $db->setState($userId, 'tr_uname_change', []);
        $tg->sendInlineButtons($userId,
            "Your public username is <b>@" . tr_h($existing) . "</b>.\n\n" .
            ($pending !== '' ? "A change to <b>@" . tr_h($pending) . "</b> is waiting for admin approval.\n\n" : '') .
            "To request a different one, send it now. Username changes are reviewed by our team before they go live.",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'tr_menu']]]);
        return;
    }

    $db->setState($userId, 'tr_uname_new', []);
    $tg->sendInlineButtons($userId,
        ($justApproved ? "\xF0\x9F\x8E\x89 <b>Congratulations - you're a Verified Transporter!</b>\n\n" : '') .
        "\xE2\x9C\x8F\xEF\xB8\x8F <b>Choose your public HabeshaList username</b>\n\n" .
        "This is the name customers will see on the website, on your advertisements and when they rate you - " .
        "for example <b>@AbebeTravel</b>.\n\n" .
        "Rules: letters, numbers and underscore only, no spaces, 4-24 characters, and it must be unique.\n\n" .
        "Send the username you'd like:",
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

/** Handle a typed username, for either the first claim or a change request. */
function trHandleUsername($userId, $text, $isChange) {
    global $tg, $db;

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    if (!$me) { $db->setState($userId, 'idle', []); trMenu($userId); return; }

    $wanted = hl_tr_normalize_username($text);

    if ($isChange) {
        $err = hl_tr_request_username_change($tdb, (int) $me['id'], $wanted);
        if ($err !== '') {
            $tg->sendInlineButtons($userId, "\xE2\x9A\xA0\xEF\xB8\x8F " . tr_h($err) . "\n\nPlease send another one:",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;
        }
        $db->setState($userId, 'idle', []);
        $tg->sendInlineButtons($userId,
            "\xE2\x9C\x85 Request received. Our team will review the change to <b>@" . tr_h($wanted) . "</b> " .
            "and let you know. Your current username stays active until then.",
            [tr_home_row()]);
        trNotifyAdminsUsernameChange($me, $wanted);
        return;
    }

    $err = hl_tr_set_username($tdb, (int) $me['id'], $wanted);
    if ($err !== '') {
        $tg->sendInlineButtons($userId, "\xE2\x9A\xA0\xEF\xB8\x8F " . tr_h($err) . "\n\nPlease send another one:",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
        return;
    }

    $db->setState($userId, 'idle', []);
    // Approval + username confirmation is what creates the website profile.
    $url = tr_profile_url($wanted);
    $buttons = [];
    if ($url !== '') $buttons[] = [['text' => "\xF0\x9F\x8C\x90 View my public profile", 'url' => $url]];
    $buttons[] = [['text' => "\xF0\x9F\x93\xA6 Post Available Space", 'callback_data' => 'tr_post']];
    $buttons[] = tr_home_row();

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8E\x89 <b>You're live!</b>\n\n" .
        "Your public username is <b>@" . tr_h($wanted) . "</b> and your Verified Transporter profile " .
        "is now on HabeshaList.com" . ($url !== '' ? "" : "/transporters") . ".\n\n" .
        "You can now post your available space whenever you're travelling.",
        $buttons);
}

function trNotifyAdminsUsernameChange($tr, $wanted) {
    global $tg, $config;
    $id = (int) $tr['id'];
    foreach (($config['admin_ids'] ?? []) as $adminId) {
        $tg->sendInlineButtons($adminId,
            "\xE2\x9C\x8F\xEF\xB8\x8F <b>Username change requested</b>\n\n" .
            "Transporter: <b>" . tr_h($tr['full_name'] ?? '') . "</b> (#{$id})\n" .
            "Current: <b>@" . tr_h($tr['username'] ?? '') . "</b>\n" .
            "Requested: <b>@" . tr_h($wanted) . "</b>",
            [[
                ['text' => "\xE2\x9C\x85 Approve change", 'callback_data' => "truok_{$id}"],
                ['text' => "\xE2\x9D\x8C Decline",        'callback_data' => "truno_{$id}"],
            ]]);
    }
}

function trModerateUsername($adminId, $transporterId, $approve) {
    global $tg;
    if (!isAdmin($adminId)) return;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, $transporterId) : null;
    if (!$tr) { $tg->sendMessage($adminId, "That transporter could not be found."); return; }

    $wanted = hl_tr_normalize_username($tr['username_pending'] ?? '');
    $err = hl_tr_resolve_username_change($tdb, $transporterId, $approve);
    if ($err !== '') { $tg->sendMessage($adminId, "\xE2\x9A\xA0\xEF\xB8\x8F " . tr_h($err)); return; }

    $tid = (int) $tr['telegram_id'];
    if ($approve) {
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Username changed to <b>@" . tr_h($wanted) . "</b>.");
        if ($tid) $tg->sendInlineButtons($tid,
            "\xE2\x9C\x85 Your public username is now <b>@" . tr_h($wanted) . "</b>. Your profile link has been updated.",
            [tr_home_row()]);
    } else {
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Username change declined.");
        if ($tid) $tg->sendInlineButtons($tid,
            "Your username change request wasn't approved. Your current username stays active.",
            [tr_home_row()]);
    }
}

// ---------------------------------------------------------------------------
// The transporter's own profile card in the bot
// ---------------------------------------------------------------------------

function trShowProfile($userId) {
    global $tg;

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    if (!$me) { trApplyIntro($userId); return; }

    $uname = hl_tr_normalize_username($me['username'] ?? '');
    $status = (string) $me['verification_status'];
    $statusLabel = [
        'pending'   => "\xE2\x8F\xB3 Under review",
        'approved'  => "\xE2\x9C\x85 Verified Transporter",
        'rejected'  => "\xE2\x9D\x8C Not approved",
        'suspended' => "\xE2\x9B\x94 Suspended",
    ][$status] ?? $status;

    $posts = hl_tr_published_count($tdb, (int) $me['id']);
    $url = tr_profile_url($uname);

    $txt = "\xF0\x9F\x91\xA4 <b>My Transporter Profile</b>\n\n" .
        "Status: <b>{$statusLabel}</b>\n" .
        ($uname !== '' ? "Username: <b>@" . tr_h($uname) . "</b>\n" : '') .
        "Route: <b>" . tr_h($me['origin']) . " \xE2\x86\x92 " . tr_h($me['destination']) . "</b>\n" .
        "Rating: <b>" . hl_tr_stars(hl_tr_avg($me)) . " " . hl_tr_rating_label($me) . "</b>\n" .
        "Published posts: <b>{$posts}</b>\n";
    if (hl_tr_normalize_username($me['username_pending'] ?? '') !== '') {
        $txt .= "Username change to <b>@" . tr_h($me['username_pending']) . "</b>: awaiting admin approval\n";
    }

    $buttons = [];
    if ($url !== '') $buttons[] = [['text' => "\xF0\x9F\x8C\x90 View public profile", 'url' => $url]];
    $buttons[] = [['text' => "\xF0\x9F\x93\xA6 Post Available Space", 'callback_data' => 'tr_post']];
    if ($uname !== '') $buttons[] = [['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Change my username", 'callback_data' => 'tr_uname']];
    $buttons[] = tr_home_row();

    $tg->sendInlineButtons($userId, $txt, $buttons);
}

// ---------------------------------------------------------------------------
// Section 8 - Post Available Space (eligibility first)
// ---------------------------------------------------------------------------

function trPostStart($userId) {
    global $tg, $db;

    $settings = tr_settings();
    if (!$settings['enabled']) {
        $tg->sendInlineButtons($userId, "Luggage & Transport is temporarily unavailable. Please check back soon.",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        return;
    }

    $tdb = tr_db();
    $me  = $tdb ? hl_tr_by_telegram($tdb, $userId) : null;
    $elig = hl_tr_eligibility($me);

    if (!$elig['can_post']) {
        // Every blocked state gets the ONE action that actually unblocks it.
        $buttons = [];
        if ($elig['state'] === 'none' || $elig['state'] === 'rejected') {
            $buttons[] = [['text' => "\xE2\x9C\x85 Become a Verified Transporter", 'callback_data' => 'tr_apply']];
        } elseif ($elig['state'] === 'no_username') {
            $buttons[] = [['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Create my username", 'callback_data' => 'tr_uname']];
        }
        $buttons[] = tr_home_row();
        $tg->sendInlineButtons($userId, "\xF0\x9F\x93\xA6 <b>Post Available Space</b>\n\n" . $elig['message'], $buttons);
        return;
    }

    // Prefill the usual route - most trips repeat it, and they can type over it.
    $db->setState($userId, 'tr_p_origin', [
        'transporter_id' => (int) $me['id'],
        'def_origin'      => (string) $me['origin'],
        'def_destination' => (string) $me['destination'],
        'def_contact'     => (string) $me['contact_method'],
    ]);

    $hint = trim((string) $me['origin']) !== '' ? "\n\n(Your usual origin is <b>" . tr_h($me['origin']) . "</b> - send it again or type a different one.)" : '';
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\xA6 <b>Post Available Space</b>\n\n" .
        "\xF0\x9F\x93\x8D <b>1 of 6</b> - Where are you travelling <b>from</b>?" . $hint,
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

// ---------------------------------------------------------------------------
// Section 9 - the advertisement preview
// ---------------------------------------------------------------------------

/** The exact advertisement body that gets published, reused by the preview. */
function trAdText(array $tr, array $d, $forGroup = true) {
    $uname = hl_tr_normalize_username($tr['username'] ?? '');
    $url   = tr_profile_url($uname);

    $txt = "\xF0\x9F\xA7\xB3 <b>Luggage Space Available</b>\n\n" .
        "\xE2\x9C\x85 <b>Verified Transporter</b>  <b>@" . tr_h($uname) . "</b>\n";

    if ((int) ($tr['rating_count'] ?? 0) > 0) {
        $txt .= hl_tr_stars(hl_tr_avg($tr)) . " " . hl_tr_rating_label($tr) . "\n";
    }

    $txt .= "\n" .
        "\xF0\x9F\x93\x8D Route: <b>" . tr_h($d['origin']) . " \xE2\x86\x92 " . tr_h($d['destination']) . "</b>\n" .
        "\xF0\x9F\x93\x85 Date: <b>" . tr_h($d['travel_date']) . "</b>\n" .
        "\xF0\x9F\x93\xA6 Available space: <b>" . tr_h($d['space']) . "</b>\n" .
        "\xF0\x9F\x92\xB5 Price: <b>" . tr_h($d['price']) . "</b>\n" .
        "\xF0\x9F\x93\x9E Contact: <b>" . tr_h($d['contact_method']) . "</b>\n";

    if ($url !== '') $txt .= "\n\xF0\x9F\x8C\x90 Profile: " . $url . "\n";

    $txt .= "\n" . hl_tr_disclaimer();
    return $txt;
}

function trShowPreview($userId, $data) {
    global $tg, $db;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, (int) ($data['transporter_id'] ?? 0)) : null;
    if (!$tr) { trPostStart($userId); return; }

    $db->setState($userId, 'tr_p_preview', $data);

    $settings = tr_settings();
    $fee = hl_tr_fee_for($tr, $settings);
    $data['_fee'] = $fee['fee'];

    $feeLine = $fee['fee'] > 0
        ? "Advertising fee: <b>" . hl_tr_money($fee['fee'], $settings['currency']) . "</b>"
        : ($fee['reason'] === 'intro'
            ? "Advertising fee: <b>FREE</b> (introductory post " . $fee['free_left'] . " remaining)"
            : "Advertising fee: <b>FREE</b>");

    $tg->sendMessage($userId,
        "\xF0\x9F\x91\x81\xEF\xB8\x8F <b>This is exactly how your advertisement will be published:</b>\n\n" .
        "\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\n\n" .
        trAdText($tr, $data) . "\n\n" .
        "\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81");

    $db->setState($userId, 'tr_p_preview', $data);
    $tg->sendInlineButtons($userId,
        $feeLine . "\n\n" .
        ($fee['fee'] > 0
            ? "This fee is for <b>HabeshaList advertising exposure only</b> - it is not a transportation or shipping charge."
            : "No payment needed for this post."),
        [
            [['text' => "\xF0\x9F\x93\xA2 Promote This Post", 'callback_data' => 'tr_p_promote']],
            [
                ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit",   'callback_data' => 'tr_p_edit'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel'],
            ],
        ]);
}

/** "Edit" - let them jump back to any single field. */
function trEditMenu($userId, $state) {
    global $tg, $db;
    $data = $state['data'] ?? [];
    $db->setState($userId, 'tr_p_preview', $data);
    $tg->sendInlineButtons($userId, "\xE2\x9C\x8F\xEF\xB8\x8F What would you like to change?", [
        [['text' => "\xF0\x9F\x93\x8D From",  'callback_data' => 'tred_origin'],
         ['text' => "\xF0\x9F\x8E\xAF To",    'callback_data' => 'tred_destination']],
        [['text' => "\xF0\x9F\x93\x85 Date",  'callback_data' => 'tred_travel_date'],
         ['text' => "\xF0\x9F\x93\xA6 Space", 'callback_data' => 'tred_space']],
        [['text' => "\xF0\x9F\x92\xB5 Price", 'callback_data' => 'tred_price'],
         ['text' => "\xF0\x9F\x93\x9E Contact", 'callback_data' => 'tred_contact_method']],
        [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to preview", 'callback_data' => 'tr_p_back']],
    ]);
}

function trEditField($userId, $field, $state) {
    global $tg, $db;
    $labels = [
        'origin'         => 'Where are you travelling <b>from</b>?',
        'destination'    => 'Where are you travelling <b>to</b>?',
        'travel_date'    => 'What is your <b>travel date</b>?',
        'space'          => 'How much <b>space</b> do you have available?',
        'price'          => 'What is <b>your price</b>?',
        'contact_method' => 'How should customers <b>contact</b> you?',
    ];
    if (!isset($labels[$field])) { trShowPreview($userId, $state['data'] ?? []); return; }

    $data = $state['data'] ?? [];
    $data['_editing'] = $field;
    $db->setState($userId, 'tr_p_editing', $data);
    $tg->sendInlineButtons($userId, "\xE2\x9C\x8F\xEF\xB8\x8F " . $labels[$field],
        [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'tr_p_back']]]);
}

// ---------------------------------------------------------------------------
// Sections 10-11 - the advertising fee + payment
// ---------------------------------------------------------------------------

function trShowPayment($userId, $data) {
    global $tg, $db;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, (int) ($data['transporter_id'] ?? 0)) : null;
    if (!$tr) { trPostStart($userId); return; }

    $settings = tr_settings();
    $fee = hl_tr_fee_for($tr, $settings);

    // Free (pilot / advertising off / zero fee) - publish immediately.
    if ($fee['fee'] <= 0) {
        $data['ad_fee'] = 0.0;
        $data['payment_method'] = 'free';
        $data['payment_status'] = 'free';
        $data['_free_reason'] = $fee['reason'];
        trFinalize($userId, $data);
        return;
    }

    // The fee is FROZEN here, at checkout, so a later admin price change can
    // never rewrite this transaction (spec section 11).
    $data['ad_fee'] = (float) $fee['fee'];
    $db->setState($userId, 'tr_p_payment', $data);

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x92\xB3 <b>Advertising fee: " . hl_tr_money($fee['fee'], $settings['currency']) . "</b>\n\n" .
        "This pays for <b>HabeshaList advertising exposure</b> - publishing your post to our Telegram " .
        "community and keeping your profile listed. It is <b>not</b> a transportation or shipping charge, " .
        "and HabeshaList is not part of any agreement between you and your customer.\n\n" .
        "Choose how you'd like to pay:",
        [
            [['text' => "\xF0\x9F\x92\xB3 Pay by Card", 'callback_data' => 'trpay_card']],
            [['text' => "\xF0\x9F\x93\xB8 Submit Payment Screenshot", 'callback_data' => 'trpay_manual']],
            [
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'tr_p_back'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel'],
            ],
        ]);
}

function trHandlePayMethod($userId, $method, $state) {
    global $tg, $db, $config;

    $data = $state['data'] ?? [];
    if (empty($data['transporter_id'])) { trPostStart($userId); return; }
    $settings = tr_settings();

    if ($method === 'card') {
        $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key'] ?? ''));
        if (empty($key) || !function_exists('hl_stripe_create_session')) {
            $db->setState($userId, 'tr_p_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB3 Card payments are being set up. For now please pay manually and send a screenshot - " .
                "your post is reviewed and published right after.",
                [
                    [['text' => "\xF0\x9F\x93\xB8 Submit Payment Screenshot", 'callback_data' => 'trpay_manual']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
                ]);
            return;
        }

        $amountCents = (int) round(((float) ($data['ad_fee'] ?? 0)) * 100);
        if ($amountCents < 50) {                     // below Stripe's minimum - treat as free
            $data['payment_method'] = 'card';
            $data['payment_status'] = 'approved';
            $data['payment_ref']    = 'HLT-FREE-' . strtoupper(substr(md5($userId . microtime()), 0, 4));
            trFinalize($userId, $data);
            return;
        }

        $sess = hl_stripe_create_session(
            $key, $amountCents,
            'HabeshaList transporter advertising - @' . ($data['_username'] ?? 'transporter'),
            tr_return_link('trpaid'), tr_return_link('trpaycancel'),
            ['telegram_id' => $userId, 'transporter_id' => (int) $data['transporter_id'], 'kind' => 'transporter_ad']
        );

        if (empty($sess['url'])) {
            $err = $sess['error'] ?? 'unknown';
            error_log('transporter stripe create session failed: ' . $err);
            foreach (($config['admin_ids'] ?? []) as $aid) {
                $tg->sendMessage((int) $aid,
                    "\xE2\x9A\xA0\xEF\xB8\x8F <b>Card checkout failed for a transporter ad.</b>\n" .
                    "Stripe said: <b>" . tr_h($err) . "</b>\nFix the key on the panel Keys page.");
            }
            $db->setState($userId, 'tr_p_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xE2\x9A\xA0\xEF\xB8\x8F Card checkout is temporarily unavailable. Please pay manually and send a screenshot instead.",
                [
                    [['text' => "\xF0\x9F\x93\xB8 Submit Payment Screenshot", 'callback_data' => 'trpay_manual']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
                ]);
            return;
        }

        $data['payment_method']  = 'card';
        $data['_stripe_session'] = $sess['id'];
        $db->setState($userId, 'tr_p_card', $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x94\x92 <b>Secure Card Payment</b>\n\n" .
            "Tap below to pay " . hl_tr_money($data['ad_fee'], $settings['currency']) .
            " on our secure payment page, then come back here.",
            [
                [['text' => "Continue to Payment", 'url' => $sess['url']]],
                [['text' => "\xF0\x9F\x94\x84 I've paid - check", 'callback_data' => 'tr_check_card']],
                [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
            ]);
        return;
    }

    // Manual: pay off-platform, then upload proof.
    $zelle   = $db->getSetting('pay_zelle', $config['payment_defaults']['pay_zelle'] ?? '');
    $cashapp = $db->getSetting('pay_cashapp', $config['payment_defaults']['pay_cashapp'] ?? '');
    $support = $db->getSetting('pay_support', $config['payment_defaults']['pay_support'] ?? '@Habesha_list');

    $msg = "\xF0\x9F\x93\xB8 <b>Pay " . hl_tr_money($data['ad_fee'], $settings['currency']) . "</b>\n\n";
    if ($zelle)   $msg .= "Zelle: <b>" . tr_h($zelle) . "</b>\n";
    if ($cashapp) $msg .= "Cash App: <b>" . tr_h($cashapp) . "</b>\n";
    if (!$zelle && !$cashapp) $msg .= "Please contact " . tr_h($support) . " for payment details.\n";
    $msg .= "\nAfter paying, send a <b>screenshot</b> of your confirmation here. " .
            "Our team verifies it and publishes your post.";

    $data['payment_method'] = 'manual';
    $db->setState($userId, 'tr_p_proof', $data);
    $tg->sendInlineButtons($userId, $msg, [
        [['text' => "\xE2\x9C\x85 I've sent the payment", 'callback_data' => 'tr_paid_manual']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'tr_p_back'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel'],
        ],
    ]);
}

/** "I've paid" after Stripe checkout - verify OUTBOUND with Stripe. */
function trCheckCard($userId, $state) {
    global $tg, $db, $config;

    $data = $state['data'] ?? [];
    $sessionId = $data['_stripe_session'] ?? '';
    if ($sessionId === '') { trShowPayment($userId, $data); return; }

    // Stripe is an optional include (webhook.php loads it only if present), so
    // never assume the helpers exist - fall back to the manual route instead of
    // fataling on a server where the file hasn't been uploaded.
    if (!function_exists('hl_stripe_get_session') || !function_exists('hl_stripe_session_paid')) {
        $db->setState($userId, 'tr_p_payment', $data);
        $tg->sendInlineButtons($userId,
            "\xE2\x9A\xA0\xEF\xB8\x8F I can't check card payments right now. Please pay manually and send a screenshot, " .
            "or contact support if you've already been charged.",
            [
                [['text' => "\xF0\x9F\x93\xB8 Submit Payment Screenshot", 'callback_data' => 'trpay_manual']],
                [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
            ]);
        return;
    }

    $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key'] ?? ''));
    $session = hl_stripe_get_session($key, $sessionId);

    if (hl_stripe_session_paid($session)) {
        $pi = is_array($session) ? (is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '') : '';
        $ref = preg_replace('/[^a-zA-Z0-9]/', '', $pi !== '' ? $pi : $sessionId);
        $data['payment_status'] = 'approved';          // card success -> publish automatically
        $data['payment_ref']    = 'HLT-CARD-' . strtoupper(substr($ref, -6));
        unset($data['_stripe_session']);
        trFinalize($userId, $data);
        return;
    }

    // Failed / not yet complete -> do NOT publish.
    $db->setState($userId, 'tr_p_card', $data);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x95\x92 I couldn't confirm your payment yet. If you just completed it, give it a few seconds and tap Check again.",
        [
            [['text' => "\xF0\x9F\x94\x84 Check payment status", 'callback_data' => 'tr_check_card']],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
        ]);
}

function trReturnFromCheckout($userId) {
    global $db;
    $state = $db->getState($userId);
    if (($state['state'] ?? '') === 'tr_p_card' && !empty($state['data']['_stripe_session'])) {
        trCheckCard($userId, $state);
        return;
    }
    trMenu($userId);
}

function trReturnFromCancel($userId) {
    global $db;
    $state = $db->getState($userId);
    if (strpos($state['state'] ?? '', 'tr_p_') === 0 && !empty($state['data']['transporter_id'])) {
        trShowPayment($userId, $state['data']);
        return;
    }
    trMenu($userId);
}

/** Manual payment: proof screenshot received (or "I've sent it" with none yet). */
function trManualProceed($userId, $state, $proofFileId) {
    global $tg, $db;

    $data = $state['data'] ?? [];
    if (empty($data['transporter_id'])) { trPostStart($userId); return; }

    if (!$proofFileId && empty($data['payment_proof'])) {
        $db->setState($userId, 'tr_p_proof', $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Please send the payment <b>screenshot</b> here as a photo, and I'll pass it to our team.",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
        return;
    }
    if ($proofFileId) $data['payment_proof'] = $proofFileId;

    $data['payment_status'] = 'pending_review';        // admin must approve before publishing
    $data['payment_ref']    = 'HLT-MAN-' . strtoupper(substr(md5($userId . microtime()), 0, 4));
    trFinalize($userId, $data);
}

// ---------------------------------------------------------------------------
// Section 12 - save the post, then publish (or queue for admin review)
// ---------------------------------------------------------------------------

/**
 * Persist the post with whatever payment outcome it reached, then either
 * publish it straight away (card approved / free pilot) or hand it to the admin
 * queue (screenshot). Everything about the published copy is frozen here.
 */
function trFinalize($userId, $data) {
    global $tg, $db;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, (int) ($data['transporter_id'] ?? 0)) : null;
    if (!$tr) { $db->setState($userId, 'idle', []); trMenu($userId); return; }

    // Re-check eligibility at the last moment - they may have been suspended
    // while filling the form, and a suspended transporter must never publish.
    $elig = hl_tr_eligibility($tr);
    if (!$elig['can_post']) {
        $db->setState($userId, 'idle', []);
        $tg->sendInlineButtons($userId, $elig['message'], [tr_home_row()]);
        return;
    }

    $payStatus = (string) ($data['payment_status'] ?? 'unpaid');
    $postStatus = in_array($payStatus, ['approved', 'free'], true) ? 'pending_review' : 'pending_payment';
    if ($payStatus === 'pending_review') $postStatus = 'pending_review';

    $postId = hl_tr_create_post($tdb, [
        'transporter_id' => (int) $tr['id'],
        'origin'         => $data['origin'] ?? '',
        'destination'    => $data['destination'] ?? '',
        'travel_date'    => $data['travel_date'] ?? '',
        'space'          => $data['space'] ?? '',
        'price'          => $data['price'] ?? '',
        'contact_method' => $data['contact_method'] ?? '',
        'ad_fee'         => (float) ($data['ad_fee'] ?? 0),
        'payment_method' => $data['payment_method'] ?? '',
        'payment_status' => $payStatus,
        'payment_ref'    => $data['payment_ref'] ?? '',
        'payment_proof'  => $data['payment_proof'] ?? '',
        'post_status'    => $postStatus,
    ]);

    $db->setState($userId, 'idle', []);

    if (!$postId) {
        $tg->sendMessage($userId, "Sorry, we couldn't save your post. Please contact support.");
        return;
    }

    // A free introductory post is only counted once it has actually been saved.
    if (($data['_free_reason'] ?? '') === 'intro') hl_tr_consume_free_post($tdb, (int) $tr['id']);

    if (in_array($payStatus, ['approved', 'free'], true)) {
        $res = trPublish($tdb, $postId);
        if ($res['ok']) {
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x8E\x89 <b>Your post is live!</b>\n\n" .
                "It has been published to the HabeshaList group" .
                (($data['payment_ref'] ?? '') !== '' ? "\nReference: <b>" . tr_h($data['payment_ref']) . "</b>" : '') . "\n\n" .
                "Customers will contact you directly using the contact method in your post.",
                [tr_home_row(), [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        } else {
            $tg->sendInlineButtons($userId,
                "\xE2\x9C\x85 Your payment is confirmed and your post is saved. " .
                "We hit a snag publishing it to the group, so our team will post it shortly - no action needed from you.",
                [tr_home_row()]);
            trNotifyAdminsPublishFailed($postId, $res['error'] ?? '');
        }
        return;
    }

    // Screenshot path: wait for an admin.
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Payment proof received.</b>\n\n" .
        "Status: <b>Awaiting verification</b>\n" .
        (($data['payment_ref'] ?? '') !== '' ? "Reference: <b>" . tr_h($data['payment_ref']) . "</b>\n" : '') .
        "\nOur team will verify your payment and publish your post, usually within 24 hours. You'll be notified here.",
        [tr_home_row(), [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);

    trNotifyAdminsPost($postId);
}

/**
 * Publish a post to the configured HabeshaList group. The duplicate guard is
 * hl_tr_mark_published(): it only matches a row with no Telegram message id, so
 * a retried payment or a double tap can never post twice.
 */
function trPublish(SQLite3 $tdb, $postId) {
    global $tg;

    $post = hl_tr_post_by_id($tdb, $postId);
    if (!$post) return ['ok' => false, 'error' => 'post not found'];

    $tr = hl_tr_by_id($tdb, (int) $post['transporter_id']);
    $block = hl_tr_publish_block_reason($tdb, $post, $tr);
    if ($block !== '') return ['ok' => false, 'error' => $block];

    $settings = tr_settings();
    $chat = $settings['group_chat_id'];
    if ($chat === '') return ['ok' => false, 'error' => 'no group chat configured'];

    $text = trAdText($tr, [
        'origin'         => $post['origin'],
        'destination'    => $post['destination'],
        'travel_date'    => $post['travel_date'],
        'space'          => $post['space'],
        'price'          => $post['price'],
        'contact_method' => $post['contact_method'],
    ]);

    // Lead with the transporter's photo when we have one - a face gets far more
    // engagement in a busy group than a wall of text.
    $photo = (string) ($tr['photo_file_id'] ?? '');
    $resp = $photo !== ''
        ? $tg->sendPhoto($chat, $photo, $text)
        : $tg->sendMessage($chat, $text);

    // If the photo send failed (expired file_id, for example), fall back to text
    // rather than losing a paid advertisement.
    if (empty($resp['ok']) && $photo !== '') {
        $resp = $tg->sendMessage($chat, $text);
    }

    if (empty($resp['ok']) || empty($resp['result']['message_id'])) {
        return ['ok' => false, 'error' => $resp['description'] ?? 'send failed'];
    }

    $mid = (int) $resp['result']['message_id'];
    if (!hl_tr_mark_published($tdb, $postId, $mid)) {
        // Someone else already published it between our check and our send.
        return ['ok' => false, 'error' => 'already published'];
    }
    return ['ok' => true, 'message_id' => $mid];
}

function trNotifyAdminsPost($postId) {
    global $tg, $config;

    $tdb = tr_db();
    $post = $tdb ? hl_tr_post_by_id($tdb, $postId) : null;
    if (!$post) return;
    $tr = hl_tr_by_id($tdb, (int) $post['transporter_id']);
    $settings = tr_settings();

    $summary = "\xF0\x9F\x94\x94 <b>Transporter ad awaiting payment verification</b>\n\n" .
        "Transporter: <b>@" . tr_h($tr['username'] ?? '') . "</b> (" . tr_h($tr['full_name'] ?? '') . ")\n" .
        "Route: <b>" . tr_h($post['origin']) . " \xE2\x86\x92 " . tr_h($post['destination']) . "</b>\n" .
        "Date: <b>" . tr_h($post['travel_date']) . "</b>\n" .
        "Space: <b>" . tr_h($post['space']) . "</b>\n" .
        "Their price: <b>" . tr_h($post['price']) . "</b>\n" .
        "Advertising fee: <b>" . hl_tr_money($post['ad_fee'], $settings['currency']) . "</b>" .
        (($post['payment_ref'] ?? '') !== '' ? " (ref " . tr_h($post['payment_ref']) . ")" : '') . "\n\n" .
        "Approve to publish it to the group.";

    $buttons = [[
        ['text' => "\xE2\x9C\x85 Approve & Publish", 'callback_data' => "trpok_{$postId}"],
        ['text' => "\xE2\x9D\x8C Reject",            'callback_data' => "trpno_{$postId}"],
    ]];

    foreach (($config['admin_ids'] ?? []) as $adminId) {
        if (!empty($post['payment_proof'])) {
            $tg->sendPhoto($adminId, $post['payment_proof'], "\xF0\x9F\x92\xB3 Payment proof - " . ($post['payment_ref'] ?? ''));
        }
        $tg->sendInlineButtons($adminId, $summary, $buttons);
    }
}

function trNotifyAdminsPublishFailed($postId, $error) {
    global $tg, $config;
    foreach (($config['admin_ids'] ?? []) as $adminId) {
        $tg->sendInlineButtons((int) $adminId,
            "\xE2\x9A\xA0\xEF\xB8\x8F <b>A PAID transporter ad could not be published.</b>\n\n" .
            "Post #{$postId}\nReason: <b>" . tr_h($error) . "</b>\n\n" .
            "The payment went through, so please publish it manually or retry below.",
            [[['text' => "\xF0\x9F\x94\x84 Retry publishing", 'callback_data' => "trpok_{$postId}"]]]);
    }
}

/** Admin decision on a screenshot-paid post (Approve & Publish / Reject). */
function trModeratePost($adminId, $postId, $approve) {
    global $tg;
    if (!isAdmin($adminId)) return;

    $tdb = tr_db();
    $post = $tdb ? hl_tr_post_by_id($tdb, $postId) : null;
    if (!$post) { $tg->sendMessage($adminId, "That post could not be found."); return; }

    if ((int) ($post['tg_message_id'] ?? 0) > 0) {
        $tg->sendMessage($adminId, "That post has already been published.");
        return;
    }

    $tr  = hl_tr_by_id($tdb, (int) $post['transporter_id']);
    $tid = (int) ($tr['telegram_id'] ?? 0);

    if (!$approve) {
        hl_tr_update_post($tdb, $postId, ['payment_status' => 'rejected', 'post_status' => 'rejected']);
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Rejected post #{$postId}.");
        if ($tid) $tg->sendInlineButtons($tid,
            "Your transporter advertisement wasn't approved, so it has not been published. " .
            "If you think this is a mistake or you'd like to resubmit, please contact support.",
            [tr_home_row()]);
        return;
    }

    hl_tr_update_post($tdb, $postId, ['payment_status' => 'approved']);
    $res = trPublish($tdb, $postId);

    if ($res['ok']) {
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Published post #{$postId} to the group.");
        if ($tid) $tg->sendInlineButtons($tid,
            "\xF0\x9F\x8E\x89 <b>Your payment is verified and your post is live!</b>\n\n" .
            "It has been published to the HabeshaList group. Customers will contact you directly.",
            [tr_home_row()]);
    } else {
        $tg->sendMessage($adminId, "\xE2\x9A\xA0\xEF\xB8\x8F Could not publish post #{$postId}: " . tr_h($res['error'] ?? ''));
    }
}

// ---------------------------------------------------------------------------
// Section 7 - Find a Transporter
// ---------------------------------------------------------------------------

function trFindStart($userId) {
    global $tg, $db;
    $db->setState($userId, 'tr_f_from', []);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x94\x8D <b>Find a Transporter</b>\n\n" .
        "Where are you sending <b>from</b>? (For example: DMV)\n\n" .
        "Type <b>any</b> to see everyone.",
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

function trFindResults($userId, $from, $to) {
    global $tg, $db;

    $db->setState($userId, 'idle', []);
    $tdb = tr_db();
    $hits = $tdb ? hl_tr_search($tdb, $from, $to, 10) : [];

    if (!$hits) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x94\x8D No Verified Transporters match <b>" . tr_h($from) . " \xE2\x86\x92 " . tr_h($to) . "</b> yet.\n\n" .
            "We're adding transporters all the time - try a broader search, or check the full directory on the website.",
            [
                [['text' => "\xF0\x9F\x94\x8D Search again", 'callback_data' => 'tr_find']],
                tr_home_row(),
            ]);
        return;
    }

    $txt = "\xF0\x9F\x94\x8D <b>" . count($hits) . " Verified Transporter" . (count($hits) === 1 ? '' : 's') . " found</b>\n\n";
    $buttons = [];
    foreach ($hits as $t) {
        $uname = hl_tr_normalize_username($t['username']);
        $txt .= "\xE2\x9C\x85 <b>@" . tr_h($uname) . "</b>\n" .
                "   " . tr_h($t['origin']) . " \xE2\x86\x92 " . tr_h($t['destination']) . "\n" .
                "   " . hl_tr_stars(hl_tr_avg($t)) . " " . hl_tr_rating_label($t) . "\n\n";
        $url = tr_profile_url($uname);
        if ($url !== '') {
            $buttons[] = [['text' => "\xF0\x9F\x91\xA4 View @{$uname}", 'url' => $url]];
        }
    }
    $txt .= hl_tr_disclaimer();

    $buttons[] = [['text' => "\xF0\x9F\x94\x8D Search again", 'callback_data' => 'tr_find']];
    $buttons[] = tr_home_row();
    $tg->sendInlineButtons($userId, $txt, $buttons);
}

// ---------------------------------------------------------------------------
// Sections 13-14 - Rate a Transporter
// ---------------------------------------------------------------------------

function trRateStart($userId) {
    global $tg, $db;
    $db->setState($userId, 'tr_r_username', []);
    $tg->sendInlineButtons($userId,
        "\xE2\xAD\x90 <b>Rate a Transporter</b>\n\n" .
        "Send the transporter's public HabeshaList username - for example <b>@AbebeTravel</b>.",
        [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
}

function trRateFound($userId, $text) {
    global $tg, $db;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_username($tdb, $text) : null;

    if (!$tr) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\xA4\x94 I couldn't find <b>@" . tr_h(hl_tr_normalize_username($text)) . "</b>.\n\n" .
            "Please check the spelling and send it again - it's the HabeshaList username shown on their advertisement.",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
        return;
    }

    // Show them WHO they're about to rate, so a typo can't hit the wrong person.
    $uname = hl_tr_normalize_username($tr['username']);
    $db->setState($userId, 'tr_r_stars', ['transporter_id' => (int) $tr['id'], 'username' => $uname]);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>@" . tr_h($uname) . "</b>\n" .
        tr_h($tr['full_name'] ?? '') . "\n" .
        tr_h($tr['origin']) . " \xE2\x86\x92 " . tr_h($tr['destination']) . "\n" .
        hl_tr_stars(hl_tr_avg($tr)) . " " . hl_tr_rating_label($tr) . "\n\n" .
        "How would you rate your experience?",
        [
            [
                ['text' => "1 \xE2\xAD\x90", 'callback_data' => 'trstar_1'],
                ['text' => "2 \xE2\xAD\x90", 'callback_data' => 'trstar_2'],
                ['text' => "3 \xE2\xAD\x90", 'callback_data' => 'trstar_3'],
            ],
            [
                ['text' => "4 \xE2\xAD\x90", 'callback_data' => 'trstar_4'],
                ['text' => "5 \xE2\xAD\x90", 'callback_data' => 'trstar_5'],
            ],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']],
        ]);
}

/** Star tapped - save it, then ALWAYS offer the optional comment (spec 13). */
function trRateStars($userId, $stars, $state) {
    global $tg, $db;

    $data = $state['data'] ?? [];
    $trId = (int) ($data['transporter_id'] ?? 0);
    if ($trId <= 0) { trRateStart($userId); return; }

    $tdb = tr_db();
    $res = hl_tr_add_rating($tdb, $trId, (int) $stars, '', (int) $userId);
    if (!$res['ok']) {
        $tg->sendInlineButtons($userId, "\xE2\x9A\xA0\xEF\xB8\x8F " . tr_h($res['error']), [tr_home_row()]);
        return;
    }

    // Remember the row we just wrote so an optional comment attaches to IT.
    $data['rating_id'] = (int) $tdb->lastInsertRowID();
    $data['stars'] = (int) $stars;
    $db->setState($userId, 'tr_r_comment', $data);

    if ($res['flagged']) trNotifyAdminsLowRating($trId);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Thank you!</b> Your " . (int) $stars . "-star rating for <b>@" .
        tr_h($data['username'] ?? '') . "</b> has been saved.\n\n" .
        "Would you like to leave a comment? It's completely optional.",
        [
            [['text' => "\xE2\x9C\x8D\xEF\xB8\x8F Leave a comment", 'callback_data' => 'tr_r_add_comment']],
            [['text' => "\xE2\x9E\xA1\xEF\xB8\x8F No thanks", 'callback_data' => 'tr_r_skip']],
        ]);
}

function trRateAskComment($userId, $state) {
    global $tg, $db;
    $db->setState($userId, 'tr_r_comment_text', $state['data'] ?? []);
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x8D\xEF\xB8\x8F Please type your comment:",
        [[['text' => "\xE2\x9E\xA1\xEF\xB8\x8F Skip", 'callback_data' => 'tr_r_skip']]]);
}

function trRateSaveComment($userId, $text, $state) {
    global $tg, $db;

    $data = $state['data'] ?? [];
    $ratingId = (int) ($data['rating_id'] ?? 0);
    $tdb = tr_db();

    if ($ratingId > 0 && $tdb && trim($text) !== '') {
        $stmt = $tdb->prepare('UPDATE transporter_ratings SET comment = :c WHERE id = :id');
        $stmt->bindValue(':c', trim($text), SQLITE3_TEXT);
        $stmt->bindValue(':id', $ratingId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    trRateDone($userId, $data);
}

function trRateDone($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'idle', []);

    $uname = $data['username'] ?? '';
    $url = tr_profile_url($uname);
    $buttons = [];
    if ($url !== '') $buttons[] = [['text' => "\xF0\x9F\x91\xA4 View @{$uname}", 'url' => $url]];
    $buttons[] = tr_home_row();
    $buttons[] = [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']];

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x99\x8F <b>Thank you for your feedback!</b>\n\n" .
        "Your rating helps the whole community choose with confidence. " .
        "It's now reflected on <b>@" . tr_h($uname) . "</b>'s profile on HabeshaList.com.",
        $buttons);
}

/** The 3-distinct-low-rating rule fired - tell the admins, suspend NOTHING. */
function trNotifyAdminsLowRating($transporterId) {
    global $tg, $config;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, $transporterId) : null;
    if (!$tr) return;

    $recent = hl_tr_ratings($tdb, $transporterId, 8);
    $lines = '';
    foreach ($recent as $r) {
        $lines .= "  " . str_repeat("\xE2\xAD\x90", max(1, (int) $r['rating'])) .
                  (trim((string) $r['comment']) !== '' ? " - " . tr_h(mb_substr($r['comment'], 0, 90)) : '') . "\n";
    }

    $msg = "\xE2\x9A\xA0\xEF\xB8\x8F <b>Transporter flagged for review</b>\n\n" .
        "<b>@" . tr_h($tr['username'] ?? '') . "</b> (" . tr_h($tr['full_name'] ?? '') . ", #{$transporterId}) " .
        "has now received low ratings from <b>3 different customers</b>.\n\n" .
        "Overall: " . hl_tr_stars(hl_tr_avg($tr)) . " " . hl_tr_rating_label($tr) . "\n\n" .
        "Recent ratings:\n" . $lines . "\n" .
        "They have <b>not</b> been suspended - please review and choose:";

    $buttons = [
        [['text' => "\xE2\x9C\x85 Keep Active", 'callback_data' => "trkeep_{$transporterId}"]],
        [['text' => "\xE2\x9A\xA0\xEF\xB8\x8F Send Warning", 'callback_data' => "trwarn_{$transporterId}"]],
        [['text' => "\xE2\x9B\x94 Suspend",     'callback_data' => "trsusp_{$transporterId}"]],
    ];

    foreach (($config['admin_ids'] ?? []) as $adminId) {
        $tg->sendInlineButtons((int) $adminId, $msg, $buttons);
    }
}

/** Keep Active | Send Warning | Suspend (spec section 14). */
function trReviewAction($adminId, $transporterId, $action) {
    global $tg;
    if (!isAdmin($adminId)) return;

    $tdb = tr_db();
    $tr  = $tdb ? hl_tr_by_id($tdb, $transporterId) : null;
    if (!$tr) { $tg->sendMessage($adminId, "That transporter could not be found."); return; }

    $tid = (int) $tr['telegram_id'];
    $uname = tr_h($tr['username'] ?? $tr['full_name'] ?? '');

    if ($action === 'keep') {
        hl_tr_clear_review($tdb, $transporterId, false);
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Kept active: <b>@{$uname}</b>. Review flag cleared.");
        return;
    }

    if ($action === 'warn') {
        hl_tr_clear_review($tdb, $transporterId, true);
        $tg->sendMessage($adminId, "\xE2\x9A\xA0\xEF\xB8\x8F Warning sent to <b>@{$uname}</b>.");
        if ($tid) $tg->sendInlineButtons($tid,
            "\xE2\x9A\xA0\xEF\xB8\x8F <b>A note from the HabeshaList team</b>\n\n" .
            "We've received some low ratings from customers about your recent trips. " .
            "Your profile is still active and you can keep posting.\n\n" .
            "Please make sure you confirm the route, date, price and handover clearly with every customer, " .
            "and keep in touch with them until the delivery is complete. Repeated complaints may lead to suspension.",
            [tr_home_row()]);
        return;
    }

    // Suspend: hide the profile and block new posts. History is NEVER deleted.
    hl_tr_set_status($tdb, $transporterId, 'suspended');
    hl_tr_clear_review($tdb, $transporterId, false);
    $tg->sendMessage($adminId, "\xE2\x9B\x94 Suspended: <b>@{$uname}</b>. New posts blocked and the website profile is hidden. Past posts and ratings are kept.");
    if ($tid) $tg->sendInlineButtons($tid,
        "\xE2\x9B\x94 <b>Your transporter account has been suspended.</b>\n\n" .
        "New transportation posts are paused and your public profile is hidden for now. " .
        "Please contact support if you'd like to discuss this.",
        [[['text' => "\xF0\x9F\x93\x9E Contact Us", 'callback_data' => 'contact']]]);
}

// ---------------------------------------------------------------------------
// Text input router for every tr_ state
// ---------------------------------------------------------------------------

function trHandleStateInput($userId, $msg, $state) {
    global $tg, $db;

    $st   = $state['state'];
    $data = $state['data'] ?? [];
    $text = trim((string) ($msg['text'] ?? ''));

    // A photo lands here only if it carried no caption path; the photo handler
    // below deals with images, so an empty text in a photo step is ignored.
    if ($text === '' && !in_array($st, ['tr_ap_photo', 'tr_p_proof'], true)) {
        $tg->sendMessage($userId, "Please send that as a text message.");
        return;
    }

    switch ($st) {

        // ---- Application ----
        case 'tr_ap_name':
            if (mb_strlen($text) < 2) { $tg->sendMessage($userId, "Please enter your full name (at least 2 characters):"); return; }
            $data['full_name'] = $text;
            $db->setState($userId, 'tr_ap_phone', $data);
            $tg->sendInlineButtons($userId, "\xF0\x9F\x93\xB1 <b>2 of 6</b> - What is your <b>phone number</b>?",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_ap_phone':
            if (preg_replace('/\D/', '', $text) === '' || mb_strlen($text) < 6) {
                $tg->sendMessage($userId, "Please enter a valid phone number:");
                return;
            }
            $data['phone_number'] = $text;
            $db->setState($userId, 'tr_ap_contact', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\x9E <b>3 of 6</b> - How should customers <b>contact</b> you?\n\n" .
                "(For example your Telegram @handle, WhatsApp number or phone number - this is shown publicly.)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_ap_contact':
            $data['contact_method'] = $text;
            trAskPhoto($userId, $data);
            return;

        case 'tr_ap_photo':
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xB7 Please send a <b>photo</b> (as an image, not a file) for your public profile.",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_ap_origin':
            $data['origin'] = $text;
            $db->setState($userId, 'tr_ap_dest', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x8E\xAF <b>6 of 6</b> - What is your usual <b>destination</b>?\n\n(For example: Addis Ababa)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_ap_dest':
            $data['destination'] = $text;
            trShowAgreement($userId, $data);
            return;

        // ---- Username ----
        case 'tr_uname_new':
            trHandleUsername($userId, $text, false);
            return;

        case 'tr_uname_change':
            trHandleUsername($userId, $text, true);
            return;

        // ---- Post Available Space ----
        case 'tr_p_origin':
            $data['origin'] = $text;
            $db->setState($userId, 'tr_p_dest', $data);
            $hint = ($data['def_destination'] ?? '') !== ''
                ? "\n\n(Your usual destination is <b>" . tr_h($data['def_destination']) . "</b>.)" : '';
            $tg->sendInlineButtons($userId, "\xF0\x9F\x8E\xAF <b>2 of 6</b> - Where are you travelling <b>to</b>?" . $hint,
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_p_dest':
            $data['destination'] = $text;
            $db->setState($userId, 'tr_p_date', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\x85 <b>3 of 6</b> - What is your <b>travel date</b>?\n\n(For example: 15 October 2026)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_p_date':
            $data['travel_date'] = $text;
            $db->setState($userId, 'tr_p_space', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xA6 <b>4 of 6</b> - How much <b>space</b> do you have available?\n\n(For example: 2 suitcases, up to 20kg)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_p_space':
            $data['space'] = $text;
            $db->setState($userId, 'tr_p_price', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB5 <b>5 of 6</b> - What is <b>your price</b>?\n\n" .
                "(This is your own charge to the customer - for example \$8 per kg. HabeshaList is not involved in this payment.)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_p_price':
            $data['price'] = $text;
            $db->setState($userId, 'tr_p_contact', $data);
            $hint = ($data['def_contact'] ?? '') !== ''
                ? "\n\n(On your profile you have <b>" . tr_h($data['def_contact']) . "</b>.)" : '';
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\x9E <b>6 of 6</b> - How should customers <b>contact</b> you for this trip?" . $hint,
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_p_contact':
            $data['contact_method'] = $text;
            trShowPreview($userId, $data);
            return;

        case 'tr_p_editing':
            $field = $data['_editing'] ?? '';
            if ($field !== '') { $data[$field] = $text; unset($data['_editing']); }
            trShowPreview($userId, $data);
            return;

        // ---- Find ----
        case 'tr_f_from':
            $data['from'] = $text;
            $db->setState($userId, 'tr_f_to', $data);
            $tg->sendInlineButtons($userId, "\xF0\x9F\x8E\xAF Where are you sending <b>to</b>? (Type <b>any</b> for all destinations.)",
                [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
            return;

        case 'tr_f_to':
            trFindResults($userId, $data['from'] ?? '', $text);
            return;

        // ---- Rate ----
        case 'tr_r_username':
            trRateFound($userId, $text);
            return;

        case 'tr_r_comment':
        case 'tr_r_comment_text':
            // Typing straight after the stars counts as the comment.
            trRateSaveComment($userId, $text, $state);
            return;

        default:
            $db->setState($userId, 'idle', []);
            trMenu($userId);
            return;
    }
}

/** Photos: the application profile picture and the manual payment screenshot. */
function trHandlePhoto($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    $st = $state['state'] ?? '';
    $data = $state['data'] ?? [];

    $photos = $msg['photo'] ?? [];
    if (!$photos) return false;
    $fileId = end($photos)['file_id'] ?? '';        // largest size
    if ($fileId === '') return false;

    if ($st === 'tr_ap_photo') {
        $data['photo_file_id'] = $fileId;
        $db->setState($userId, 'tr_ap_origin', $data);
        $tg->sendInlineButtons($userId,
            "\xE2\x9C\x85 Photo received.\n\n" .
            "\xF0\x9F\x93\x8D <b>5 of 6</b> - What is your usual <b>origin</b>?\n\n(For example: DMV)",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'tr_cancel']]]);
        return true;
    }

    if ($st === 'tr_p_proof') {
        trManualProceed($userId, $state, $fileId);
        return true;
    }

    return false;
}

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

function trCancel($userId) {
    global $tg, $db;
    $db->setState($userId, 'idle', []);
    $tg->sendInlineButtons($userId, "No problem - cancelled.", [tr_home_row(),
        [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
}
