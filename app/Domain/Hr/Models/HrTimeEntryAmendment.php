<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrTimeEntryAmendment extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'hr_time_entry_id',
        'amended_by',
        'field_name',
        'old_value',
        'new_value',
        'reason',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(HrTimeEntry::class, 'hr_time_entry_id');
    }

    public function amendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
