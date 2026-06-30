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

// Handle callback queries (inline button taps)
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    exit;
}

// Handle regular messages
if (isset($update['message'])) {
    handleMessage($update['message']);
    exit;
}

http_response_code(200);
exit;

// ============================================================
// CALLBACK QUERY HANDLER (inline button taps)
// ============================================================

function handleCallbackQuery($query) {
    global $tg, $db, $config;

    $tg->answerCallbackQuery($query['id']);

    $data = $query['data'];
    $chatId = $query['message']['chat']['id'];
    $userId = $query['from']['id'];

    // Group button taps — redirect to private chat
    if (in_array($data, ['post_ad', 'promote', 'botw', 'contact'])) {
        $botUsername = getBotUsername();
        $deepLink = "https://t.me/{$botUsername}?start={$data}";
        $tg->sendMessage($chatId, "👉 <a href=\"{$deepLink}\">Tap here to continue in private chat</a>");
        return;
    }

    // Private chat callbacks
    $state = $db->getState($userId);

    // Back to categories
    if ($data === 'cat_back') {
        showCategoryPicker($userId);
        return;
    }

    // Category selection
    if (strpos($data, 'cat_') === 0) {
        $catKey = substr($data, 4);
        handleCategorySelect($userId, $catKey, $state);
        return;
    }

    // Subcategory selection
    if (strpos($data, 'subcat_') === 0) {
        $subcatKey = substr($data, 7);
        handleSubcategorySelect($userId, $subcatKey, $state);
        return;
    }

    // Location selection
    if (strpos($data, 'loc_') === 0) {
        $locKey = substr($data, 4);
        handleLocationSelect($userId, $locKey, $state);
        return;
    }

    // Confirm publish
    if ($data === 'confirm_publish') {
        handlePublish($userId, $state);
        return;
    }

    // Edit ad before publishing
    if ($data === 'edit_ad') {
        handleEditAd($userId, $state);
        return;
    }

    // Cancel
    if ($data === 'cancel') {
        $db->setState($userId, 'idle', []);
        $tg->sendMessage($userId, "❌ Cancelled. Send /menu to start over.");
        return;
    }

    // Done uploading photos
    if ($data === 'photos_done') {
        handlePhotosDone($userId, $state);
        return;
    }

    // Skip photos
    if ($data === 'photos_skip') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }
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

    // Handle /start with deep link in private chat
    if ($isPrivate && strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        $param = $parts[1] ?? '';

        $user = $db->getUser($userId);

        if (!$user) {
            // New user — start registration, remember intended action
            $db->setState($userId, 'reg_name', ['intended_action' => $param]);
            $botName = $config['bot_name'];
            $tg->sendMessage($userId,
                "👋 Welcome to HabeshaList.com!\n\n" .
                "Before we begin, let's create your free account.\n\n" .
                "📝 Please enter your <b>full name</b>:"
            );
            return;
        }

        // Existing user — go to intended action or main menu
        if ($param) {
            handleAction($userId, $param);
        } else {
            showMainMenu($userId, $user['name']);
        }
        return;
    }

    // Handle /menu in private chat
    if ($isPrivate && $text === '/menu') {
        $user = $db->getUser($userId);
        if ($user) {
            showMainMenu($userId, $user['name']);
        } else {
            $db->setState($userId, 'reg_name', ['intended_action' => '']);
            $tg->sendMessage($userId,
                "👋 Welcome to HabeshaList.com!\n\n" .
                "Let's create your free account first.\n\n" .
                "📝 Please enter your <b>full name</b>:"
            );
        }
        return;
    }

    // Handle /setup in group (sends the pinned message with inline buttons)
    if (!$isPrivate && ($text === '/setup' || strpos($text, '/setup@') === 0)) {
        if (isAdmin($userId)) {
            sendGroupButtons($chatId);
        }
        return;
    }

    // Handle photo messages in private chat
    if ($isPrivate && isset($msg['photo'])) {
        if (handlePhotoMessage($userId, $msg)) return;
    }

    // Handle state-based conversation in private chat
    if ($isPrivate) {
        handleStateInput($userId, $msg);
    }
}

