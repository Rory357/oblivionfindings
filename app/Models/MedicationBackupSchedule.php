<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationBackupSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer', 'retention_days' => 'integer'];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MedicationBackupRecipient::class, 'schedule_id');
    }
}
