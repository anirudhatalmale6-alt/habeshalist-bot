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
    $userId = $query['from']['id'];
    $state = $db->getState($userId);

    // ---- Navigation ----

    if ($data === 'main_menu') {
        $user = $db->getUser($userId);
        $db->setState($userId, 'idle', []);
        showMainMenu($userId, $user['name'] ?? 'there');
        return;
    }

    if ($data === 'cancel') {
        $user = $db->getUser($userId);
        $db->setState($userId, 'idle', []);
        showMainMenu($userId, $user['name'] ?? 'there');
        return;
    }

    // ---- Main menu actions ----

    if ($data === 'post_ad') {
        handleAction($userId, 'post_ad');
        return;
    }
    if ($data === 'promote') {
        handleAction($userId, 'promote');
        return;
    }
    if ($data === 'botw') {
        handleAction($userId, 'botw');
        return;
    }
    if ($data === 'contact') {
        handleAction($userId, 'contact');
        return;
    }

    // ---- Category / Subcategory ----

    if ($data === 'cat_back') {
        showCategoryPicker($userId);
        return;
    }

    if (strpos($data, 'cat_') === 0) {
        $catKey = substr($data, 4);
        handleCategorySelect($userId, $catKey, $state);
        return;
    }

    if (strpos($data, 'subcat_') === 0) {
        $subcatKey = substr($data, 7);
        handleSubcategorySelect($userId, $subcatKey, $state);
        return;
    }

    // ---- Location ----

    if (strpos($data, 'loc_') === 0) {
        $locKey = substr($data, 4);
        handleLocationSelect($userId, $locKey, $state);
        return;
    }

    // ---- Back navigation ----

    if ($data === 'back_to_cats') {
        showCategoryPicker($userId);
        return;
    }

    if ($data === 'back_to_subcats') {
        $stateData = $state['data'];
        $catKey = $stateData['category'] ?? '';
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
        $tg->sendInlineButtons($userId,
            "📝 Enter the <b>title</b> for your ad:",
            [
                [
                    ['text' => '⬅️ Back', 'callback_data' => 'back_to_subcats'],
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                ],
            ]
        );
        return;
    }

    if ($data === 'back_to_desc') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_description', $stateData);
        $tg->sendInlineButtons($userId,
            "📝 Enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)",
            [
                [
                    ['text' => '⬅️ Back', 'callback_data' => 'back_to_title'],
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                ],
            ]
        );
        return;
    }

    if ($data === 'back_to_price') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_price', $stateData);
        $tg->sendInlineButtons($userId,
            "💰 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
            [
                [
                    ['text' => '⬅️ Back', 'callback_data' => 'back_to_desc'],
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                ],
            ]
        );
        return;
    }

    if ($data === 'back_to_location') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_location', $stateData);
        showLocationPicker($userId);
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

    if ($data === 'photos_done') {
        handlePhotosDone($userId, $state);
        return;
    }

    if ($data === 'photos_skip') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Review / Publish ----

    if ($data === 'confirm_publish') {
        handlePublish($userId, $state);
        return;
    }

    if ($data === 'edit_ad') {
        showEditOptions($userId, $state);
        return;
    }

    // ---- Edit individual fields ----

    if ($data === 'edit_title') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_title', $stateData);
        $tg->sendInlineButtons($userId,
            "Current title: <b>{$stateData['title']}</b>\n\n📝 Enter the new <b>title</b>:",
            [[['text' => '⬅️ Back to Review', 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_description') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_description', $stateData);
        $tg->sendInlineButtons($userId,
            "Current description:\n{$stateData['description']}\n\n📝 Enter the new <b>description</b>:",
            [[['text' => '⬅️ Back to Review', 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_price') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_price', $stateData);
        $tg->sendInlineButtons($userId,
            "Current price: <b>{$stateData['price']}</b>\n\n💰 Enter the new <b>price</b>:",
            [[['text' => '⬅️ Back to Review', 'callback_data' => 'back_to_review']]]
        );
        return;
    }

    if ($data === 'edit_location') {
        $stateData = $state['data'];
        $db->setState($userId, 'editing_location', $stateData);
        showLocationPicker($userId, true);
        return;
    }

    if ($data === 'edit_photos') {
        $stateData = $state['data'];
        $stateData['photos'] = [];
        $db->setState($userId, 'editing_photos', $stateData);
        showPhotoPrompt($userId, true);
        return;
    }

    if ($data === 'back_to_review') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Edit location select ----

    if (strpos($data, 'eloc_') === 0) {
        $locKey = substr($data, 5);
        $stateData = $state['data'];
        $locName = $config['locations'][$locKey] ?? $locKey;
        $stateData['location'] = $locKey;
        $stateData['location_name'] = $locName;
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Edit photos done ----

    if ($data === 'edit_photos_done') {
        $stateData = $state['data'];
        $db->setState($userId, 'awaiting_review', $stateData);
        showAdReview($userId, $stateData);
        return;
    }

    // ---- Post-publish actions ----

    if ($data === 'post_another') {
        showCategoryPicker($userId);
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

    // /start with deep link in private chat
    if ($isPrivate && strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        $param = $parts[1] ?? '';

        $user = $db->getUser($userId);

        if (!$user) {
            $db->setState($userId, 'reg_name', ['intended_action' => $param]);
            $tg->sendMessage($userId,
                "👋 Welcome to HabeshaList.com!\n\n" .
                "Before we begin, let's create your free account.\n\n" .
                "📝 Please enter your <b>full name</b>:"
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
                "👋 Welcome to HabeshaList.com!\n\n" .
                "Let's create your free account first.\n\n" .
                "📝 Please enter your <b>full name</b>:"
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
            $tg->sendInlineButtons($userId,
                "📝 Now enter a <b>description</b> for your ad:\n\n(Describe what you're offering in detail)",
                [
                    [
                        ['text' => '⬅️ Back', 'callback_data' => 'back_to_title'],
                        ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                    ],
                ]
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
                "💰 Enter the <b>price</b>:\n\n(Enter a number, or type \"Free\" or \"Negotiable\")",
                [
                    [
                        ['text' => '⬅️ Back', 'callback_data' => 'back_to_desc'],
                        ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                    ],
                ]
            );
            break;

        case 'awaiting_price':
            $stateData['price'] = $text;
            $db->setState($userId, 'awaiting_location', $stateData);
            showLocationPicker($userId);
            break;

        case 'awaiting_location_other':
            if (strlen($text) < 2) {
                $tg->sendMessage($userId, "Please enter a valid location:");
                return;
            }
            $stateData['location'] = 'other';
            $stateData['location_name'] = $text;
            $db->setState($userId, 'awaiting_photos', $stateData);
            $stateData['photos'] = [];
            $db->setState($userId, 'awaiting_photos', $stateData);
            showPhotoPrompt($userId);
            break;

        case 'awaiting_photos':
            if (isset($msg['photo'])) {
                $photo = end($msg['photo']);
                $stateData['photos'] = $stateData['photos'] ?? [];
                $stateData['photos'][] = $photo['file_id'];
                $count = count($stateData['photos']);
                $db->setState($userId, 'awaiting_photos', $stateData);

                $buttons = [
                    [['text' => '✅ Done', 'callback_data' => 'photos_done']],
                ];
                if ($count < 5) {
                    $buttons[] = [['text' => '❌ Cancel', 'callback_data' => 'cancel']];
                }

                $tg->sendInlineButtons($userId,
                    "📸 Photo {$count}/5 received! Send more photos or tap Done.",
                    $buttons
                );
                return;
            }
            if ($text) {
                $tg->sendMessage($userId, "Please send a photo, or tap one of the buttons above.");
            }
            break;

        // --- EDIT FLOW ---

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

        case 'editing_photos':
            if (isset($msg['photo'])) {
                $photo = end($msg['photo']);
                $stateData['photos'] = $stateData['photos'] ?? [];
                $stateData['photos'][] = $photo['file_id'];
                $count = count($stateData['photos']);
                $db->setState($userId, 'editing_photos', $stateData);

                $tg->sendInlineButtons($userId,
                    "📸 Photo {$count}/5 received! Send more or tap Done.",
                    [
                        [['text' => '✅ Done', 'callback_data' => 'edit_photos_done']],
                        [['text' => '❌ Cancel', 'callback_data' => 'back_to_review']],
                    ]
                );
                return;
            }
            if ($text) {
                $tg->sendMessage($userId, "Please send a photo, or tap one of the buttons above.");
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

function handlePhotoMessage($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    if ($state['state'] !== 'awaiting_photos' && $state['state'] !== 'editing_photos') return false;

    $photo = end($msg['photo']);
    $stateData = $state['data'];
    $stateData['photos'] = $stateData['photos'] ?? [];
    $stateData['photos'][] = $photo['file_id'];
    $count = count($stateData['photos']);
    $db->setState($userId, $state['state'], $stateData);

    $doneCallback = ($state['state'] === 'editing_photos') ? 'edit_photos_done' : 'photos_done';
    $cancelCallback = ($state['state'] === 'editing_photos') ? 'back_to_review' : 'cancel';

    $tg->sendInlineButtons($userId,
        "📸 Photo {$count}/5 received! Send more or tap Done.",
        [
            [['text' => '✅ Done', 'callback_data' => $doneCallback]],
            [['text' => '❌ Cancel', 'callback_data' => $cancelCallback]],
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
            $tg->sendInlineButtons($userId,
                "📢 <b>Promote Your Business</b>\n\nThis feature is coming soon! Stay tuned.",
                [[['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']]]
            );
            break;
        case 'botw':
            $tg->sendInlineButtons($userId,
                "🏆 <b>Business of the Week</b>\n\nThis feature is coming soon! Stay tuned.",
                [[['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']]]
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
        $tg->sendInlineButtons($userId,
            "✅ Category: <b>{$cat['name']}</b>\n\n📝 Enter the <b>title</b> for your ad:",
            [
                [
                    ['text' => '⬅️ Back', 'callback_data' => 'cat_back'],
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                ],
            ]
        );
        return;
    }

    $buttons = [];
    foreach ($cat['subcategories'] as $key => $name) {
        $buttons[] = [['text' => $name, 'callback_data' => 'subcat_' . $key]];
    }
    $buttons[] = [
        ['text' => '⬅️ Back', 'callback_data' => 'cat_back'],
        ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
    ];

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
    $tg->sendInlineButtons($userId,
        "✅ Category: <b>{$stateData['category_name']}</b>\n" .
        "📂 Subcategory: <b>{$subcatName}</b>\n\n" .
        "📝 Enter the <b>title</b> for your ad:",
        [
            [
                ['text' => '⬅️ Back', 'callback_data' => 'back_to_subcats'],
                ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
            ],
        ]
    );
}

function showLocationPicker($userId, $isEdit = false) {
    global $tg, $db, $config;

    $buttons = [];
    $row = [];
    foreach ($config['locations'] as $key => $name) {
        if ($key === 'other') continue;
        $prefix = $isEdit ? 'eloc_' : 'loc_';
        $row[] = ['text' => $name, 'callback_data' => $prefix . $key];
        if (count($row) === 2) {
            $buttons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) $buttons[] = $row;

    $prefix = $isEdit ? 'eloc_' : 'loc_';
    $buttons[] = [['text' => '📍 Other (Type your location)', 'callback_data' => $prefix . 'other']];

    if ($isEdit) {
        $buttons[] = [['text' => '⬅️ Back to Review', 'callback_data' => 'back_to_review']];
    } else {
        $buttons[] = [
            ['text' => '⬅️ Back', 'callback_data' => 'back_to_price'],
            ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
        ];
    }

    $tg->sendInlineButtons($userId, "📍 <b>Select your location:</b>", $buttons);
}

function handleLocationSelect($userId, $locKey, $state) {
    global $tg, $db, $config;

    $stateData = $state['data'];

    if ($locKey === 'other') {
        $db->setState($userId, 'awaiting_location_other', $stateData);
        $tg->sendInlineButtons($userId,
            "📍 Type your <b>location</b> (city, state, or country):",
            [
                [
                    ['text' => '⬅️ Back', 'callback_data' => 'back_to_location'],
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel'],
                ],
            ]
        );
        return;
    }

    $locName = $config['locations'][$locKey] ?? $locKey;
    $stateData['location'] = $locKey;
    $stateData['location_name'] = $locName;

    $stateData['photos'] = [];
    $db->setState($userId, 'awaiting_photos', $stateData);
    showPhotoPrompt($userId);
}

function showPhotoPrompt($userId, $isEdit = false) {
    global $tg;

    $doneCallback = $isEdit ? 'edit_photos_done' : 'photos_skip';
    $cancelCallback = $isEdit ? 'back_to_review' : 'cancel';
    $doneText = $isEdit ? '⏩ Skip Photos' : '⏩ Skip - No Photos';

    $tg->sendInlineButtons($userId,
        "📸 <b>Upload Photos for Your Ad</b>\n\n" .
        "Tap the attachment button in Telegram to select and send your photos.\n\n" .
        "- Send one photo at a time (up to 5 photos)\n" .
        "- When finished, tap Done\n" .
        "- Or tap Skip if you don't want to add photos",
        [
            [['text' => $doneText, 'callback_data' => $doneCallback]],
            [['text' => '❌ Cancel', 'callback_data' => $cancelCallback]],
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

function showEditOptions($userId, $state) {
    global $tg;

    $tg->sendInlineButtons($userId,
        "✏️ <b>What would you like to edit?</b>",
        [
            [
                ['text' => '📝 Title', 'callback_data' => 'edit_title'],
                ['text' => '📄 Description', 'callback_data' => 'edit_description'],
            ],
            [
                ['text' => '💰 Price', 'callback_data' => 'edit_price'],
                ['text' => '📍 Location', 'callback_data' => 'edit_location'],
            ],
            [['text' => '📸 Photos', 'callback_data' => 'edit_photos']],
            [['text' => '⬅️ Back to Review', 'callback_data' => 'back_to_review']],
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

        $tg->sendInlineButtons($userId,
            "✅ <b>Your ad has been submitted successfully!</b>\n\n" .
            "📝 Title: <b>{$title}</b>\n\n" .
            "Thank you! Your ad has been published on HabeshaList.com and shared with the community.\n\n" .
            "What would you like to do next?",
            [
                [['text' => '➕ Post Another Ad', 'callback_data' => 'post_another']],
                [['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']],
            ]
        );
    } else {
        $db->updateAdStatus($adId, 'published');

        $tg->sendInlineButtons($userId,
            "✅ <b>Your ad has been submitted successfully!</b>\n\n" .
            "📝 Title: <b>{$title}</b>\n\n" .
            "Thank you! Your ad has been published on HabeshaList.com and shared with the community.\n\n" .
            "What would you like to do next?",
            [
                [['text' => '➕ Post Another Ad', 'callback_data' => 'post_another']],
                [['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']],
            ]
        );
    }

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
        'location' => $adData['location_name'] ?? $adData['location'] ?? '',
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
// MENUS & UI
// ============================================================

function showMainMenu($userId, $name) {
    global $tg, $config;

    $tg->sendInlineButtons($userId,
        "🏠 <b>Welcome to HabeshaList.com!</b>\n\n" .
        "The Habesha marketplace where you can:\n" .
        "- Find housing\n" .
        "- Discover jobs\n" .
        "- Buy and Sell\n" .
        "- Promote your business\n" .
        "- Connect with the community\n\n" .
        "All in one place. Tap a button below to get started:",
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

    $tg->sendInlineButtons($userId,
        "📞 <b>Contact the HabeshaList Team</b>\n\n" .
        "Need assistance or have a question? We're here to help!\n\n" .
        "💬 Telegram Support: @Habesha_list\n" .
        "📱 WhatsApp: HabeshaList_Beti\n" .
        "📧 Email: info@habeshalist.com\n" .
        "🌐 Website: https://habeshalist.com\n\n" .
        "📱 <b>Follow HabeshaList</b>\n\n" .
        "📘 Facebook: https://www.facebook.com/beti.negatu/\n" .
        "📸 Instagram: https://www.instagram.com/beti_negatu/\n" .
        "🎵 TikTok: https://www.tiktok.com/@habeshalistofficial\n" .
        "▶️ YouTube: https://www.youtube.com/@HabeshaListOfficial\n" .
        "💼 LinkedIn: https://www.linkedin.com/in/habesha-list/\n\n" .
        "⏰ <b>Support Hours</b>\n" .
        "Monday - Friday, 9:00 AM - 5:00 PM (ET)\n\n" .
        "Thank you for being part of the HabeshaList community! 🙏",
        [
            [['text' => '🏠 Main Menu', 'callback_data' => 'main_menu']],
        ]
    );
}

function sendGroupButtons($chatId) {
    global $tg;

    $botUsername = getBotUsername();

    $tg->sendInlineButtons($chatId,
        "🌟 <b>Welcome to HabeshaList.com</b> 🌟\n\n" .
        "The Habesha marketplace where you can:\n" .
        "- Find housing\n" .
        "- Discover jobs\n" .
        "- Buy and Sell\n" .
        "- Promote your business\n" .
        "- Connect with the community\n\n" .
        "All in one place. Tap a button below to get started:",
        [
            [['text' => '📝 Post to Website (FREE)', 'url' => "https://t.me/{$botUsername}?start=post_ad"]],
            [['text' => '📢 Promote My Business', 'url' => "https://t.me/{$botUsername}?start=promote"]],
            [['text' => '🏆 Business of the Week', 'url' => "https://t.me/{$botUsername}?start=botw"]],
            [['text' => '📞 Contact Us', 'url' => "https://t.me/{$botUsername}?start=contact"]],
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
