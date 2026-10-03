<?php

namespace App\Support;

/**
 * Classement des routes de l'API par service de la plateforme (graphiques « par service »)
 * et des methodes HTTP par type d'action.
 */
class ServiceMap
{
    public const LABELS = [
        'connexion' => 'Connexion et compte',
        'accueil' => 'Tableau de bord',
        'notifications' => 'Notifications',
        'calendrier' => 'Calendrier et événements',
        'fiss' => 'FISS et validations',
        'suivi' => 'Suivi spirituel',
        'exercices' => 'Exercices',
        'annonces' => 'Annonces',
        'rapports' => 'Rapports',
        'membres' => 'Membres',
        'roles' => 'Rôles',
        'presences' => 'Présences',
        'organisation' => 'Organisation',
        'profil' => 'Profil et famille',
        'demandes' => 'Demandes',
        'servir' => 'Servir',
        'supervision' => 'Supervision',
        'console' => 'Console',
        'securite' => 'Sécurité',
        'autre' => 'Autres',
    ];

    /** Sources du journal central (colonne service de monitor_events). */
    public const SOURCES = [
        'api' => 'Serveur (API)',
        'auth' => 'Connexion des membres',
        'console' => 'Console',
        'automation' => 'Tâches planifiées',
        'push' => 'Notifications push',
        'sms' => 'SMS (Twilio Verify)',
        'browser' => 'Navigateur',
        'database' => 'Base de données',
        'security' => 'Sécurité',
    ];

    /** Types d'evenements du journal central. */
    public const EVENT_LABELS = [
        'auth.login' => 'Connexion',
        'auth.otp_requested' => 'Code de connexion demandé',
        'auth.otp_failed' => 'Code de connexion erroné',
        'auth.blocked' => 'Connexion refusée (compte bloqué)',
        'console.login' => 'Connexion à la console',
        'console.otp_requested' => 'Code de la console demandé',
        'console.otp_failed' => 'Code erroné (console)',
        'console.login_denied' => 'Connexion à la console refusée',
        'console.denied' => 'Action de la console refusée',
        'exception' => 'Erreur serveur',
        'log' => 'Journal du serveur',
        'client.error' => 'Erreur du navigateur',
        'db.slow_query' => 'Requête SQL lente',
        'job.failed' => 'Tâche planifiée en échec',
    ];

    /** Service d'une action du journal d'audit de l'eglise (prefixe de l'action). */
    public static function auditService(string $action): string
    {
        return match (strtok($action, '.')) {
            'fiss' => 'fiss',
            'tribe_change', 'tribe', 'department' => 'organisation',
            'member' => 'membres',
            'profile', 'family' => 'profil',
            'role' => 'roles',
            'announcement' => 'annonces',
            'event' => 'calendrier',
            'exercise' => 'exercices',
            'evaluation' => 'suivi',
            'report', 'leader_report', 'gem_report' => 'rapports',
            'verse' => 'accueil',
            default => 'autre',
        };
    }

    public const ACTION_LABELS = ['read' => 'Consultation', 'create' => 'Création / action', 'update' => 'Modification', 'delete' => 'Suppression'];

    /** Prefixes (sans « api/ »), du plus precis au plus general. */
    private const PREFIXES = [
        'console' => 'console',
        'monitor' => 'supervision',
        'health' => 'supervision',
        'auth' => 'connexion',
        'me/fiss' => 'fiss',
        'admin/validations' => 'fiss',
        'admin/members/{user}/fiss' => 'fiss',
        'admin/members/{user}/spiritual' => 'suivi',
        'admin/members/{user}/evaluations' => 'suivi',
        'admin/evaluations' => 'suivi',
        'me/spiritual' => 'suivi',
        'me/evaluations' => 'suivi',
        'me/overview' => 'suivi',
        'me/notifications' => 'notifications',
        'me/notification-prefs' => 'notifications',
        'me/push' => 'notifications',
        'me/pulse' => 'notifications',
        'push' => 'notifications',
        'calendar' => 'calendrier',
        'me/events' => 'calendrier',
        'admin/events' => 'calendrier',
        'me/exercises' => 'exercices',
        'admin/exercises' => 'exercices',
        'me/announcements' => 'annonces',
        'admin/announcements' => 'annonces',
        'admin/reports' => 'rapports',
        'admin/leader-reports' => 'rapports',
        'admin/gem-reports' => 'rapports',
        'admin/stats' => 'rapports',
        'admin/roles' => 'roles',
        'admin/permissions' => 'roles',
        'admin/members/{user}/roles' => 'roles',
        'admin/attendance' => 'presences',
        'admin/organization' => 'organisation',
        'admin/tribes' => 'organisation',
        'admin/departments' => 'organisation',
        'admin/gems' => 'organisation',
        'admin/members' => 'membres',
        'admin/new-members' => 'membres',
        'admin/leaders' => 'membres',
        'admin/audiences' => 'annonces',
        'admin/requests' => 'demandes',
        'me/requests' => 'demandes',
        'me/services' => 'servir',
        'profile' => 'profil',
        'me/family' => 'profil',
        'me/spiritual-profile' => 'profil',
        'me/tribe-change' => 'profil',
        'me/home' => 'accueil',
        'me/welcome-back' => 'accueil',
        'dashboard' => 'accueil',
        'admin/verses' => 'accueil',
        'reference' => 'accueil',
        'me' => 'connexion',
    ];

    public static function service(string $route): string
    {
        $path = preg_replace('#^/?api/#', '', $route) ?? $route;
        // me/spiritual-profile avant me/spiritual : on compare sur un segment complet.
        $best = null;
        foreach (self::PREFIXES as $prefix => $service) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $service];
            }
        }

        return $best[1] ?? 'autre';
    }

    public static function label(?string $service): string
    {
        return self::LABELS[$service] ?? ($service ?: 'Autres');
    }

    public static function action(string $method): string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD' => 'read',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'create',
        };
    }
}
