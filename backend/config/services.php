<?php

return [

    // Codes de connexion a la console : 'log' en developpement (code affiche a l'ecran en local),
    // 'twilio_verify' en production (memes identifiants Twilio que la plateforme, ou un service dedie).
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            'api_key_sid' => env('TWILIO_API_KEY_SID'),
            'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
            'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
            'template_sid' => env('TWILIO_VERIFY_TEMPLATE_SID'),
            'retry_delay_ms' => (int) env('TWILIO_RETRY_DELAY_MS', 1500),
        ],
    ],

];
