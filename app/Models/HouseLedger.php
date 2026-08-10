<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HouseLedger extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'opening_balance',
        'current_balance',
        'currency',
        'last_reconciled_at',
        'reconciled_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'last_reconciled_at' => 'datetime',
    ];

    // Relationships

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HouseLedgerEntry::class);
    }
}
