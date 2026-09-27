<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Welcome Credits
    |--------------------------------------------------------------------------
    |
    | Credits granted to a brand new account on registration.
    |
    */

    'welcome_credits' => (int) env('BILLING_WELCOME_CREDITS', 10),

    /*
    |--------------------------------------------------------------------------
    | Cost Per Message
    |--------------------------------------------------------------------------
    */

    'credits_per_message' => (int) env('BILLING_CREDITS_PER_MESSAGE', 1),

    /*
    |--------------------------------------------------------------------------
    | Low Credit Warning
    |--------------------------------------------------------------------------
    |
    | The balance at which the account owner gets a single "credits running
    | low" notification. It fires once per threshold crossing, not per
    | message, so it stays a warning rather than a nuisance. Kept below the
    | welcome bonus so a brand new account is not told it is running out
    | before it has spent anything.
    |
    */

    'low_credits_threshold' => (int) env('BILLING_LOW_CREDITS_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration_enabled' => (bool) env('BILLING_REGISTRATION_ENABLED', true),

];
