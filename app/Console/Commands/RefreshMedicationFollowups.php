<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ShiftHandover;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshMedicationFollowups extends Command
{
    protected $signature = 'emar:workflow-followups
        {--preview : Read-only preview of the bounded legacy source batch}
        {--import : Explicitly prepare the bounded legacy source batch}
        {--after=0 : Resume after this administration ID}
        {--through= : Fixed final administration ID from the first preview}
        {--limit=100 : Maximum administrations per invocation, from 1 to 1000}
        {--site= : Restrict legacy preparation to one site}
        {--client= : Restrict legacy preparation to one person}';

    protected $description = 'Create unacknowledged medication handover heads-ups; optionally import existing follow-up identities';

    public function handle(MedicationFollowupService $work): int
    {
        if ($this->option('import') || $this->option('preview')) {
            if ($this->option('import') && $this->option('preview')) {
                $this->error('Choose either --preview or --import. Preview never writes follow-up or handover evidence.');

                return self::INVALID;
            }
            $bounds = [];
            foreach (['after' => 0, 'through' => 0, 'limit' => 1, 'site' => 1, 'client' => 1] as $option => $minimum) {
                $value = $this->option($option);
                if ($value !== null) {
                    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
                    if ($parsed === false || ($option === 'limit' && $parsed > 1000)) {
                        $this->error('Use valid positive bounds; --limit must be between 1 and 1000.');

                        return self::INVALID;
                    }
                    $bounds[$option] = $parsed;
                }
            }
            $query = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where(fn ($q) => $q
                ->whereNotNull('effect_check_due_at')->orWhere('review_required', true)
                ->orWhere(fn ($q) => $q->where('status', 'given')->where('administered_at', '<=', now('UTC'))
                    ->whereHas('medication', fn ($m) => $m->where('is_prn', true)))
                ->orWhereExists(fn ($r) => $r->selectRaw('1')->from('medication_refusal_followups')
                    ->whereColumn('medication_refusal_followups.client_medication_administration_id', 'client_medication_administrations.id'))
                ->orWhereHas('prnEffectiveness'));
            app(MedicationGovernanceScopeService::class)->scopeCanonicalClientMedicationRows($query, isset($bounds['site']) ? [$bounds['site']] : null, false);
            $query->when($bounds['client'] ?? null, fn ($q, $id) => $q->where('client_id', $id));
            $through = $bounds['through'] ?? (int) (clone $query)->max('id');
            $rows = $query->where('id', '>', $bounds['after'])->where('id', '<=', $through)
                ->orderBy('id')->limit($bounds['limit'])->get();
            foreach ($rows as $snapshot) {
                if ($this->option('import')) {
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
                        $record = ClientMedicationAdministration::query()->whereKey($snapshot->id)
                            ->where('client_id', $client->id)->where('client_medication_id', $medication->id)->lockForUpdate()->first();
                        if ($record) {
                            $record->setRelation('client', $client)->setRelation('medication', $medication);
                            $work->syncAdministration($record, ['legacy_preparation' => ['method' => 'explicit_import']]);
                        }
                    }, 5);
                }
            }
            $last = $rows->last()?->id ?? $bounds['after'];
            $this->info(($this->option('preview') ? 'Previewed' : 'Prepared').' '.$rows->count().' administration sources; clinical dose and effect evidence is unchanged.');
            $this->table(['Administration', 'Person', 'Medicine', 'Outcome', 'Effect check due (UTC)', 'Review required'],
                $rows->map(fn ($source) => [$source->id, $source->client_id, $source->client_medication_id,
                    $source->status, $source->getRawOriginal('effect_check_due_at') ?? 'Time not set', $source->review_required ? 'Yes' : 'No'])->all());
            $this->line('Batch boundary: --after='.$last.' --through='.$through.' --limit='.$bounds['limit']
                .(isset($bounds['site']) ? ' --site='.$bounds['site'] : '').(isset($bounds['client']) ? ' --client='.$bounds['client'] : ''));
            $this->line((clone $query)->where('id', '>', $last)->exists()
                ? 'More source rows remain within this boundary. Review the next batch before importing it.'
                : 'No further source rows remain within this boundary.');

            // Source preparation is separate from the scheduler's heads-up pass.
            return self::SUCCESS;
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
