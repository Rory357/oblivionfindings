<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrDocumentTemplate extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'content',
        'merge_fields',
        'is_active',
        'version',
        'approval_required',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'merge_fields' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'approval_required' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
