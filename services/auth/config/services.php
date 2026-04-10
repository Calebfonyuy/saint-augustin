<?php

/*
 * Third-party service credentials.
 * Google OAuth via Socialite: https://laravel.com/docs/12.x/socialite
 */

return [

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

];
