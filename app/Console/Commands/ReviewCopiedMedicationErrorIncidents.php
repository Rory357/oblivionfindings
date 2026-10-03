<?php

namespace App\Console\Commands;

use App\Models\MedicationError;
use Illuminate\Console\Command;

/** Read-only one-off review list. Redaction, if approved, belongs to Incidents' audited edit. */
class ReviewCopiedMedicationErrorIncidents extends Command
{
    protected $signature = 'emar:review-copied-error-incidents';

    protected $description = 'List linked medication incidents containing copied error free text; never changes records';

    public function handle(): int
    {
        $rows = [];
        MedicationError::query()->whereNotNull('client_incident_id')->with('incident')->chunkById(200, function ($errors) use (&$rows): void {
            foreach ($errors as $error) {
                $incident = $error->incident;
                if (! $incident || (int) $incident->client_id !== (int) $error->client_id) {
                    continue;
                }
                $copies = [];
                foreach (['description' => 'description', 'immediate_action' => 'immediate_action_taken', 'review_notes' => 'review_notes', 'outcome' => 'closed_notes', 'close_note' => 'closed_notes'] as $from => $to) {
                    $value = trim((string) $error->{$from});
                    if ($value !== '' && str_contains((string) $incident->{$to}, $value)) {
                        $copies[] = $from;
                    }
                }
                if ($copies) {
                    $rows[] = [$error->reference_number, $incident->reference_number, $incident->id, implode(', ', $copies)];
                }
            }
        });
        $this->table(['Error', 'Incident', 'Incident ID', 'Copied fields to review'], $rows);
        $this->line(count($rows).' linked incidents to review. No records changed. Use the Incidents audited edit for any approved redaction.');

        return self::SUCCESS;
    }
}
