<?php

$config = require __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/promotion.php';
// Stripe helper is optional-at-load: if this file hasn't been uploaded yet the
// bot must still run (card checkout simply falls back to manual payment) rather
// than crash the whole poller on a missing include.
if (is_file(__DIR__ . '/includes/stripe.php')) require_once __DIR__ . '/includes/stripe.php';
require_once __DIR__ . '/includes/scheduler.php'; // for HL_Scheduler::renderPostText() preview
require_once __DIR__ . '/includes/referral.php';  // Invite & Earn engine + screens

// Allow tests to pre-inject a mock $db / $tg; otherwise create the real ones.
if (!isset($db)) $db = new Database(__DIR__ . '/data/bot.sqlite');
if (!isset($tg)) $tg = new Telegram($config['bot_token']);
// Invite & Earn engine (own SQLite handle to the same db, like HL_Scheduler).
if (!isset($referral)) { try { $referral = new HL_Referral($db->path()); } catch (\Throwable $e) { $referral = null; } }

// Only dispatch incoming updates when served over a real HTTP request. When
// this file is required from poll.php (cron) or from CLI tests there is no HTTP
// method set, so we must NOT dispatch here - we only set up handlers. Detecting
// "no REQUEST_METHOD" is more reliable than checking the SAPI name, because some
// cron setups on this host run the CGI PHP binary (SAPI 'cgi-fcgi') with no web
// request behind it.
$isHttpRequest = !empty($_SERVER['REQUEST_METHOD']) && php_sapi_name() !== 'cli';
if ($isHttpRequest) {
    // Verify the request really comes from Telegram. Telegram echoes our
    // configured secret in this header on every webhook call; anything else
    // (a random bot/scanner hitting the URL) is rejected before we do any work.
    // This is what keeps the endpoint safe even though the server firewall is
    // relaxed for it.
    $expectedSecret = $config['webhook_secret'] ?? '';
    $gotSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $gotSecret)) {
        http_response_code(403);
        exit;
    }

    $input = file_get_contents('php://input');

    // Acknowledge Telegram INSTANTLY (HTTP 200) and close the connection before
    // doing any work. Telegram only needs a fast 200 from the webhook; the reply
    // to the user is delivered via separate outbound API calls, not this response
    // body. Finishing the request up front keeps every hit to a few milliseconds,
    // so many users tapping at the same moment can't pile up open connections or
    // trip the host's concurrency / security limits (the cause of the earlier
    // "409 Conflict" backlog that made the bot go silent).
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: text/plain');
    header('Content-Length: 0');
    header('Connection: close');
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();          // PHP-FPM
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();        // LiteSpeed (Bluehost)
    } else {
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }

    // Now do the real work, with the client already disconnected.
    $update = json_decode($input, true);
    if ($update) {
        if (isset($update['callback_query'])) {
            handleCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            handleMessage($update['message']);
        } elseif (isset($update['chat_member'])) {
            handleChatMember($update['chat_member']);
        }
    }
    exit;
}

// ============================================================
// CALLBACK QUERY HANDLER
// ============================================================

