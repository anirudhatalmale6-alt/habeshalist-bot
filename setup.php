<?php

// ============================================================
// WEBHOOK SETUP SCRIPT
// ============================================================
// Run this once to register your webhook URL with Telegram.
// Usage: php setup.php https://yourdomain.com/bot/webhook.php
// ============================================================

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/telegram.php';

$tg = new Telegram($config['bot_token']);

$webhookUrl = $argv[1] ?? '';

if (empty($webhookUrl)) {
    echo "Usage: php setup.php <webhook_url>\n";
    echo "Example: php setup.php https://www.habeshalist.com/bot/webhook.php\n\n";

    // Show current webhook info
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

// Verify
$info = $tg->callApi('getWebhookInfo');
echo "\nWebhook info:\n";
echo json_encode($info, JSON_PRETTY_PRINT) . "\n";
