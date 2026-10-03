<?php

namespace App\Http\Controllers\Api;

use App\Services\AgentClient;
use App\Services\AgentException;
use App\Services\AlertEngine;
use App\Services\AlertNotifier;
use App\Services\Retention;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Depannage : actions sures, adaptees aux fonctions existantes. Chacune exige le role prevu, une
 * confirmation explicite, et laisse une trace dans l'audit (resultat compris). Les actions sur la
 * plateforme sont executees par la plateforme elle-meme (API signee de l'agent).
 */
class ActionController extends ConsoleController
{
    public const ACTIONS = [
        'health_check' => [
            'label' => 'Vérifier l\'état maintenant',
            'help' => 'Relance tout de suite les contrôles, la détection des incidents, les enquêtes et les actions automatiques.',
            'role' => 'admin', 'impact' => 'Aucun effet pour les membres.', 'where' => 'console',
        ],
        'run_automation' => [
            'label' => 'Lancer les automatismes',
            'help' => 'Exécute sur la plateforme les rappels, anniversaires, relances FISS et nettoyages (app:tick). Sans doublon : un rappel déjà envoyé ne repart pas.',
            'role' => 'admin', 'impact' => 'Peut envoyer les notifications prévues à cette heure-ci.', 'where' => 'platform',
        ],
        'flush_push' => [
            'label' => 'Renvoyer les notifications en attente',
            'help' => 'Envoie les notifications push restées dans la boîte d\'envoi de la plateforme (3 essais au plus par envoi).',
            'role' => 'admin', 'impact' => 'Les membres concernés reçoivent les notifications retardées.', 'where' => 'platform',
        ],
        'test_alert' => [
            'label' => 'Envoyer une alerte de test',
            'help' => 'Vérifie que les canaux d\'alerte configurés fonctionnent (application, courriel, webhook).',
            'role' => 'admin', 'impact' => 'Les destinataires des alertes reçoivent un message de test.', 'where' => 'console',
        ],
        'sms_check' => [
            'label' => 'Diagnostic des SMS',
            'help' => 'Vérifie les identifiants Twilio Verify de la plateforme et le service configuré, sans envoyer de SMS.',
            'role' => 'admin', 'impact' => 'Aucun SMS envoyé.', 'where' => 'platform',
        ],
        'clear_config' => [
            'label' => 'Recharger la configuration de la plateforme',
            'help' => 'Vide les caches de configuration, de routes et de vues de la plateforme : à utiliser après une modification de son fichier .env.',
            'role' => 'owner', 'impact' => 'La première requête suivante est un peu plus lente.', 'where' => 'platform',
        ],
        'purge' => [
            'label' => 'Appliquer la conservation maintenant',
            'help' => 'Efface dès maintenant les journaux et mesures plus anciens que les durées de conservation réglées (console et plateforme).',
            'role' => 'owner', 'impact' => 'Suppression définitive des données de supervision anciennes.', 'where' => 'console',
        ],
    ];

    public function __construct(private AgentClient $agent) {}

    public function index(): JsonResponse
    {
        $last = DB::table('audit_logs')->whereIn('action', array_map(fn ($k) => 'action.'.$k, array_keys(self::ACTIONS)))
            ->orderByDesc('id')->get(['action', 'outcome', 'operator_label', 'created_at'])->unique('action')->keyBy('action');

        return response()->json([
            'actions' => collect(self::ACTIONS)->map(fn ($a, $key) => $a + [
                'key' => $key,
                'available' => $a['where'] === 'console' || $this->agent->configured(),
                'last' => isset($last['action.'.$key]) ? [
                    'outcome' => $last['action.'.$key]->outcome, 'by' => $last['action.'.$key]->operator_label,
                    'at' => $this->iso($last['action.'.$key]->created_at),
                ] : null,
            ])->values(),
        ]);
    }

    public function run(Request $request, string $action): JsonResponse
    {
        abort_unless(isset(self::ACTIONS[$action]), 404, 'Action inconnue.');
        $request->validate(['confirm' => ['accepted'], 'incident_id' => ['nullable', 'integer']], ['confirm.accepted' => 'Confirmez l\'action avant de la lancer.']);
        $operator = $this->operator($request);
        $def = self::ACTIONS[$action];
        if (! $operator->atLeast($def['role'])) {
            Audit::log($operator, 'action.'.$action, 'denied', details: ['required_role' => $def['role']]);
            abort(403, 'Votre rôle ne permet pas cette action.');
        }

        try {
            [$message, $details] = $this->execute($action, $operator->label());
        } catch (AgentException $e) {
            Audit::log($operator, 'action.'.$action, 'failure', details: ['error' => $e->getMessage()]);
            abort($e->status(), 'La plateforme n\'a pas pu exécuter l\'action : '.$e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            Audit::log($operator, 'action.'.$action, 'failure', details: ['error' => $e->getMessage()]);
            abort(500, 'L\'action a échoué : '.mb_substr(\App\Support\Redactor::text($e->getMessage()), 0, 200));
        }
        Audit::log($operator, 'action.'.$action, 'success', $request->integer('incident_id') ? 'incident' : null,
            $request->integer('incident_id') ?: null, details: $details);

        // Action lancee depuis l'enquete d'un incident : notee dans son historique.
        if ($incident = $request->integer('incident_id') ? \App\Models\Incident::find($request->integer('incident_id')) : null) {
            $incident->addTimeline('action', self::ACTIONS[$action]['label'].' : '.$message, $operator);
            $incident->save();
        }

        return response()->json(['message' => $message, 'details' => $details]);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function execute(string $action, string $actor): array
    {
        if (self::ACTIONS[$action]['where'] === 'platform') {
            $r = $this->agent->post('actions/'.$action, [], $actor);

            return [(string) ($r['message'] ?? 'Fait.'), (array) ($r['details'] ?? [])];
        }

        return match ($action) {
            'health_check' => (function () {
                $r = AlertEngine::evaluate(true);

                return ['Vérification terminée : état « '.($r['status'] ?? '?').' », '.($r['opened'] ?? 0).' incident(s) ouvert(s), '
                    .($r['resolved'] ?? 0).' résolu(s), '.($r['auto_actions'] ?? 0).' action(s) automatique(s).', $r];
            })(),
            'test_alert' => (function () {
                $results = AlertNotifier::send('Test d\'alerte de la console', ['Ceci est un message de test envoyé depuis la console de supervision.'], '/reglages', 'info');

                return [AlertNotifier::describe($results), ['results' => $results]];
            })(),
            'purge' => (function () {
                $deleted = Retention::purge();

                return [$deleted ? array_sum($deleted).' ligne(s) ancienne(s) supprimée(s).' : 'Rien à supprimer.', ['deleted' => $deleted]];
            })(),
        };
    }
}