function handleCallbackQuery($query) {
    global $tg, $db, $config;

    $tg->answerCallbackQuery($query['id']);

    $data = $query['data'];
    $chatId = $query['message']['chat']['id'];
    $msgId = $query['message']['message_id'] ?? null;
    $userId = $query['from']['id'];
    $state = $db->getState($userId);

    // Calendar cells must NOT tear down the calendar keyboard. Handle them BEFORE
    // the "deactivate buttons" step below so:
    //   - a filler / disabled (past) date is a silent no-op (calendar stays put)
    //   - a month arrow edits the SAME calendar message in place (no new "Choose
    //     a date" message each time you navigate).
    if ($data === 'pnop') { return; }
    if (strpos($data, 'pcaln_') === 0) { promoCalendarNav($userId, substr($data, 6), $state, $msgId); return; }

    // Deactivate buttons on the clicked message
    if ($msgId) {
        $tg->editMessageReplyMarkup($chatId, $msgId);
    }

    // ---- Cancel flow ----

    if ($data === 'cancel') {
        $db->setState($userId, 'confirming_cancel', [
            'prev_state' => $state['state'],
            'prev_data' => $state['data'],
        ]);
        $tg->sendInlineButtons($userId,
            "Are you sure you want to cancel?",
            [[
                ['text' => "\xE2\x9C\x85 Yes", 'callback_data' => 'cancel_yes'],
                ['text' => "\xE2\x9D\x8C No", 'callback_data' => 'cancel_no'],
            ]]
        );
        return;
    }

    if ($data === 'cancel_yes') {
        $user = $db->getUser($userId);
        $db->setState($userId, 'idle', []);
        showMainMenu($userId, $user['name'] ?? 'there');
        return;
    }

    if ($data === 'cancel_no') {
        $prevState = $state['data']['prev_state'] ?? 'idle';
        $prevData = $state['data']['prev_data'] ?? [];
        $db->setState($userId, $prevState, $prevData);
        restoreStateUI($userId, $prevState, $prevData);
        return;
    }

    // ---- Navigation ----

    if ($data === 'main_menu') {
        $user = $db->getUser($userId);
        $db->setState($userId, 'idle', []);
        showMainMenu($userId, $user['name'] ?? 'there');
        return;
    }

    // ---- Main menu actions ----

    if ($data === 'post_ad') { handleAction($userId, 'post_ad'); return; }
    if ($data === 'promote') { handleAction($userId, 'promote'); return; }
    if ($data === 'botw') { handleAction($userId, 'botw'); return; }
    if ($data === 'contact') { handleAction($userId, 'contact'); return; }

    // ---- Invite & Earn ----
    if ($data === 'invite')       { handleAction($userId, 'invite'); return; }
    if ($data === 'inv_link')     { inviteShowLink($userId); return; }
    if ($data === 'inv_verify_join') { inviteVerifyGroupJoin($userId); return; }
    if ($data === 'inv_progress') { inviteShowProgress($userId); return; }
    if ($data === 'inv_rewards')  { inviteShowRewards($userId); return; }
    if ($data === 'inv_myrewards'){ inviteViewMyRewards($userId); return; }
    if (strpos($data, 'inv_redeem_') === 0) { inviteRedeemReward($userId, (int) substr($data, 11)); return; }
    if (strpos($data, 'inv_claim_') === 0) { inviteClaimReward($userId, (int) substr($data, 10)); return; }
    if (strpos($data, 'inv_start_') === 0) { inviteRewardStartMode($userId, (int) substr($data, 10), 'start'); return; }
    if (strpos($data, 'inv_save_')  === 0) { inviteRewardStartMode($userId, (int) substr($data, 9), 'save'); return; }
    if (strpos($data, 'inv_date_')  === 0) { inviteRewardChooseDate($userId, (int) substr($data, 9)); return; }

    // ---- Promote My Business ----

    if ($data === 'promo_start') { promoStart($userId); return; }
    if (strpos($data, 'promopkg_') === 0) { promoSelectPackage($userId, substr($data, 9)); return; }
    if ($data === 'promo_continue') { promoShowPayment($userId, $state['data']); return; }
    if ($data === 'promo_payment_back') { promoShowPayment($userId, $state['data']); return; }
    if (strpos($data, 'promopay_') === 0) { promoHandlePayMethod($userId, substr($data, 9), $state); return; }
    if ($data === 'promo_paid_manual') { promoPaymentProceed($userId, $state, null); return; }
    if ($data === 'promo_check_card') { promoCheckCard($userId, $state); return; }
    if (strpos($data, 'promocat_') === 0) { promoSelectCategory($userId, (int)substr($data, 9), $state); return; }
    if ($data === 'promo_images_done' || $data === 'promo_images_skip') { promoImagesDone($userId, $state); return; }
    if (strpos($data, 'promoskip_') === 0) { promoSkipField($userId, substr($data, 10), $state); return; }
    if (strpos($data, 'promoback_') === 0) { promoBackField($userId, substr($data, 10), $state); return; }
    if ($data === 'promo_submit') { promoSubmit($userId, $state); return; }
    if ($data === 'promo_edit') { promoEditMenu($userId, $state); return; }
    if (strpos($data, 'promoedit_') === 0) { promoEditField($userId, substr($data, 10), $state); return; }
    if ($data === 'promo_back_review') { promoBackToReview($userId, $state); return; }
    if ($data === 'promo_cancel_yes') { promoDoCancel($userId); return; }
    if ($data === 'promo_cancel_no') { promoRestore($userId, $state); return; }
    if ($data === 'promo_cancel') { promoConfirmCancel($userId, $state); return; }
    if (strpos($data, 'promo_approve_') === 0) { promoModerate($userId, (int)substr($data, 14), 'approve'); return; }
    if (strpos($data, 'promo_reject_') === 0) { promoModerate($userId, (int)substr($data, 13), 'reject'); return; }
    if ($data === 'promo_admin_menu') { promoAdminMenu($userId); return; }
    if (strpos($data, 'promoset_') === 0) { promoAdminEdit($userId, substr($data, 9)); return; }

    // ---- Preview + scheduling picker ----

    if ($data === 'promo_preview') { promoShowPreview($userId, $state['data']); return; }
    if ($data === 'promo_sched_start') { promoSchedStart($userId, $state); return; }
    // (pnop and pcaln_ are handled earlier, before the keyboard is torn down.)
    if (strpos($data, 'pschd_') === 0) { promoSchedPickDate($userId, substr($data, 6), $state); return; }
    if (strpos($data, 'pschrt_') === 0) {
        $rest = substr($data, 7);                 // "<slot>_<hhmm|custom>"
        $us = strpos($rest, '_');
        if ($us !== false) {
            promoSchedPickTimeRecurring($userId, substr($rest, 0, $us), substr($rest, $us + 1), $state);
        }
        return;
    }
    if (strpos($data, 'pscht_') === 0) { promoSchedPickTimeSingle($userId, substr($data, 6), $state); return; }
    if (strpos($data, 'pschw_') === 0) {
        $rest = substr($data, 6);                 // "<slot>_<dow>"
        $us = strpos($rest, '_');
        if ($us !== false) {
            promoSchedPickWeekday($userId, (int) substr($rest, 0, $us), substr($rest, $us + 1), $state);
        }
        return;
    }
    if ($data === 'promo_resched_save') { promoReschedSave($userId, $state); return; }

    // ---- User dashboard ----

    if ($data === 'promo_dashboard') { promoShowDashboard($userId); return; }
    if (strpos($data, 'dash_sched_') === 0) { promoDashViewSchedule($userId, (int) substr($data, 11)); return; }
    if ($data === 'dash_ads') { promoDashMyAds($userId); return; }
    if ($data === 'dash_pay') { promoDashPayments($userId); return; }
    if (strpos($data, 'dash_edit_') === 0) { promoDashEditAd($userId, (int) substr($data, 10)); return; }
    if (strpos($data, 'dash_slot_') === 0) { promoDashSelectSlot($userId, (int) substr($data, 10)); return; }
    if (strpos($data, 'dash_cancelyes_') === 0) { promoDashDoCancel($userId, (int) substr($data, 15)); return; }
    if (strpos($data, 'dash_cancel_') === 0) { promoDashCancelConfirm($userId, (int) substr($data, 12)); return; }

    // ---- Category / Subcategory ----

    if ($data === 'cat_back') { showCategoryPicker($userId); return; }

    if (strpos($data, 'cat_') === 0) {
        handleCategorySelect($userId, substr($data, 4), $state);
        return;
    }

    if (strpos($data, 'subcat_') === 0) {
        handleSubcategorySelect($userId, substr($data, 7), $state);
        return;
    }

    // ---- Country selection ----

    if (strpos($data, 'country_') === 0) {
        handleCountrySelect($userId, substr($data, 8), $state);
        return;
    }

    if ($data === 'skip_country') {
        $stateData = $state['data'];
        $isEdit = !empty($stateData['editing_location']);
        doSkipLocation($userId, $stateData, $isEdit);
        return;
    }

    if ($data === 'skip_state') {
        $stateData = $state['data'];
        $stateData['loc_state'] = '';
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
        showCityPrompt($userId, $stateData, $isEdit);
        return;
    }

    if ($data === 'skip_city') {
        $stateData = $state['data'];
        $stateData['loc_city'] = '';
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_address_text' : 'awaiting_address_text', $stateData);
        showAddressPrompt($userId, $stateData, $isEdit);
        return;
    }

    if ($data === 'skip_address') {
        $stateData = $state['data'];
        $stateData['loc_address'] = '';
        buildLocationName($stateData);
        $isEdit = !empty($stateData['editing_location']);
        if ($isEdit) {
            unset($stateData['editing_location']);
            $db->setState($userId, 'awaiting_review', $stateData);
            showAdReview($userId, $stateData);
        } else {
            $stateData['photos'] = [];
            $db->setState($userId, 'awaiting_photos', $stateData);
            showPhotoPrompt($userId);
        }
        return;
    }

    // ---- Back navigation ----

    if ($data === 'back_to_cats') { showCategoryPicker($userId); return; }

    if ($data === 'back_to_subcats') {
        $catKey = $state['data']['category'] ?? '';
        if ($catKey) {
            handleCategorySelect($userId, $catKey, $state);
        } else {
            showCategoryPicker($userId);
        }
        return;
    }

    if ($data === 'back_to_title') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_title', $stateData);
        $catName = $stateData['category_name'] ?? '';
        $subcatName = $stateData['subcategory_name'] ?? '';
        $header = "\xE2\x9C\x85 Category: <b>{$catName}</b>\n";
        if ($subcatName) $header .= "\xF0\x9F\x93\x82 Subcategory: <b>{$subcatName}</b>\n";
        $tg->sendInlineButtons($userId,
            $header . "\n\xF0\x9F\x93\x9D Enter the <b>title</b> for your ad:",
            [[
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_subcats'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
            ]]
        );
        return;
    }

    if ($data === 'back_to_desc') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_description', $stateData);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x9D Enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)",
            [[
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_title'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
            ]]
        );
        return;
    }

    if ($data === 'back_to_price') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_price', $stateData);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x92\xB0 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
            [[
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_desc'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
            ]]
        );
        return;
    }

    if ($data === 'back_to_country') {
        $stateData = $state['data'];
        $isEdit = !empty($stateData['editing_location']);
        $stateName = $isEdit ? 'editing_country' : 'awaiting_country';
        $db->setState($userId, $stateName, $stateData);
        showCountryPicker($userId, $isEdit);
        return;
    }

    if ($data === 'back_to_state') {
        $stateData = $state['data'];
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_state_text' : 'awaiting_state_text', $stateData);
        showStatePrompt($userId, $stateData, $isEdit);
        return;
    }

    if ($data === 'back_to_city') {
        $stateData = $state['data'];
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
        showCityPrompt($userId, $stateData, $isEdit);
        return;
    }

    if ($data === 'back_to_photos') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        $db->setState($userId, 'awaiting_photos', $stateData);
        showPhotoPrompt($userId);
        return;
    }

    // ---- Photos ----

    if ($data === 'photos_done') { handlePhotosDone($userId, $state); return; }

    if ($data === 'photos_skip') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Review / Publish ----

    if ($data === 'confirm_publish') { handlePublish($userId, $state); return; }
    if ($data === 'edit_ad') { showEditOptions($userId, $state); return; }

    // ---- Edit individual fields ----

    if ($data === 'edit_title') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_title', $stateData);
        $tg->sendInlineButtons($userId,
            "Current title: <b>{$stateData['title']}</b>\n\n\xF0\x9F\x93\x9D Enter the new <b>title</b>:",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_description') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_description', $stateData);
        $tg->sendInlineButtons($userId,
            "Current description:\n{$stateData['description']}\n\n\xF0\x9F\x93\x9D Enter the new <b>description</b>:",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_price') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_price', $stateData);
        $tg->sendInlineButtons($userId,
            "Current price: <b>{$stateData['price']}</b>\n\n\xF0\x9F\x92\xB0 Enter the new <b>price</b>:",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_location') {
        $stateData = $state['data'];
        $stateData['editing_location'] = true;
        // Clear old location so the running summary rebuilds cleanly during the edit
        unset($stateData['loc_country'], $stateData['loc_country_code'],
              $stateData['loc_state'], $stateData['loc_city'], $stateData['loc_address']);
        $db->setState($userId, 'editing_country', $stateData);
        showCountryPicker($userId, true);
        return;
    }

    if ($data === 'edit_photos') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        unset($stateData['_last_media_group']);
        $db->setState($userId, 'editing_photos', $stateData);
        showPhotoPrompt($userId, true);
        return;
    }

    if ($data === 'back_to_review') {
        $stateData = $state['data'];
        unset($stateData['editing_location']);
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    if ($data === 'edit_photos_done') {
        $stateData = $state['data'];
        unset($stateData['_last_media_group']);
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Post-publish actions ----

    if ($data === 'post_another') { showCategoryPicker($userId); return; }

    // ---- Admin moderation ----

    if (strpos($data, 'approve_') === 0) {
        moderateAd($userId, (int)substr($data, 8), 'approve');
        return;
    }

    if (strpos($data, 'reject_') === 0) {
        moderateAd($userId, (int)substr($data, 7), 'reject');
        return;
    }
}

// ============================================================
// MESSAGE HANDLER
// ============================================================

function handleMessage($msg) {
    global $tg, $db, $config, $referral;

    $chatId = $msg['chat']['id'];
    $userId = $msg['from']['id'];
    $text = trim($msg['text'] ?? '');
    $isPrivate = ($msg['chat']['type'] === 'private');

    // /start with deep link in private chat
    if ($isPrivate && strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        $param = $parts[1] ?? '';

        $user = $db->getUser($userId);

        // Is this deep-link a referral code (not one of our action keywords)?
        $isRefCode = ($param !== '' && $referral && $referral->looksLikeCode($param) && $referral->codeOwner($param));

        if (!$user) {
            // New user arriving via a referral link: remember who invited them so
            // we can attribute once they finish registering, and land them on the
            // Invite & Earn screen afterward.
            startRegistration($userId, $isRefCode ? 'invite' : $param, $isRefCode ? $param : '');
            return;
        }

        if ($isRefCode) {
            // An existing member can STILL be referred - but only to grow the
            // community. If they've never joined the Telegram group, their friend's
            // invite counts once they join. If they're already in the group (or the
            // group-join rule is off), the link simply doesn't apply to their account.
            if ($referral && $referral->requiresGroupJoin()) {
                referralAttributeExistingUser($userId, $param, $user);
            } else {
                $tg->sendMessage($userId, "\xF0\x9F\x91\x8B You're already a HabeshaList member, so this invite link doesn't apply to your account - but you can invite your own friends and earn rewards!");
                showMainMenu($userId, $user['name']);
            }
        } elseif ($param) {
            handleAction($userId, $param);
        } else {
            showMainMenu($userId, $user['name']);
        }
        return;
    }

    // /resetme in private chat (admin only) - deletes your own account so you can
    // test the first-time registration flow again from scratch.
    if ($isPrivate && $text === '/resetme') {
        if (isAdmin($userId)) {
            $db->deleteUser($userId);
            $db->setState($userId, 'idle', []);
            $tg->sendMessage($userId,
                "\xF0\x9F\x94\x84 Your account has been reset. You're now a first-time user again.\n\n" .
                "Tap <b>Post to Website</b> (or send /start) and you'll be asked to register."
            );
        }
        return;
    }

    // /promoadmin in private chat (admin only) - view & edit package prices and payment handles
    if ($isPrivate && $text === '/promoadmin') {
        if (isAdmin($userId)) {
            $db->setState($userId, 'idle', []);
            promoAdminMenu($userId);
        }
        return;
    }

    // /dashboard in private chat
    if ($isPrivate && $text === '/dashboard') {
        $user = $db->getUser($userId);
        if ($user) {
            promoShowDashboard($userId);
        } else {
            startRegistration($userId, 'dashboard');
        }
        return;
    }

    // /menu in private chat
    if ($isPrivate && $text === '/menu') {
        $user = $db->getUser($userId);
        if ($user) {
            showMainMenu($userId, $user['name']);
        } else {
            startRegistration($userId, '');
        }
        return;
    }

    // /setup in group (admin only)
    if (!$isPrivate && ($text === '/setup' || strpos($text, '/setup@') === 0)) {
        if (isAdmin($userId)) {
            sendGroupButtons($chatId);
        }
        return;
    }

    // Photo messages in private chat
    if ($isPrivate && isset($msg['photo'])) {
        $ps = $db->getState($userId);
        if (strpos($ps['state'], 'promo_') === 0) {
            if (promoHandlePhoto($userId, $msg)) return;
        }
        if (handlePhotoMessage($userId, $msg)) return;
    }

    // Video messages in private chat (promotion media step accepts videos too)
    $isVideoMsg = isset($msg['video']) ||
        (isset($msg['document']['mime_type']) && strpos($msg['document']['mime_type'], 'video/') === 0);
    if ($isPrivate && $isVideoMsg) {
        $vs = $db->getState($userId);
        if (strpos($vs['state'], 'promo_') === 0) {
            if (promoHandleVideo($userId, $msg)) return;
        }
    }

    // State-based conversation in private chat
    if ($isPrivate) {
        handleStateInput($userId, $msg);
    }
}

// ============================================================
// STATE INPUT HANDLER
// ============================================================

function handleStateInput($userId, $msg) {
    global $tg, $db, $config, $referral;

    $state = $db->getState($userId);
    $text = trim($msg['text'] ?? '');
    $stateData = $state['data'];

    // Route all Promote-My-Business states to the promotion module
    if (strpos($state['state'], 'promo_') === 0) {
        promoHandleStateInput($userId, $msg, $state);
        return;
    }

    // Invite & Earn: user typing a custom reward start date.
    if ($state['state'] === 'inv_reward_date') {
        inviteRewardSaveDate($userId, $text, $state);
        return;
    }

    switch ($state['state']) {

        // ---- Registration ----

        case 'reg_name':
            if (strlen($text) < 2) {
                $tg->sendMessage($userId, "Please enter a valid name (at least 2 characters):");
                return;
            }
            $stateData['name'] = $text;
            $db->setState($userId, 'reg_phone', $stateData);
            $tg->sendMessage($userId, "\xF0\x9F\x93\xB1 Great, {$text}! Now please enter your <b>phone number</b>:");
            break;

        case 'reg_phone':
            $phone = preg_replace('/[^0-9+\-\s()]/', '', $text);
            if (strlen($phone) < 7) {
                $tg->sendMessage($userId, "Please enter a valid phone number:");
                return;
            }
            $stateData['phone'] = $phone;
            $db->setState($userId, 'reg_email', $stateData);
            $tg->sendMessage($userId, "\xF0\x9F\x93\xA7 Almost done! Please enter your <b>email address</b>:");
            break;

        case 'reg_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $tg->sendMessage($userId, "Please enter a valid email address:");
                return;
            }
            $stateData['email'] = $text;
            $result = $db->createUser($userId, $stateData['name'], $stateData['phone'], $stateData['email']);
            $db->setState($userId, 'idle', []);

            if (!$result) {
                $tg->sendMessage($userId, "There was an issue creating your account. Please try again with /start");
                return;
            }

            // Sync the account to the OSClass website so it shows in the admin panel (best-effort)
            $reg = registerOnWebsite($stateData['name'], $stateData['phone'], $stateData['email']);
            if ($reg && !empty($reg['osclass_user_id'])) {
                $db->setOsclassUserId($userId, $reg['osclass_user_id']);
            }

            // Invite & Earn: if this user arrived via a referral link, attribute
            // the referral now that registration is complete, and let the inviter
            // know (plus check whether they just hit a reward milestone).
            $refCode = $stateData['ref_code'] ?? '';
            if ($refCode !== '' && $referral) {
                referralAttributeNewUser($userId, $refCode, $stateData);
            }

            $tg->sendMessage($userId,
                "\xE2\x9C\x85 Account created successfully!\n\n" .
                "Welcome, <b>{$stateData['name']}</b>! You're all set."
            );

            $intended = $stateData['intended_action'] ?? '';
            if ($intended) {
                handleAction($userId, $intended);
            } else {
                showMainMenu($userId, $stateData['name']);
            }
            break;

        // ---- Ad Posting ----

        case 'awaiting_title':
            if (strlen($text) < 3) {
                $tg->sendMessage($userId, "Title must be at least 3 characters. Please enter the <b>title</b>:");
                return;
            }
            $stateData['title'] = $text;
            $db->setState($userId, 'awaiting_description', $stateData);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\x9D Now enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)",
                [[
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_title'],
                    ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                ]]
            );
            break;

        case 'awaiting_description':
            if (strlen($text) < 10) {
                $tg->sendMessage($userId, "Description must be at least 10 characters. Please provide more detail:");
                return;
            }
            $stateData['description'] = $text;
            $db->setState($userId, 'awaiting_price', $stateData);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB0 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
                [[
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_desc'],
                    ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                ]]
            );
            break;

        case 'awaiting_price':
            $stateData['price'] = $text;
            $db->setState($userId, 'awaiting_country', $stateData);
            showCountryPicker($userId, false);
            break;

        // ---- Country (reply-keyboard dropdown selection) ----

        case 'awaiting_country':
        case 'editing_country':
            $isEdit = ($state['state'] === 'editing_country');

            if ($text === "\xE2\x9D\x8C Cancel") {
                $db->setState($userId, 'confirming_cancel', [
                    'prev_state' => $state['state'],
                    'prev_data' => $stateData,
                ]);
                $tg->sendInlineButtons($userId, "Are you sure you want to cancel?", [[
                    ['text' => "\xE2\x9C\x85 Yes", 'callback_data' => 'cancel_yes'],
                    ['text' => "\xE2\x9D\x8C No", 'callback_data' => 'cancel_no'],
                ]]);
                return;
            }

            if (!$isEdit && $text === "\xE2\xAC\x85\xEF\xB8\x8F Back") {
                $db->setState($userId, 'awaiting_price', $stateData);
                showPricePrompt($userId);
                return;
            }

            if ($isEdit && $text === "\xE2\xAC\x85\xEF\xB8\x8F Back to Review") {
                unset($stateData['editing_location']);
                $db->setState($userId, 'awaiting_review', $stateData);
                showAdReview($userId, $stateData);
                return;
            }

            if ($text === "\xE2\x8F\xA9 Skip Location") {
                doSkipLocation($userId, $stateData, $isEdit);
                return;
            }

            if ($text === "\xF0\x9F\x93\x8D Other (type country)") {
                $db->setState($userId, $isEdit ? 'editing_country_other' : 'awaiting_country_other', $stateData);
                $tg->sendInlineButtons($userId,
                    "\xF0\x9F\x93\x8D Type your <b>country</b> name:",
                    [[
                        ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_country'],
                        ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                    ]]
                );
                return;
            }

            $matchedKey = null;
            foreach ($config['countries'] as $k => $c) {
                if ($c['name'] === $text) { $matchedKey = $k; break; }
            }
            if ($matchedKey !== null) {
                $c = $config['countries'][$matchedKey];
                $stateData['loc_country'] = $c['name'];
                $stateData['loc_country_code'] = $c['code'];
                $db->setState($userId, $isEdit ? 'editing_state_text' : 'awaiting_state_text', $stateData);
                showStatePrompt($userId, $stateData, $isEdit);
                return;
            }

            if (strlen($text) >= 2) {
                $stateData['loc_country'] = $text;
                $stateData['loc_country_code'] = '';
                $db->setState($userId, $isEdit ? 'editing_state_text' : 'awaiting_state_text', $stateData);
                showStatePrompt($userId, $stateData, $isEdit);
                return;
            }

            showCountryPicker($userId, $isEdit);
            return;

        // ---- Location text inputs ----

        case 'awaiting_country_other':
        case 'editing_country_other':
            if (strlen($text) < 2) {
                $tg->sendMessage($userId, "Please enter a valid country name:");
                return;
            }
            $stateData['loc_country'] = $text;
            $stateData['loc_country_code'] = '';
            $isEdit = ($state['state'] === 'editing_country_other');
            $db->setState($userId, $isEdit ? 'editing_state_text' : 'awaiting_state_text', $stateData);
            showStatePrompt($userId, $stateData, $isEdit);
            break;

        case 'awaiting_state_text':
        case 'editing_state_text':
            $stateData['loc_state'] = $text;
            $isEdit = ($state['state'] === 'editing_state_text');
            $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
            showCityPrompt($userId, $stateData, $isEdit);
            break;

        case 'awaiting_city_text':
        case 'editing_city_text':
            $stateData['loc_city'] = $text;
            $isEdit = ($state['state'] === 'editing_city_text');
            $db->setState($userId, $isEdit ? 'editing_address_text' : 'awaiting_address_text', $stateData);
            showAddressPrompt($userId, $stateData, $isEdit);
            break;

        case 'awaiting_address_text':
        case 'editing_address_text':
            $stateData['loc_address'] = $text;
            $isEdit = ($state['state'] === 'editing_address_text');
            buildLocationName($stateData);
            if ($isEdit) {
                unset($stateData['editing_location']);
                $db->setState($userId, 'awaiting_review', $stateData);
                showAdReview($userId, $stateData);
            } else {
                $stateData['photos'] = [];
                $db->setState($userId, 'awaiting_photos', $stateData);
                showPhotoPrompt($userId);
            }
            break;

        // ---- Photos (text input when expecting photo) ----

        case 'awaiting_photos':
        case 'editing_photos':
            $tg->sendMessage($userId, "Please send a photo, or tap one of the buttons above.");
            break;

        // ---- Edit fields ----

        case 'editing_title':
            if (strlen($text) < 3) {
                $tg->sendMessage($userId, "Title must be at least 3 characters:");
                return;
            }
            $stateData['title'] = $text;
            $db->setState($userId, 'awaiting_review', $stateData);
            showAdReview($userId, $stateData);
            break;

        case 'editing_description':
            if (strlen($text) < 10) {
                $tg->sendMessage($userId, "Description must be at least 10 characters:");
                return;
            }
            $stateData['description'] = $text;
            $db->setState($userId, 'awaiting_review', $stateData);
            showAdReview($userId, $stateData);
            break;

        case 'editing_price':
            $stateData['price'] = $text;
            $db->setState($userId, 'awaiting_review', $stateData);
            showAdReview($userId, $stateData);
            break;

        // ---- Default ----

        case 'idle':
        default:
            $user = $db->getUser($userId);
            if ($user) {
                showMainMenu($userId, $user['name']);
            } else {
                $tg->sendMessage($userId, "Send /start to get started!");
            }
            break;
    }
}

