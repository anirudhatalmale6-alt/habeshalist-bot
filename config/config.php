<?php

/**
 * Secrets (bot token, webhook secret, API secret, Stripe key) are NEVER
 * hardcoded in this file. They are read strictly from the environment.
 *
 * On shared hosting where you can't set OS-level environment variables, put
 * them in a plain ".env" file at the app root (one KEY=value per line). That
 * file is git-ignored so the secrets never land in the repo. See .env.example.
 *
 * If a required secret is missing the app fails loudly (HTTP 500 + error log)
 * instead of running half-configured.
 */

// --- optional .env loader: KEY=value per line, "#" comments allowed ---
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        // strip optional surrounding quotes
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false && !isset($_SERVER[$k])) {
            putenv("$k=$v");
            $_SERVER[$k] = $v;
        }
    }
}

// Read an env var from getenv() or the server array (covers .env, real OS env,
// and Apache/LiteSpeed SetEnv), falling back to $default when unset/blank.
$env = function (string $key, string $default = '') {
    $val = getenv($key);
    if ($val === false || $val === '') {
        $val = $_SERVER[$key] ?? $default;
    }
    return $val;
};

// Required secrets — no hardcoded fallback. Fail loudly if any is absent so a
// misconfigured deploy is obvious instead of a silently broken bot.
$missing = [];
foreach (['TELEGRAM_BOT_TOKEN', 'WEBHOOK_SECRET', 'API_SECRET'] as $key) {
    if ($env($key) === '') {
        $missing[] = $key;
    }
}
if ($missing && php_sapi_name() !== 'cli') {
    error_log('HabeshaList bot: missing required environment variables: ' . implode(', ', $missing));
    http_response_code(500);
    exit;
}

