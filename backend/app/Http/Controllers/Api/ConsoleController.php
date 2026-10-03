<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Outils communs des ecrans de la console. */
abstract class ConsoleController extends Controller
{
    public const PERIODS = ['24h' => [24, 'hour'], '7d' => [168, 'day'], '30d' => [720, 'day'], '90d' => [2160, 'day']];

    protected function operator(Request $request): Operator
    {
        return $request->user('console');
    }

    /**
     * Periode demandee : raccourci (24h, 7d, 30d, 90d) ou dates explicites (from, to).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function period(Request $request, string $default = '24h'): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:24h,7d,30d,90d'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        if (! empty($data['from'])) {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now();
            if ($to->lt($from)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            // Au plus 1 an d'un coup.
            if ($from->diffInDays($to, true) > 366) {
                $from = $to->copy()->subDays(366);
            }

            return [$from, $to, $from->diffInHours($to, true) <= 48 ? 'hour' : 'day'];
        }
        [$hours, $step] = self::PERIODS[$data['period'] ?? $default];

        return [now()->subHours($hours), now(), $step];
    }

    protected function iso(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->toIso8601String() : null;
    }
}
