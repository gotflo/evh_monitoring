<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Operator;

/**
 * Journal d'audit de la console : connexions, changements d'acces, actions sur les comptes,
 * incidents, reglages, depannage et actions automatiques de l'agent, avec la personne
 * responsable, la cible et le resultat. Jamais de code, de jeton ni de contenu prive.
 */
class Audit
{
    /** Auteur des actions declenchees automatiquement par la supervision. */
    public const AGENT = 'Agent automatique';

    public const LABELS = [
        'console.login' => 'Connexion à la console',
        'console.login_failed' => 'Code erroné à la connexion',
        'console.login_denied' => 'Connexion refusée (numéro non autorisé)',
        'console.logout' => 'Déconnexion de la console',
        'console.denied' => 'Action refusée (droits insuffisants)',
        'access.invited' => 'Accès ajouté',
        'access.updated' => 'Accès modifié',
        'access.revoked' => 'Accès retiré',
        'access.restored' => 'Accès rétabli',
        'access.sessions_revoked' => 'Sessions de la console fermées',
        'user.blocked' => 'Compte membre bloqué',
        'user.unblocked' => 'Compte membre débloqué',
        'user.sessions_revoked' => 'Sessions du membre fermées',
        'user.deleted' => 'Compte membre supprimé',
        'incident.acknowledged' => 'Incident pris en charge',
        'incident.resolved' => 'Incident résolu',
        'incident.reopened' => 'Incident rouvert',
        'incident.noted' => 'Note ajoutée à un incident',
        'incident.investigated' => 'Enquête relancée',
        'settings.updated' => 'Réglages modifiés',
        'report.generated' => 'Rapport généré',
        'action.health_check' => 'Vérification lancée',
        'action.run_automation' => 'Automatismes lancés',
        'action.flush_push' => 'Notifications en attente renvoyées',
        'action.test_alert' => 'Alerte de test envoyée',
        'action.sms_check' => 'Diagnostic SMS',
        'action.clear_config' => 'Caches de configuration vidés',
        'action.purge' => 'Conservation appliquée',
        'action.vulnerability_check' => 'Vulnérabilités vérifiées',
        'auto.run_automation' => 'Automatismes relancés automatiquement',
        'auto.flush_push' => 'Notifications renvoyées automatiquement',
        'logs.file_viewed' => 'Fichier journal consulté',
    ];

    /**
     * Seules les valeurs qui changent.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            $prev = $before[$key] ?? null;
            if ((string) json_encode($prev) !== (string) json_encode($value)) {
                $old[$key] = $prev;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(
        ?Operator $operator, string $action, string $outcome = 'success', ?string $targetType = null,
        ?int $targetId = null, ?string $targetLabel = null, array $details = [], ?string $operatorLabel = null,
    ): AuditLog {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'operator_id' => $operator?->id,
            'operator_label' => mb_substr($operator?->label() ?? $operatorLabel ?? '', 0, 120) ?: null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel ? mb_substr($targetLabel, 0, 160) : null,
            'outcome' => $outcome,
            'details' => $details ? Redactor::context($details) : null,
            'ip' => $request?->ip(),
        ]);
    }
}
