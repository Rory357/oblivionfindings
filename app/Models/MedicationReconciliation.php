<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationReconciliation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['signed_off_at' => 'datetime', 'support_reassessment_required' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicationReconciliationItem::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(MedicationOrderFile::class);
    }
}
