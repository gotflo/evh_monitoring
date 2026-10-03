<?php

namespace App\Http\Controllers\Api;

use App\Models\Operator;
use App\Services\MonitorSettings;
use App\Services\SecurityChecks;
use App\Support\Audit;
use App\Support\ServiceMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Securite : controles de configuration, acces refuses, comportements suspects detectables
 * dans les donnees existantes, comptes bloques, connexions a la console, vulnerabilites.
 */
class SecurityController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '7d');

        $denied = DB::connection('app')->table('monitor_requests')->whereIn('reason', ['denied', 'throttled'])->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        $otpFailures = DB::connection('app')->table('monitor_events')->whereIn('type', ['auth.otp_failed', 'console.otp_failed'])->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        $otpRequests = DB::connection('app')->table('monitor_events')->whereIn('type', ['auth.otp_requested', 'console.otp_requested'])->where('created_at', '>=', $from)->where('created_at', '<=', $to);

        $byIp = fn ($q, int $min) => (clone $q)->whereNotNull('ip')->groupBy('ip')->selectRaw('ip, COUNT(*) as n, MAX(created_at) as last_at')
            ->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['ip' => $r->ip, 'count' => (int) $r->n, 'last_at' => $this->iso($r->last_at)])->values();

        $deniedUsers = (clone $denied)->where('reason', 'denied')->where('status', 403)->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as n, MAX(created_at) as last_at')->havingRaw('COUNT(*) >= 5')->orderByDesc('n')->limit(10)->get();
        $names = DB::connection('app')->table('profiles')->whereIn('user_id', $deniedUsers->pluck('user_id'))->get(['user_id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($p) => [$p->user_id => trim($p->first_name.' '.$p->last_name)]);

        $suspicious = [];
        foreach ($byIp($otpFailures, 5) as $r) {
            $suspicious[] = ['kind' => 'otp_failures', 'label' => $r['count'].' codes erronés depuis '.$r['ip'], 'ip' => $r['ip'], 'count' => $r['count'], 'last_at' => $r['last_at']];
        }
        foreach ($byIp($otpRequests, 10) as $r) {
            $suspicious[] = ['kind' => 'otp_requests', 'label' => $r['count'].' demandes de code depuis '.$r['ip'], 'ip' => $r['ip'], 'count' => $r['count'], 'last_at' => $r['last_at']];
        }
        foreach ($deniedUsers as $u) {
            $suspicious[] = ['kind' => 'denied_user', 'label' => ($names[$u->user_id] ?? 'Compte #'.$u->user_id).' : '.$u->n.' accès refusés (403)',
                'user_id' => (int) $u->user_id, 'count' => (int) $u->n, 'last_at' => $this->iso($u->last_at)];
        }
        $consoleDenied = DB::table('audit_logs')->whereIn('action', ['console.login_denied', 'console.denied'])->where('created_at', '>=', $from)->count();
        if ($consoleDenied) {
            $suspicious[] = ['kind' => 'console_denied', 'label' => $consoleDenied.' accès refusé(s) à la console', 'count' => $consoleDenied];
        }

        return response()->json([
            'checks' => SecurityChecks::configuration(\Illuminate\Support\Facades\Cache::get('monitor:last-health') ?? \App\Services\PlatformHealth::run()),
            'denied' => [
                'total' => (clone $denied)->count(),
                'by_status' => (clone $denied)->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status'),
                'by_route' => (clone $denied)->groupBy('route', 'status')->selectRaw('route, status, COUNT(*) as n')->orderByDesc('n')->limit(10)->get()
                    ->map(fn ($r) => ['route' => $r->route, 'service' => ServiceMap::label(ServiceMap::service($r->route)), 'status' => (int) $r->status, 'count' => (int) $r->n]),
                'by_ip' => $byIp($denied, 1),
            ],
            'otp' => [
                'failures' => (clone $otpFailures)->count(),
                'requests' => (clone $otpRequests)->count(),
                'failures_by_ip' => $byIp($otpFailures, 1),
            ],
            'suspicious' => $suspicious,
            'blocked_users' => DB::connection('app')->table('users')->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')->whereNotNull('users.blocked_at')
                ->orderByDesc('users.blocked_at')->limit(50)->get(['users.id', 'users.phone', 'users.blocked_at', 'users.blocked_reason', 'profiles.first_name', 'profiles.last_name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: Operator::maskPhone($u->phone),
                    'blocked_at' => $this->iso($u->blocked_at), 'reason' => $u->blocked_reason]),
            'console_logins' => DB::table('audit_logs')->whereIn('action', ['console.login', 'console.login_denied', 'console.login_failed'])
                ->orderByDesc('id')->limit(15)->get(['id', 'action', 'operator_label', 'target_label', 'outcome', 'ip', 'created_at'])
                ->map(fn ($l) => ['id' => $l->id, 'label' => Audit::LABELS[$l->action] ?? $l->action, 'who' => $l->operator_label ?: $l->target_label,
                    'outcome' => $l->outcome, 'ip' => $l->ip, 'created_at' => $this->iso($l->created_at)]),
            'vulnerabilities' => MonitorSettings::get('vulnerabilities'),
            'vulnerability_check_enabled' => (bool) config('monitoring.vulnerability_check'),
            'localities' => $this->localities($from, $to),
            'geo' => ['enabled' => \App\Services\GeoLocator::enabled(), 'provider' => \App\Services\GeoLocator::provider(), 'home_countries' => \App\Services\GeoLocator::homeCountries()],
            'limits' => [
                'Les dépendances des interfaces (npm) ne sont pas présentes sur les serveurs : lancez « npm audit » dans chaque dossier frontend avant une mise en ligne.',
                'Les tentatives bloquées par l\'hébergeur (pare-feu, WAF) avant d\'atteindre l\'application ne sont pas visibles ici.',
                'Les adresses IP peuvent être partagées (réseau mobile, Wi-Fi de l\'église) : vérifiez avant de conclure à un abus.',
            ],
        ]);
    }

    /** Connexions de la periode regroupees par localite (les pays inhabituels en premier). @return array<int, array<string, mixed>> */
    private function localities(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        $logins = DB::connection('app')->table('monitor_events')->where('type', 'auth.login')->whereNotNull('ip')
            ->where('created_at', '>=', $from)->where('created_at', '<=', $to)->limit(5000)->get(['ip', 'user_id', 'created_at']);
        $places = \App\Services\GeoLocator::lookup($logins->pluck('ip')->all());
        $home = \App\Services\GeoLocator::homeCountries();

        return $logins->groupBy(fn ($l) => $places[$l->ip]['label'] ?? 'Localité inconnue')
            ->map(function ($g, $label) use ($places, $home) {
                $cc = $places[$g->first()->ip]['country_code'] ?? null;

                return ['label' => $label, 'country_code' => $cc, 'logins' => $g->count(), 'members' => $g->pluck('user_id')->filter()->unique()->count(),
                    'last_at' => $this->iso($g->max('created_at')), 'unusual' => $cc !== null && ! in_array($cc, $home, true)];
            })
            ->sortBy([['unusual', 'desc'], ['logins', 'desc']])->take(30)->values()->all();
    }

    public function checkVulnerabilities(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        try {
            $result = SecurityChecks::vulnerabilities();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Audit::log($operator, 'action.vulnerability_check', 'failure', details: ['error' => $e->getMessage()]);
            abort(503, 'Vérification impossible pour le moment : '.mb_substr($e->getMessage(), 0, 160));
        }
        Audit::log($operator, 'action.vulnerability_check', 'success', details: ['packages' => $result['packages'], 'advisories' => count($result['advisories'])]);

        return response()->json([
            'message' => count($result['advisories']) ? count($result['advisories']).' avis de sécurité concernent les versions installées.' : 'Aucune vulnérabilité connue pour les '.$result['packages'].' paquets PHP installés.',
            'result' => $result,
        ]);
    }
}
