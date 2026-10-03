<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Operator;
use App\Support\Like;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Journal d'audit de la console (lecture seule : aucune route de modification). */
class AuditController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, '30d');
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'operator_id' => ['nullable', 'integer'],
            'outcome' => ['nullable', 'in:success,failure,denied'],
            'before_id' => ['nullable', 'integer'],
        ]);
        $q = AuditLog::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if (! empty($f['action'])) {
            str_ends_with($f['action'], '.') ? Like::where($q, 'action', Like::escape($f['action']).'%') : $q->where('action', $f['action']);
        }
        foreach (['operator_id', 'outcome'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['before_id'])) {
            $q->where('id', '<', $f['before_id']);
        }
        $rows = $q->orderByDesc('id')->limit(51)->get();

        return response()->json([
            'logs' => $rows->take(50)->map(fn (AuditLog $l) => [
                'id' => $l->id, 'created_at' => $this->iso($l->created_at), 'action' => $l->action,
                'label' => Audit::LABELS[$l->action] ?? $l->action, 'operator' => $l->operator_label ?: 'Non identifié',
                'operator_id' => $l->operator_id, 'target_type' => $l->target_type, 'target_id' => $l->target_id,
                'target' => $l->target_label, 'outcome' => $l->outcome, 'details' => $l->details, 'ip' => $l->ip,
            ])->values(),
            'next_before_id' => $rows->count() > 50 ? $rows->take(50)->last()->id : null,
            'actions' => collect(Audit::LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'operators' => Operator::orderBy('id')->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->label()])->values(),
        ]);
    }
}
