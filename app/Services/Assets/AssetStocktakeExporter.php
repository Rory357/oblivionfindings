<?php

namespace App\Services\Assets;

use App\Models\AssetStocktake;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;

final class AssetStocktakeExporter
{
    public function download(AssetStocktake $count, string $format)
    {
        $brand = app(VehicleTripReportExporter::class)->branding();
        $report = app(AssetStocktakeService::class)->present($count);
        $filename = 'stocktake-'.$count->id.'.'.$format;
        if ($format === 'pdf') {
            $response = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
                ->loadView('pdf.asset-stocktake', ['report' => $report, 'brand' => $brand])->setPaper('a4')->download($filename);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }
        $results = [['Asset', 'Tag', 'Serial number', 'Assigned room at start', 'Expected', 'Result', 'Method', 'Recorded by', 'Observed at', 'Assignment changed', 'Note']];
        foreach ($report['entries'] as $entry) {
            $results[] = [$entry['name'], $entry['asset_tag'], $entry['serial_number'], $entry['room'], $entry['expected'] ? 'Yes' : 'Extra',
                $entry['result'], $entry['source'], $entry['actor'], $entry['observed_at'], $entry['changed'] ? 'Yes' : 'No', $entry['note']];
        }
        $followups = [$results[0], ...array_values(array_filter(array_slice($results, 1), fn ($row) => $row[4] === 'Extra' || $row[5] !== 'found' || $row[9] === 'Yes'))];
        $activity = [['Time', 'Action', 'Asset', 'Recorded by', 'Method']];
        foreach ($report['activity'] as $event) {
            $activity[] = [$event['at'], $event['action'], $event['name'] ?? '', $event['actor'], $event['source'] ?? ''];
        }
        $summary = [['Field', 'Value'], ['Stocktake', 'ST-'.$count->id], ['Title', $count->title], ['Site', $count->scope['site']],
            ['Room', $count->scope['room'] ?? 'Whole site'], ['Counted by', $count->scope['counter']], ['Started', $count->counted_at->toISOString()],
            ['Completed', $count->completed_at->toISOString()], ['Items', count($report['entries'])], ['Follow-up owner', $count->follow_up_name],
            ['Review note', $count->review_note], ['Meaning', 'Physical observations only. Assignment and custody changes are reviewed separately.']];

        return response(app(BrandedWorkbook::class)->bytes(['Summary' => $summary, 'Results' => $results, 'Follow-ups' => $followups, 'Activity' => $activity], $brand, $count->title), 200,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'no-store, private']);
    }
}
