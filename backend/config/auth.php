<?php

use App\Models\Operator;

/*
| Authentification de la console : uniquement les personnes autorisees (table operators),
| par code SMS a usage unique, avec un jeton Sanctum a duree limitee. Aucun mot de passe.
*/

return [

    'defaults' => [
        'guard' => 'console',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'operators',
        ],

        'console' => [
            'driver' => 'sanctum',
            'provider' => 'operators',
        ],
    ],

    'providers' => [
        'operators' => [
            'driver' => 'eloquent',
            'model' => Operator::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
