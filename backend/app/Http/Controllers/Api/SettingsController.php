<?php

namespace App\Http\Controllers\Api;

use App\Services\AlertNotifier;
use App\Services\MonitorSettings;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reglages de la supervision : seuils d'alerte (administrateur), rapports et canaux
 * (administrateur), durees de conservation (proprietaire). Les secrets (adresse du webhook,
 * identifiants de courriel) restent dans le fichier .env du serveur et ne sont jamais affiches.
 */
class SettingsController extends ConsoleController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'rules' => collect(MonitorSettings::RULES)->map(fn ($def, $key) => [
                'key' => $key, 'label' => $def['label'], 'help' => $def['help'], 'severity' => $def['severity'],
                'auto_action' => isset($def['auto_action']) ? MonitorSettings::AUTO_ACTIONS[$def['auto_action']] : null,
                'params' => collect($def['params'])->map(fn ($p, $k) => $p + ['key' => $k])->values(),
                'values' => MonitorSettings::rules()[$key],
            ])->values(),
            'retention' => MonitorSettings::retention(),
            'retention_bounds' => MonitorSettings::RETENTION_BOUNDS,
            'reports' => MonitorSettings::reports(),
            'channels' => AlertNotifier::channels(),
            'collection' => [
                'enabled' => (bool) config('monitoring.enabled'),
                'slow_request_ms' => (int) config('monitoring.slow_request_ms'),
                'slow_query_ms' => (int) config('monitoring.slow_query_ms'),
                'log_level' => (string) config('monitoring.log_level'),
                'session_hours' => (int) config('monitoring.console.session_hours'),
                'platform_url' => config('monitoring.platform.url'),
                'agent_configured' => app(\App\Services\AgentClient::class)->configured(),
                'collected_since' => MonitorSettings::installedAt(),
            ],
        ]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        $data = $request->validate(['rules' => ['required', 'array']]);
        $current = MonitorSettings::rules();
        $next = $current;
        foreach ($data['rules'] as $key => $values) {
            if (! isset(MonitorSettings::RULES[$key]) || ! is_array($values)) {
                continue;
            }
            if (array_key_exists('enabled', $values)) {
                $next[$key]['enabled'] = (bool) $values['enabled'];
            }
            if (array_key_exists('auto', $values) && isset(MonitorSettings::RULES[$key]['auto_action'])) {
                $next[$key]['auto'] = (bool) $values['auto'];
            }
            foreach (MonitorSettings::RULES[$key]['params'] as $param => $spec) {
                if (! array_key_exists($param, $values)) {
                    continue;
                }
                $v = $values[$param];
                if (! is_numeric($v) || $v < $spec['min'] || $v > $spec['max']) {
                    throw ValidationException::withMessages(["rules.$key.$param" => $spec['label'].' : entre '.$spec['min'].' et '.$spec['max'].'.']);
                }
                $next[$key][$param] = (int) $v;
            }
        }
        $operator = $this->operator($request);
        MonitorSettings::set('rules', $next, $operator->id);
        $changes = [];
        foreach ($next as $key => $values) {
            [$old, $new] = \App\Support\Audit::diff($current[$key], $values);
            if ($new) {
                $changes[$key] = ['before' => $old, 'after' => $new];
            }
        }
        if ($changes) {
            Audit::log($operator, 'settings.updated', 'success', 'settings', null, 'Seuils d\'alerte', $changes);
        }

        return response()->json(['message' => $changes ? 'Seuils d\'alerte enregistrés.' : 'Aucun changement.']);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reports' => ['nullable', 'array'],
            'reports.daily' => ['boolean'], 'reports.weekly' => ['boolean'], 'reports.monthly' => ['boolean'],
            'channels' => ['nullable', 'array'],
            'channels.app' => ['boolean'], 'channels.email' => ['boolean'], 'channels.webhook' => ['boolean'],
        ]);
        $operator = $this->operator($request);
        $changes = [];
        foreach (['reports' => MonitorSettings::reports(), 'channels' => MonitorSettings::channels()] as $key => $current) {
            if (! isset($data[$key])) {
                continue;
            }
            $next = array_merge($current, array_intersect_key(array_map('boolval', $data[$key]), $current));
            [$old, $new] = \App\Support\Audit::diff($current, $next);
            if ($new) {
                MonitorSettings::set($key, $next, $operator->id);
                $changes[$key] = ['before' => $old, 'after' => $new];
            }
        }
        if ($changes) {
            Audit::log($operator, 'settings.updated', 'success', 'settings', null, 'Rapports et canaux', $changes);
        }

        return response()->json(['message' => $changes ? 'Réglages enregistrés.' : 'Aucun changement.']);
    }

    public function updateRetention(Request $request): JsonResponse
    {
        $rules = [];
        foreach (MonitorSettings::RETENTION_BOUNDS as $key => [$min, $max]) {
            $rules[$key] = ['required', 'integer', 'min:'.$min, 'max:'.$max];
        }
        $data = $request->validate($rules);
        $operator = $this->operator($request);
        $current = MonitorSettings::retention();
        [$old, $new] = \App\Support\Audit::diff($current, array_map('intval', $data));
        if ($new) {
            MonitorSettings::set('retention', array_map('intval', $data), $operator->id);
            Audit::log($operator, 'settings.updated', 'success', 'settings', null, 'Durées de conservation', ['before' => $old, 'after' => $new]);
        }

        return response()->json(['message' => $new ? 'Durées de conservation enregistrées (appliquées lors du nettoyage quotidien).' : 'Aucun changement.']);
    }
}
