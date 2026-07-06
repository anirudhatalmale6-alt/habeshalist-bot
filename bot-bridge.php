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
    $osclassPath = __DIR__ . '/oc-load.php';

    if (!file_exists($osclassPath)) {
        echo json_encode(['success' => false, 'error' => 'OSClass not found at expected path']);
        return;
    }

    define('OC_ADMIN', true);
    require_once $osclassPath;

    try {
        $categoryId = getCategoryId($data['category'] ?? '', $data['subcategory'] ?? '');

        if ($categoryId === null) {
            echo json_encode(['success' => false, 'error' => 'Category not found']);
            return;
        }

        $title = $data['title'] ?? 'Untitled';
        $description = $data['description'] ?? '';
        $priceRaw = $data['price'] ?? '0';
        $price = floatval(preg_replace('/[^0-9.]/', '', $priceRaw));
        if (in_array(strtolower($priceRaw), ['free', 'negotiable'])) {
            $price = 0;
        }
        $contactName = $data['contact_name'] ?? 'HabeshaList User';
        $contactEmail = $data['contact_email'] ?? '';
        $location = $data['location'] ?? '';
        $secret = md5(uniqid(rand(), true));

        $item = Item::newInstance();
        $insertResult = $item->insert([
            'fk_i_user_id' => null,
            'dt_pub_date' => date('Y-m-d H:i:s'),
            'dt_mod_date' => date('Y-m-d H:i:s'),
            'f_price' => $price,
            'fk_c_currency_code' => 'USD',
            's_contact_name' => $contactName,
            's_contact_email' => $contactEmail,
            'b_enabled' => 1,
            'b_active' => 1,
            'b_spam' => 0,
            'fk_i_category_id' => $categoryId,
            's_secret' => $secret,
        ]);

        if (!$insertResult) {
            echo json_encode(['success' => false, 'error' => 'Item insert failed']);
            return;
        }

        $itemId = $item->dao->insertedId();

        if (!$itemId) {
            echo json_encode(['success' => false, 'error' => 'Could not get item ID']);
            return;
        }

        $prefix = DB_TABLE_PREFIX;
        $dao = Item::newInstance()->dao;
        $escTitle = addslashes($title);
        $escDesc = addslashes($description);

        $dao->query(
            "INSERT INTO {$prefix}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) " .
            "VALUES ({$itemId}, 'en_US', '{$escTitle}', '{$escDesc}')"
        );

        if (!empty($location)) {
            $escLoc = addslashes($location);
            $dao->query(
                "INSERT INTO {$prefix}t_item_location (fk_i_item_id, s_city, fk_c_country_code, s_country) " .
                "VALUES ({$itemId}, '{$escLoc}', 'US', 'United States')"
            );
        }

        $photos = $data['photos'] ?? [];
        $botToken = 'REDACTED';
        $uploadDir = osc_content_path() . 'uploads/';

        foreach ($photos as $idx => $fileId) {
            $fileInfo = json_decode(file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}"), true);
            if (!$fileInfo || !$fileInfo['ok']) continue;

            $filePath = $fileInfo['result']['file_path'];
            $imageData = file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}");
            if (!$imageData) continue;

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $resourceName = $itemId . '_' . $idx;
            $fileName = $resourceName . '.' . $ext;

            file_put_contents($uploadDir . $fileName, $imageData);

            $dao->query(
                "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path) " .
                "VALUES ({$itemId}, '{$resourceName}', '{$ext}', 'image/{$ext}', 'oc-content/uploads/')"
            );

            $resourceId = $dao->insertedId();
            $originalName = $resourceName . '_original.' . $ext;
            copy($uploadDir . $fileName, $uploadDir . $originalName);

            $thumbName = $resourceName . '_thumbnail.' . $ext;
            copy($uploadDir . $fileName, $uploadDir . $thumbName);
        }

        if (class_exists('CategoryStats')) {
            CategoryStats::newInstance()->increaseNumItems($categoryId);
        }

        echo json_encode([
            'success' => true,
            'osclass_id' => $itemId,
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getCategoryId($category, $subcategory) {
    // This is a basic mapping — update these IDs to match your OSClass installation
    // You can find the IDs in your OSClass admin panel under Categories
    // Or query: SELECT * FROM oc_t_category_description;

    $categoryMap = [
        'housing' => ['_id' => 4, 'rent' => 44, 'sale' => 43, 'shops' => 51, 'other_housing' => 49, 'vacation' => 47],
        'services' => ['_id' => 5, 'beauty' => 52, 'car_repair' => 53, 'babysitter' => 97, 'transportation' => 54, 'electronics_repair' => 55, 'dj_music' => 57, 'doctors' => 58, 'tax_finance' => 60, 'grocery' => 61, 'restaurant' => 11, 'legal' => 96, 'other_services' => 62],
        'personals' => ['_id' => 7, 'friendship' => 73, 'missed' => 74],
        'classes' => ['_id' => 3, 'computer' => 38, 'language' => 39, 'tutoring' => 42],
        'community' => ['_id' => 6, 'events' => 63, 'donation' => 65, 'others' => 66],
        'forsale' => ['_id' => 1, 'cars' => 12, 'ethiopian' => 13, 'electronics' => 14, 'clothing' => 15, 'tickets' => 27, 'everything_else' => 30],
        'jobs' => ['_id' => 8, 'sales' => 77, 'accounting' => 75, 'marketing' => 76, 'education' => 80, 'engineering' => 81, 'healthcare' => 82, 'legal_jobs' => 85, 'food_service' => 91, 'technology' => 94, 'other_jobs' => 95],
        'luggage' => ['_id' => 2, 'bag_delivery' => 31],
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