// ============================================================
// PHOTO HANDLER
// ============================================================

function handlePhotoMessage($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    $currentState = $state['state'];
    if ($currentState !== 'awaiting_photos' && $currentState !== 'editing_photos') return false;

    $photo = end($msg['photo']);
    $stateData = $state['data'];
    $stateData['photos'] = $stateData['photos'] ?? [];

    $doneCallback = ($currentState === 'editing_photos') ? 'edit_photos_done' : 'photos_done';
    $cancelCallback = ($currentState === 'editing_photos') ? 'back_to_review' : 'cancel';
    $maxButtons = [
        [['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCallback]],
        [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => $cancelCallback]],
    ];

    // Already at the 5-photo limit: ignore extras, notify once with Done/Cancel only
    if (count($stateData['photos']) >= 5) {
        if (empty($stateData['_max_notified'])) {
            $stateData['_max_notified'] = true;
            $db->setState($userId, $currentState, $stateData);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xB8 Maximum of 5 photos reached. Tap Done to continue.",
                $maxButtons
            );
        }
        return true;
    }

    $stateData['photos'][] = $photo['file_id'];
    $count = count($stateData['photos']);

    $mediaGroupId = $msg['media_group_id'] ?? null;
    $lastMediaGroup = $stateData['_last_media_group'] ?? null;

    $sendMsg = true;
    if ($mediaGroupId && $mediaGroupId === $lastMediaGroup) {
        $sendMsg = false;
    }
    if ($mediaGroupId) {
        $stateData['_last_media_group'] = $mediaGroupId;
    }

    // Just hit the limit with this photo: show the max message with Done/Cancel only
    if ($count >= 5) {
        $stateData['_max_notified'] = true;
        $db->setState($userId, $currentState, $stateData);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Maximum of 5 photos reached. Tap Done to continue.",
            $maxButtons
        );
        return true;
    }

    $db->setState($userId, $currentState, $stateData);

    if ($sendMsg) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Photo received! ({$count}/5)\n\nSend more photos or tap Done when finished.",
            $maxButtons
        );
    }
    return true;
}

