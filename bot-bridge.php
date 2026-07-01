<?php

// ============================================================
// OSCLASS API BRIDGE
// ============================================================
// This file goes in your OSClass root directory.
// It receives ad submissions from the Telegram bot and
// inserts them into the OSClass database.
//
// SECURITY: Protected by a secret API key. Only requests
// with the correct key are accepted.
// ============================================================

header('Content-Type: application/json');

// Your secret key — must match the one in the bot's config.php
$API_SECRET = '717e34f13a2589d049d43149649e2668318e4949712b6f2f7e9cd94e28ad8f07';

// Read incoming request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['secret']) || $data['secret'] !== $API_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $data['action'] ?? '';

if ($action === 'create_listing') {
    createListing($data);
} elseif ($action === 'get_categories') {
    getCategories();
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function createListing($data) {
    // Load OSClass bootstrap to use its functions
    // Adjust the path if your OSClass installation is in a subdirectory
    $osclassPath = __DIR__ . '/oc-load.php';

    if (!file_exists($osclassPath)) {
        echo json_encode(['success' => false, 'error' => 'OSClass not found at expected path']);
        return;
    }

    define('OC_ADMIN', true);
    require_once $osclassPath;

    try {
        // Map bot category/subcategory to OSClass category IDs
        // This mapping needs to be configured based on your OSClass category IDs
        $categoryId = getCategoryId($data['category'] ?? '', $data['subcategory'] ?? '');

        if (!$categoryId) {
            echo json_encode(['success' => false, 'error' => 'Category not found']);
            return;
        }

        // Get or create user in OSClass
        $email = '';
        $contactName = '';
        // You can extend this to look up the user by telegram_id

        // Insert the listing using OSClass model
        $itemData = [
            'catId' => $categoryId,
            'title' => $data['title'] ?? 'Untitled',
            'description' => $data['description'] ?? '',
            'price' => floatval(preg_replace('/[^0-9.]/', '', $data['price'] ?? '0')),
            'currency' => 'USD',
            'contactName' => $data['contact_name'] ?? 'HabeshaList User',
            'contactEmail' => $data['contact_email'] ?? '',
        ];

        // Use OSClass Item model to insert
        $item = Item::newInstance();
        $result = $item->insert([
            'fk_i_user_id' => null,
            'dt_pub_date' => date('Y-m-d H:i:s'),
            'dt_mod_date' => date('Y-m-d H:i:s'),
            'f_price' => $itemData['price'],
            'fk_c_currency_code' => 'USD',
            's_contact_name' => $itemData['contactName'],
            's_contact_email' => $itemData['contactEmail'],
            'b_enabled' => 1,
            'b_active' => 1,
            'b_spam' => 0,
            'fk_i_category_id' => $categoryId,
        ]);

        if ($result) {
            $itemId = $item->dao->insertedId();

            // Insert title and description
            $item->insertDescription($itemId, 'en_US', $itemData['title'], $itemData['description']);

            // Set location if available
            if (!empty($data['location'])) {
                ItemLocation::newInstance()->insert([
                    'fk_i_item_id' => $itemId,
                    's_city' => $data['location'],
                    's_country' => 'US',
                ]);
            }

            echo json_encode([
                'success' => true,
                'osclass_id' => $itemId,
                'url' => osc_item_url_from_item(['pk_i_id' => $itemId]),
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to insert listing']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getCategoryId($category, $subcategory) {
    // This is a basic mapping — update these IDs to match your OSClass installation
    // You can find the IDs in your OSClass admin panel under Categories
    // Or query: SELECT * FROM oc_t_category_description;

    $categoryMap = [
        'housing' => ['_id' => 0, 'rent' => 0, 'sale' => 0, 'shops' => 0, 'other_housing' => 0],
        'services' => ['_id' => 0, 'beauty' => 0, 'car_repair' => 0, 'babysitter' => 0],
        'personals' => ['_id' => 0, 'friendship' => 0, 'missed' => 0],
        'classes' => ['_id' => 0, 'computer' => 0, 'tutoring' => 0],
        'community' => ['_id' => 0, 'events' => 0, 'donation' => 0],
        'forsale' => ['_id' => 0, 'cars' => 0, 'ethiopian' => 0, 'electronics' => 0],
        'jobs' => ['_id' => 0, 'sales' => 0, 'accounting' => 0],
        'luggage' => ['_id' => 0],
    ];

    if (isset($categoryMap[$category])) {
        if ($subcategory && isset($categoryMap[$category][$subcategory])) {
            return $categoryMap[$category][$subcategory];
        }
        return $categoryMap[$category]['_id'];
    }

    return null;
}

function getCategories() {
    $osclassPath = __DIR__ . '/oc-load.php';
    if (!file_exists($osclassPath)) {
        echo json_encode(['success' => false, 'error' => 'OSClass not found']);
        return;
    }

    require_once $osclassPath;

    $categories = Category::newInstance()->listAll();
    echo json_encode(['success' => true, 'categories' => $categories]);
}
