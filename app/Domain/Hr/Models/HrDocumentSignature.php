<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDocumentSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'document_id',
        'signer_user_id',
        'signature_data',
        'signed_at',
        'ip_address',
        'user_agent',
        'status',
        'requested_by',
        'requested_at',
        'declined_reason',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'requested_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
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
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('status', 'signed');
    }

    public function scopeForSigner(Builder $query, int $userId): Builder
    {
        return $query->where('signer_user_id', $userId);
    }
}
