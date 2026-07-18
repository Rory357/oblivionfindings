<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ItEmailDelivery extends Model
{
    use HasFactory;

    public const STATUSES = ['queued', 'sending', 'accepted', 'delivered', 'failed', 'bounced', 'retried'];

    protected $fillable = [
        'tenant_id', 'notification_uuid', 'retry_of_delivery_id', 'it_ticket_id', 'it_provisioning_request_id',
        'it_ticket_comment_id',
        'recipient_user_id', 'recipient_email', 'notification_type', 'audience',
        'notification_context', 'subject', 'provider', 'provider_message_id', 'status', 'attempt_count',
        'retry_count', 'last_error', 'queued_at', 'sending_at', 'accepted_at', 'provider_status_at', 'delivered_at',
        'failed_at', 'bounced_at', 'last_retried_by_user_id',
    ];

    protected $casts = [
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
    ];

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

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
