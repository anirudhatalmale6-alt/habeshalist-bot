<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: 'YOUR_BOT_TOKEN',

    // Secret key for the API bridge (generate a random string)
    'api_secret' => getenv('API_SECRET') ?: 'CHANGE_THIS_TO_A_RANDOM_STRING',

    // Your OSClass website URL
    'website_url' => 'https://www.habeshalist.com',

    // API bridge URL (this file lives on your OSClass server)
    'api_bridge_url' => 'https://www.habeshalist.com/bot-bridge.php',

    // Stripe (for paid features in Phase 2)
    'stripe_key' => getenv('STRIPE_KEY') ?: '',

    // Telegram Payment provider token (for Phase 2)
    'payment_provider_token' => getenv('PAYMENT_PROVIDER_TOKEN') ?: '',

    // Admin Telegram user IDs (add your Telegram user ID here)
    'admin_ids' => [],

    // Bot assistant name
    'bot_name' => 'Yohana',

    // Categories matching HabeshaList.com
    'categories' => [
        'housing' => [
            'name' => 'Housing & Real Estate',
            'icon' => '🏠',
            'subcategories' => [
                'rent' => 'Houses & Rooms for Rent',
                'sale' => 'Houses - Apartments for Sale',
                'shops' => 'Shops/Offices for Rent - Sale',
                'other_housing' => 'Other Housing',
            ],
        ],
        'services' => [
            'name' => 'Local Services',
            'icon' => '🔧',
            'subcategories' => [
                'beauty' => 'Beauty Salons',
                'car_repair' => 'Car Repair & Service',
                'babysitter' => 'Baby Sitter & Child Care',
                'electronics_repair' => 'Electronic Repair',
                'dj_music' => 'DJ/Bands & Music',
                'doctors' => 'Doctors & Dentist',
                'tax_finance' => 'Tax & Finance',
                'grocery' => 'Grocery Stores',
                'restaurant' => 'Restaurant/Coffee Shops',
                'legal' => 'Legal Service',
                'other_services' => 'Other Services',
            ],
        ],
        'personals' => [
            'name' => 'Personals',
            'icon' => '💑',
            'subcategories' => [
                'friendship' => 'Friendship - Activity Partners',
                'missed' => 'Missed Connections',
            ],
        ],
        'classes' => [
            'name' => 'Classes',
            'icon' => '📚',
            'subcategories' => [
                'computer' => 'Computer - Multimedia Classes',
                'tutoring' => 'Tutoring & Other Classes',
            ],
        ],
        'community' => [
            'name' => 'Community',
            'icon' => '🤝',
            'subcategories' => [
                'events' => 'Community Activities & Events',
                'donation' => 'Donation Programs',
            ],
        ],
        'forsale' => [
            'name' => 'For Sale',
            'icon' => '🛒',
            'subcategories' => [
                'cars' => 'Cars/Trucks',
                'ethiopian' => 'Ethiopian Products & Services',
                'electronics' => 'Electronics',
                'clothing' => 'Clothing',
                'tickets' => 'Tickets',
            ],
        ],
        'jobs' => [
            'name' => 'Jobs',
            'icon' => '💼',
            'subcategories' => [
                'sales' => 'Sales',
                'accounting' => 'Accounting',
                'marketing' => 'Marketing',
                'education' => 'Education',
                'engineering' => 'Engineering',
                'healthcare' => 'Healthcare',
                'legal_jobs' => 'Legal',
                'food_service' => 'Food Service',
                'technology' => 'Technology',
            ],
        ],
        'luggage' => [
            'name' => 'Luggage Delivery',
            'icon' => '✈️',
            'subcategories' => [],
        ],
    ],

    // Locations matching HabeshaList.com
    'locations' => [
        'addis' => 'Addis Ababa',
        'maryland' => 'Maryland',
        'texas' => 'Texas',
        'virginia' => 'Virginia',
        'dc' => 'Washington D.C.',
    ],
];
