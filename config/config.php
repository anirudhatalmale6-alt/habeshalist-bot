<?php

return [
    'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: 'REDACTED',

    // Secret key for the API bridge
    'api_secret' => getenv('API_SECRET') ?: '717e34f13a2589d049d43149649e2668318e4949712b6f2f7e9cd94e28ad8f07',

    // Your OSClass website URL
    'website_url' => 'https://www.habeshalist.com',

    // API bridge URL (this file lives on your OSClass server)
    'api_bridge_url' => 'https://www.habeshalist.com/bot-bridge.php',

    // Stripe (for paid features in Phase 2)
    'stripe_key' => getenv('STRIPE_KEY') ?: '',

    // Telegram Payment provider token (for Phase 2)
    'payment_provider_token' => getenv('PAYMENT_PROVIDER_TOKEN') ?: '',

    // Admin Telegram user IDs (add your Telegram user ID here)
    'admin_ids' => [702720985],

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
                'vacation' => 'Vacation Rentals',
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
                'language' => 'Language Classes',
                'tutoring' => 'Tutoring & Other Classes',
            ],
        ],
        'community' => [
            'name' => 'Community',
            'icon' => '🤝',
            'subcategories' => [
                'events' => 'Community Activities & Events',
                'donation' => 'Donation to In Needs',
                'others' => 'Others',
            ],
        ],
        'forsale' => [
            'name' => 'For Sale',
            'icon' => '🛒',
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
            'icon' => '💼',
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
            'icon' => '✈️',
            'subcategories' => [
                'bag_delivery' => 'Bag Delivery',
            ],
        ],
    ],

    // Locations matching HabeshaList.com
    'locations' => [
        'addis' => 'Addis Ababa, Ethiopia',
        'dc' => 'Washington D.C., USA',
        'maryland' => 'Maryland, USA',
        'virginia' => 'Virginia, USA',
        'texas' => 'Texas, USA',
        'california' => 'California, USA',
        'minnesota' => 'Minnesota, USA',
        'georgia' => 'Georgia, USA',
        'colorado' => 'Colorado, USA',
        'nevada' => 'Nevada, USA',
        'newyork' => 'New York, USA',
        'ohio' => 'Ohio, USA',
        'seattle' => 'Seattle, USA',
        'canada' => 'Canada',
        'uk' => 'United Kingdom',
        'germany' => 'Germany',
        'sweden' => 'Sweden',
        'israel' => 'Israel',
        'uae' => 'UAE',
        'saudi' => 'Saudi Arabia',
        'italy' => 'Italy',
        'other' => 'Other',
    ],
];
