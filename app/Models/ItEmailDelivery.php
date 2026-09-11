<?php

namespace App\Models;

use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ItEmailDelivery extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['queued', 'sending', 'accepted', 'delivered', 'failed', 'bounced', 'retried'];

    protected $fillable = [
        'notification_uuid', 'retry_of_delivery_id', 'it_ticket_id', 'it_provisioning_request_id',
        'it_ticket_comment_id',
        'recipient_user_id', 'recipient_email', 'notification_type', 'audience',
        'notification_context', 'subject', 'provider', 'provider_message_id', 'status', 'attempt_count',
        'retry_count', 'last_error', 'queued_at', 'sending_at', 'accepted_at', 'provider_status_at', 'delivered_at',
        'failed_at', 'bounced_at', 'last_retried_by_user_id',
        'dispatch_requested_at', 'dispatch_finished_at',
    ];

    protected $casts = [
        'rfc_message_id' => 'encrypted',
        'rfc_message_id_recorded_at' => 'immutable_datetime',
        'attempt_count' => 'integer',
        'retry_count' => 'integer',
        'notification_context' => 'array',
        'queued_at' => 'datetime',
        'sending_at' => 'datetime',
        'accepted_at' => 'datetime',
        'provider_status_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'bounced_at' => 'datetime',
        'dispatch_requested_at' => 'datetime',
        'dispatch_finished_at' => 'datetime',
    ];

    protected $hidden = ['rfc_message_id', 'rfc_message_id_hash', 'rfc_message_id_recorded_at', 'last_error'];

    protected static function booted(): void
    {
        self::saving(function (self $delivery): void {
            $snapshot = ($delivery->getOriginal('notification_context') ?? [])['submission_snapshot'] ?? null;
            if ($delivery->exists && $snapshot !== null
                && (($delivery->notification_context['submission_snapshot'] ?? null) !== $snapshot || $delivery->isDirty('provider'))) {
                throw new LogicException('Email submission evidence is immutable.');
            }
            $fields = ['rfc_message_id', 'rfc_message_id_hash', 'rfc_message_id_recorded_at'];
            if ($delivery->exists && $delivery->getOriginal('rfc_message_id_hash') !== null && $delivery->isDirty($fields)) {
                throw new LogicException('Outgoing message identity is immutable.');
            }
            if ($delivery->rfc_message_id !== null || $delivery->rfc_message_id_hash !== null || $delivery->rfc_message_id_recorded_at !== null) {
                $parser = new ItEmailMessageIdentifiers;
                $id = $parser->messageId($delivery->rfc_message_id);
                if ($id === null || $id !== $delivery->rfc_message_id || $parser->hash($id) !== $delivery->rfc_message_id_hash
                    || $delivery->rfc_message_id_recorded_at === null) {
                    throw new LogicException('Outgoing message identity is incomplete.');
                }
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ItTicketComment::class, 'it_ticket_comment_id');
    }

    public function provisioningRequest(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningRequest::class, 'it_provisioning_request_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_delivery_id');
    }

    public function retryAttempt(): HasOne
    {
        return $this->hasOne(self::class, 'retry_of_delivery_id');
    }
}
