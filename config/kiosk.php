<?php

/*
|--------------------------------------------------------------------------
| Simple mode (kiosk)
|--------------------------------------------------------------------------
|
| The big-button screens for factory staff at /kiosk. See docs/KIOSK.md.
|
*/

return [

    // Languages staff can switch between with one tap. The first is the default.
    'locales' => ['si', 'en'],

    'default_locale' => env('KIOSK_DEFAULT_LOCALE', 'si'),

    // Sign out automatically after this many idle minutes — shared tablets are left lying
    // around. 0 turns it off.
    'idle_minutes' => (int) env('KIOSK_IDLE_MINUTES', 5),

];