// ============================================================
// ACTION ROUTER
// ============================================================

// Attribute a just-registered user to their inviter and notify the inviter.
// Called after createUser() when the user arrived via a referral link.
function referralAttributeNewUser($userId, $refCode, $stateData) {
    global $tg, $referral;
    if (!$referral) return;
    try {
        $referrerId = $referral->codeOwner($refCode);
        list($ok, $why) = $referral->attribute(
            $userId, $refCode,
            $stateData['name'] ?? '', $stateData['phone'] ?? '', $stateData['email'] ?? ''
        );
        if (!$ok || !$referrerId) return;

        $friend = $stateData['name'] ?? 'A friend';
        $needJoin = ($why !== 'flagged') && $referral->requiresGroupJoin();

        // If the friend is already in the group, count the join right away so we
        // don't nag them to "join" something they're already in.
        if ($needJoin && userInGroup($userId)) {
            $referral->markGroupJoined($userId);
            $needJoin = false;
        }

        // Tell the inviter (best-effort - they've DM'd the bot before, so allowed).
        if ($why === 'flagged') {
            $note = "\xF0\x9F\x91\x80 A new sign-up used your invite link but needs a quick review before it counts.";
        } elseif ($needJoin) {
            $note = "\xF0\x9F\x8E\x89 <b>{$friend}</b> just signed up with your invite link! It'll count toward your rewards once they join the group and settle in.";
        } else {
            $note = "\xF0\x9F\x8E\x89 <b>{$friend}</b> just joined using your invite link! It'll count toward your rewards once they settle in.";
        }
        $tg->sendInlineButtons($referrerId, $note,
            [[['text' => "\xF0\x9F\x93\x88 My Progress", 'callback_data' => 'inv_progress']]]);

        // Ask the new user to join the group to complete the invite.
        if ($needJoin) { inviteGroupJoinPrompt($userId); }

        // Did that push them over a milestone (e.g. when the settle window is 0)?
        foreach ($referral->checkMilestones($referrerId) as $rw) { inviteRewardUnlocked($referrerId, $rw); }
    } catch (\Throwable $e) { /* never block registration on referral errors */ }
}

// An EXISTING bot user tapped a friend's referral link. Unlike a brand-new user
// they're already registered - but the community still benefits when they join the
// group, so we credit the inviter once this user joins the Telegram group. The
// link is DECLINED only if they're already in the group or were already referred
// by someone else. Only reached when the group-join rule is active.
function referralAttributeExistingUser($userId, $refCode, $user) {
    global $tg, $referral;
    $name = $user['name'] ?? 'there';
    if (!$referral) { showMainMenu($userId, $name); return; }
    try {
        // Already part of the community? Nothing to gain - decline politely.
        if (userInGroup($userId)) {
            $tg->sendMessage($userId, "\xF0\x9F\x91\x8B You're already in our Telegram group, so this invite link doesn't apply to your account - but you can invite your own friends and earn rewards!");
            showMainMenu($userId, $name);
            return;
        }

        $referrerId = $referral->codeOwner($refCode);
        list($ok, $why) = $referral->attribute(
            $userId, $refCode,
            $name, $user['phone'] ?? '', $user['email'] ?? ''
        );

        if (!$ok || !$referrerId) {
            if ($why === 'already_referred') {
                $tg->sendMessage($userId, "\xF0\x9F\x91\x8B You've already been invited before, so this link doesn't apply - but you can invite your own friends and earn rewards!");
            } elseif ($why === 'self') {
                $tg->sendMessage($userId, "\xF0\x9F\x98\x85 You can't use your own referral link - but share it with friends to earn rewards!");
            }
            // feature_off / bad_code / error: stay quiet and just show the menu.
            showMainMenu($userId, $name);
            return;
        }

        // Recorded. Let the inviter know, then ask this user to join the group to
        // complete the invite (mirrors the brand-new-user flow).
        $friend = htmlspecialchars($name);
        if ($why === 'flagged') {
            $note = "\xF0\x9F\x91\x80 Someone used your invite link but needs a quick review before it counts.";
            $tg->sendInlineButtons($referrerId, $note,
                [[['text' => "\xF0\x9F\x93\x88 My Progress", 'callback_data' => 'inv_progress']]]);
            $tg->sendMessage($userId, "Thanks! We just need a quick review, then you're all set.");
            showMainMenu($userId, $name);
            return;
        }

        $note = "\xF0\x9F\x8E\x89 <b>{$friend}</b> used your invite link! It'll count toward your rewards once they join the group and settle in.";
        $tg->sendInlineButtons($referrerId, $note,
            [[['text' => "\xF0\x9F\x93\x88 My Progress", 'callback_data' => 'inv_progress']]]);
        inviteGroupJoinPrompt($userId);
    } catch (\Throwable $e) {
        showMainMenu($userId, $name);
    }
}

