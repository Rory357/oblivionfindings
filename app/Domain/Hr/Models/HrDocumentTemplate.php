<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrDocumentTemplate extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

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
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
