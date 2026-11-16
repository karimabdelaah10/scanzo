<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin
    |--------------------------------------------------------------------------
    |
    | The first account for the admin panel, created by PlatformAdminSeeder.
    | Read through config() rather than env() because the entrypoint runs
    | `config:cache`, after which env() returns null.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Platform Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@scanzo.local'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for a new business
    |--------------------------------------------------------------------------
    |
    | Copied into businesses.settings when one is created. After that the
    | business owns its own copy and changing these does not affect it.
    |
    */

    'business_defaults' => [
        'currency' => 'EGP',
        'returns' => [
            'window_days' => 14,
            'sellers_allowed' => false,
            'without_invoice' => false,
        ],
        'voids' => [
            // Hours after issuing during which a manager or owner may cancel an invoice.
            'window_hours' => 24,
        ],
        'selling' => [
            // Whether a scan drops straight into the cart or opens the item first.
            'quick_scan' => false,
        ],
        'labels' => [
            // Must be a LabelTemplate value, exactly.
            'size' => 'roll-40x30',
            'show_price' => true,
        ],
        // Printed on the receipt. Empty means the receipt simply omits it.
        'receipt' => [
            'header' => '',
            'footer' => '',
        ],
        // null means "the platform's own palette". `logo` is a path on the
        // public disk (brands/{business_id}/…), set by an upload.
        'brand' => [
            'primary' => null,
            'secondary' => null,
            'logo' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Static assets version
    |--------------------------------------------------------------------------
    |
    | Files in public/ (css/tokens.css, js/*.js) are not hashed by Vite, so
    | the Blade views add this as ?v=. Bump it by hand whenever one of them
    | changes, or a browser keeps serving the old colours.
    |
    */

    'assets_version' => '4',

    /*
    |--------------------------------------------------------------------------
    | Platform panel palettes
    |--------------------------------------------------------------------------
    |
    | The picker in /admin (Karim's own panel; shops get a brand, not a
    | picker). `deep` and `accent` are the two swatch dots and the theme-color;
    | they must equal --c-deep / --c-accent of the same palette in
    | public/css/tokens.css, which DesignTokensTest checks. The first is the
    | default and has no html[data-palette] block of its own.
    |
    */

    'admin_palettes' => [
        'navy' => ['name' => 'كحلي وبرتقالي', 'deep' => '#0F2A44', 'accent' => '#E05614'],
        'gold' => ['name' => 'كحلي وذهبي', 'deep' => '#0F2A44', 'accent' => '#C9A24A'],
        'teal' => ['name' => 'تركواز ومرجاني', 'deep' => '#0B4F5C', 'accent' => '#EE8466'],
        'plum' => ['name' => 'بنفسجي وكهرماني', 'deep' => '#46204F', 'accent' => '#E7B04C'],
        'graphite' => ['name' => 'فحمي ونحاسي', 'deep' => '#25282E', 'accent' => '#CE6F40'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription reminder
    |--------------------------------------------------------------------------
    |
    | How many days before a subscription ends it counts as "ending soon": the
    | owner's dashboard shows a banner and the platform panel's «بينتهي قريب»
    | filter lists it. Reminding only — an ended subscription never stops
    | selling (Karim's decision; only a suspended business is read only).
    |
    */

    'subscription_warning_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Offline
    |--------------------------------------------------------------------------
    |
    | Fallback for a business with no subscription row. A device that has not
    | reached the server within this many days stops selling.
    |
    */

    'offline_grace_days' => env('SCANZO_OFFLINE_GRACE_DAYS', 7),

];
