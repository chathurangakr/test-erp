<?php

/*
|--------------------------------------------------------------------------
| Mobile / REST API
|--------------------------------------------------------------------------
|
| Every tunable of the versioned API lives here, so no limit or lifetime is
| scattered through controllers. See docs/API_V1.md.
|
*/

return [

    /*
     | The current version. Routes live under /api/{version}; a future v2 is added
     | beside v1, never in place of it.
     */
    'version' => 'v1',

    /*
     | The single Sanctum ability every mobile token carries. It proves the token was
     | issued for the API — nothing more. What the user may DO is decided by their
     | roles and permissions on every request, never by token abilities.
     */
    'token_ability' => 'mobile-api',

    /*
     | How long a device stays signed in. After this the token stops working and the
     | person signs in again. 90 days was chosen by the owner; a lost phone should be
     | handled by deactivating the user or "sign out everywhere", which revoke at once.
     */
    'token_lifetime_days' => (int) env('API_TOKEN_LIFETIME_DAYS', 90),

    'device_name_max_length' => 100,

    'rate_limits' => [
        // Sign-in attempts per minute, per email address and IP together.
        'login_per_minute' => (int) env('API_LOGIN_PER_MINUTE', 5),
        // Authenticated requests per minute, per user.
        'requests_per_minute' => (int) env('API_REQUESTS_PER_MINUTE', 120),
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
     | Refuse plain-HTTP API calls. On by default in production, where the API must
     | only ever be reached over TLS; off in local development, where nginx already
     | terminates the self-signed certificate.
     */
    'require_https' => (bool) env('API_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

];
