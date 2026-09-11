<?php

namespace App\Domain\It\Services;

use App\Models\ItAttachment;
use App\Models\ItTicketDraft;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Expiry scrubs text before physical cleanup; failed files retain their owner. */
final class ItTicketDraftPruner
{
    public function __construct(
        private readonly ItTicketDraftService $drafts,
        private readonly ItTicketDraftAttachmentService $files,
    ) {}

    public function run(): array
    {
        $result = ['enabled' => $this->drafts->enabled(), 'expired' => 0, 'removed_slots' => 0, 'files_deleted' => 0, 'cleanup_failed' => 0];
        if (! $result['enabled']) {
            return $result;
        }
        $processed = [];

        ItTicketDraft::query()->where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$result, &$processed): void {
            foreach ($rows as $row) {
                $generation = DB::transaction(function () use ($row, &$result): ?string {
                    $draft = ItTicketDraft::query()->whereKey($row->id)->where('expires_at', '<=', now())->lockForUpdate()->first();
                    if (! $draft) {
                        return null;
                    }
                    if ($draft->state === 'active') {
                        $draft->forceFill([
                            'state' => 'expired', 'encrypted_payload' => null, 'payload_hash' => null,
                            'last_base_revision' => $draft->revision, 'revision' => $draft->revision + 1,
                            'last_mutation' => 'expired', 'expires_at' => now()->addDays((int) config('it.drafts.terminal_retention_days')),
                        ])->save();
                        AuditLogger::logOrFail('it.draft.expired', $draft, [
                            'draft_owner_user_id' => (int) $draft->actor_user_id, 'purpose' => $draft->purpose->value,
                            'draft_uuid' => $draft->draft_uuid, 'revision' => $draft->revision, 'state' => 'expired',
                        ]);
                        $result['expired']++;
                    }

                    return $draft->draft_uuid;
                });
                if ($generation !== null) {
                    $this->cleanup((int) $row->id, $generation, $result, $processed);
                    DB::transaction(function () use ($row, &$result): void {
                        $draft = ItTicketDraft::query()->whereKey($row->id)->where('state', '!=', 'active')
                            ->where('expires_at', '<=', now())->lockForUpdate()->first();
                        if ($draft && ! $draft->attachments()->exists()) {
                            $draft->delete();
                            $result['removed_slots']++;
                        }
                    });
                }
            }
        });

        // A failed explicit remove or old generation cleanup also needs retry
        // while the current draft remains active. Scan only canonical draft rows.
        ItAttachment::query()->where('attachable_type', (new ItTicketDraft)->getMorphClass())
            ->where('draft_storage_state', 'cleanup_pending')->whereNotNull('draft_generation_uuid')
            ->orderBy('id')->chunkById(100, function ($rows) use (&$result, &$processed): void {
                foreach ($rows->unique(fn (ItAttachment $file): string => $file->attachable_id.':'.$file->draft_generation_uuid) as $file) {
                    $this->cleanup((int) $file->attachable_id, $file->draft_generation_uuid, $result, $processed);
                }
            });

        return $result;
    }

    private function cleanup(int $draftId, string $generation, array &$result, array &$processed): void
    {
        $key = $draftId.':'.$generation;
        if (isset($processed[$key])) {
            return;
        }
        $processed[$key] = true;
        $cleanup = $this->files->cleanupGeneration($draftId, $generation);
        $result['files_deleted'] += $cleanup['deleted'];
        $result['cleanup_failed'] += $cleanup['failed'];
    }
}
