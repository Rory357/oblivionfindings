<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinConsolidationGroup extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_consolidation_groups';

    protected $fillable = [
        'name',
        'description',
        'parent_organization_id',
        'base_currency_code',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function entities(): HasMany
    {
        return $this->hasMany(FinConsolidationEntity::class, 'group_id');
    }

    public function intercompanyTransactions(): HasMany
    {
        return $this->hasMany(FinIntercompanyTransaction::class, 'group_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(FinConsolidationRun::class, 'group_id');
    }

    public function accountMappings(): HasMany
    {
        return $this->hasMany(FinAccountMapping::class, 'group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
