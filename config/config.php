<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: 'REDACTED',

    'api_secret' => getenv('API_SECRET') ?: '717e34f13a2589d049d43149649e2668318e4949712b6f2f7e9cd94e28ad8f07',

    'website_url' => 'https://www.habeshalist.com',

    'api_bridge_url' => 'https://www.habeshalist.com/bot-bridge.php',

    // Shared secret Telegram sends back in the X-Telegram-Bot-Api-Secret-Token
    // header on every webhook request. webhook.php rejects any request whose
    // header doesn't match, so only Telegram can reach the bot even with the
    // server firewall relaxed for this endpoint.
    'webhook_secret' => getenv('WEBHOOK_SECRET') ?: '73c06d2b54a73d0f2e1c24341e5d80b88920b3fa49f039e8f3bed982f17a9c1f',

    'stripe_key' => getenv('STRIPE_KEY') ?: '',

    'payment_provider_token' => getenv('PAYMENT_PROVIDER_TOKEN') ?: '',

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
