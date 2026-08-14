<?php

namespace App\Observers;

use App\Models\SiteChecklistRun;
use App\Services\AuditLogger;
use LogicException;

class SiteChecklistRunObserver
{
    public function updating(SiteChecklistRun $run): void
    {
        if ($run->isDirty('status')
            && $run->status === 'completed'
            && ! $run->hasVerifiableSignatureProvenance()) {
            throw new LogicException('Checklist completion requires verifiable signature provenance.');
        }
    }

    public function updated(SiteChecklistRun $run): void
    {
        // Log completion
        if ($run->wasChanged('status') && $run->status === 'completed') {
            AuditLogger::logOrFail('checklist.completed', $run, [
                'site_id' => $run->site_id,
                'template_id' => $run->template_id,
                'completed_by' => $run->completed_by_user_id,
                'actor_id' => (int) $run->completed_by_user_id,
                'signature_name' => $run->signature_name,
                'signature_signed_at' => $run->signature_signed_at?->toIso8601String(),
                'completion_authority' => $run->completion_authority,
                'completion_authority_reason' => $run->completion_authority_reason,
                'signature_payload_hash' => $run->signature_payload_hash,
                'items_passed' => $run->items_passed,
                'items_failed' => $run->items_failed,
            ]);

            // Update completion stats
            $run->calculateCompletion();
        }
    }
}