// True when a user is currently a member of the configured Telegram group.
// Best-effort: needs the bot to be in (ideally admin of) that group.
function userInGroup($userId) {
    global $tg, $db;
    $chat = trim((string) $db->getSetting('sched_group_chat_id', ''));
    if ($chat === '') return false;
    try {
        $res = $tg->callApi('getChatMember', ['chat_id' => $chat, 'user_id' => $userId]);
        if (empty($res['ok'])) return false;
        $st = $res['result']['status'] ?? '';
        if (in_array($st, ['creator', 'administrator', 'member'], true)) return true;
        if ($st === 'restricted') return !empty($res['result']['is_member']);
        return false; // left | kicked
    } catch (\Throwable $e) {
        return false;
    }
}

// True only for links Telegram lets a NON-member open to join the group.
// Rejects the two links that trigger "This group is unavailable":
//   - t.me/c/<internal_id>/...  (private-group internal links, members only)
//   - t.me/<username>/<msg_id>  (message deep-links, not join links)
// Accepts: t.me/+HASH and t.me/joinchat/HASH (invite links) and a bare public
// username t.me/<username> (only works if the group is public, but harmless).
function isJoinableLink($url) {
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://t\.me/#i', $url)) return false;
    $path = rtrim(preg_replace('#^https?://t\.me/#i', '', $url), '/');
    if ($path === '') return false;
    if (strpos($path, '/') !== false) {
        // Only the old-style invite link may contain a slash.
        return (bool) preg_match('#^joinchat/[A-Za-z0-9_-]+$#i', $path);
    }
    if ($path[0] === '+') return strlen($path) > 1;                 // t.me/+HASH
    if (strcasecmp($path, 'c') === 0) return false;                 // t.me/c
    return (bool) preg_match('#^[A-Za-z][A-Za-z0-9_]{3,}$#', $path); // public @username
}

// Ask Telegram for a real, permanent invite link for the configured group and
// cache it. Requires the bot to be an ADMIN of the group with the "Invite Users
// via Link" right. Returns '' if that isn't the case.
function generateGroupInviteLink() {
    global $tg, $db;
    $chat = trim((string) $db->getSetting('sched_group_chat_id', ''));
    if ($chat === '') return '';
    foreach (['createChatInviteLink', 'exportChatInviteLink'] as $method) {
        try {
            $params = ['chat_id' => $chat];
            if ($method === 'createChatInviteLink') {
                $params['name'] = 'Invite & Earn';
                $params['creates_join_request'] = false;
            }
            $res = $tg->callApi($method, $params);
            $link = '';
            if (!empty($res['ok'])) {
                $link = is_array($res['result'] ?? null)
                    ? (string) ($res['result']['invite_link'] ?? '')
                    : (string) ($res['result'] ?? '');
            }
            if ($link !== '' && preg_match('#^https?://#i', $link)) {
                $db->setSetting('group_invite_link_auto', $link);
                return $link;
            }
        } catch (\Throwable $e) { /* try next method */ }
    }
    return '';
}

// A working "join the group" link. The admin-set link is honoured only when it's
// actually a join link; otherwise we self-heal with a bot-generated invite link
// so a pasted internal/message link never causes "This group is unavailable".
function groupJoinLink() {
    global $db;
    // 1) Admin override, but ONLY if it's a real join link (not t.me/c/ or a
    //    message link). This is what caused the reported "unavailable" error.
    $stored = trim((string) $db->getSetting('group_invite_link', ''));
    if (isJoinableLink($stored)) return $stored;
    // 2) A bot invite link we generated earlier (known good, stable).
    $cached = trim((string) $db->getSetting('group_invite_link_auto', ''));
    if (isJoinableLink($cached)) return $cached;
    // 3) Generate a fresh one from the group chat id (bot must be admin).
    $auto = generateGroupInviteLink();
    if ($auto !== '') return $auto;
    // 4) Nothing usable — return '' so callers show only the "I've Joined" button
    //    rather than a broken link.
    return '';
}

// DM an invited user asking them to join the group to complete their friend's invite.
function inviteGroupJoinPrompt($userId) {
    global $tg, $db;
    $gname = trim((string) $db->getSetting('group_name', 'HabeshaList'));
    if ($gname === '') $gname = 'HabeshaList';
    $gnameH = htmlspecialchars($gname);
    $link = groupJoinLink();
    $rows = [];
    if ($link !== '' && preg_match('#^https?://#i', $link)) {
        $rows[] = [['text' => "\xF0\x9F\x91\xA5 Join {$gname}", 'url' => $link]];
    }
    $rows[] = [['text' => "\xE2\x9C\x85 I've Joined", 'callback_data' => 'inv_verify_join']];
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8E\x89 You're registered! One last step to complete your friend's invite:\n\n" .
        "Join our <b>{$gnameH}</b> Telegram group, then tap \"I've Joined\" so we can confirm it.",
        $rows);
}

// Handle the invited user tapping "I've Joined".
function inviteVerifyGroupJoin($userId) {
    global $tg, $db, $referral;
    if (!$referral) return;
    $ref = $referral->referralByReferred($userId);
    if (!$ref) {
        $tg->sendMessage($userId, "You don't have a pending invite to confirm - but thanks for joining us! \xF0\x9F\x99\x8F");
        return;
    }
    if ((int) $ref['group_joined'] === 1) {
        $tg->sendMessage($userId, "\xE2\x9C\x85 You're all set - your friend's invite is already confirmed.");
        return;
    }
    if (!userInGroup($userId)) {
        $gname = trim((string) $db->getSetting('group_name', 'HabeshaList'));
        if ($gname === '') $gname = 'HabeshaList';
        $link = groupJoinLink();
        $rows = [];
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            $rows[] = [['text' => "\xF0\x9F\x91\xA5 Join {$gname}", 'url' => $link]];
        }
        $rows[] = [['text' => "\xE2\x9C\x85 I've Joined", 'callback_data' => 'inv_verify_join']];
        $tg->sendInlineButtons($userId,
            "I can't see you in the group yet. Tap \"Join " . htmlspecialchars($gname) . "\", then press \"I've Joined\" again.",
            $rows);
        return;
    }
    $row = $referral->markGroupJoined($userId);
    $gname = trim((string) $db->getSetting('group_name', 'HabeshaList'));
    if ($gname === '') $gname = 'HabeshaList';
    $tg->sendMessage($userId, "\xE2\x9C\x85 Confirmed! You've joined " . htmlspecialchars($gname) . " and your friend's invite now counts. Welcome aboard! \xF0\x9F\x8E\x89");
    if ($row) { referralNotifyInviterJoined($row); }
}

// Tell the inviter their friend has joined the group (invite now confirmed).
function referralNotifyInviterJoined($row) {
    global $tg, $referral;
    if (!$referral || !$row) return;
    $inviter = (int) $row['referrer_id'];
    $friend = !empty($row['referred_name']) ? htmlspecialchars($row['referred_name']) : 'Your friend';
    try {
        $tg->sendInlineButtons($inviter,
            "\xE2\x9C\x85 <b>{$friend}</b> joined the group - your invite is now confirmed and counts toward your rewards!",
            [[['text' => "\xF0\x9F\x93\x88 My Progress", 'callback_data' => 'inv_progress']]]);
        foreach ($referral->checkMilestones($inviter) as $rw) { inviteRewardUnlocked($inviter, $rw); }
    } catch (\Throwable $e) { /* best-effort */ }
}

// Passive detection: fires when someone's membership in the group changes.
// Requires the bot to be an ADMIN of the group and 'chat_member' in allowed_updates.
function handleChatMember($cm) {
    global $tg, $db, $referral;
    if (!$referral) return;
    try {
        $want = trim((string) $db->getSetting('sched_group_chat_id', ''));
        if ($want === '') return;
        $chatId   = (string) ($cm['chat']['id'] ?? '');
        $chatUser = isset($cm['chat']['username']) ? '@' . $cm['chat']['username'] : '';
        $matches = ($want === $chatId) || ($chatUser !== '' && strcasecmp($want, $chatUser) === 0);
        if (!$matches) return;

        $st = $cm['new_chat_member']['status'] ?? '';
        $isMember = in_array($st, ['member', 'administrator', 'creator'], true)
                 || ($st === 'restricted' && !empty($cm['new_chat_member']['is_member']));
        if (!$isMember) return;

        $uid = $cm['new_chat_member']['user']['id'] ?? null;
        if (!$uid) return;

        $row = $referral->markGroupJoined($uid);
        if (!$row) return; // not a referred user, or already counted

        $gname = trim((string) $db->getSetting('group_name', 'HabeshaList'));
        if ($gname === '') $gname = 'HabeshaList';
        try {
            $tg->sendMessage($uid, "\xE2\x9C\x85 Thanks for joining " . htmlspecialchars($gname) . "! Your friend's invite is now confirmed. \xF0\x9F\x8E\x89");
        } catch (\Throwable $e) { /* user may block DMs; ignore */ }
        referralNotifyInviterJoined($row);
    } catch (\Throwable $e) { /* never let a membership update crash the loop */ }
}

