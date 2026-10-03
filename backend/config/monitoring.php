<?php

/*
|--------------------------------------------------------------------------
| Console de supervision My vasesdhonneur
|--------------------------------------------------------------------------
| Projet separe de la plateforme, heberge sur son propre sous-domaine.
| Liaisons avec la plateforme :
|  - base de la plateforme (connexion « app ») : lecture des mesures, des comptes et de l'audit ;
|  - API signee de l'agent (PLATFORM_URL/api/agent/*) : actions et etat detaille ;
|  - /api/health public : disponibilite vue de l'exterieur.
*/

return [

    'console' => [
        // Proprietaire principal a la mise en service (seul numero autorise au depart).
        'owner_phone' => env('CONSOLE_OWNER_PHONE', '+14187181876'),
        // Duree d'une session de la console (heures).
        'session_hours' => (int) env('CONSOLE_SESSION_HOURS', 12),
    ],

    'platform' => [
        // Adresse publique de la plateforme supervisee (sans / final).
        'url' => rtrim((string) env('PLATFORM_URL', 'http://127.0.0.1:8000'), '/'),
        // Secret partage avec la plateforme (MONITOR_AGENT_SECRET des deux cotes, 32 caracteres minimum).
        'agent_secret' => env('MONITOR_AGENT_SECRET'),
        // Delai maximal d'une reponse de la plateforme (secondes).
        'timeout' => (int) env('PLATFORM_TIMEOUT', 10),
        // Au-dela, une reponse de /api/health est consideree lente (ms).
        'slow_health_ms' => (int) env('PLATFORM_SLOW_HEALTH_MS', 3000),
    ],

    // Conservation par defaut (jours), modifiable ensuite par le proprietaire dans la console.
    'retention' => [
        'events_days' => (int) env('MONITOR_RETENTION_DAYS', 30),
        'metrics_days' => (int) env('MONITOR_METRICS_RETENTION_DAYS', 90),
        'audit_days' => (int) env('CONSOLE_AUDIT_RETENTION_DAYS', 365),
    ],

    // Canaux d'alerte (le canal « application » passe par l'agent de la plateforme).
    'alerts' => [
        'emails' => env('MONITOR_ALERT_EMAILS'),
        'webhook_url' => env('MONITOR_ALERT_WEBHOOK_URL'),
        'webhook_format' => env('MONITOR_ALERT_WEBHOOK_FORMAT', 'json'),
    ],

    // Localite des connexions (ville, region, pays) d'apres l'adresse IP.
    // ipwhois (https://ipwho.is, defaut) | ipapi (http://ip-api.com) | none (desactive).
    // Les adresses IP publiques des connexions sont transmises au service choisi.
    'geo' => [
        'provider' => env('GEOIP_PROVIDER', 'ipwhois'),
        // Pays habituels : une connexion depuis un autre pays ouvre un incident (codes ISO, separes par des virgules).
        'home_countries' => env('MONITOR_HOME_COUNTRIES', 'CA'),
    ],

    // Verification des vulnerabilites des paquets PHP (base publique de Packagist).
    'vulnerability_check' => env('MONITOR_VULNERABILITY_CHECK', true),

];
