<?php

/*
 * Configuration CORS.
 * Les origines autorisees viennent de FRONTEND_URL (.env), separees par des virgules.
 * En local, on autorise les serveurs de dev Vite par defaut.
 */

$origins = array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', ''))));

if (empty($origins)) {
    $origins = [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Jetons Bearer (pas de cookies de session cross-site) : pas besoin de credentials.
    'supports_credentials' => false,
];
