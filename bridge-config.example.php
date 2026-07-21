<?php
/**
 * bridge-config.example.php
 *
 * Copy this file to "bridge-config.php" (same folder as bot-bridge.php on the
 * OSClass website) and set the real shared secret. bot-bridge.php reads the
 * secret from here (or from the HL_API_SECRET / API_SECRET environment variable)
 * so it is NEVER hardcoded in a committed file.
 *
 * This "api_secret" MUST match the bot's API_SECRET (in the bot's .env).
 *
 * bridge-config.php is git-ignored so the real secret never reaches the repo.
 * Generate a fresh random secret with:
 *   php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
 */
return [
    'api_secret' => 'PUT_YOUR_SHARED_SECRET_HERE',
];
