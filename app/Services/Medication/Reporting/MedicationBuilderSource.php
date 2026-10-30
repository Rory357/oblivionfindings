<?php

namespace App\Services\Medication\Reporting;

use App\Models\User;

final class MedicationBuilderSource
{
    public function read(User $actor, array $definition, array $siteIds): array
    {
        $period = new MedicationReportPeriod($definition['date_from'], $definition['date_to']);
        $dataset = app(MedicationReportDataset::class);
        $client = $definition['subject_id'] ?? null;
        $rows = match ($definition['source']) {
            'dose_slots' => $dataset->doseRows($actor, $period, $siteIds, $client),
            'prn_doses' => $dataset->prnRows($actor, $period, $siteIds, $client),
            default => $dataset->read($actor, MedicationReportDataset::SOURCES[$definition['source']], $period, $siteIds, $client)['rows'],
        };
        if ($definition['source'] === 'medication_errors') {
            // The builder is facts-only and excludes erroneous accounts.
            $rows = array_values(array_filter($rows, fn ($row) => ! $row['in_error']));
        }

        return ['rows' => $rows, 'digest' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    public function assertCurrent(User $actor, array $definition, string $digest): void
    {
        $context = app(\App\Services\Reporting\ReportAccess::class)->context($actor, $definition);
        abort_unless(hash_equals($digest, $this->read($actor, $definition, $context['site_ids'])['digest']), 403, 'Medication evidence changed. Run the report again.');
    }
}
