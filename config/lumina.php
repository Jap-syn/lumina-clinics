<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking grid
    |--------------------------------------------------------------------------
    | "Bookings are on the hour and the half hour." Every candidate start time
    | is a multiple of this many minutes past midnight, in the branch timezone.
    */
    'slot_step_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Hold window
    |--------------------------------------------------------------------------
    | How long an unpaid booking holds its room and therapist while the client
    | pays the deposit. Expired holds are released lazily on the next write -
    | there is no scheduled job. See App\Services\Booking\HoldSweeper.
    */
    'hold_minutes' => 10,

    /*
    |--------------------------------------------------------------------------
    | Deposit
    |--------------------------------------------------------------------------
    | "There is a 300 deposit to hold a booking. Members don't pay it."
    | Stored in the smallest currency unit (satang) to avoid float money.
    */
    'deposit_minor_units' => 30000,
    'currency' => 'THB',

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    | "Free cancellation up to 24 hours before."
    */
    'free_cancellation_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Booking horizon
    |--------------------------------------------------------------------------
    | How far ahead the public site will show availability.
    */
    'booking_horizon_days' => 60,

    /*
    |--------------------------------------------------------------------------
    | Staff access
    |--------------------------------------------------------------------------
    | Phase one only. A single shared bearer token guards the reception diary.
    | This is not real authentication and is called out in the README.
    */
    'staff_token' => env('LUMINA_STAFF_TOKEN', 'lumina-reception'),
];