return [
    'bot_token' => $env('TELEGRAM_BOT_TOKEN'),

    'api_secret' => $env('API_SECRET'),

    'website_url' => 'https://www.habeshalist.com',

    'api_bridge_url' => 'https://www.habeshalist.com/bot-bridge.php',

    // Shared secret Telegram sends back in the X-Telegram-Bot-Api-Secret-Token
    // header on every webhook request. webhook.php rejects any request whose
    // header doesn't match, so only Telegram can reach the bot even with the
    // server firewall relaxed for this endpoint.
    'webhook_secret' => $env('WEBHOOK_SECRET'),

    'stripe_key' => $env('STRIPE_KEY'),

    'payment_provider_token' => $env('PAYMENT_PROVIDER_TOKEN'),

    'admin_ids' => [702720985],

    'bot_name' => 'Yohana',

    // ---- Promote My Business (paid Telegram Group promotion) ----
    // Structural attributes live here; PRICES are admin-editable at runtime
    // (stored in the settings table, keyed "price_<packageKey>"). The values
    // below are only the seed/default prices used until the admin changes them.
    'promo_packages' => [
        'one_time' => [
            'name' => 'One-Time Post',
            'emoji' => "\xF0\x9F\x93\x8C",
            'default_price' => 10,
            'posts_total' => 1,
            'posts_per_week' => null,   // one-time: pick 1 of the 3 daily slots
            'duration_days' => null,
            'pinned' => false,
            'summary' => "A single promotional post in the HabeshaList Telegram Group. Perfect for a one-off promotion like a grand opening, holiday sale, new product or job opening.",
            'features' => [
                '1 promotional post',
                'Posted once',
                'No pinned message',
                'You choose the date & time',
            ],
        ],
        'monthly' => [
            'name' => 'Monthly Plan',
            'emoji' => "\xF0\x9F\x93\x85",
            'default_price' => 50,
            'posts_total' => 8,
            'posts_per_week' => 2,
            'duration_days' => 30,
            'pinned' => true,
            'summary' => "Up to 8 promotional posts over one month, spread out (max 2 per week) so the group stays balanced. Includes 1 pinned message (24 hours).",
            'features' => [
                'Up to 8 posts per month',
                '1 pinned message (24h)',
                'Up to 2 posts per week',
                'Choose your start date',
            ],
        ],
        'yearly' => [
            'name' => 'Yearly Plan',
            'emoji' => "\xF0\x9F\x91\x91",
            'default_price' => 500,
            'posts_total' => 96,
            'posts_per_week' => 2,
            'duration_days' => 365,
            'pinned' => true,
            'summary' => "96 promotional posts per year (8 per month), with 1 pinned message each month. Unused posts roll over to the following months. Book up to 30 days in advance.",
            'features' => [
                '96 posts per year (8 per month)',
                '1 pinned message monthly',
                'Up to 2 posts per week',
                'Unused posts roll over',
                'Book up to 30 days ahead',
            ],
        ],

        // Business of the Week - its OWN main-menu button (NOT part of the
        // package picker above). Strictly one exclusive business per week,
        // featured & pinned for the full 7 days. Bookable up to 4 weeks ahead.
        // Reuses the same payment / ad form / approval engine as the packages.
        'botw' => [
            'name' => 'Business of the Week',
            'emoji' => "\xF0\x9F\x8F\x86",
            'default_price' => 75,
            'posts_total' => 1,
            'posts_per_week' => null,
            'duration_days' => 7,
            'pinned' => true,
            'exclusive' => true,        // only one business can hold the week
            'booking_weeks_ahead' => 4, // users can book up to 4 weeks in advance
            'summary' => "Be THE featured business of the week in the HabeshaList Telegram Group. Only one business is featured each week, so you get the spotlight all to yourself - a pinned feature post kept at the top of the group for all 7 days.",
            'features' => [
                'Exclusive - only 1 business per week',
                'Featured & pinned for the full 7 days',
                'Top-of-group visibility all week',
                'Book up to 4 weeks in advance',
            ],
        ],
    ],

    // Order the packages appear in the picker (Business of the Week is
    // intentionally excluded - it has its own dedicated menu button).
    'promo_package_order' => ['one_time', 'monthly', 'yearly'],

    // Business categories for the promotion ad form
    'business_categories' => [
        'Retail', 'Restaurant & Food', 'Services', 'Health & Medical',
        'Beauty & Salon', 'Automotive', 'Real Estate', 'Education',
        'Technology', 'Events', 'Travel', 'Other',
    ],

    // Payment handles shown for manual payment. These are admin-editable at
    // runtime via the settings table (keys pay_zelle / pay_cashapp / pay_support).
    'payment_defaults' => [
        'pay_zelle' => '',      // e.g. habeshalist@email.com or a phone number
        'pay_cashapp' => '',    // e.g. $HabeshaList
        'pay_support' => '@Habesha_list',
    ],

    'categories' => [
        'housing' => [
            'name' => 'Housing & Real Estate',
            'icon' => "\xF0\x9F\x8F\xA0",
            'subcategories' => [
                'rent' => 'Houses & Rooms for Rent',
                'sale' => 'Houses - Apartments for Sale',
                'shops' => 'Shops/Offices for Rent - Sale',
                'vacation' => 'Vacation Rentals',
                'other_housing' => 'Other Housing',
            ],
        ],
        'services' => [
            'name' => 'Local Services',
            'icon' => "\xF0\x9F\x94\xA7",
            'subcategories' => [
                'beauty' => 'Beauty Salons',
                'car_repair' => 'Car Repair & Service',
                'babysitter' => 'Baby Sitter & Child Care',
                'transportation' => 'Transportation Service',
                'electronics_repair' => 'Electronic Repair',
                'dj_music' => 'DJ, Bands & Music',
                'doctors' => 'Doctors & Dentist',
                'tax_finance' => 'Tax & Finance',
                'grocery' => 'Grocery Stores',
                'restaurant' => 'Restaurant, Coffee Shops & Catering',
                'legal' => 'Legal Service',
                'other_services' => 'Other Services',
            ],
        ],
        'personals' => [
            'name' => 'Personals',
            'icon' => "\xF0\x9F\x92\x91",
            'subcategories' => [
                'friendship' => 'Friendship - Activity Partners',
                'missed' => 'Missed Connections',
            ],
        ],
        'classes' => [
            'name' => 'Classes',
            'icon' => "\xF0\x9F\x93\x9A",
            'subcategories' => [
                'computer' => 'Computer - Multimedia Classes',
                'language' => 'Language Classes',
                'tutoring' => 'Tutoring & Other Classes',
            ],
        ],
        'community' => [
            'name' => 'Community',
            'icon' => "\xF0\x9F\xA4\x9D",
            'subcategories' => [
                'events' => 'Community Activities & Events',
                'donation' => 'Donation to In Needs',
                'others' => 'Others',
            ],
        ],
        'forsale' => [
            'name' => 'For Sale',
            'icon' => "\xF0\x9F\x9B\x92",
            'subcategories' => [
                'cars' => 'Cars/Trucks',
                'ethiopian' => 'Ethiopian Products & Services',
                'electronics' => 'Electronics & Accessories',
                'clothing' => 'Clothing',
                'tickets' => 'Tickets',
                'everything_else' => 'Everything Else',
            ],
        ],
        'jobs' => [
            'name' => 'Jobs',
            'icon' => "\xF0\x9F\x92\xBC",
            'subcategories' => [
                'sales' => 'Sales & Customer Service',
                'accounting' => 'Accounting - Finance',
                'marketing' => 'Marketing & Advertising',
                'education' => 'Education - Training',
                'engineering' => 'Engineering - Architecture',
                'healthcare' => 'Healthcare',
                'legal_jobs' => 'Legal',
                'food_service' => 'Restaurant - Food Service',
                'technology' => 'Technology',
                'other_jobs' => 'Other Jobs',
            ],
        ],
        'luggage' => [
            'name' => 'Luggage Delivery',
            'icon' => "\xE2\x9C\x88\xEF\xB8\x8F",
            'subcategories' => [
                'bag_delivery' => 'Bag Delivery',
            ],
        ],
    ],

    'countries' => [
        'et' => ['name' => 'Ethiopia', 'code' => 'ET'],
        'us' => ['name' => 'United States', 'code' => 'US'],
        'ca' => ['name' => 'Canada', 'code' => 'CA'],
        'gb' => ['name' => 'United Kingdom', 'code' => 'GB'],
        'de' => ['name' => 'Germany', 'code' => 'DE'],
        'se' => ['name' => 'Sweden', 'code' => 'SE'],
        'il' => ['name' => 'Israel', 'code' => 'IL'],
        'ae' => ['name' => 'UAE', 'code' => 'AE'],
        'sa' => ['name' => 'Saudi Arabia', 'code' => 'SA'],
        'it' => ['name' => 'Italy', 'code' => 'IT'],
    ],
];
