<?php

/*
 * NFR-4 requires bcrypt with cost factor >= 12.
 * Ref: https://laravel.com/docs/12.x/hashing
 * Ref: https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
 */

return [

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),  // NFR-4: cost factor >= 12
        'verify' => true,
    ],

    'argon' => [
        'memory'  => 65536,
        'threads' => 1,
        'time'    => 4,
        'verify'  => true,
    ],

    'rehash_on_login' => true,

];
