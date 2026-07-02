<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('incidents.export') || $request->user()?->canDo('reports.viewAny'), 403);

        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('reports/incidents', [
            'clients' => $clients,
            'filters' => [
                'from' => $request->get('from'),
                'to' => $request->get('to'),
                'client_id' => $request->get('client_id'),
                'severity' => $request->get('severity'),
                'reviewed' => $request->get('reviewed'),
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canDo('incidents.export'), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'in:low,medium,high'],
            'reviewed' => ['nullable', 'in:yes,no'],
        ]);

        $q = ClientIncident::query()
            ->with(['client:id,first_name,last_name', 'reporter:id,name', 'shift:id,starts_at,ends_at'])
            ->when(!empty($data['from']), fn($qq) => $qq->whereDate('occurred_at', '>=', $data['from']))
            ->when(!empty($data['to']), fn($qq) => $qq->whereDate('occurred_at', '<=', $data['to']))
            ->when(!empty($data['client_id']), fn($qq) => $qq->where('client_id', $data['client_id']))
            ->when(!empty($data['severity']), fn($qq) => $qq->where('severity', $data['severity']))
            ->when(($data['reviewed'] ?? null) === 'yes', fn($qq) => $qq->whereNotNull('reviewed_at'))
            ->when(($data['reviewed'] ?? null) === 'no', fn($qq) => $qq->whereNull('reviewed_at'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $filename = 'incident_report_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Incident ID',
                'Client',
                'Shift ID',
                'Type',
                'Severity',
                'Occurred At',
                'Description',
                'Requires Followup',
                'Immediate Action Taken',
                'Witnesses',
                'Reporter',
                'Submitted At',
                'Reviewed At',
                'Reviewed By',
                'Review Notes',
            ]);

            $q->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $i) {
                    $this->putCsv($out, [
                        $i->id,
                        ($i->client?->first_name . ' ' . $i->client?->last_name),
                        $i->shift_id,
                        $i->type,
                        $i->severity,
                        optional($i->occurred_at)->toDateTimeString(),
                        $i->description,
                        $i->requires_followup ? 'yes' : 'no',
                        $i->immediate_action_taken,
                        $i->witnesses,
                        $i->reporter?->name,
                        optional($i->submitted_at)->toDateTimeString(),
                        optional($i->reviewed_at)->toDateTimeString(),
                        $i->reviewed_by,
                        $i->review_notes,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
