<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinAccountMapping extends Model
{
    use HasFactory;

    protected $table = 'fin_account_mappings';

    protected $fillable = [
        'group_id',
        'entity_id',
        'source_account_id',
        'consolidated_account_code',
        'consolidated_account_name',
        'is_elimination_account',
    ];

    protected $casts = [
        'is_elimination_account' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationGroup::class, 'group_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinConsolidationEntity::class, 'entity_id');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'source_account_id');
    }
}
