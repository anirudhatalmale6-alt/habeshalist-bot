<?php

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/telegram.php';

$db = new Database(__DIR__ . '/data/bot.sqlite');
$tg = new Telegram($config['bot_token']);

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    exit;
}

if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    exit;
}

if (isset($update['message'])) {
    handleMessage($update['message']);
    exit;
}

http_response_code(200);
exit;

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
    if ($data === 'view_my_ads') { showUserAds($userId); return; }

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
        if ($isEdit) {
            unset($stateData['editing_location']);
            $db->setState($userId, 'awaiting_review', $stateData);
            showAdReview($userId, $stateData);
        } else {
            $stateData['loc_country'] = '';
            $stateData['loc_country_code'] = '';
            $stateData['loc_state'] = '';
            $stateData['loc_city'] = '';
            $stateData['loc_address'] = '';
            $stateData['location_name'] = 'Not specified';
            $stateData['photos'] = [];
            $db->setState($userId, 'awaiting_photos', $stateData);
            showPhotoPrompt($userId);
        }
        return;
    }

    if ($data === 'skip_state') {
        $stateData = $state['data'];
        $stateData['loc_state'] = '';
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
        showCityPrompt($userId, $isEdit);
        return;
    }

    if ($data === 'skip_city') {
        $stateData = $state['data'];
        $stateData['loc_city'] = '';
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_address_text' : 'awaiting_address_text', $stateData);
        showAddressPrompt($userId, $isEdit);
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
        showStatePrompt($userId, $isEdit);
        return;
    }

    if ($data === 'back_to_city') {
        $stateData = $state['data'];
        $isEdit = !empty($stateData['editing_location']);
        $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
        showCityPrompt($userId, $isEdit);
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
}

// ============================================================
// MESSAGE HANDLER
// ============================================================

function handleMessage($msg) {
    global $tg, $db, $config;

    $chatId = $msg['chat']['id'];
    $userId = $msg['from']['id'];
    $text = trim($msg['text'] ?? '');
    $isPrivate = ($msg['chat']['type'] === 'private');

    // /start with deep link in private chat
    if ($isPrivate && strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        $param = $parts[1] ?? '';

        $user = $db->getUser($userId);

        if (!$user) {
            $db->setState($userId, 'reg_name', ['intended_action' => $param]);
            $tg->sendMessage($userId,
                "\xF0\x9F\x91\x8B Welcome to HabeshaList.com!\n\n" .
                "Before we begin, let's create your free account.\n\n" .
                "\xF0\x9F\x93\x9D Please enter your <b>full name</b>:"
            );
            return;
        }

        if ($param) {
            handleAction($userId, $param);
        } else {
            showMainMenu($userId, $user['name']);
        }
        return;
    }

    // /menu in private chat
    if ($isPrivate && $text === '/menu') {
        $user = $db->getUser($userId);
        if ($user) {
            showMainMenu($userId, $user['name']);
        } else {
            $db->setState($userId, 'reg_name', ['intended_action' => '']);
            $tg->sendMessage($userId,
                "\xF0\x9F\x91\x8B Welcome to HabeshaList.com!\n\n" .
                "Let's create your free account first.\n\n" .
                "\xF0\x9F\x93\x9D Please enter your <b>full name</b>:"
            );
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
        if (handlePhotoMessage($userId, $msg)) return;
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
    global $tg, $db, $config;

    $state = $db->getState($userId);
    $text = trim($msg['text'] ?? '');
    $stateData = $state['data'];

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
            showStatePrompt($userId, $isEdit);
            break;

        case 'awaiting_state_text':
        case 'editing_state_text':
            $stateData['loc_state'] = $text;
            $isEdit = ($state['state'] === 'editing_state_text');
            $db->setState($userId, $isEdit ? 'editing_city_text' : 'awaiting_city_text', $stateData);
            showCityPrompt($userId, $isEdit);
            break;

        case 'awaiting_city_text':
        case 'editing_city_text':
            $stateData['loc_city'] = $text;
            $isEdit = ($state['state'] === 'editing_city_text');
            $db->setState($userId, $isEdit ? 'editing_address_text' : 'awaiting_address_text', $stateData);
            showAddressPrompt($userId, $isEdit);
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

    if (count($stateData['photos']) >= 5) {
        $tg->sendMessage($userId, "Maximum 5 photos reached. Tap Done to continue.");
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

    $db->setState($userId, $currentState, $stateData);

    if ($sendMsg) {
        $doneCallback = ($currentState === 'editing_photos') ? 'edit_photos_done' : 'photos_done';
        $cancelCallback = ($currentState === 'editing_photos') ? 'back_to_review' : 'cancel';

        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\xB8 Photo received! ({$count}/5)\n\nSend more photos or tap Done when finished.",
            [
                [['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCallback]],
                [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => $cancelCallback]],
            ]
        );
    }
    return true;
}

// ============================================================
// ACTION ROUTER
// ============================================================

function handleAction($userId, $action) {
    global $tg, $db, $config;

    switch ($action) {
        case 'post_ad':
            showCategoryPicker($userId);
            break;
        case 'promote':
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xA2 <b>Promote Your Business</b>\n\nThis feature is coming soon! Stay tuned.",
                [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]
            );
            break;
        case 'botw':
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x8F\x86 <b>Business of the Week</b>\n\nThis feature is coming soon! Stay tuned.",
                [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]
            );
            break;
        case 'contact':
            showContactInfo($userId);
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

    $buttons = [];
    foreach ($config['countries'] as $key => $country) {
        $buttons[] = [['text' => $country['name'], 'callback_data' => 'country_' . $key]];
    }
    $buttons[] = [['text' => "\xF0\x9F\x93\x8D Other (Type your country)", 'callback_data' => 'country_other']];
    $buttons[] = [['text' => "\xE2\x8F\xA9 Skip Location", 'callback_data' => 'skip_country']];

    if ($isEdit) {
        $buttons[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'back_to_review']];
    } else {
        $buttons[] = [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_price'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ];
    }

    $tg->sendInlineButtons($userId, "\xF0\x9F\x8C\x8D <b>Select your country:</b>\n\n(You can skip this entire section)", $buttons);
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
    showStatePrompt($userId, $isEdit);
}

function showStatePrompt($userId, $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_state']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_country'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8D Enter your <b>state/region</b>:\n\n(Or tap Skip to continue)",
        $buttons
    );
}

function showCityPrompt($userId, $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_city']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_state'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8D Enter your <b>city</b>:\n\n(Or tap Skip to continue)",
        $buttons
    );
}

