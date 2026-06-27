<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ArchiveCandidateDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $retentionMonths = config('hr.candidate_retention_months', 24);
        $cutoffDate = now()->subMonths($retentionMonths);

        $query = HrCandidate::query()
            ->whereIn('status', ['rejected', 'withdrawn'])
            ->where('updated_at', '<', $cutoffDate)
            // Pooled candidates are kept warm for future roles — never anonymise
            // or soft-delete them on retention (handover item 22).
            ->whereDoesntHave('talentPoolMembership');

        if ($this->tenantId) {
            $query->where('tenant_id', $this->tenantId);
        }

        $count = $query->count();
        $anonymise = (bool) config('hr.retention.anonymise_candidates_before_archive', true);

        // Soft-delete in chunks to avoid memory issues
        $query->chunkById(200, function ($candidates) {
            foreach ($candidates as $candidate) {
                $cvPaths = $candidate->applications()
                    ->whereNotNull('cv_storage_path')
                    ->pluck('cv_storage_path')
                    ->filter()
                    ->unique()
                    ->values();

                foreach ($cvPaths as $path) {
                    try {
                        Storage::disk('private')->delete($path);
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to delete archived candidate CV', [
                            'candidate_id' => $candidate->id,
                            'path' => $path,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                $candidate->applications()->update([
                    'cv_storage_path' => null,
                    'cv_original_name' => null,
                    'cover_letter' => null,
                    // Screening capture holds candidate-supplied PII — scrub on retention.
                    'screening_answers' => null,
                ]);

                if ((bool) config('hr.retention.anonymise_candidates_before_archive', true)) {
                    $candidate->update([
                        'first_name' => 'Archived',
                        'last_name' => "Candidate {$candidate->id}",
                        'preferred_name' => null,
                        'personal_email' => "archived+{$candidate->id}@example.invalid",
                        'personal_phone' => null,
                        'notes' => null,
                        'source_detail' => null,
                        'tags' => [],
                    ]);
                }

                $candidate->delete(); // soft delete
            }
        });

        Log::info("ArchiveCandidateDataJob: Archived {$count} candidates older than {$retentionMonths} months.", [
            'tenant_id'   => $this->tenantId,
            'cutoff_date' => $cutoffDate->toDateString(),
            'anonymised' => $anonymise,
        ]);
    }
}
