<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ShiftHandover;
use App\Services\Medication\Followups\MedicationFollowupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshMedicationFollowups extends Command
{
    protected $signature = 'emar:workflow-followups {--import : Import legacy source identities in bounded batches}';

    protected $description = 'Create unacknowledged medication handover heads-ups; optionally import existing follow-up identities';

    public function handle(MedicationFollowupService $work): int
    {
        if ($this->option('import')) {
            ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where(fn ($q) => $q
                ->whereNotNull('effect_check_due_at')->orWhere('review_required', true)
                ->orWhereExists(fn ($r) => $r->selectRaw('1')->from('medication_refusal_followups')
                    ->whereColumn('medication_refusal_followups.client_medication_administration_id', 'client_medication_administrations.id'))
                ->orWhereHas('prnEffectiveness'))
                ->chunkById(100, function ($rows) use ($work): void {
                    foreach ($rows as $snapshot) {
                        DB::transaction(function () use ($snapshot, $work): void {
                            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
                            if (! $client) {
                                return;
                            }
                            $medication = ClientMedication::withTrashed()->whereKey($snapshot->client_medication_id)
                                ->where('client_id', $client->id)->lockForUpdate()->first();
                            if (! $medication) {
                                return;
                            }
                            $record = ClientMedicationAdministration::query()->whereKey($snapshot->id)->lockForUpdate()->first();
                            if ($record) {
                                $record->setRelation('client', $client)->setRelation('medication', $medication);
                                $work->syncAdministration($record);
                            }
                        }, 5);
                    }
                });
        }
        ShiftHandover::query()->where('status', 'submitted')->whereNull('acknowledged_at')
            ->whereNotNull('incoming_shift_id')->chunkById(100, function ($rows) use ($work): void {
                foreach ($rows as $row) {
                    $work->headsUp($row);
                }
            });

        return self::SUCCESS;
    }
}
