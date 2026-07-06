<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: 'REDACTED',

    'api_secret' => getenv('API_SECRET') ?: '717e34f13a2589d049d43149649e2668318e4949712b6f2f7e9cd94e28ad8f07',

    'website_url' => 'https://www.habeshalist.com',

    'api_bridge_url' => 'https://www.habeshalist.com/bot-bridge.php',

    'stripe_key' => getenv('STRIPE_KEY') ?: '',

    'payment_provider_token' => getenv('PAYMENT_PROVIDER_TOKEN') ?: '',

    'admin_ids' => [702720985],

    'bot_name' => 'Yohana',

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
