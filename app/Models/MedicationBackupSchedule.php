<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationBackupSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer', 'retention_days' => 'integer', 'last_run_at' => 'datetime'];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MedicationBackupRecipient::class, 'schedule_id');
    }

    /** Whoever last saved the schedule: the scheduled job runs as them (EA-142). */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
