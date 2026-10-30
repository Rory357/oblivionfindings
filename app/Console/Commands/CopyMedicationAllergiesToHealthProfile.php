<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\ClientAllergyRecordService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Explicit evidence copy. The default only previews counts and performs no writes. */
final class CopyMedicationAllergiesToHealthProfile extends Command
{
    protected $signature = 'emar:copy-allergies {--client=* : Explicit canonical client IDs} {--apply : Copy evidence; requires one or more explicit client IDs}';

    protected $description = 'Preview or explicitly copy medication allergy evidence to the canonical health profile';

    public function handle(): int
    {
        $raw = $this->option('client');
        if (collect($raw)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Client IDs must be positive integers.');

            return self::FAILURE;
        }
        $ids = collect($raw)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($this->option('apply') && $ids === []) {
            $this->error('An applied copy needs explicit --client IDs. The default is a dry run.');

            return self::FAILURE;
        }
        $query = Client::query()->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id');
        if ($ids !== [] && (clone $query)->count() !== count($ids)) {
            $this->error('One or more client IDs were not found. Nothing was copied.');

            return self::FAILURE;
        }
        $service = app(ClientAllergyRecordService::class);
        $people = 0;
        $entries = 0;
        $query->chunkById(100, function ($clients) use ($service, &$people, &$entries) {
            foreach ($clients as $client) {
                $people++;
                $entries += count($service->forClient($client));
                if (! $this->option('apply')) {
                    continue;
                }
                DB::transaction(function () use ($client, $service) {
                    $person = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
                    if (! $person->site_id) {
                        throw new \RuntimeException('An applied copy needs a canonical current house.');
                    }
                    $before = ClientMedicalProfile::query()->where('client_id', $person->id)->first();
                    $beforeDigest = hash('sha256', json_encode([$before?->allergy_records, $before?->allergies_canonical_at !== null], JSON_THROW_ON_ERROR));
                    $profile = $service->copyLegacy($person);
                    $summary = $service->summary($person);
                    // The clinical digest deliberately ignores provenance. A
                    // new exact duplicate still adds retained source evidence.
                    $evidenceDigest = hash('sha256', json_encode($profile->allergy_records, JSON_THROW_ON_ERROR));
                    $afterDigest = hash('sha256', json_encode([$profile->allergy_records, $profile->allergies_canonical_at !== null], JSON_THROW_ON_ERROR));
                    if (! hash_equals($beforeDigest, $afterDigest)) {
                        app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: (int) $person->site_id,
                            kind: 'allergy.source-copied', subjectType: 'health_profile', subjectId: (string) $profile->id, actorId: null,
                            occurredAt: CarbonImmutable::now('UTC'), summary: 'Legacy allergy evidence copied to health profile',
                            facts: ['digest' => $summary['digest'], 'evidence_digest' => $evidenceDigest, 'reviewed' => $summary['reviewed'] !== null], clientId: (int) $person->id));
                    }
                }, 5);
            }
        });
        $this->info(($this->option('apply') ? 'Applied evidence copy: ' : 'Dry run — no writes: ').$people.' people, '.$entries.' active entries. Source rows are retained; copied entries are not reviewed.');

        return self::SUCCESS;
    }
}
