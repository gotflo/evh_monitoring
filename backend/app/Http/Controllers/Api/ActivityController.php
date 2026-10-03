<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\PlatformAudit;
use App\Support\ServiceMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Activite de la plateforme : actions des membres et responsables (journal d'audit de l'eglise),
 * connexions et evenements de securite, dans un seul fil filtrable.
 */
class ActivityController extends ConsoleController
{
    private const PAGE = 50;

    /** Types du journal central affiches dans l'activite (le reste est dans « Journaux »). */
    private const EVENT_TYPES = ['auth.login', 'auth.otp_requested', 'auth.otp_failed', 'auth.blocked'];

    /** Connexions a la console (journal d'audit de la console). */
    private const CONSOLE_TYPES = ['console.login', 'console.login_failed', 'console.login_denied', 'console.denied'];

    public static function auditLabel(string $action): string
    {
        return PlatformAudit::label($action);
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '7d');
        $f = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'service' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:60'],
            'outcome' => ['nullable', 'in:success,failure,denied'],
            'before' => ['nullable', 'date'],
        ]);
        $before = ! empty($f['before']) ? Carbon::parse($f['before'])->setTimezone(config('app.timezone')) : null;
        $limit = self::PAGE + 1;

        $items = collect();

        // Journal d'audit de l'eglise (actions reussies par definition).
        $auditWanted = (empty($f['outcome']) || $f['outcome'] === 'success')
            && (empty($f['type']) || ! in_array($f['type'], array_merge(self::EVENT_TYPES, self::CONSOLE_TYPES), true))
            && (empty($f['service']) || ! in_array($f['service'], ['connexion', 'console', 'securite'], true));
        if ($auditWanted) {
            $q = DB::connection('app')->table('audit_logs')->where('created_at', '>=', $from)->where('created_at', '<=', $to);
            if ($before) {
                $q->where('created_at', '<', $before);
            }
            if (! empty($f['user_id'])) {
                $q->where(fn ($w) => $w->where('user_id', $f['user_id'])->orWhere('member_user_id', $f['user_id']));
            }
            if (! empty($f['type'])) {
                $q->where('action', $f['type']);
            }
            if (! empty($f['service'])) {
                $actions = array_keys(array_filter(PlatformAudit::LABELS, fn ($l, $a) => ServiceMap::auditService($a) === $f['service'], ARRAY_FILTER_USE_BOTH));
                $q->whereIn('action', $actions ?: ['-']);
            }
            foreach ($q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $l) {
                $service = ServiceMap::auditService($l->action);
                $items->push([
                    'id' => 'a'.$l->id, 'source' => 'audit', 'created_at' => Carbon::parse($l->created_at),
                    'type' => $l->action, 'label' => PlatformAudit::label($l->action),
                    'service' => $service, 'service_label' => ServiceMap::label($service), 'outcome' => 'success',
                    'user_id' => $l->user_id, 'member_user_id' => $l->member_user_id,
                    'subject' => $l->subject_type ? $l->subject_type.' #'.$l->subject_id : null, 'ip' => $l->ip, 'detail' => null,
                ]);
            }
        }

        // Connexions et securite.
        $q = DB::connection('app')->table('monitor_events')->whereIn('type', self::EVENT_TYPES)->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if ($before) {
            $q->where('created_at', '<', $before);
        }
        foreach (['user_id' => 'user_id', 'type' => 'type', 'outcome' => 'outcome'] as $param => $col) {
            if (! empty($f[$param])) {
                $q->where($col, $f[$param]);
            }
        }
        if (! empty($f['service'])) {
            match ($f['service']) {
                'connexion' => $q->where('type', 'like', 'auth.%'),
                'console' => $q->whereRaw('1 = 0'),
                'securite' => $q->where(fn ($w) => $w->where('service', 'security')->orWhereIn('outcome', ['denied', 'failure'])),
                default => $q->whereRaw('1 = 0'),
            };
        }
        foreach ($q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $e) {
            $service = $e->service === 'security' ? 'securite' : 'connexion';
            $items->push([
                'id' => 'e'.$e->id, 'source' => 'event', 'created_at' => Carbon::parse($e->created_at),
                'type' => $e->type, 'label' => ServiceMap::EVENT_LABELS[$e->type] ?? $e->type,
                'service' => $service, 'service_label' => ServiceMap::label($service), 'outcome' => $e->outcome,
                'user_id' => $e->user_id, 'member_user_id' => null, 'subject' => null, 'ip' => $e->ip,
                'detail' => $e->message,
            ]);
        }

        // Connexions et refus de la console (journal d'audit de la console).
        $consoleWanted = (empty($f['service']) || in_array($f['service'], ['console', 'securite'], true))
            && (empty($f['type']) || in_array($f['type'], self::CONSOLE_TYPES, true)) && empty($f['user_id']);
        if ($consoleWanted) {
            $q = DB::table('audit_logs')->whereIn('action', self::CONSOLE_TYPES)->where('created_at', '>=', $from)->where('created_at', '<=', $to);
            if ($before) {
                $q->where('created_at', '<', $before);
            }
            if (! empty($f['type'])) {
                $q->where('action', $f['type']);
            }
            if (! empty($f['outcome'])) {
                $q->where('outcome', $f['outcome']);
            }
            if (($f['service'] ?? null) === 'securite') {
                $q->where('outcome', '!=', 'success');
            }
            foreach ($q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $l) {
                $items->push([
                    'id' => 'c'.$l->id, 'source' => 'console', 'created_at' => Carbon::parse($l->created_at),
                    'type' => $l->action, 'label' => Audit::LABELS[$l->action] ?? $l->action,
                    'service' => 'console', 'service_label' => ServiceMap::label('console'), 'outcome' => $l->outcome,
                    'user_id' => null, 'member_user_id' => null, 'subject' => null, 'ip' => $l->ip,
                    'detail' => $l->operator_label ?: $l->target_label,
                ]);
            }
        }

        $sorted = $items->sortByDesc(fn ($i) => $i['created_at']->getTimestamp())->values();
        [$page, $next] = $this->cut($sorted);

        $names = $this->names($page->pluck('user_id')->merge($page->pluck('member_user_id'))->filter()->unique()->all());
        $places = \App\Services\GeoLocator::lookup($page->pluck('ip')->all());

        return response()->json([
            'items' => $page->map(fn ($i) => array_merge($i, [
                'created_at' => $i['created_at']->toIso8601String(),
                'user' => $i['user_id'] ? ($names[$i['user_id']] ?? 'Compte #'.$i['user_id']) : null,
                'member' => $i['member_user_id'] ? ($names[$i['member_user_id']] ?? 'Compte #'.$i['member_user_id']) : null,
                'location' => $i['ip'] ? ($places[$i['ip']]['label'] ?? null) : null,
            ]))->values(),
            'next_before' => $next,
            'filters' => [
                'services' => collect(['connexion', 'console', 'securite', 'fiss', 'organisation', 'membres', 'profil', 'roles', 'annonces', 'calendrier', 'exercices', 'suivi', 'rapports', 'accueil'])
                    ->map(fn ($k) => ['key' => $k, 'label' => ServiceMap::label($k)])->values(),
                'types' => collect(ServiceMap::EVENT_LABELS)->only(self::EVENT_TYPES)->merge(collect(Audit::LABELS)->only(self::CONSOLE_TYPES))->merge(PlatformAudit::LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ],
        ]);
    }

    /**
     * Page coupee sur un changement de seconde : les elements de la meme seconde que le dernier
     * passent a la page suivante (curseur « strictement avant »), rien n'est perdu ni double.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: string|null}
     */
    private function cut(Collection $sorted): array
    {
        if ($sorted->count() <= self::PAGE) {
            return [$sorted, null];
        }
        $page = $sorted->take(self::PAGE);
        $lastTs = $page->last()['created_at']->getTimestamp();
        $trimmed = $page->filter(fn ($i) => $i['created_at']->getTimestamp() > $lastTs)->values();
        if ($trimmed->isNotEmpty()) {
            // La page suivante reprend a la seconde ecartee (« strictement avant » la seconde suivante).
            return [$trimmed, Carbon::createFromTimestamp($lastTs + 1, config('app.timezone'))->toIso8601String()];
        }

        // Plus de 50 elements dans la meme seconde : tous affiches, la suite commence avant cette seconde.
        return [$sorted->filter(fn ($i) => $i['created_at']->getTimestamp() === $lastTs)->values(), Carbon::createFromTimestamp($lastTs, config('app.timezone'))->toIso8601String()];
    }

    /** @param array<int> $ids @return array<int, string> */
    private function names(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::connection('app')->table('users')->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')->whereIn('users.id', $ids)
            ->get(['users.id', 'users.phone', 'profiles.first_name', 'profiles.last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: \App\Models\Operator::maskPhone($u->phone)])
            ->all();
    }
}