// ============================================================
// REGISTRATION FLOW
// ============================================================

function handleStateInput($userId, $msg) {
    global $tg, $db, $config;

    $state = $db->getState($userId);
    $text = trim($msg['text'] ?? '');
    $stateData = $state['data'];

    switch ($state['state']) {

        case 'reg_name':
            if (strlen($text) < 2) {
                $tg->sendMessage($userId, "Please enter a valid name (at least 2 characters):");
                return;
            }
            $stateData['name'] = $text;
            $db->setState($userId, 'reg_phone', $stateData);
            $tg->sendMessage($userId, "📱 Great, {$text}! Now please enter your <b>phone number</b>:");
            break;

        case 'reg_phone':
            $phone = preg_replace('/[^0-9+\-\s()]/', '', $text);
            if (strlen($phone) < 7) {
                $tg->sendMessage($userId, "Please enter a valid phone number:");
                return;
            }
            $stateData['phone'] = $phone;
            $db->setState($userId, 'reg_email', $stateData);
            $tg->sendMessage($userId, "📧 Almost done! Please enter your <b>email address</b>:");
            break;

        case 'reg_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $tg->sendMessage($userId, "Please enter a valid email address:");
                return;
            }
            $stateData['email'] = $text;
            $db->createUser($userId, $stateData['name'], $stateData['phone'], $stateData['email']);
            $db->setState($userId, 'idle', []);

            $tg->sendMessage($userId,
                "✅ Account created successfully!\n\n" .
                "Welcome, <b>{$stateData['name']}</b>! You're all set."
            );

            $intended = $stateData['intended_action'] ?? '';
            if ($intended) {
                handleAction($userId, $intended);
            } else {
                showMainMenu($userId, $stateData['name']);
            }
            break;

        // --- AD POSTING FLOW ---

        case 'awaiting_title':
            if (strlen($text) < 3) {
                $tg->sendMessage($userId, "Title must be at least 3 characters. Please enter the <b>title</b>:");
                return;
            }
            $stateData['title'] = $text;
            $db->setState($userId, 'awaiting_description', $stateData);
            $tg->sendMessage($userId, "📝 Now enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)");
            break;

        case 'awaiting_description':
            if (strlen($text) < 10) {
                $tg->sendMessage($userId, "Description must be at least 10 characters. Please provide more detail:");
                return;
            }
            $stateData['description'] = $text;
            $db->setState($userId, 'awaiting_price', $stateData);
            $tg->sendMessage($userId, "💰 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")");
            break;

        case 'awaiting_price':
            $stateData['price'] = $text;
            $db->setState($userId, 'awaiting_location', $stateData);
            showLocationPicker($userId);
            break;

        case 'awaiting_photos':
            // Handle photo uploads
            if (isset($msg['photo'])) {
                $photo = end($msg['photo']);
                $stateData['photos'] = $stateData['photos'] ?? [];
                $stateData['photos'][] = $photo['file_id'];
                $count = count($stateData['photos']);
                $db->setState($userId, 'awaiting_photos', $stateData);

                $tg->sendInlineButtons($userId,
                    "📸 Photo {$count} received! Send more photos or tap Done.",
                    [
                        [['text' => '✅ Done', 'callback_data' => 'photos_done']],
                    ]
                );
                return;
            }

            if ($text) {
                $tg->sendMessage($userId, "Please send a photo, or tap one of the buttons below.");
            }
            break;

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

// Handle photo messages in awaiting_photos state
function handlePhotoMessage($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    if ($state['state'] !== 'awaiting_photos') return false;

    $photo = end($msg['photo']);
    $stateData = $state['data'];
    $stateData['photos'] = $stateData['photos'] ?? [];
    $stateData['photos'][] = $photo['file_id'];
    $count = count($stateData['photos']);
    $db->setState($userId, 'awaiting_photos', $stateData);

    $tg->sendInlineButtons($userId,
        "📸 Photo {$count} received! Send more or tap Done.",
        [
            [['text' => '✅ Done - Proceed to Review', 'callback_data' => 'photos_done']],
        ]
    );
    return true;
}

// ============================================================
// AD POSTING FLOW HANDLERS
// ============================================================

function handleAction($userId, $action) {
    global $tg, $db, $config;

    switch ($action) {
        case 'post_ad':
            showCategoryPicker($userId);
            break;
        case 'promote':
            $tg->sendMessage($userId, "📢 <b>Promote Your Business</b>\n\nThis feature is coming soon in Phase 2! Stay tuned.");
            break;
        case 'botw':
            $tg->sendMessage($userId, "🏆 <b>Business of the Week</b>\n\nThis feature is coming soon in Phase 2! Stay tuned.");
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

function showCategoryPicker($userId) {
    global $tg, $db, $config;

    $buttons = [];
    foreach ($config['categories'] as $key => $cat) {
        $buttons[] = [['text' => $cat['icon'] . ' ' . $cat['name'], 'callback_data' => 'cat_' . $key]];
    }
    $buttons[] = [['text' => '❌ Cancel', 'callback_data' => 'cancel']];

    $db->setState($userId, 'awaiting_category', []);
    $tg->sendInlineButtons($userId, "📋 <b>Select a category:</b>", $buttons);
}

function handleCategorySelect($userId, $catKey, $state) {
    global $tg, $db, $config;

    $cat = $config['categories'][$catKey] ?? null;
    if (!$cat) return;

    $stateData = ['category' => $catKey, 'category_name' => $cat['name']];

    if (empty($cat['subcategories'])) {
        $db->setState($userId, 'awaiting_title', $stateData);
        $tg->sendMessage($userId, "✅ Category: <b>{$cat['name']}</b>\n\n📝 Now enter the <b>title</b> for your ad:");
        return;
    }

    $buttons = [];
    foreach ($cat['subcategories'] as $key => $name) {
        $buttons[] = [['text' => $name, 'callback_data' => 'subcat_' . $key]];
    }
    $buttons[] = [['text' => '⬅️ Back to Categories', 'callback_data' => 'cat_back']];

    $db->setState($userId, 'awaiting_subcategory', $stateData);
    $tg->sendInlineButtons($userId, "📂 <b>{$cat['name']}</b>\n\nSelect a subcategory:", $buttons);
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
    $tg->sendMessage($userId,
        "✅ Category: <b>{$stateData['category_name']}</b>\n" .
        "📂 Subcategory: <b>{$subcatName}</b>\n\n" .
        "📝 Now enter the <b>title</b> for your ad:"
    );
}

function showLocationPicker($userId) {
    global $tg, $config;

    $buttons = [];
    $row = [];
    foreach ($config['locations'] as $key => $name) {
        $row[] = ['text' => '📍 ' . $name, 'callback_data' => 'loc_' . $key];
        if (count($row) === 2) {
            $buttons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) $buttons[] = $row;

    $tg->sendInlineButtons($userId, "📍 <b>Select your location:</b>", $buttons);
}

function handleLocationSelect($userId, $locKey, $state) {
    global $tg, $db, $config;

    $stateData = $state['data'];
    $locName = $config['locations'][$locKey] ?? $locKey;
    $stateData['location'] = $locKey;
    $stateData['location_name'] = $locName;

    $db->setState($userId, 'awaiting_photos', $stateData);
    $stateData['photos'] = [];
    $db->setState($userId, 'awaiting_photos', $stateData);

    $tg->sendInlineButtons($userId,
        "📸 <b>Upload photos</b> for your ad.\n\nSend photos one at a time (up to 5). When done, tap the button below.",
        [
            [['text' => '✅ Done - No Photos', 'callback_data' => 'photos_skip']],
        ]
    );
}

function handlePhotosDone($userId, $state) {
    global $db;

    $stateData = $state['data'];
    $db->setState($userId, 'awaiting_review', $stateData);
    showAdReview($userId, $stateData);
}

function showAdReview($userId, $adData) {
    global $tg;

    $photoCount = count($adData['photos'] ?? []);
    $photoText = $photoCount > 0 ? "{$photoCount} photo(s)" : "No photos";

    $review = "📋 <b>Review Your Ad</b>\n\n" .
        "📂 Category: <b>{$adData['category_name']}</b>\n" .
        (isset($adData['subcategory_name']) ? "📁 Subcategory: <b>{$adData['subcategory_name']}</b>\n" : '') .
        "📝 Title: <b>{$adData['title']}</b>\n" .
        "📄 Description: {$adData['description']}\n" .
        "💰 Price: <b>{$adData['price']}</b>\n" .
        "📍 Location: <b>{$adData['location_name']}</b>\n" .
        "📸 Photos: {$photoText}\n\n" .
        "Does everything look correct?";

    $tg->sendInlineButtons($userId, $review, [
        [
            ['text' => '✅ Publish', 'callback_data' => 'confirm_publish'],
            ['text' => '✏️ Edit', 'callback_data' => 'edit_ad'],
        ],
        [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
    ]);
}

function handlePublish($userId, $state) {
    global $tg, $db, $config;

    $adData = $state['data'];
    $adId = $db->createAd($userId, $adData);

    // Send to OSClass via the API bridge
    $result = publishToOSClass($adData, $userId);

    if ($result && isset($result['success']) && $result['success']) {
        $db->updateAdStatus($adId, 'published', $result['osclass_id'] ?? null);
        $listingUrl = $result['url'] ?? $config['website_url'];
        $tg->sendMessage($userId,
            "🎉 <b>Your ad has been published!</b>\n\n" .
            "📝 {$adData['title']}\n\n" .
            "🔗 View it on HabeshaList.com\n\n" .
            "Send /menu to post another ad."
        );
    } else {
        $db->updateAdStatus($adId, 'pending');
        $tg->sendMessage($userId,
            "✅ <b>Your ad has been submitted!</b>\n\n" .
            "📝 {$adData['title']}\n\n" .
            "Your ad is being reviewed and will appear on HabeshaList.com shortly.\n\n" .
            "Send /menu to post another ad."
        );
    }

    $db->setState($userId, 'idle', []);
}

function handleEditAd($userId, $state) {
    global $tg, $db;

    $db->setState($userId, 'idle', []);
    $tg->sendMessage($userId, "Let's start over. Send /menu to begin a new ad.");
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
        'location' => $adData['location'] ?? '',
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
// MENUS & UI
// ============================================================

function showMainMenu($userId, $name) {
    global $tg, $config;

    $botName = $config['bot_name'];
    $tg->sendInlineButtons($userId,
        "🏠 <b>Welcome to HabeshaList.com!</b>\n\n" .
        "Hi <b>{$name}</b>, I'm {$botName}, your HabeshaList assistant! 🤖\n\n" .
        "What would you like to do?",
        [
            [['text' => '📝 Post to Website (Free)', 'callback_data' => 'post_ad']],
            [['text' => '📢 Promote My Business', 'callback_data' => 'promote']],
            [['text' => '🏆 Business of the Week', 'callback_data' => 'botw']],
            [['text' => '📞 Contact Us', 'callback_data' => 'contact']],
        ]
    );
}

function showContactInfo($userId) {
    global $tg;

    $tg->sendMessage($userId,
        "📞 <b>Contact Us</b>\n\n" .
        "We're here to help! Reach us through:\n\n" .
        "📱 Telegram: @HabeshaList\n" .
        "🌐 Website: www.habeshalist.com\n\n" .
        "Send /menu to go back."
    );
}

function sendGroupButtons($chatId) {
    global $tg;

    $tg->sendInlineButtons($chatId,
        "🌟 <b>Welcome to HabeshaList.com!</b> 🌟\n\n" .
        "The #1 Habesha Listing & Community.\n" .
        "Buy, sell, rent, promote your business, and connect with the Habesha community — all in one place.\n\n" .
        "To get started, simply choose one of the options below:",
        [
            [['text' => '📝 Post to Website (FREE)', 'callback_data' => 'post_ad']],
            [['text' => '📢 Promote My Business', 'callback_data' => 'promote']],
            [['text' => '🏆 Business of the Week', 'callback_data' => 'botw']],
            [['text' => '📞 Contact Us', 'callback_data' => 'contact']],
        ]
    );
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
