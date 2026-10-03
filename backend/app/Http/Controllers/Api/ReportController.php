<?php

namespace App\Http\Controllers\Api;

use App\Models\Report;
use App\Services\ReportBuilder;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rapports periodiques de supervision : liste, lecture, generation a la demande. */
class ReportController extends ConsoleController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'reports' => Report::orderByDesc('id')->limit(60)->get(['id', 'period', 'period_start', 'period_end', 'sent_at', 'created_at', 'generated_by'])
                ->map(fn (Report $r) => [
                    'id' => $r->id, 'period' => $r->period, 'label' => ReportBuilder::PERIOD_LABELS[$r->period] ?? $r->period,
                    'period_start' => $this->iso($r->period_start), 'period_end' => $this->iso($r->period_end),
                    'sent_at' => $this->iso($r->sent_at), 'created_at' => $this->iso($r->created_at), 'manual' => (bool) $r->generated_by,
                ])->values(),
        ]);
    }

    public function show(Report $report): JsonResponse
    {
        return response()->json(['report' => [
            'id' => $report->id, 'period' => $report->period, 'label' => ReportBuilder::PERIOD_LABELS[$report->period] ?? $report->period,
            'period_start' => $this->iso($report->period_start), 'period_end' => $this->iso($report->period_end),
            'created_at' => $this->iso($report->created_at), 'sent_at' => $this->iso($report->sent_at),
            'delivery' => $report->delivery, 'data' => $report->data,
        ]]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'send' => ['nullable', 'boolean'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay()->min(now());
        abort_if($from->diffInDays($to, true) > 366, 422, 'La période ne peut pas dépasser un an.');
        $operator = $this->operator($request);
        $report = ReportBuilder::generate('custom', $from, $to, $operator, (bool) ($data['send'] ?? false));
        Audit::log($operator, 'report.generated', 'success', 'report', $report->id, 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
            ['sent' => (bool) ($data['send'] ?? false)]);

        return response()->json(['message' => 'Rapport généré.', 'id' => $report->id]);
    }
}
