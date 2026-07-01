<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

echo "=== HabeshaList Bot Diagnostic ===\n\n";

// 1. PHP version
echo "PHP Version: " . phpversion() . "\n";

// 2. Check PDO SQLite
echo "PDO SQLite: " . (extension_loaded('pdo_sqlite') ? 'YES' : 'NO - THIS IS THE PROBLEM') . "\n";

// 3. Check data folder
$dataDir = __DIR__ . '/data';
echo "Data folder exists: " . (is_dir($dataDir) ? 'YES' : 'NO') . "\n";
if (is_dir($dataDir)) {
    echo "Data folder writable: " . (is_writable($dataDir) ? 'YES' : 'NO') . "\n";
}

// 4. Check config loads
echo "\nLoading config... ";
try {
    $config = require __DIR__ . '/config/config.php';
    echo "OK\n";
    echo "Bot token starts with: " . substr($config['bot_token'], 0, 10) . "...\n";
    echo "Website URL: " . $config['website_url'] . "\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// 5. Check includes load
echo "\nLoading database.php... ";
try {
    require __DIR__ . '/includes/database.php';
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "Loading telegram.php... ";
try {
    require __DIR__ . '/includes/telegram.php';
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// 6. Try creating database
echo "\nCreating SQLite database... ";
try {
    $db = new Database(__DIR__ . '/data/bot.sqlite');
    echo "OK\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// 7. Test Telegram API
echo "\nTesting Telegram API... ";
try {
    $tg = new Telegram($config['bot_token']);
    $me = $tg->callApi('getMe');
    if ($me && $me['ok']) {
        echo "OK\n";
        echo "Bot name: " . $me['result']['first_name'] . "\n";
        echo "Bot username: @" . $me['result']['username'] . "\n";
    } else {
        echo "FAILED: " . json_encode($me) . "\n";
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== Done ===\n";
