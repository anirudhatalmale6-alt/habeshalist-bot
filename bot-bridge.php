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

// The shared secret (must match the bot's API_SECRET) is NEVER hardcoded here.
// It is read from the server environment, or from a local, git-ignored
// "bridge-config.php" next to this file. See bridge-config.example.php.
$API_SECRET = getenv('HL_API_SECRET') ?: (getenv('API_SECRET') ?: '');
if ($API_SECRET === '' && is_readable(__DIR__ . '/bridge-config.php')) {
    $__cfg = require __DIR__ . '/bridge-config.php';
    if (is_array($__cfg)) {
        $API_SECRET = (string) ($__cfg['api_secret'] ?? '');
    }
}
if ($API_SECRET === '') {
    // Fail loudly instead of running with no authentication.
    http_response_code(500);
    error_log('bot-bridge: API secret not configured (set HL_API_SECRET or bridge-config.php)');
    echo json_encode(['success' => false, 'error' => 'Bridge not configured']);
    exit;
}

// Read incoming request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['secret']) || !hash_equals($API_SECRET, (string) $data['secret'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $data['action'] ?? '';

if ($action === 'create_listing') {
    createListing($data);
} elseif ($action === 'register_user') {
    registerUser($data);
} elseif ($action === 'moderate_item') {
    moderateItem($data);
} elseif ($action === 'get_categories') {
    getCategories();
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

// Escape a user-supplied string for inline SQL using the DB driver's own
// escaper (charset-aware) when reachable, falling back to addslashes. Used for
// the few OSClass inserts that go through the raw DAO ->query() path.
function bridge_esc($dao, $s) {
    $s = (string) $s;
    try {
        if (isset($dao->conn) && is_object($dao->conn) && method_exists($dao->conn, 'getOsclassDb')) {
            $link = $dao->conn->getOsclassDb();
            if ($link instanceof mysqli) {
                return $link->real_escape_string($s);
            }
        }
    } catch (\Throwable $e) { /* fall through */ }
    return addslashes($s);
}

// Allowed upload extensions for ad media downloaded from Telegram.
const BRIDGE_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const BRIDGE_VIDEO_EXT = ['mp4', 'mov', 'webm'];
const BRIDGE_MAX_UPLOAD_BYTES = 25165824; // 24 MB cap per file

function moderateItem($data) {
    $osclassPath = __DIR__ . '/oc-load.php';

    if (!file_exists($osclassPath)) {
        echo json_encode(['success' => false, 'error' => 'OSClass not found at expected path']);
        return;
    }

    define('OC_ADMIN', true);
    require_once $osclassPath;

    try {
        $itemId = !empty($data['item_id']) ? (int)$data['item_id'] : 0;
        $decision = $data['decision'] ?? '';

        if (!$itemId) {
            echo json_encode(['success' => false, 'error' => 'Missing item id']);
            return;
        }

        $item = Item::newInstance()->findByPrimaryKey($itemId);
        if (!$item) {
            echo json_encode(['success' => false, 'error' => 'Item not found']);
            return;
        }

        $prefix = DB_TABLE_PREFIX;
        $dao = Item::newInstance()->dao;

        if ($decision === 'approve') {
            $dao->query("UPDATE {$prefix}t_item SET b_enabled = 1, b_active = 1 WHERE pk_i_id = {$itemId}");
            if (class_exists('CategoryStats') && !empty($item['fk_i_category_id'])) {
                CategoryStats::newInstance()->increaseNumItems($item['fk_i_category_id']);
            }
            echo json_encode(['success' => true, 'status' => 'approved']);
        } elseif ($decision === 'reject') {
            // Keep it hidden from the public; admin can permanently delete from the panel if desired
            $dao->query("UPDATE {$prefix}t_item SET b_enabled = 0, b_active = 0 WHERE pk_i_id = {$itemId}");
            echo json_encode(['success' => true, 'status' => 'rejected']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown decision']);
        }

    } catch (Exception $e) {
        // Never leak DB/OSClass internals to the caller; log the detail instead.
        error_log('bot-bridge error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
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

        // Some OSClass installs mark t_user.s_username as NOT NULL. Building a
        // unique username up front means the insert never fails on a missing/
        // duplicate username, which was leaving bot users out of the site admin.
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0]));
        if ($base === '') $base = 'hluser';
        $username = $base . rand(1000, 9999999);

        $user = User::newInstance();
        $ok = $user->insert([
            's_name'      => $name !== '' ? $name : 'HabeshaList User',
            's_username'  => $username,
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
            $escPhone = bridge_esc($dao, $phone);
            @$dao->query("UPDATE {$prefix}t_user SET s_phone_mobile = '{$escPhone}' WHERE pk_i_id = {$userId}");
        }

        echo json_encode(['success' => true, 'osclass_user_id' => (int)$userId]);

    } catch (Exception $e) {
        // Never leak DB/OSClass internals to the caller; log the detail instead.
        error_log('bot-bridge error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
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

        // Ads posted via the bot start hidden and go live only after admin approval.
        // Pass "auto_approve": true to publish immediately (kept for flexibility).
        $enabled = !empty($data['auto_approve']) ? 1 : 0;

        $item = Item::newInstance();
        $insertResult = $item->insert([
            'fk_i_user_id' => $osUserId,
            'dt_pub_date' => date('Y-m-d H:i:s'),
            'dt_mod_date' => date('Y-m-d H:i:s'),
            // Far-future expiry so OSClass never treats the ad as expired and hides
            // it from listings/search. Without this the column can default to a past
            // date and the ad silently disappears even when enabled+active.
            'dt_expiration' => '9999-12-31 23:59:59',
            'f_price' => $price,
            'fk_c_currency_code' => 'USD',
            's_contact_name' => $contactName,
            's_contact_email' => $contactEmail,
            'b_enabled' => $enabled,
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
        $escTitle = bridge_esc($dao, $title);
        $escDesc = bridge_esc($dao, $description);

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
            $escCountry = bridge_esc($dao, $locCountry);
            $escCountryCode = bridge_esc($dao, $locCountryCode ?: '');
            $escState = bridge_esc($dao, $locState);
            $escCity = bridge_esc($dao, $locCity);
            $escAddress = bridge_esc($dao, $locAddress);
            $dao->query(
                "INSERT INTO {$prefix}t_item_location (fk_i_item_id, fk_c_country_code, s_country, s_region, s_city, s_address) " .
                "VALUES ({$itemId}, '{$escCountryCode}', '{$escCountry}', '{$escState}', '{$escCity}', '{$escAddress}')"
            );
        }

        $photos = $data['photos'] ?? [];
        $videos = $data['videos'] ?? [];
        // The bot passes its token in the payload so it never has to be hardcoded
        // here. A hardcoded fallback is kept only for manual/legacy calls.
        $botToken = !empty($data['bot_token']) ? $data['bot_token'] : '';
        $uploadDir = osc_content_path() . 'uploads/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        // Download a Telegram file by file_id and store it as an OSClass item
        // resource (photo or video). Returns true on success. Never fatal.
        $storeMedia = function ($fileId, $isVideo) use ($dao, $prefix, $uploadDir, $itemId, $botToken) {
            if ($botToken === '' || $fileId === '') return false;
            $fileId = rawurlencode($fileId);
            $info = @file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}");
            $fileInfo = $info ? json_decode($info, true) : null;
            if (!$fileInfo || empty($fileInfo['ok'])) return false;

            $filePath = $fileInfo['result']['file_path'] ?? '';
            if ($filePath === '') return false;
            $bin = @file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}");
            if ($bin === false || $bin === '') return false;

            // Size cap: never write an oversized file to disk.
            if (strlen($bin) > BRIDGE_MAX_UPLOAD_BYTES) {
                error_log('bot-bridge: rejected oversized upload (' . strlen($bin) . ' bytes)');
                return false;
            }

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($isVideo) {
                if ($ext === '') $ext = 'mp4';
                // Extension allowlist: reject anything that isn't a known video type.
                if (!in_array($ext, BRIDGE_VIDEO_EXT, true)) {
                    error_log('bot-bridge: rejected video upload with extension "' . $ext . '"');
                    return false;
                }
                $contentType = ($ext === 'mov') ? 'video/quicktime' : (($ext === 'webm') ? 'video/webm' : 'video/mp4');
            } else {
                if ($ext === '') $ext = 'jpg';
                // Extension allowlist: reject anything that isn't a known image type.
                if (!in_array($ext, BRIDGE_IMAGE_EXT, true)) {
                    error_log('bot-bridge: rejected image upload with extension "' . $ext . '"');
                    return false;
                }
                $contentType = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg'));
            }

            // Belt-and-braces: make sure nothing in the uploads dir can execute
            // as a script even if a bad file ever lands there.
            $htPath = $uploadDir . '.htaccess';
            if (!file_exists($htPath)) {
                @file_put_contents($htPath, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\nAddType text/plain .php .phtml .phps\n");
            }

            $dao->query(
                "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path) " .
                "VALUES ({$itemId}, 'temp', '{$ext}', '{$contentType}', 'oc-content/uploads/')"
            );
            $resourceId = $dao->insertedId();
            if (!$resourceId) return false;

            $dao->query("UPDATE {$prefix}t_item_resource SET s_name = '{$resourceId}' WHERE pk_i_id = {$resourceId}");

            file_put_contents($uploadDir . $resourceId . '.' . $ext, $bin);
            if ($isVideo) {
                // Videos have no image derivatives; keep an _original for downloads.
                @copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_original.' . $ext);
            } else {
                @copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_original.' . $ext);
                @copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_preview.' . $ext);
                @copy($uploadDir . $resourceId . '.' . $ext, $uploadDir . $resourceId . '_thumbnail.' . $ext);
            }
            return true;
        };

        foreach ($photos as $fileId) { $storeMedia($fileId, false); }
        foreach ($videos as $fileId) { $storeMedia($fileId, true); }

        // Only count the item in category stats once it is actually live
        if ($enabled && class_exists('CategoryStats')) {
            CategoryStats::newInstance()->increaseNumItems($categoryId);
        }

        echo json_encode([
            'success' => true,
            'osclass_id' => $itemId,
        ]);

    } catch (Exception $e) {
        // Never leak DB/OSClass internals to the caller; log the detail instead.
        error_log('bot-bridge error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
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
