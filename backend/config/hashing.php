<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Argon2id is the recommended algorithm for fintech applications.
    | It is resistant to GPU/ASIC brute-force attacks and provides
    | memory-hard, time-hard, and parallelism-hard security.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options (legacy - not recommended for fintech)
    |--------------------------------------------------------------------------
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon2id Options (recommended for fintech)
    |--------------------------------------------------------------------------
    |
    | memory_cost: 65536 KB (64 MB) — memory required per hash
    | time_cost: 4 iterations — CPU time per hash
    | threads: 2 — parallel threads
    |
    | These values exceed OWASP minimums (19 MiB, 2 iterations)
    | and provide strong resistance against GPU/ASIC attacks.
    |
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 2),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon2id Options (alias)
    |--------------------------------------------------------------------------
    */

    'argon2id' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 2),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

];
