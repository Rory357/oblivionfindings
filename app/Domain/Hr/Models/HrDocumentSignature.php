<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDocumentSignature extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'document_id',
        'signer_user_id',
        'signature_data',
        'signed_at',
        'ip_address',
        'user_agent',
        'status',
        'signing_order',
        'order_index',
        'requested_by',
        'requested_at',
        'due_at',
        'reminder_sent_at',
        'declined_reason',
        'message',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'requested_at' => 'datetime',
        'due_at' => 'date',
        'reminder_sent_at' => 'datetime',
        'order_index' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'document_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('status', 'signed');
    }

    public function scopeDeclined(Builder $query): Builder
    {
        return $query->where('status', 'declined');
    }

    public function scopeForSigner(Builder $query, int $userId): Builder
    {
        return $query->where('signer_user_id', $userId);
    }
}
