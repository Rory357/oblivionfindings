<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItAttachmentStorageReservation;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketDraft;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;

/** One private storage path for ticket and conversation evidence. */
final class ItAttachmentStorageService
{
    /** @return array<int, ItAttachmentStorageReservation> */
    public function reserveDirect(ItTicket|ItTicketComment $parent, array $attachments, User $actor): array
    {
        return app(ItAttachmentStorageIntentService::class)->reserveDirect($parent, $attachments, $actor);
    }

    public function storeReservedDirect(ItTicket|ItTicketComment $parent, array $attachments, User $actor, array $reservations, array &$storedPaths): void
    {
        app(ItAttachmentStorageIntentService::class)->storeReservedDirect($parent, $attachments, $actor, $reservations, $storedPaths);
    }

    public function requestRollbackCleanup(array $reservations): array
    {
        return app(ItAttachmentStorageIntentService::class)->requestRollbackCleanup($reservations);
    }

    public function retryPending(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Cleanup requires a bounded run outside an existing transaction.');
        }
        $direct = app(ItAttachmentStorageIntentService::class);
        if (! Schema::hasColumn('it_attachments', 'inbound_storage_state')) {
            return $direct->retryPending($limit);
        }
        $staging = app(ItInboundAttachmentStaging::class);
        // One shared budget. Never-attempted work comes first, then the oldest retry;
        // one failed file cannot consume the whole run or starve the other source.
        $candidates = ItAttachmentStorageIntent::query()->useWritePdo()->where('state', 'cleanup_pending')
            ->orderBy('last_cleanup_attempt_at')->orderBy('cleanup_attempts')->orderBy('id')->limit($limit)
            ->get(['id', 'last_cleanup_attempt_at', 'cleanup_attempts'])
            ->map(fn ($row) => ['kind' => 'direct', 'id' => (int) $row->id,
                'at' => $row->last_cleanup_attempt_at?->getTimestamp(), 'attempts' => $row->cleanup_attempts])->all();
        foreach ($staging->pendingCleanupQuery()
            ->orderByRaw('CASE WHEN inbound_cleanup_attempts = 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inbound_cleanup_attempts = 0 THEN NULL ELSE updated_at END')
            ->orderBy('inbound_cleanup_attempts')->orderBy('id')->limit($limit)
            ->get(['id', 'updated_at', 'inbound_cleanup_attempts']) as $row) {
            $candidates[] = ['kind' => 'inbound', 'id' => (int) $row->id,
                'at' => $row->inbound_cleanup_attempts === 0 ? null : $row->updated_at->getTimestamp(),
                'attempts' => $row->inbound_cleanup_attempts];
        }
        if (Schema::hasColumn('it_attachment_storage_intents', 'inbound_email_id')) {
            foreach ($direct->abandonedMailboxQuery()->orderBy('last_cleanup_attempt_at')->orderBy('cleanup_attempts')->orderBy('id')->limit($limit)
                ->get(['id', 'last_cleanup_attempt_at', 'cleanup_attempts']) as $row) {
                $candidates[] = ['kind' => 'mailbox', 'id' => (int) $row->id,
                    'at' => $row->last_cleanup_attempt_at?->getTimestamp(), 'attempts' => $row->cleanup_attempts];
            }
        }
        usort($candidates, fn ($a, $b) => [$a['at'] !== null, $a['at'] ?? 0, $a['attempts'], $a['id'], $a['kind']]
            <=> [$b['at'] !== null, $b['at'] ?? 0, $b['attempts'], $b['id'], $b['kind']]);
        $selected = ['direct' => [], 'inbound' => [], 'mailbox' => []];
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $selected[$candidate['kind']][] = $candidate['id'];
        }
        $result = ['requested' => 0, 'deleted' => 0, 'failed' => 0, 'deferred' => 0, 'reconciliation_required' => 0];
        if ($selected['direct'] !== []) {
            $result = $direct->retryPending(count($selected['direct']), $selected['direct']);
        }
        if ($selected['inbound'] !== []) {
            $inbound = $staging->cleanupPending(count($selected['inbound']), onlyIds: $selected['inbound']);
            $result['requested'] += $inbound['deleted'] + $inbound['failed'];
            $result['deleted'] += $inbound['deleted'];
            $result['failed'] += $inbound['failed'];
        }
        if ($selected['mailbox'] !== []) {
            $mailbox = $direct->retryAbandonedMailboxReservations($selected['mailbox']);
            foreach ($result as $key => $count) {
                $result[$key] = $count + $mailbox[$key];
            }
        }

        return $result;
    }

    /** Write only a previously committed, opaque draft-owned private path. */
    public function storeReserved(ItAttachment $attachment, UploadedFile $file): void
    {
        if ($attachment->attachable_type !== (new ItTicketDraft)->getMorphClass()
            || $attachment->draft_generation_uuid === null
            || ! in_array($attachment->draft_storage_state, ['reserved', 'failed'], true)
            || preg_match('/^it_attachments\/[a-f0-9-]{36}$/', $attachment->path) !== 1) {
            throw new DomainException('This private file reservation is unavailable.');
        }
        $path = Storage::disk(ItAttachment::DISK)->putFileAs('it_attachments', $file, basename($attachment->path));
        if ($path !== $attachment->path) {
            throw new DomainException('The private attachment could not be stored. Retry the same upload.');
        }
    }
}
