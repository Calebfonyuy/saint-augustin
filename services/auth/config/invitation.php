<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation Token Expiry
    |--------------------------------------------------------------------------
    |
    | Number of hours before an admin-issued invitation link expires.
    | Set via INVITATION_EXPIRE_HOURS in the environment.
    |
    */
    'expire_hours' => (int) env('INVITATION_EXPIRE_HOURS', 48),

];
