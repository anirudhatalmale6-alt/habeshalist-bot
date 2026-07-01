<?php

// ============================================================
// WEBHOOK SETUP SCRIPT
// ============================================================
// Run via CLI: php setup.php https://yourdomain.com/bot/webhook.php
// Or open in browser: https://yourdomain.com/bot/setup.php?action=set
// ============================================================

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/telegram.php';

$tg = new Telegram($config['bot_token']);

// Browser mode
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    $action = $_GET['action'] ?? 'info';
    $webhookUrl = rtrim($config['website_url'], '/') . '/bot/webhook.php';

    if ($action === 'set') {
        $result = $tg->callApi('setWebhook', [
            'url' => $webhookUrl,
            'allowed_updates' => ['message', 'callback_query', 'chat_member'],
        ]);
        echo "Setting webhook to: {$webhookUrl}\n\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    } elseif ($action === 'delete') {
        $result = $tg->deleteWebhook();
        echo "Webhook deleted:\n\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    }

    $info = $tg->callApi('getWebhookInfo');
    echo "Current webhook info:\n";
    echo json_encode($info, JSON_PRETTY_PRINT) . "\n";
    exit;
}

// CLI mode
$webhookUrl = $argv[1] ?? '';

if (empty($webhookUrl)) {
    echo "Usage: php setup.php <webhook_url>\n";
    echo "Example: php setup.php https://www.habeshalist.com/bot/webhook.php\n\n";
    $info = $tg->callApi('getWebhookInfo');
    echo "Current webhook info:\n";
    echo json_encode($info, JSON_PRETTY_PRINT) . "\n";
    exit;
}

if ($webhookUrl === 'delete') {
    $result = $tg->deleteWebhook();
    echo "Webhook deleted:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit;
}

$result = $tg->setWebhook($webhookUrl);
echo "Webhook set:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

$info = $tg->callApi('getWebhookInfo');
echo "\nWebhook info:\n";
echo json_encode($info, JSON_PRETTY_PRINT) . "\n";
