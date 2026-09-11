<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/** Technical storage evidence; ItAttachment remains the canonical file record. */
final class ItAttachmentStorageIntent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'content_sha256', 'original_name_sha256', 'inbound_email_id', 'source_inbound_attachment_id',
        'mailbox_connection_id', 'mailbox_configuration_version', 'mailbox_claim_token'];

    protected $casts = [
        'actor_user_id' => 'integer', 'parent_id' => 'integer', 'attachment_id' => 'integer',
        'size' => 'integer', 'revision' => 'integer', 'cleanup_attempts' => 'integer',
        'cleanup_requested_at' => 'immutable_datetime', 'last_cleanup_attempt_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
        'inbound_email_id' => 'integer', 'source_inbound_attachment_id' => 'integer',
        'mailbox_connection_id' => 'integer', 'mailbox_configuration_version' => 'integer',
        'mailbox_claim_token' => 'string',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $intent): void {
            $ownership = ['inbound_email_id', 'source_inbound_attachment_id', 'mailbox_connection_id', 'mailbox_configuration_version', 'mailbox_claim_token'];
            $hasOwnership = count(array_filter($ownership, fn ($field) => $intent->{$field} !== null)) > 0;
            if ($hasOwnership && ($intent->inbound_email_id < 1 || $intent->source_inbound_attachment_id < 1
                || $intent->mailbox_connection_id < 1 || $intent->mailbox_configuration_version < 1
                || ! Str::isUuid((string) $intent->mailbox_claim_token))) {
                throw new LogicException('Incomplete mailbox storage ownership.');
            }
            if (! Str::isUuid((string) $intent->intent_uuid)
                || $intent->path !== 'it_attachments/'.$intent->intent_uuid
                || $intent->actor_user_id < 1 || $intent->parent_id < 1
                || ! in_array($intent->parent_type, [(new ItTicket)->getMorphClass(), (new ItTicketComment)->getMorphClass()], true)
                || preg_match('/^[a-f0-9]{64}$/', (string) $intent->content_sha256) !== 1
                || preg_match('/^[a-f0-9]{64}$/', (string) $intent->original_name_sha256) !== 1
                || $intent->size < 0
                || ! in_array($intent->state, ['reserved', 'attached', 'cleanup_pending', 'deleted', 'reconciliation_required'], true)) {
                throw new LogicException('Invalid IT storage intent.');
            }
            if (! $intent->exists) {
                if ($intent->state !== 'reserved' || $intent->attachment_id !== null) {
                    throw new LogicException('Storage intents must begin as unassociated reservations.');
                }

                return;
            }
            if ($intent->isDirty(['intent_uuid', 'path', 'actor_user_id', 'parent_type', 'parent_id', 'content_sha256', 'original_name_sha256', 'size', ...$ownership])) {
                throw new LogicException('IT storage intent identity is immutable.');
            }
            if ($intent->getOriginal('attachment_id') !== null && $intent->isDirty('attachment_id')) {
                throw new LogicException('An attached storage intent cannot be reassigned.');
            }
            $from = $intent->getOriginal('state');
            $allowed = match ($from) {
                'reserved' => ['reserved', 'attached', 'cleanup_pending', 'reconciliation_required'],
                'cleanup_pending' => ['cleanup_pending', 'deleted', 'reconciliation_required'],
                'attached' => ['attached'],
                'reconciliation_required' => ['reconciliation_required'],
                'deleted' => ['deleted'],
                default => [],
            };
            if (! in_array($intent->state, $allowed, true)
                || ($from === 'deleted' && $intent->isDirty())
                || ($intent->state === 'attached' && $intent->attachment_id < 1)
                || ($intent->state === 'deleted' && $intent->deleted_at === null)
                || ($intent->state === 'cleanup_pending' && $intent->cleanup_requested_at === null)) {
                throw new LogicException('Invalid IT storage intent transition.');
            }
        });
        self::deleting(function (): void {
            throw new LogicException('IT storage intent tombstones cannot be deleted.');
        });
    }
}
