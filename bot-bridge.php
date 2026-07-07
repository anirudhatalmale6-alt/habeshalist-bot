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
} elseif ($action === 'register_user') {
    registerUser($data);
} elseif ($action === 'get_categories') {
    getCategories();
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function registerUser($data) {
    $osclassPath = __DIR__ . '/oc-load.php';

    if (!file_exists($osclassPath)) {
        echo json_encode(['success' => false, 'error' => 'OSClass not found at expected path']);
        return;
    }

    define('OC_ADMIN', true);
    require_once $osclassPath;

    try {
        $name  = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');

        if ($email === '') {
            echo json_encode(['success' => false, 'error' => 'Email required']);
            return;
        }

        // Reuse an existing website account with this email if there is one
        $existing = User::newInstance()->findByEmail($email);
        if ($existing && !empty($existing['pk_i_id'])) {
            echo json_encode(['success' => true, 'osclass_user_id' => (int)$existing['pk_i_id'], 'existing' => true]);
            return;
        }

        // Users register through Telegram, not the website, so give them a random password
        $plainPw = bin2hex(random_bytes(8));
        if (function_exists('osc_hash_password')) {
            $hash = osc_hash_password($plainPw);
        } elseif (function_exists('osc_encrypt_password')) {
            $hash = osc_encrypt_password($plainPw);
        } else {
            $hash = sha1($plainPw);
        }
        $secret = md5(uniqid(rand(), true));

        $user = User::newInstance();
        $ok = $user->insert([
            's_name'      => $name !== '' ? $name : 'HabeshaList User',
            's_email'     => $email,
            's_password'  => $hash,
            's_secret'    => $secret,
            'b_enabled'   => 1,
            'b_active'    => 1,
            'dt_reg_date' => date('Y-m-d H:i:s'),
        ]);

        if (!$ok) {
            echo json_encode(['success' => false, 'error' => 'User insert failed']);
            return;
        }

        $userId = $user->dao->insertedId();

        // Phone column name varies across OSClass versions — best-effort, never fatal
        if ($phone !== '' && $userId) {
            $prefix = DB_TABLE_PREFIX;
            $dao = User::newInstance()->dao;
            $escPhone = addslashes($phone);
            @$dao->query("UPDATE {$prefix}t_user SET s_phone_mobile = '{$escPhone}' WHERE pk_i_id = {$userId}");
        }

        echo json_encode(['success' => true, 'osclass_user_id' => (int)$userId]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
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
        $osUserId = !empty($data['osclass_user_id']) ? (int)$data['osclass_user_id'] : null;
        $secret = md5(uniqid(rand(), true));

        $item = Item::newInstance();
        $insertResult = $item->insert([
            'fk_i_user_id' => $osUserId,
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

        $locCountry = $data['country'] ?? '';
        $locCountryCode = $data['country_code'] ?? '';
        $locState = $data['state'] ?? '';
        $locCity = $data['city'] ?? '';
        $locAddress = $data['address'] ?? '';

        if ($locCountry || $locState || $locCity || $locAddress) {
            $escCountry = addslashes($locCountry);
            $escCountryCode = addslashes($locCountryCode ?: '');
            $escState = addslashes($locState);
            $escCity = addslashes($locCity);
            $escAddress = addslashes($locAddress);
            $dao->query(
                "INSERT INTO {$prefix}t_item_location (fk_i_item_id, fk_c_country_code, s_country, s_region, s_city, s_address) " .
                "VALUES ({$itemId}, '{$escCountryCode}', '{$escCountry}', '{$escState}', '{$escCity}', '{$escAddress}')"
            );
        }

        $photos = $data['photos'] ?? [];
        $botToken = 'REDACTED';
        $uploadDir = osc_content_path() . 'uploads/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        foreach ($photos as $idx => $fileId) {
            $fileInfo = json_decode(file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}"), true);
            if (!$fileInfo || !$fileInfo['ok']) continue;

            $filePath = $fileInfo['result']['file_path'];
            $imageData = file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}");
            if (!$imageData) continue;

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';

            $dao->query(
                "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path) " .
                "VALUES ({$itemId}, 'temp', '{$ext}', '{$contentType}', 'oc-content/uploads/')"
            );
            $resourceId = $dao->insertedId();

            if (!$resourceId) continue;

            $dao->query(
                "UPDATE {$prefix}t_item_resource SET s_name = '{$resourceId}' WHERE pk_i_id = {$resourceId}"
            );

            file_put_contents($uploadDir . $resourceId . '.' . $ext, $imageData);
            copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_original.' . $ext);
            copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_preview.' . $ext);
            copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_thumbnail.' . $ext);
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
