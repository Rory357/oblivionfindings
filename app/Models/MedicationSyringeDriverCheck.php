<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationSyringeDriverCheck extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'medication_syringe_driver_id',
        'checked_at',
        'checked_by',
        'infusion_running',
        'site_condition',
        'volume_remaining',
        'notes',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'infusion_running' => 'boolean',
    ];

    public function syringeDriver(): BelongsTo
    {
        return $this->belongsTo(MedicationSyringeDriver::class, 'medication_syringe_driver_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
