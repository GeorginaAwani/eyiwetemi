<?php

return [

    /*
    | Every time shown or picked in the app is West Africa Time. Values are
    | stored in UTC and converted for display and scheduling.
    */
    'timezone' => 'Africa/Lagos',

    /*
    | Filesystem disk for uploads: covers, photos, research files, share cards.
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    | Host the admin is served on.
    */
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.eyiwetemi.test'),

    /*
    | Counting rules approved for the first-party, cookieless stats.
    */
    'stats' => [
        'read_after_seconds' => 10,
        'country_header' => env('STATS_COUNTRY_HEADER', 'CF-IPCountry'),
    ],

    /*
    | Lengths of time approved in the design.
    */
    'reviewer_invite_days' => 14,
    'trusted_device_days' => 30,
    'username_hold_days' => 30,
    'login_link_minutes' => 15,

];
