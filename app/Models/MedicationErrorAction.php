<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationErrorAction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['due_at' => 'datetime', 'created_at' => 'datetime', 'completed_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function error(): BelongsTo
    {
        return $this->belongsTo(MedicationError::class, 'medication_error_id');
    }
}
