<?php

namespace App\Domain\It\Data;

use App\Domain\It\Services\ItMailboxPollState;
use App\Models\ItAttachment;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/** A trusted canonical upload with verifiable scan provenance; never constructed from request JSON. */
final class ItScannedEmailAttachment extends UploadedFile
{
    public readonly int $sourceAttachmentId;

    public readonly int $inboundEmailId;

    public readonly string $contentHash;

    private readonly ?ItMailboxConnection $mailboxClaim;

    public function __construct(ItAttachment $source, ?ItMailboxConnection $mailboxClaim = null)
    {
        if (! $source->exists || $source->attachable_type !== (new ItInboundEmail)->getMorphClass()
            || $source->inbound_storage_state !== 'ready' || $source->malware_scan_status !== 'clean'
            || $source->malware_scanned_at === null || ! is_string($source->inbound_content_hash)
            || $source->path !== 'it_attachments/'.basename($source->path) || ! Str::isUuid(basename($source->path))) {
            throw new LogicException('Email file preparation is not complete.');
        }
        $this->sourceAttachmentId = (int) $source->id;
        $this->inboundEmailId = (int) $source->attachable_id;
        $this->contentHash = $source->inbound_content_hash;
        $this->mailboxClaim = $mailboxClaim === null ? null : clone $mailboxClaim;
        parent::__construct(Storage::disk(ItAttachment::DISK)->path($source->path), $source->original_name, $source->mime, null, true);
    }

    /** Persisted technical ownership lets recovery fence an interrupted writer, never infer it from age. */
    public function recoveryOwnership(): array
    {
        if ($this->mailboxClaim === null) {
            return [];
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Mailbox file ownership requires its canonical transaction.');
        }
        $current = app(ItMailboxPollState::class)->current($this->mailboxClaim, lock: true);
        $this->scanEvidence();
        $receipt = ItInboundEmail::findOrFail($this->inboundEmailId);
        if ($receipt->mailbox_scope_hash !== $current->mailboxScopeHash()) {
            throw new LogicException('Mailbox file ownership does not match the current receipt.');
        }

        return ['inbound_email_id' => $this->inboundEmailId, 'source_inbound_attachment_id' => $this->sourceAttachmentId,
            'mailbox_connection_id' => (int) $current->id, 'mailbox_configuration_version' => (int) $current->configuration_version,
            'mailbox_claim_token' => $current->poll_claim_token];
    }

    /** Recheck the canonical source inside the same transaction that creates the final file. */
    public function scanEvidence(): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Email scan evidence requires its owning transaction.');
        }
        $receipt = ItInboundEmail::query()->whereKey($this->inboundEmailId)->lockForUpdate()->firstOrFail();
        $source = $receipt->attachments()->whereKey($this->sourceAttachmentId)->lockForUpdate()->firstOrFail();
        clearstatcache(true, $this->getPathname());
        if ($receipt->status !== 'pending' || $source->inbound_storage_state !== 'ready'
            || $source->malware_scan_status !== 'clean' || $source->malware_scanned_at === null
            || $this->getPathname() !== Storage::disk(ItAttachment::DISK)->path($source->path)
            || $this->getClientOriginalName() !== $source->original_name
            || ! is_file($this->getPathname()) || is_link($this->getPathname())
            || filesize($this->getPathname()) !== $source->size
            || ! hash_equals($this->contentHash, (string) $source->inbound_content_hash)
            || ! hash_equals($this->contentHash, (string) hash_file('sha256', $this->getPathname()))) {
            throw new LogicException('Email file scan evidence is no longer current.');
        }

        return ['source_inbound_attachment_id' => $source->id, 'inbound_content_hash' => $this->contentHash,
            'malware_scan_status' => 'clean', 'malware_scanner' => $source->malware_scanner,
            'malware_scan_attempted_at' => $source->malware_scan_attempted_at, 'malware_scanned_at' => $source->malware_scanned_at];
    }
}
