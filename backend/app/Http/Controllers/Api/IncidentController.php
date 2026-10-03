<?php

namespace App\Http\Controllers\Api;

use App\Models\Incident;
use App\Services\AlertEngine;
use App\Services\MonitorSettings;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Incidents detectes : liste, detail, prise en charge, notes, resolution. */
class IncidentController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:open,resolved,all'],
            'severity' => ['nullable', 'in:warning,critical'],
            'rule' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $q = Incident::query();
        match ($f['status'] ?? 'open') {
            'open' => $q->where('status', '!=', 'resolved'),
            'resolved' => $q->where('status', 'resolved'),
            default => null,
        };
        foreach (['severity', 'rule'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        $total = (clone $q)->count();
        $page = (int) ($f['page'] ?? 1);
        $items = $q->orderByRaw("CASE status WHEN 'resolved' THEN 1 ELSE 0 END")->orderByDesc('last_seen_at')
            ->offset(($page - 1) * 30)->limit(30)->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'incidents' => $items->map(fn ($i) => $this->row($i))->values(),
            'counts' => [
                'open' => Incident::where('status', 'open')->count(),
                'acknowledged' => Incident::where('status', 'acknowledged')->count(),
                'resolved_7d' => Incident::where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))->count(),
            ],
            'rules' => collect(MonitorSettings::RULES)->map(fn ($r, $k) => ['key' => $k, 'label' => $r['label']])->values(),
        ]);
    }

    public function show(Incident $incident): JsonResponse
    {
        return response()->json(['incident' => $this->row($incident) + [
            'details' => $incident->details,
            'investigation' => $incident->investigation,
            'auto_action' => \App\Services\MonitorSettings::RULES[$incident->rule]['auto_action'] ?? null,
            'auto_action_at' => $this->iso($incident->auto_action_at),
            'timeline' => $incident->timeline ?? [],
            'resolution_note' => $incident->resolution_note,
        ]]);
    }

    public function acknowledge(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $this->ensureOpen($incident);
        $operator = $this->operator($request);
        $incident->fill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $operator->id]);
        $incident->addTimeline('acknowledged', 'Pris en charge'.($note ? ' : '.$note : '.'), $operator);
        $incident->save();
        Audit::log($operator, 'incident.acknowledged', 'success', 'incident', $incident->id, $incident->title, array_filter(['note' => $note]));

        return response()->json(['message' => 'Incident pris en charge.', 'incident' => $this->row($incident)]);
    }

    public function resolve(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']])['note'];
        $this->ensureOpen($incident);
        $operator = $this->operator($request);
        AlertEngine::resolve($incident, $note, $operator);
        Audit::log($operator, 'incident.resolved', 'success', 'incident', $incident->id, $incident->title, ['note' => $note]);

        return response()->json(['message' => 'Incident marqué comme résolu. Il sera rouvert automatiquement si le problème réapparaît.']);
    }

    public function reopen(Request $request, Incident $incident): JsonResponse
    {
        abort_if($incident->isOpen(), 422, 'Cet incident est déjà ouvert.');
        $operator = $this->operator($request);
        $incident->fill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null]);
        $incident->addTimeline('reopened', 'Rouvert manuellement.', $operator);
        $incident->save();
        Audit::log($operator, 'incident.reopened', 'success', 'incident', $incident->id, $incident->title);

        return response()->json(['message' => 'Incident rouvert.']);
    }

    /** Relance l'enquete automatique avec l'etat le plus recent. */
    public function investigate(Request $request, Incident $incident): JsonResponse
    {
        $operator = $this->operator($request);
        $incident->investigation = \App\Services\Investigator::run($incident, \App\Services\PlatformHealth::run());
        $incident->addTimeline('investigated', 'Enquête relancée : '.$incident->investigation['cause'], $operator);
        $incident->save();
        Audit::log($operator, 'incident.investigated', 'success', 'incident', $incident->id, $incident->title);

        return response()->json(['message' => 'Enquête mise à jour.', 'investigation' => $incident->investigation]);
    }

    public function note(Request $request, Incident $incident): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:2', 'max:1000']])['note'];
        $operator = $this->operator($request);
        $incident->addTimeline('note', $note, $operator);
        $incident->save();
        Audit::log($operator, 'incident.noted', 'success', 'incident', $incident->id, $incident->title);

        return response()->json(['message' => 'Note ajoutée.']);
    }

    private function ensureOpen(Incident $incident): void
    {
        if (! $incident->isOpen()) {
            throw ValidationException::withMessages(['note' => 'Cet incident est déjà résolu.']);
        }
    }

    /** @return array<string, mixed> */
    private function row(Incident $i): array
    {
        return [
            'id' => $i->id, 'rule' => $i->rule, 'rule_label' => MonitorSettings::RULES[$i->rule]['label'] ?? $i->rule,
            'severity' => $i->severity, 'status' => $i->status, 'title' => $i->title, 'summary' => $i->summary,
            'occurrences' => $i->occurrences, 'first_seen_at' => $this->iso($i->first_seen_at), 'last_seen_at' => $this->iso($i->last_seen_at),
            'notified_at' => $this->iso($i->notified_at), 'acknowledged_at' => $this->iso($i->acknowledged_at),
            'resolved_at' => $this->iso($i->resolved_at), 'resolved_automatically' => $i->status === 'resolved' && ! $i->resolved_by,
            'cause' => $i->investigation['cause'] ?? null, 'confidence' => $i->investigation['confidence'] ?? null,
        ];
    }
}
