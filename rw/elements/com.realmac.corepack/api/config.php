<?php

/**
 * Main API Configuration
 * 
 * This is the main configuration file used by the API application.
 * This is NOT related to the test suite configuration.
 */

return [
    'APP_ENV' => 'prod',
    'APP_DEBUG' => false,
    'APP_SECRET' => 'your-secret-key-change-this-in-production',
    'API_VERSION' => '1.0.0',

    // CMS Configuration - Updated for dynamic content paths
    'cms_base_path' => __DIR__ . '/_content',
    'cache_path' => __DIR__ . '/_cache',
    'cache_ttl' => 3600,

    // CMS Security Configuration (Optional)
    // Uncomment and configure to restrict content paths for security
    // 'allowed_content_paths' => [
    //     '/var/www/content',
    //     '/home/user/websites/content',
    //     __DIR__ . '/examples/content'
    // ],

    'resources' => [
        'path' => '/resources',
        'fields' => ['image', 'thumbnail', 'featured_image']
    ],

    'parser' => [
        'markdown' => [
            'allow_unsafe_links' => false,
            'allow_unsafe_images' => false,
            'html_input' => 'allow',
        ]
    ],

    'search' => [
        'enabled' => true,
        'index_content' => true,
        'index_metadata' => true,
    ],

    'collections' => [
        'auto_discover' => true,
    ],

    'pagination' => [
        'default_per_page' => 10,
        'max_per_page' => 100,
    ],

    // Spam Protection Configuration
    'spam_protection' => [
        'enabled' => false, // Global default - can be overridden per form
        'default_provider' => 'recaptcha',
        'providers' => [
            'recaptcha' => [
                'secret_key' => '', // Set your reCAPTCHA secret key
                'site_key' => '',   // Optional, for client-side reference
                'score_threshold' => 0.5, // For reCAPTCHA v3 (0.0 to 1.0)
                'timeout' => 5
            ],
            'hcaptcha' => [
                'secret_key' => '', // Set your hCAPTCHA secret key
                'site_key' => '',   // Optional, for client-side reference
                'timeout' => 5
            ],
            'turnstile' => [
                'secret_key' => '', // Set your Turnstile secret key
                'site_key' => '',   // Optional, for client-side reference
                'timeout' => 5
            ]
        ],
        'error_messages' => [
            'missing_token' => 'Captcha verification required',
            'invalid_token' => 'Captcha verification failed',
            'provider_error' => 'Unable to verify captcha at this time',
            'score_too_low' => 'Captcha score too low, please try again'
        ]
    ],
];
