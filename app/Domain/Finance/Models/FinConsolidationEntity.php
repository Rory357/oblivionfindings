<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinConsolidationEntity extends Model
{
    use HasFactory;

    protected $table = 'fin_consolidation_entities';

    protected $fillable = [
        'group_id',
        'organization_id',
        'entity_name',
        'ownership_percentage',
        'consolidation_method',
        'currency_code',
        'is_active',
    ];

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

    public function intercompanyTransactionsFrom(): HasMany
    {
        return $this->hasMany(FinIntercompanyTransaction::class, 'from_entity_id');
    }

    public function intercompanyTransactionsTo(): HasMany
    {
        return $this->hasMany(FinIntercompanyTransaction::class, 'to_entity_id');
    }

    public function accountMappings(): HasMany
    {
        return $this->hasMany(FinAccountMapping::class, 'entity_id');
    }
}