function handleAction($userId, $action) {
    global $tg, $db, $config;

    switch ($action) {
        case 'post_ad':
            if (!$db->getUser($userId)) {
                startRegistration($userId, 'post_ad');
                break;
            }
            showCategoryPicker($userId);
            break;
        case 'promote':
            if (!$db->getUser($userId)) {
                startRegistration($userId, 'promote');
                break;
            }
            promoStart($userId);
            break;
        case 'botw':
            if (!$db->getUser($userId)) {
                startRegistration($userId, 'botw');
                break;
            }
            promoStartBotw($userId);
            break;
        case 'contact':
            showContactInfo($userId);
            break;
        case 'invite':
            if (!$db->getUser($userId)) {
                startRegistration($userId, 'invite');
                break;
            }
            inviteEarnHome($userId);
            break;
        case 'paid':
            // Return from Stripe checkout (success) - verify & continue.
            promoReturnFromCheckout($userId);
            break;
        case 'paycancel':
            // Return from Stripe checkout (cancelled) - back to payment options.
            promoReturnFromCancel($userId);
            break;
        case 'dashboard':
            if (!$db->getUser($userId)) {
                startRegistration($userId, 'dashboard');
                break;
            }
            promoShowDashboard($userId);
            break;
        default:
            $user = $db->getUser($userId);
            showMainMenu($userId, $user['name'] ?? 'there');
            break;
    }
}

// ============================================================
// CATEGORY FUNCTIONS
// ============================================================

function showCategoryPicker($userId) {
    global $tg, $db, $config;

    $buttons = [];
    foreach ($config['categories'] as $key => $cat) {
        $buttons[] = [['text' => $cat['icon'] . ' ' . $cat['name'], 'callback_data' => 'cat_' . $key]];
    }
    $buttons[] = [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel']];

    $db->setState($userId, 'awaiting_category', []);
    $tg->sendInlineButtons($userId, "\xF0\x9F\x93\x8B <b>Select a category:</b>", $buttons);
}

function handleCategorySelect($userId, $catKey, $state) {
    global $tg, $db, $config;

    $cat = $config['categories'][$catKey] ?? null;
    if (!$cat) return;

    $stateData = ['category' => $catKey, 'category_name' => $cat['name']];

    if (empty($cat['subcategories'])) {
        $db->setState($userId, 'awaiting_title', $stateData);
        $tg->sendInlineButtons($userId,
            "\xE2\x9C\x85 Category: <b>{$cat['name']}</b>\n\n\xF0\x9F\x93\x9D Enter the <b>title</b> for your ad:",
            [[
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'cat_back'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
            ]]
        );
        return;
    }

    $buttons = [];
    foreach ($cat['subcategories'] as $key => $name) {
        $buttons[] = [['text' => $name, 'callback_data' => 'subcat_' . $key]];
    }
    $buttons[] = [
        ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'cat_back'],
        ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
    ];

    $db->setState($userId, 'awaiting_subcategory', $stateData);
    $tg->sendInlineButtons($userId, "\xF0\x9F\x93\x82 <b>{$cat['name']}</b>\n\nSelect a subcategory:", $buttons);
}

function handleSubcategorySelect($userId, $subcatKey, $state) {
    global $tg, $db, $config;

    $stateData = $state['data'];
    $cat = $config['categories'][$stateData['category']] ?? null;
    if (!$cat) return;

    $subcatName = $cat['subcategories'][$subcatKey] ?? $subcatKey;
    $stateData['subcategory'] = $subcatKey;
    $stateData['subcategory_name'] = $subcatName;

    $db->setState($userId, 'awaiting_title', $stateData);
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 Category: <b>{$stateData['category_name']}</b>\n" .
        "\xF0\x9F\x93\x82 Subcategory: <b>{$subcatName}</b>\n\n" .
        "\xF0\x9F\x93\x9D Enter the <b>title</b> for your ad:",
        [[
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_subcats'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ]]
    );
}

// ============================================================
// LOCATION FUNCTIONS
// ============================================================

function showCountryPicker($userId, $isEdit = false) {
    global $tg, $db, $config;

    // Reply-keyboard "dropdown" list of countries (2 per row)
    $rows = [];
    $row = [];
    foreach ($config['countries'] as $key => $country) {
        $row[] = ['text' => $country['name']];
        if (count($row) === 2) {
            $rows[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $rows[] = $row;
    }

    $rows[] = [['text' => "\xF0\x9F\x93\x8D Other (type country)"]];
    $rows[] = [['text' => "\xE2\x8F\xA9 Skip Location"]];

    if ($isEdit) {
        $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review"]];
    } else {
        $rows[] = [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back"],
            ['text' => "\xE2\x9D\x8C Cancel"],
        ];
    }

    $tg->sendReplyKeyboard($userId,
        "\xF0\x9F\x8C\x8D <b>Select your country</b> from the list below\xF0\x9F\x91\x87\n\n(You can skip this entire section)",
        $rows
    );
}

function handleCountrySelect($userId, $countryKey, $state) {
    global $tg, $db, $config;

    $stateData = $state['data'];
    $isEdit = !empty($stateData['editing_location']);

    if ($countryKey === 'other') {
        $nextState = $isEdit ? 'editing_country_other' : 'awaiting_country_other';
        $db->setState($userId, $nextState, $stateData);
        $backCb = $isEdit ? 'back_to_country' : 'back_to_country';
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8D Type your <b>country</b>:",
            [[
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_country'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
            ]]
        );
        return;
    }

    $country = $config['countries'][$countryKey] ?? null;
    if (!$country) return;

    $stateData['loc_country'] = $country['name'];
    $stateData['loc_country_code'] = $country['code'];

    $nextState = $isEdit ? 'editing_state_text' : 'awaiting_state_text';
    $db->setState($userId, $nextState, $stateData);
    showStatePrompt($userId, $stateData, $isEdit);
}

function showStatePrompt($userId, $stateData = [], $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_state']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_country'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        locationSummary($stateData) . "\xF0\x9F\x93\x8D Enter your <b>state/region</b>:\n\n(Or tap Skip to continue)",
        $buttons
    );
}

function showCityPrompt($userId, $stateData = [], $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_city']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_state'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        locationSummary($stateData) . "\xF0\x9F\x93\x8D Enter your <b>city</b>:\n\n(Or tap Skip to continue)",
        $buttons
    );
}

function showAddressPrompt($userId, $stateData = [], $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_address']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_city'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        locationSummary($stateData) . "\xF0\x9F\x93\x8D Enter your <b>address</b>:\n\n(Or tap Skip to continue)",
        $buttons
    );
}

function buildLocationName(&$stateData) {
    $parts = [];
    if (!empty($stateData['loc_address'])) $parts[] = $stateData['loc_address'];
    if (!empty($stateData['loc_city'])) $parts[] = $stateData['loc_city'];
    if (!empty($stateData['loc_state'])) $parts[] = $stateData['loc_state'];
    if (!empty($stateData['loc_country'])) $parts[] = $stateData['loc_country'];
    $stateData['location_name'] = !empty($parts) ? implode(', ', $parts) : 'Not specified';
}

// Running summary of the location entered so far (shown above each location prompt)
function locationSummary($stateData) {
    $lines = [];
    if (isset($stateData['loc_country'])) {
        $v = $stateData['loc_country'] !== '' ? $stateData['loc_country'] : 'Skipped';
        $lines[] = "\xE2\x9C\x85 Country: <b>{$v}</b>";
    }
    if (isset($stateData['loc_state'])) {
        $v = $stateData['loc_state'] !== '' ? $stateData['loc_state'] : 'Skipped';
        $lines[] = "\xE2\x9C\x85 State/Region: <b>{$v}</b>";
    }
    if (isset($stateData['loc_city'])) {
        $v = $stateData['loc_city'] !== '' ? $stateData['loc_city'] : 'Skipped';
        $lines[] = "\xE2\x9C\x85 City: <b>{$v}</b>";
    }
    if (isset($stateData['loc_address'])) {
        $v = $stateData['loc_address'] !== '' ? $stateData['loc_address'] : 'Skipped';
        $lines[] = "\xE2\x9C\x85 Address: <b>{$v}</b>";
    }
    return empty($lines) ? '' : (implode("\n", $lines) . "\n\n");
}

function doSkipLocation($userId, $stateData, $isEdit) {
    global $tg, $db;

    $stateData['loc_country'] = '';
    $stateData['loc_country_code'] = '';
    $stateData['loc_state'] = '';
    $stateData['loc_city'] = '';
    $stateData['loc_address'] = '';
    $stateData['location_name'] = 'Not specified';

    if ($isEdit) {
        unset($stateData['editing_location']);
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
    } else {
        $stateData['photos'] = [];
        $db->setState($userId, 'awaiting_photos', $stateData);
        showPhotoPrompt($userId);
    }
}

function showPricePrompt($userId) {
    global $tg;
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x92\xB0 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
        [[
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_desc'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ]]
    );
}

// ============================================================
// PHOTO FUNCTIONS
// ============================================================

function showPhotoPrompt($userId, $isEdit = false) {
    global $tg, $db;

    // Clear per-upload tracking flags whenever the photo step is (re)entered
    $st = $db->getState($userId);
    $sd = $st['data'];
    if (isset($sd['_max_notified']) || isset($sd['_last_media_group'])) {
        unset($sd['_max_notified'], $sd['_last_media_group']);
        $db->setState($userId, $st['state'], $sd);
    }

    $doneCallback = $isEdit ? 'edit_photos_done' : 'photos_skip';
    $cancelCallback = $isEdit ? 'back_to_review' : 'cancel';
    $doneText = $isEdit ? "\xE2\x8F\xA9 Skip Photos" : "\xE2\x8F\xA9 Skip - No Photos";

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\xB8 <b>Upload Photos for Your Ad</b>\n\n" .
        "Send your photos now (up to 5 photos).\n" .
        "You can select multiple photos at once.\n" .
        "When finished, tap Done. Or tap Skip if you don't want photos.",
        [
            [['text' => $doneText, 'callback_data' => $doneCallback]],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => $cancelCallback]],
        ]
    );
}

