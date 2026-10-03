<?php

namespace App\Support;

/**
 * Libelles des actions du journal d'audit de la plateforme (table audit_logs de sa base),
 * repris a l'identique pour les afficher dans la console.
 */
class PlatformAudit
{
    public const LABELS = [
        'fiss.created' => 'FISS enregistrée',
        'fiss.locked' => 'FISS verrouillée',
        'fiss.unlocked' => 'FISS déverrouillée',
        'fiss.modified' => 'FISS modifiée',
        'fiss.edit_requested' => 'Demande de modification de FISS',
        'fiss.edit_approved' => 'Modification de FISS approuvée',
        'fiss.edit_rejected' => 'Modification de FISS refusée',
        'fiss.edit_cancelled' => 'Demande de modification annulée',
        'tribe_change.requested' => 'Changement de tribu demandé',
        'tribe_change.approved' => 'Changement de tribu approuvé',
        'tribe_change.rejected' => 'Changement de tribu refusé',
        'tribe_change.cancelled' => 'Demande de changement de tribu annulée',
        'tribe.changed' => 'Tribu modifiée',
        'member.inactivated' => 'Membre devenu inactif',
        'member.reactivated' => 'Membre réactivé',
        'member.status_forced' => "Statut d'activité forcé",
        'member.belonging_updated' => 'Appartenance modifiée',
        'member.welcomed' => 'Nouvel inscrit accueilli',
        'member.blocked' => 'Compte bloqué (console de supervision)',
        'member.unblocked' => 'Compte débloqué (console de supervision)',
        'member.sessions_revoked' => 'Sessions fermées (console de supervision)',
        'member.deleted' => 'Compte supprimé (console de supervision)',
        'profile.updated' => 'Profil modifié',
        'family.spouse_designated' => 'Conjoint(e) désigné(e)',
        'family.spouse_name_set' => 'Nom du conjoint renseigné',
        'family.spouse_unlinked' => 'Lien conjugal retiré',
        'family.link_confirmed' => 'Lien familial confirmé',
        'family.link_declined' => 'Lien familial refusé',
        'family.children_updated' => 'Enfants modifiés',
        'role.assigned' => 'Rôle attribué',
        'role.revoked' => 'Rôle retiré',
        'role.removed' => 'Rôle supprimé',
        'department.deleted' => 'Département supprimé',
        'department.leaders_updated' => 'Responsables de département modifiés',
        'announcement.published' => 'Annonce publiée',
        'announcement.deleted' => 'Annonce supprimée',
        'event.created' => 'Événement créé',
        'event.updated' => 'Événement modifié',
        'event.deleted' => 'Événement supprimé',
        'exercise.created' => 'Exercice créé',
        'exercise.deleted' => 'Exercice supprimé',
        'evaluation.created' => 'Note ajoutée',
        'evaluation.deleted' => 'Note supprimée',
        'report.exported' => 'Rapport exporté (PDF)',
        'leader_report.submitted' => 'Rapport mensuel envoyé',
        'leader_report.updated' => 'Rapport mensuel corrigé',
        'leader_report.exported' => 'Rapport mensuel exporté (PDF)',
        'gem_report.submitted' => 'Rapport de GEM envoyé',
        'gem_report.updated' => 'Rapport de GEM corrigé',
        'verse.created' => 'Verset du tableau de bord ajouté',
        'verse.updated' => 'Verset du tableau de bord modifié',
        'verse.deleted' => 'Verset du tableau de bord supprimé',
        'verse.reordered' => 'Ordre des versets modifié',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }
}
