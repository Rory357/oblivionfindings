<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document attached to an HR equipment asset (receipt, warranty, signed handover,
 * photo). Stored on a private disk and served through a gated download route.
 * Mirrors the canonical asset_documents column shape.
 */
class HrAssetDocument extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'title',
        'category',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'effective_at',
        'expiry_at',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'effective_at' => 'date',
        'expiry_at' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(HrAsset::class, 'asset_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