function handlePhotosDone($userId, $state) {
    global $db;

    $stateData = $state['data'];
    unset($stateData['_last_media_group']);
    $db->setState($userId, 'awaiting_review', $stateData);
    showAdReview($userId, $stateData);
}

// ============================================================
// REVIEW & PUBLISH
// ============================================================

function showAdReview($userId, $adData) {
    global $tg;

    $photoCount = count($adData['photos'] ?? []);
    $photoText = $photoCount > 0 ? "{$photoCount} photo(s)" : "No photos";

    $review = "\xF0\x9F\x93\x8B <b>Review Your Ad</b>\n\n" .
        "\xF0\x9F\x93\x82 Category: <b>{$adData['category_name']}</b>\n" .
        (isset($adData['subcategory_name']) ? "\xF0\x9F\x93\x81 Subcategory: <b>{$adData['subcategory_name']}</b>\n" : '') .
        "\xF0\x9F\x93\x9D Title: <b>{$adData['title']}</b>\n" .
        "\xF0\x9F\x93\x84 Description: {$adData['description']}\n" .
        "\xF0\x9F\x92\xB0 Price: <b>{$adData['price']}</b>\n" .
        "\xF0\x9F\x93\x8D Location: <b>{$adData['location_name']}</b>\n" .
        "\xF0\x9F\x93\xB8 Photos: {$photoText}\n\n" .
        "Does everything look correct?";

    $tg->sendInlineButtons($userId, $review, [
        [
            ['text' => "\xE2\x9C\x85 Publish", 'callback_data' => 'confirm_publish'],
            ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit", 'callback_data' => 'edit_ad'],
        ],
        [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel']],
    ]);
}

function showEditOptions($userId, $state) {
    global $tg, $db;

    $stateData = $state['data'];
    $db->setState($userId, 'awaiting_review', $stateData);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x8F\xEF\xB8\x8F <b>What would you like to edit?</b>",
        [
            [
                ['text' => "\xF0\x9F\x93\x9D Title", 'callback_data' => 'edit_title'],
                ['text' => "\xF0\x9F\x93\x84 Description", 'callback_data' => 'edit_description'],
            ],
            [
                ['text' => "\xF0\x9F\x92\xB0 Price", 'callback_data' => 'edit_price'],
                ['text' => "\xF0\x9F\x93\x8D Location", 'callback_data' => 'edit_location'],
            ],
            [['text' => "\xF0\x9F\x93\xB8 Photos", 'callback_data' => 'edit_photos']],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'back_to_review']],
        ]
    );
}

function handlePublish($userId, $state) {
    global $tg, $db, $config;

    $adData = $state['data'];
    $user = $db->getUser($userId);
    $adData['contact_name'] = $user['name'] ?? '';
    $adData['contact_email'] = $user['email'] ?? '';
    $adData['osclass_user_id'] = $user['osclass_user_id'] ?? null;

    // Prevent the "unresponsive" feeling during the OSClass call
    $tg->sendMessage($userId, "\xE2\x8F\xB3 Submitting your ad, please wait...");

    $adId = $db->createAd($userId, $adData);

    $result = publishToOSClass($adData, $userId);

    $title = $adData['title'] ?? 'Untitled';

    $osclassId = null;
    if ($result && isset($result['success']) && $result['success']) {
        $osclassId = $result['osclass_id'] ?? null;
    }
    // Ad is created hidden and stays 'pending' until an admin approves it
    $db->updateAdStatus($adId, 'pending', $osclassId);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Your ad has been submitted for review!</b>\n\n" .
        "\xF0\x9F\x93\x9D Title: <b>{$title}</b>\n\n" .
        "Our team will review it shortly. As soon as it's approved, you'll get a message here and it will go live on HabeshaList.com.\n\n" .
        "What would you like to do next?",
        [
            [['text' => "\xE2\x9E\x95 Post Another Ad", 'callback_data' => 'post_another']],
            [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
        ]
    );

    $db->setState($userId, 'idle', []);

    // Send the ad to the admin(s) for approval
    notifyAdminsForReview($adId, $adData, $user);
}

function notifyAdminsForReview($adId, $adData, $user) {
    global $tg, $db, $config;

    $ad = $db->getAd($adId);
    $osclassId = $ad['osclass_id'] ?? null;
    $poster = $user['name'] ?? ($adData['contact_name'] ?? 'Unknown');
    $phone = $user['phone'] ?? '';
    $photos = $adData['photos'] ?? [];
    $photoCount = count($photos);

    $summary = "\xF0\x9F\x94\x94 <b>New ad awaiting review</b>\n\n" .
        "\xF0\x9F\x93\x82 Category: <b>{$adData['category_name']}</b>\n" .
        (isset($adData['subcategory_name']) ? "\xF0\x9F\x93\x81 Subcategory: <b>{$adData['subcategory_name']}</b>\n" : '') .
        "\xF0\x9F\x93\x9D Title: <b>{$adData['title']}</b>\n" .
        "\xF0\x9F\x93\x84 {$adData['description']}\n" .
        "\xF0\x9F\x92\xB0 Price: <b>{$adData['price']}</b>\n" .
        "\xF0\x9F\x93\x8D Location: <b>{$adData['location_name']}</b>\n" .
        "\xF0\x9F\x93\xB8 Photos: {$photoCount}\n" .
        "\xF0\x9F\x91\xA4 Posted by: <b>{$poster}</b>" . ($phone ? " ({$phone})" : '') . "\n\n" .
        "Approve to publish it, or Reject to keep it hidden.";

    $buttons = [[
        ['text' => "\xE2\x9C\x85 Approve", 'callback_data' => "approve_{$adId}"],
        ['text' => "\xE2\x9D\x8C Reject", 'callback_data' => "reject_{$adId}"],
    ]];

    foreach ($config['admin_ids'] as $adminId) {
        if (!empty($photos)) {
            $tg->sendMediaGroup($adminId, $photos);
        }
        $tg->sendInlineButtons($adminId, $summary, $buttons);
    }
}

function moderateAd($adminId, $adId, $decision) {
    global $tg, $db, $config;

    if (!isAdmin($adminId)) return;

    $ad = $db->getAd($adId);
    if (!$ad) {
        $tg->sendMessage($adminId, "That ad could not be found.");
        return;
    }
    if (($ad['status'] ?? '') !== 'pending') {
        $tg->sendMessage($adminId, "This ad was already handled (status: {$ad['status']}).");
        return;
    }

    $osclassId = $ad['osclass_id'] ?? null;
    $posterTid = $ad['telegram_id'];
    $title = $ad['title'] ?: 'your ad';

    if ($osclassId) {
        moderateOnWebsite($osclassId, $decision);
    }

    if ($decision === 'approve') {
        $db->updateAdStatus($adId, 'approved', $osclassId);
        $tg->sendMessage($adminId, "\xE2\x9C\x85 Approved: <b>{$title}</b> is now live.");

        $btns = [];
        if ($osclassId) {
            $btns[] = [['text' => "\xF0\x9F\x94\x97 View My Ad", 'url' => $config['website_url'] . '/index.php?page=item&id=' . $osclassId]];
        }
        $btns[] = [['text' => "\xE2\x9E\x95 Post Another Ad", 'callback_data' => 'post_ad']];

        $tg->sendInlineButtons($posterTid,
            "\xF0\x9F\x8E\x89 Good news! Your ad <b>{$title}</b> has been approved and is now live on HabeshaList.com.",
            $btns
        );
    } else {
        $db->updateAdStatus($adId, 'rejected', $osclassId);
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Rejected: <b>{$title}</b> has been kept hidden.");

        $tg->sendInlineButtons($posterTid,
            "Regarding your ad <b>{$title}</b> \xE2\x80\x94 unfortunately it wasn't approved for posting this time. " .
            "If you'd like, you can adjust it and post again.",
            [[['text' => "\xE2\x9E\x95 Post Another Ad", 'callback_data' => 'post_ad']]]
        );
    }
}

function moderateOnWebsite($osclassId, $decision) {
    global $config;

    return callBridge([
        'secret' => $config['api_secret'],
        'action' => 'moderate_item',
        'item_id' => $osclassId,
        'decision' => $decision,
    ], 20);
}

function publishToOSClass($adData, $userId) {
    global $config;

    $payload = [
        'secret' => $config['api_secret'],
        'action' => 'create_listing',
        'telegram_id' => $userId,
        'category' => $adData['category'] ?? '',
        'subcategory' => $adData['subcategory'] ?? '',
        'title' => $adData['title'] ?? '',
        'description' => $adData['description'] ?? '',
        'price' => $adData['price'] ?? '',
        'country' => $adData['loc_country'] ?? '',
        'country_code' => $adData['loc_country_code'] ?? '',
        'state' => $adData['loc_state'] ?? '',
        'city' => $adData['loc_city'] ?? '',
        'address' => $adData['loc_address'] ?? '',
        'contact_name' => $adData['contact_name'] ?? '',
        'contact_email' => $adData['contact_email'] ?? '',
        'osclass_user_id' => $adData['osclass_user_id'] ?? null,
        'photos' => $adData['photos'] ?? [],
    ];

    return callBridge($payload, 30);
}

