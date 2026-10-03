<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Incident detecte par la supervision, suivi jusqu'a sa resolution. */
class Incident extends Model
{
    protected $fillable = [
        'key', 'rule', 'severity', 'status', 'title', 'summary', 'details', 'investigation', 'timeline', 'occurrences', 'auto_action_at',
        'first_seen_at', 'last_seen_at', 'notified_at', 'acknowledged_at', 'acknowledged_by',
        'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'details' => 'array',
        'investigation' => 'array',
        'auto_action_at' => 'datetime',
        'timeline' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'notified_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status !== 'resolved';
    }

    /** Ajoute une ligne a l'historique de l'incident. */
    public function addTimeline(string $kind, string $text, ?Operator $by = null, ?string $byLabel = null): void
    {
        $timeline = $this->timeline ?? [];
        $timeline[] = array_filter([
            'at' => now()->toIso8601String(),
            'kind' => $kind,
            'text' => mb_substr($text, 0, 1000),
            'by' => $by?->label() ?? $byLabel,
        ], fn ($v) => $v !== null);
        // Historique borne : les 100 dernieres lignes.
        $this->timeline = array_slice($timeline, -100);
    }
}
