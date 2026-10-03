<?php

namespace App\Http\Controllers\Api;

use App\Services\Metrics;
use App\Services\AgentClient;
use App\Services\AgentException;
use App\Support\Like;
use App\Support\Audit;
use App\Support\Redactor;
use App\Support\ServiceMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journaux et diagnostic : journal central (serveur, navigateur, integrations, taches, base de
 * donnees, securite), erreurs regroupees, requetes en echec ou lentes, performances, et lecture
 * des fichiers journaux du serveur (masques). Rien n'est modifiable depuis ces ecrans.
 */
class LogController extends ConsoleController
{
    private const PAGE = 50;

    public const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '24h');
        $f = $request->validate([
            'level' => ['nullable', 'in:debug,info,notice,warning,error,critical'],
            'service' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'string', 'max:32'],
            'fingerprint' => ['nullable', 'string', 'size:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'before_id' => ['nullable', 'integer'],
        ]);
        $q = DB::connection('app')->table('monitor_events');
        // Un identifiant de requete ou une empreinte suffit : la periode ne limite pas la recherche.
        if (empty($f['request_id']) && empty($f['fingerprint'])) {
            $q->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        }
        if (! empty($f['level'])) {
            $min = self::LEVELS[$f['level']];
            $q->whereIn('level', array_keys(array_filter(self::LEVELS, fn ($v) => $v >= $min)));
        }
        foreach (['service', 'type', 'user_id', 'request_id', 'fingerprint'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['q'])) {
            Like::contains($q, 'message', $f['q']);
        }
        if (! empty($f['before_id'])) {
            $q->where('id', '<', $f['before_id']);
        }
        $rows = $q->orderByDesc('id')->limit(self::PAGE + 1)->get(['id', 'created_at', 'level', 'service', 'type', 'outcome', 'message', 'user_id', 'request_id', 'route', 'fingerprint']);

        return response()->json([
            'events' => $rows->take(self::PAGE)->map(fn ($e) => $this->event($e))->values(),
            'next_before_id' => $rows->count() > self::PAGE ? $rows->take(self::PAGE)->last()->id : null,
            'filters' => [
                'services' => collect(ServiceMap::SOURCES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
                'types' => collect(ServiceMap::EVENT_LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $e = DB::connection('app')->table('monitor_events')->find($id);
        abort_unless($e, 404, 'Événement introuvable (il a peut-être dépassé la durée de conservation).');

        $related = $e->request_id
            ? DB::connection('app')->table('monitor_events')->where('request_id', $e->request_id)->where('id', '!=', $e->id)->orderBy('id')->limit(30)->get()
            : collect();
        $request = $e->request_id ? DB::connection('app')->table('monitor_requests')->where('request_id', $e->request_id)->first() : null;
        $similar = null;
        if ($e->fingerprint) {
            $base = DB::connection('app')->table('monitor_events')->where('fingerprint', $e->fingerprint);
            $similar = [
                'total' => (clone $base)->count(),
                'last_24h' => (clone $base)->where('created_at', '>=', now()->subDay())->count(),
                'last_7d' => (clone $base)->where('created_at', '>=', now()->subDays(7))->count(),
                'users' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
                'first_at' => $this->iso((clone $base)->min('created_at')),
                'last_at' => $this->iso((clone $base)->max('created_at')),
                'recent' => (clone $base)->orderByDesc('id')->limit(10)->get(['id', 'created_at', 'user_id', 'request_id', 'route'])
                    ->map(fn ($r) => ['id' => $r->id, 'created_at' => $this->iso($r->created_at), 'user_id' => $r->user_id, 'request_id' => $r->request_id, 'route' => $r->route]),
            ];
        }

        return response()->json([
            'event' => $this->event($e) + [
                'context' => $e->context ? json_decode($e->context, true) : null,
                'ip' => $e->ip,
            ],
            'request' => $request ? $this->requestRow($request) : null,
            'related' => $related->map(fn ($r) => $this->event($r))->values(),
            'similar' => $similar,
        ]);
    }

    /** Erreurs regroupees (meme cause) sur la periode. */
    public function errors(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '7d');
        $service = $request->validate(['service' => ['nullable', 'string', 'max:30']])['service'] ?? null;

        return response()->json([
            'groups' => Metrics::topErrors($from, $to, 50, $service),
            'series' => Metrics::eventSeries($from->copy()->startOfDay(), $to),
        ]);
    }

    /** Requetes notables : erreurs serveur, refus, limites, lenteurs. */
    public function requests(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '24h');
        $f = $request->validate([
            'reason' => ['nullable', 'in:error,slow,denied,throttled'],
            'status' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'string', 'max:32'],
            'route' => ['nullable', 'string', 'max:160'],
            'before_id' => ['nullable', 'integer'],
        ]);
        $q = DB::connection('app')->table('monitor_requests');
        if (empty($f['request_id'])) {
            $q->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        }
        foreach (['reason', 'status', 'user_id', 'request_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['route'])) {
            Like::contains($q, 'route', $f['route']);
        }
        if (! empty($f['before_id'])) {
            $q->where('id', '<', $f['before_id']);
        }
        $rows = $q->orderByDesc('id')->limit(self::PAGE + 1)->get();

        return response()->json([
            'requests' => $rows->take(self::PAGE)->map(fn ($r) => $this->requestRow($r))->values(),
            'next_before_id' => $rows->count() > self::PAGE ? $rows->take(self::PAGE)->last()->id : null,
        ]);
    }

    /** Performances : routes les plus lentes et evolution des temps de reponse. */
    public function performance(Request $request): JsonResponse
    {
        [$from, $to, $step] = $this->period($request, '24h');

        return response()->json([
            'summary' => Metrics::requests($from, $to),
            'series' => Metrics::requestSeries($from, $to, $step),
            'routes' => Metrics::slowRoutes($from, $to, 20),
            'slow_threshold_ms' => (int) config('monitoring.slow_request_ms'),
            'slow_query_ms' => (int) config('monitoring.slow_query_ms'),
            'slow_queries' => DB::connection('app')->table('monitor_events')->where('type', 'db.slow_query')->where('created_at', '>=', $from)
                ->orderByDesc('id')->limit(10)->get(['id', 'created_at', 'message', 'context', 'route'])
                ->map(fn ($r) => ['id' => $r->id, 'created_at' => $this->iso($r->created_at), 'message' => $r->message,
                    'sql' => json_decode((string) $r->context, true)['sql'] ?? null, 'route' => $r->route]),
        ]);
    }

    /** Fichiers journaux : plateforme (par l'agent, masques a la source) ou console. */
    public function files(Request $request, AgentClient $agent): JsonResponse
    {
        $source = $request->validate(['source' => ['nullable', 'in:platform,console']])['source'] ?? 'platform';
        if ($source === 'console') {
            return response()->json(['source' => 'console', 'files' => collect(glob(storage_path('logs/*.log')) ?: [])
                ->map(fn ($path) => ['name' => basename($path), 'size' => filesize($path) ?: 0, 'modified_at' => date(DATE_ATOM, (int) filemtime($path))])
                ->sortByDesc('modified_at')->values()]);
        }
        try {
            return response()->json(['source' => 'platform'] + $agent->get('logs/files'));
        } catch (AgentException $e) {
            abort($e->status(), $e->getMessage());
        }
    }

    public function file(Request $request, AgentClient $agent, string $name): JsonResponse
    {
        $f = $request->validate([
            'lines' => ['nullable', 'integer', 'min:20', 'max:1000'],
            'q' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'in:platform,console'],
        ]);
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.log$/', $name), 404);
        $source = $f['source'] ?? 'platform';
        if ($source === 'platform') {
            try {
                $r = $agent->get('logs/files/'.$name, array_filter(['lines' => $f['lines'] ?? 200, 'q' => $f['q'] ?? null]), $this->operator($request)->label());
            } catch (AgentException $e) {
                abort($e->status(), $e->getMessage());
            }
            $lines = $r['lines'] ?? [];
        } else {
            $path = storage_path('logs/'.$name);
            abort_unless(is_file($path), 404, 'Fichier introuvable.');
            $lines = self::tail($path, (int) ($f['lines'] ?? 200), 2_000_000);
            if (! empty($f['q'])) {
                $needle = mb_strtolower($f['q']);
                $lines = array_values(array_filter($lines, fn ($l) => str_contains(mb_strtolower($l), $needle)));
            }
            $lines = array_map(fn ($l) => Redactor::text($l, 1500), $lines);
        }
        Audit::log($this->operator($request), 'logs.file_viewed', 'success', 'file', null, $source.' : '.$name, ['lines' => count($lines)]);

        return response()->json(['name' => $name, 'source' => $source, 'lines' => $lines]);
    }

    /**
     * Dernieres lignes d'un fichier (lecture depuis la fin, taille bornee).
     *
     * @return array<int, string>
     */
    private static function tail(string $path, int $count, int $maxBytes): array
    {
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if (! $fh) {
            return [];
        }
        $read = min($size, $maxBytes);
        fseek($fh, -$read, SEEK_END);
        $data = (string) fread($fh, $read);
        fclose($fh);
        $entries = preg_split('/\R(?=\[\d{4}-\d{2}-\d{2}[ T])/', $data) ?: [];
        $entries = array_map(fn ($e) => mb_substr(trim($e), 0, 4000), array_filter($entries, fn ($e) => trim($e) !== ''));

        return array_slice(array_values($entries), -$count);
    }

    /** @return array<string, mixed> */
    private function event(object $e): array
    {
        return [
            'id' => $e->id,
            'created_at' => $this->iso($e->created_at),
            'level' => $e->level,
            'service' => $e->service,
            'service_label' => ServiceMap::SOURCES[$e->service] ?? $e->service,
            'type' => $e->type,
            'type_label' => ServiceMap::EVENT_LABELS[$e->type] ?? $e->type,
            'outcome' => $e->outcome,
            'message' => $e->message,
            'user_id' => $e->user_id,
                        'request_id' => $e->request_id,
            'route' => $e->route,
            'fingerprint' => $e->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    private function requestRow(object $r): array
    {
        return [
            'id' => $r->id, 'created_at' => $this->iso($r->created_at), 'request_id' => $r->request_id, 'reason' => $r->reason,
            'method' => $r->method, 'route' => $r->route, 'service' => ServiceMap::label($r->service), 'status' => $r->status,
            'duration_ms' => $r->duration_ms, 'queries' => $r->queries, 'db_ms' => $r->db_ms, 'user_id' => $r->user_id, 'ip' => $r->ip,
        ];
    }
}
