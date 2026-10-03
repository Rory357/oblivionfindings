<?php

namespace App\Services\Reporting;

use App\Models\OperationalReportRun;
use App\Models\User;
use App\Services\Assets\BrandedWorkbook;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleTripReportExporter;
use App\Services\Tracking\ClientLocationAccessService;
use Barryvdh\DomPDF\Facade\Pdf;

final class ReportExporter
{
    public static function csvCell(mixed $value): string
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        $text = (string) $value;

        return (string) \App\Support\CsvCell::sanitize($text);
    }

    public function download(User $actor, OperationalReportRun $run, string $format, string $reason, string $section = 'rows')
    {
        $payload = app(ReportRuns::class)->result($actor, $run);
        $definition = $run->definition;
        $domain = config('operational-reports.sources.'.$definition['source'].'.domain');
        $medicationType = $definition['source'] === 'medication_stock' ? 'stock' : ($definition['source'] === 'controlled_register' ? 'controlled' : 'doses');
        if ($domain === 'medication') {
            abort_unless(app(\App\Services\Medication\Reporting\MedicationReportAccess::class)->canExport($actor->fresh(), $medicationType), 403);
        } elseif ($domain !== 'fleet') {
            abort_unless($actor->fresh()?->canDo('assets.telemetry.export'), 403);
        }
        $fields = config('operational-reports.sources.'.$definition['source'].'.fields');
        $columns = $definition['columns'];
        $rows = [array_map(fn ($field) => $fields[$field]['label'].($fields[$field]['type'] === 'number' && ! empty($fields[$field]['unit']) ? ' ('.$fields[$field]['unit'].')' : ''), $columns)];
        foreach ($payload['result']['rows'] as $row) {
            $rows[] = array_map(fn ($field) => $row[$field] ?? null, $columns);
        }
        $summary = [['Field', 'Value'], ['Report', $definition['name']], ['Run', $run->id], ['Source', $definition['source']], ['From', $payload['source']['window']['from']], ['To', $payload['source']['window']['to']],
            ['Timezone', 'Pacific/Auckland'], ['Coverage', $payload['source']['coverage']], ['Rows', $payload['result']['row_count']], ['Generated', $payload['generated_at']], ['Definition SHA256', $payload['definition_hash']], ['Purpose', $reason]];
        $mime = match ($format) {
            'csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf', default => abort(422)
        };
        if ($format === 'pdf') {
            abort_if(count($rows) > 2001 || count($columns) > 10, 422, 'PDF supports up to 2,000 rows and 10 columns. Use Excel or CSV for larger reports.');
        }
        $brand = app(VehicleTripReportExporter::class)->branding();
        $groups = [array_merge(array_map(fn ($field) => $fields[$field]['label'], $definition['groups']), array_map(fn ($m) => $m['label'].' ('.$m['unit'].')', $definition['measures']))];
        foreach ($payload['result']['groups'] as $group) {
            $groups[] = [...$group['dimensions'], ...array_map(fn ($m) => $group['values'][$m['id']] ?? null, $definition['measures'])];
        }
        $bytes = match ($format) {
            'csv' => $this->csv($section === 'summary' ? $groups : $rows),
            'json' => json_encode(['definition' => $definition, 'provenance' => ReportRuns::publicSource($payload['source']), 'result' => $payload['result'], 'comparison' => $payload['comparison']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            'xlsx' => app(BrandedWorkbook::class)->bytes(['Scope' => $summary, 'Summary' => $groups, 'Source rows' => $rows], $brand, $definition['name'], 'REPORT'),
            'pdf' => Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
                ->loadView('pdf.operational-report', compact('definition', 'payload', 'rows', 'summary', 'brand', 'groups'))->setPaper('a4', 'landscape')->output(),
        };
        // Recheck after expensive rendering, before any response leaves the server.
        app(ReportRuns::class)->result($actor, $run->fresh());
        if ($domain === 'medication') {
            $scope = app(ReportAccess::class)->context($actor->fresh(), $definition);
            app(\App\Services\Medication\Reporting\MedicationExportAudit::class)->record($actor, $medicationType, $scope['site_ids'], new \App\Services\Medication\Reporting\MedicationReportPeriod($definition['date_from'], $definition['date_to']), $reason, $definition['subject_id'] ?? null, ['run_id' => $run->id, 'source' => $definition['source'], 'format' => $format], fn (User $current) => app(ReportRuns::class)->result($current, $run->fresh()));
        } elseif ($domain !== 'fleet') {
            abort_unless($actor->fresh()?->canDo('assets.telemetry.export'), 403);
        }
        AuditLogger::logOrFail('reports.export.downloaded', $actor, ['run_id' => $run->id, 'actor_id' => $actor->id, 'reason' => $reason, 'format' => $format, 'rows' => $payload['result']['row_count']]);

        return response($bytes, 200, [...ClientLocationAccessService::headers(), 'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="report-'.$run->id.'.'.$format.'"', 'X-Report-Row-Count' => (string) $payload['result']['row_count']]);
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        try {
            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::csvCell(...), $row), ',', '"', '');
            }
            rewind($handle);

            return stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
