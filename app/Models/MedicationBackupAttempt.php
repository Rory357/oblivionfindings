<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationBackupAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
