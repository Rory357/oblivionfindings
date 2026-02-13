<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            ->where('updated_at', '<', $cutoffDate);

        if ($this->tenantId) {
            $query->where('tenant_id', $this->tenantId);
        }

        // TODO: Before soft-deleting, optionally:
        // 1. Remove any stored documents / attachments from disk (Storage::delete)
        // 2. Anonymise PII fields (set first_name, last_name, personal_email,
        //    personal_phone to null or redacted placeholders) for GDPR compliance
        // 3. Log the archive action to an audit trail

        $count = $query->count();

        // Soft-delete in chunks to avoid memory issues
        $query->chunkById(200, function ($candidates) {
            foreach ($candidates as $candidate) {
                // TODO: Purge related documents from storage
                // TODO: Anonymise PII if required by policy
                $candidate->delete(); // soft delete
            }
        });

        Log::info("ArchiveCandidateDataJob: Archived {$count} candidates older than {$retentionMonths} months.", [
            'tenant_id'   => $this->tenantId,
            'cutoff_date' => $cutoffDate->toDateString(),
        ]);
    }
}