function showAddressPrompt($userId, $isEdit = false) {
    global $tg;

    $buttons = [
        [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'skip_address']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'back_to_city'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'cancel'],
        ],
    ];

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8D Enter your <b>address</b>:\n\n(Or tap Skip to continue)",
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

// ============================================================
// PHOTO FUNCTIONS
// ============================================================

function showPhotoPrompt($userId, $isEdit = false) {
    global $tg;

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
    $adId = $db->createAd($userId, $adData);

    $result = publishToOSClass($adData, $userId);

    $title = $adData['title'] ?? 'Untitled';

    if ($result && isset($result['success']) && $result['success']) {
        $db->updateAdStatus($adId, 'published', $result['osclass_id'] ?? null);
    } else {
        $db->updateAdStatus($adId, 'published');
    }

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Your ad has been submitted successfully!</b>\n\n" .
        "\xF0\x9F\x93\x9D Title: <b>{$title}</b>\n\n" .
        "Thank you! Your ad has been published on HabeshaList.com and shared with the community.\n\n" .
        "What would you like to do next?",
        [
            [['text' => "\xE2\x9E\x95 Post Another Ad", 'callback_data' => 'post_another']],
            [['text' => "\xF0\x9F\x93\x8B View My Ads", 'callback_data' => 'view_my_ads']],
            [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
        ]
    );

    $db->setState($userId, 'idle', []);
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
        'photos' => $adData['photos'] ?? [],
    ];

    $ch = curl_init($config['api_bridge_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
// USER ADS
// ============================================================

function showUserAds($userId) {
    global $tg, $db, $config;

    $ads = $db->getUserAds($userId);

    if (empty($ads)) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8B <b>My Ads</b>\n\nYou haven't posted any ads yet.",
            [
                [['text' => "\xE2\x9E\x95 Post an Ad", 'callback_data' => 'post_ad']],
                [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
            ]
        );
        return;
    }

    $text = "\xF0\x9F\x93\x8B <b>My Ads</b>\n\n";
    foreach ($ads as $i => $ad) {
        $num = $i + 1;
        $title = $ad['title'] ?: 'Untitled';
        $status = ucfirst($ad['status']);
        $date = date('M j, Y', strtotime($ad['created_at']));
        $text .= "{$num}. <b>{$title}</b>\n   Status: {$status} | {$date}\n\n";
    }

    $buttons = [];
    foreach ($ads as $i => $ad) {
        if (!empty($ad['osclass_id'])) {
            $num = $i + 1;
            $title = mb_substr($ad['title'] ?: 'Untitled', 0, 20);
            $url = $config['website_url'] . '/index.php?page=item&id=' . $ad['osclass_id'];
            $buttons[] = [['text' => "\xF0\x9F\x94\x97 #{$num} {$title}", 'url' => $url]];
        }
    }
    $buttons[] = [['text' => "\xE2\x9E\x95 Post Another Ad", 'callback_data' => 'post_ad']];
    $buttons[] = [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']];

    $tg->sendInlineButtons($userId, $text, $buttons);
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
            [['text' => "\xF0\x9F\x93\x8B My Ads", 'callback_data' => 'view_my_ads']],
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
            showStatePrompt($userId, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_city_text':
        case 'editing_city_text':
            showCityPrompt($userId, strpos($stateName, 'editing') === 0);
            break;

        case 'awaiting_address_text':
        case 'editing_address_text':
            showAddressPrompt($userId, strpos($stateName, 'editing') === 0);
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