// Publish an APPROVED promotion to the website as a listing (in addition to the
// Telegram group post). Businesses go under the general Services category by
// default; the logo, extra photos AND videos are all carried over. The bot token
// is passed in the payload so the bridge can download the media from Telegram
// without needing the token hardcoded on the website side. Guarded by the caller
// so a website hiccup can never block an approval.
function publishPromotionToWebsite($promo) {
    global $config, $db;

    $user = $db->getUser((int) $promo['telegram_id']);
    $osUserId = $user['osclass_user_id'] ?? null;

    $descParts = [];
    if (!empty($promo['description'])) $descParts[] = $promo['description'];
    if (!empty($promo['phone']))   $descParts[] = "\xE2\x98\x8E Phone: " . $promo['phone'];
    if (!empty($promo['website'])) $descParts[] = "Website: " . $promo['website'];
    if (!empty($promo['social']))  $descParts[] = "Social: " . $promo['social'];
    if (!empty($promo['hours']))   $descParts[] = "Hours: " . $promo['hours'];
    $description = implode("\n", $descParts);

    $photos = [];
    if (!empty($promo['logo'])) $photos[] = $promo['logo'];
    if (!empty($promo['images'])) {
        $imgs = json_decode($promo['images'], true);
        if (is_array($imgs)) $photos = array_merge($photos, array_filter($imgs));
    }

    $videos = [];
    if (!empty($promo['videos'])) {
        $vids = json_decode($promo['videos'], true);
        if (is_array($vids)) $videos = array_filter($vids);
    }

    $payload = [
        'secret'          => $config['api_secret'],
        'action'          => 'create_listing',
        'bot_token'       => $config['bot_token'] ?? '',
        'telegram_id'     => (int) $promo['telegram_id'],
        'category'        => 'services',
        'subcategory'     => 'other_services',
        'title'           => $promo['business_name'] ?: 'Business',
        'description'     => $description,
        'price'           => '',
        'city'            => $promo['address'] ?? '',
        'address'         => $promo['address'] ?? '',
        'contact_name'    => $user['name'] ?? ($promo['business_name'] ?? ''),
        'contact_email'   => $user['email'] ?? '',
        'osclass_user_id' => $osUserId,
        'photos'          => $photos,
        'videos'          => array_values($videos),
        'auto_approve'    => true,   // paid + admin-approved -> go live on the site immediately
    ];
    return callBridge($payload, 45);
}

// Create/find the matching OSClass website account so the user shows in the admin panel
function registerOnWebsite($name, $phone, $email) {
    global $config;

    return callBridge([
        'secret' => $config['api_secret'],
        'action' => 'register_user',
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
    ], 15);
}

function callBridge($payload, $timeout = 30) {
    global $config;

    $ch = curl_init($config['api_bridge_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("OSClass bridge error: {$error}");
        return null;
    }

    return json_decode($response, true);
}

// ============================================================
// MENUS & UI
// ============================================================

function showMainMenu($userId, $name) {
    global $tg, $config;

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8F\xA0 <b>Welcome to HabeshaList.com!</b>\n\n" .
        "The Habesha marketplace where you can:\n" .
        "- Find housing\n" .
        "- Discover jobs\n" .
        "- Buy and Sell\n" .
        "- Promote your business\n" .
        "- Connect with the community\n\n" .
        "All in one place. Tap a button below to get started:",
        [
            [['text' => "\xF0\x9F\x93\x9D Post to Website (Free)", 'callback_data' => 'post_ad']],
            [['text' => "\xF0\x9F\x93\xA2 Promote My Business", 'callback_data' => 'promote']],
            [['text' => "\xF0\x9F\x8F\x86 Business of the Week", 'callback_data' => 'botw']],
            [['text' => "\xF0\x9F\x8E\x81 Invite & Earn", 'callback_data' => 'invite']],
            [['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']],
            [['text' => "\xF0\x9F\x93\x9E Contact Us", 'callback_data' => 'contact']],
        ]
    );
}

function showContactInfo($userId) {
    global $tg;

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x9E <b>Contact the HabeshaList Team</b>\n\n" .
        "Need assistance or have a question? We're here to help!\n\n" .
        "\xF0\x9F\x92\xAC Telegram Support: @Habesha_list\n" .
        "\xF0\x9F\x93\xB1 WhatsApp: HabeshaList_Beti\n" .
        "\xF0\x9F\x93\xA7 Email: info@habeshalist.com\n" .
        "\xF0\x9F\x8C\x90 Website: https://habeshalist.com\n\n" .
        "\xF0\x9F\x93\xB1 <b>Follow HabeshaList</b>\n\n" .
        "\xF0\x9F\x93\x98 Facebook: https://www.facebook.com/beti.negatu/\n" .
        "\xF0\x9F\x93\xB8 Instagram: https://www.instagram.com/beti_negatu/\n" .
        "\xF0\x9F\x8E\xB5 TikTok: https://www.tiktok.com/@habeshalistofficial\n" .
        "\xE2\x96\xB6\xEF\xB8\x8F YouTube: https://www.youtube.com/@HabeshaListOfficial\n" .
        "\xF0\x9F\x92\xBC LinkedIn: https://www.linkedin.com/in/habesha-list/\n\n" .
        "\xE2\x8F\xB0 <b>Support Hours</b>\n" .
        "Monday - Friday, 9:00 AM - 5:00 PM (ET)\n\n" .
        "Thank you for being part of the HabeshaList community! \xF0\x9F\x99\x8F",
        [
            [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
        ]
    );
}

function sendGroupButtons($chatId) {
    global $tg;

    $botUsername = getBotUsername();

    $tg->sendInlineButtons($chatId,
        "\xF0\x9F\x8C\x9F <b>Welcome to HabeshaList.com</b> \xF0\x9F\x8C\x9F\n\n" .
        "The Habesha marketplace where you can:\n" .
        "- Find housing\n" .
        "- Discover jobs\n" .
        "- Buy and Sell\n" .
        "- Promote your business\n" .
        "- Connect with the community\n\n" .
        "All in one place. Tap a button below to get started:",
        [
            [['text' => "\xF0\x9F\x93\x9D Post to Website (FREE)", 'url' => "https://t.me/{$botUsername}?start=post_ad"]],
            [['text' => "\xF0\x9F\x93\xA2 Promote My Business", 'url' => "https://t.me/{$botUsername}?start=promote"]],
            [['text' => "\xF0\x9F\x8F\x86 Business of the Week", 'url' => "https://t.me/{$botUsername}?start=botw"]],
            [['text' => "\xF0\x9F\x8E\x81 Invite & Earn", 'url' => "https://t.me/{$botUsername}?start=invite"]],
            [['text' => "\xF0\x9F\x93\x9E Contact Us", 'url' => "https://t.me/{$botUsername}?start=contact"]],
        ]
    );
}

// ============================================================
// STATE RESTORATION (for Cancel → No)
// ============================================================

function restoreStateUI($userId, $stateName, $stateData) {
    global $tg, $db;

    switch ($stateName) {
        case 'awaiting_category':
            showCategoryPicker($userId);
            break;

        case 'awaiting_subcategory':
            $catKey = $stateData['category'] ?? '';
            if ($catKey) {
                handleCategorySelect($userId, $catKey, ['data' => $stateData]);
            } else {
                showCategoryPicker($userId);
            }
            break;

        case 'awaiting_title':
            $catName = $stateData['category_name'] ?? '';
            $subcatName = $stateData['subcategory_name'] ?? '';
            $header = "\xE2\x9C\x85 Category: <b>{$catName}</b>\n";
            if ($subcatName) $header .= "\xF0\x9F\x93\x82 Subcategory: <b>{$subcatName}</b>\n";
            $tg->sendInlineButtons($userId,
                $header . "\n\xF0\x9F\x93\x9D Enter the <b>title</b> for your ad:",
                [[
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_subcats'],
                    ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                ]]
            );
            break;

        case 'awaiting_description':
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\x9D Enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)",
                [[
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_title'],
                    ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                ]]
            );
            break;

        case 'awaiting_price':
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB0 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
                [[
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_desc'],
                    ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
                ]]
            );
            break;

        case 'awaiting_country':
        case 'editing_country':
            showCountryPicker($userId, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_state_text':
        case 'editing_state_text':
            showStatePrompt($userId, $stateData, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_city_text':
        case 'editing_city_text':
            showCityPrompt($userId, $stateData, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_address_text':
        case 'editing_address_text':
            showAddressPrompt($userId, $stateData, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_photos':
        case 'editing_photos':
            showPhotoPrompt($userId, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_review':
            showAdReview($userId, $stateData);
            break;

        default:
            $user = $db->getUser($userId);
            showMainMenu($userId, $user['name'] ?? 'there');
            break;
    }
}

// ============================================================
// HELPERS
// ============================================================

function startRegistration($userId, $intendedAction = '', $refCode = '') {
    global $tg, $db;
    $db->setState($userId, 'reg_name', ['intended_action' => $intendedAction, 'ref_code' => $refCode]);
    $tg->sendMessage($userId,
        "\xF0\x9F\x91\x8B Welcome to HabeshaList.com!\n\n" .
        "Before we start, let's create your free account.\n\n" .
        "\xF0\x9F\x93\x9D Please enter your <b>full name</b>:"
    );
}

function isAdmin($userId) {
    global $config;
    return in_array($userId, $config['admin_ids']);
}

function getBotUsername() {
    global $tg;
    static $username = null;
    if (!$username) {
        $result = $tg->callApi('getMe');
        $username = $result['result']['username'] ?? 'bot';
    }
    return $username;
}
