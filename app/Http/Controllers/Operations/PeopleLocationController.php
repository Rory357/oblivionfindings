<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleTripReportExporter;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\PeopleLocationPreferences;
use App\Services\Tracking\PeopleLocationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PeopleLocationController extends Controller
{
    public function index(Request $request, PeopleLocationService $locations, string $view = 'map')
    {
        $filters = $this->filters($request);
        abort_unless($locations->mayEnter($request->user()), 403);
        if ($request->expectsJson()) {
            $data = $locations->workspace($request->user(), $filters, $request->getRequestUri(), $view);

            return response()->json($data)->withHeaders(ClientLocationAccessService::headers());
        }

        // Personal evidence is fetched separately and never stored in Inertia history.
        return inertia('operations/people-locations/index', ['view' => $view])
            ->toResponse($request)->withHeaders(ClientLocationAccessService::headers());
    }

    public function preferences(Request $request, PeopleLocationService $locations)
    {
        abort_unless($locations->mayEnter($request->user()), 403);
        $preferences = app(PeopleLocationPreferences::class);

        return response()->json($request->isMethod('put') ? $preferences->save($request->user(), $request->all()) : $preferences->read($request->user()))
            ->withHeaders(ClientLocationAccessService::headers());
    }

    public function preview(Request $request, PeopleLocationService $locations)
    {
        $data = $request->validate(['person' => ['required', 'regex:/^c[1-9][0-9]*$/'],
            'source' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Pacific/Auckland')->toDateString(),
            'journey' => 'sometimes|nullable|integer|min:1']);
        $report = $locations->history($request->user(), $data['person'], (string) $data['source'], $data['date'], isset($data['journey']) ? (int) $data['journey'] : null);

        return response()->json($report)->withHeaders(ClientLocationAccessService::headers());
    }

    public function export(Request $request, PeopleLocationService $locations)
    {
        abort_unless($request->user()->canDo('people_locations.export'), 403);
        $data = $request->validate(['person' => ['required', 'regex:/^c[1-9][0-9]*$/'],
            'source' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Pacific/Auckland')->toDateString(),
            'kind' => 'required|in:day,outing', 'journey' => 'required_if:kind,outing|nullable|integer|min:1',
            'format' => 'required|in:pdf,csv,html', 'reason' => 'required|string|min:5|max:500']);
        $report = $locations->history($request->user(), $data['person'], (string) $data['source'], $data['date'],
            $data['kind'] === 'outing' ? (int) $data['journey'] : null);
        abort_if($report['needsSource'], 403);
        $journey = $report['journey'];
        $report += ['kind' => $data['kind'], 'date' => $data['date'], 'journey' => $journey,
            'generatedAt' => now()->toISOString(), 'generatedBy' => $request->user()->name,
            'brand' => app(VehicleTripReportExporter::class)->branding()];
        $filename = 'person-'.$data['person'].'-'.$data['kind'].'-'.$data['date'].'.'.$data['format'];
        $bytes = match ($data['format']) {
            'pdf' => Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
                ->loadView('pdf.people-location-report', $report)->setPaper('a4', 'portrait')->output(),
            'html' => view('pdf.people-location-report', $report)->render(),
            'csv' => $this->csv($report),
        };
        // Render first, then revalidate authority and permission before releasing bytes.
        DB::transaction(function () use ($locations, $request, $data, $report, $bytes): void {
            $actor = $request->user()->fresh();
            abort_unless($actor->canDo('people_locations.export'), 403);
            $fresh = $locations->history($actor, $data['person'], (string) $data['source'], $data['date'], $data['kind'] === 'outing' ? (int) $data['journey'] : null);
            abort_unless(! $fresh['needsSource'] && hash_equals($report['fingerprint'], $fresh['fingerprint']), 403);
            if ($data['kind'] === 'outing') {
                abort_unless(collect($fresh['journeys'])->contains(fn ($j) => $j === $report['journey']), 409);
            }
            AuditLogger::logOrFail('people_locations.report_exported', Client::findOrFail((int) substr($data['person'], 1)),
                ['source_assignment_id' => (int) $data['source'], 'date' => $data['date'], 'kind' => $data['kind'],
                    'journey_id' => $data['journey'] ?? null, 'format' => $data['format'], 'reason' => $data['reason'],
                    'sample_count' => count($report['samples']), 'position_count' => count($report['positions']),
                    'sha256' => hash('sha256', $bytes)], $request);
        });

        return response($bytes, 200, [...ClientLocationAccessService::headers(),
            'Content-Type' => ['pdf' => 'application/pdf', 'csv' => 'text/csv; charset=UTF-8', 'html' => 'text/html; charset=UTF-8'][$data['format']],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    private function filters(Request $request): array
    {
        $preferences = app(PeopleLocationPreferences::class)->read($request->user())['value'];
        $filters = array_replace(['population' => $preferences['population'], 'site' => $preferences['site'], 'selected' => '', 'source' => '',
            'date' => now('Pacific/Auckland')->toDateString()], $request->validate([
                'journey' => 'sometimes|nullable|integer|min:1',
                'population' => 'sometimes|in:clients,staff,both', 'site' => ['sometimes', 'regex:/^(all|[1-9][0-9]*)$/'],
                'selected' => ['sometimes', 'nullable', 'regex:/^[cs][1-9][0-9]*$/'], 'source' => 'sometimes|nullable|integer|min:1',
                'date' => 'sometimes|date_format:Y-m-d|before_or_equal:'.now('Pacific/Auckland')->toDateString(),
            ]));
        $filters['source'] = (string) ($filters['source'] ?? '');
        $filters['selected'] = (string) ($filters['selected'] ?? '');

        return $filters;
    }

    private function csv(array $report): string
    {
        $stream = fopen('php://temp', 'r+');
        $literal = fn ($text) => is_string($text) && preg_match('/^[=+@\-\t\r]/u', (string) $text) ? "'".$text : $text;
        $write = fn ($row) => fputcsv($stream, array_map($literal, $row), ',', '"', '');
        $write(['Report', $report['kind'], 'Person', $report['name'], 'Source', $report['source']['reference']]);
        $write(['Scope', $report['scope'], 'Selected date', $report['date'], 'Timezone', 'Pacific/Auckland']);
        $write(['Authorised window (UTC)', $report['window']['from'], $report['window']['to']]);
        $write(['Completeness', $report['truncated'] ? 'PARTIAL: latest 500 per stream; earlier observations may be omitted' : 'All returned observations in the authorised window']);
        $write(['Generated (UTC)', $report['generatedAt'], 'Generated by', $report['generatedBy']]);
        $write(['Missing intervals remain unknown; observations do not confirm attendance or wellbeing.']);
        $write([]);
        $write(['Record type', 'Time (UTC)', 'Latitude', 'Longitude', 'Accuracy (m)', 'Battery (%)', 'Power', 'Movement']);
        foreach ($report['positions'] as $p) {
            $write(['Position', $p['timestamp'], $p['lat'], $p['lng'], $p['accuracy'] ?? '', '', '', '']);
        }
        foreach ($report['samples'] as $sample) {
            $write(['Device sample', $sample['at'], '', '', '', $sample['battery'] ?? '', $sample['power'], $sample['motion'] ?? 'unknown']);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }
}
