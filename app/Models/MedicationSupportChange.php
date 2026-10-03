<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

class MedicationSupportChange extends Model
{
    use AuditableChanges;

    protected $table = 'medication_support_changes';

    protected $fillable = ['client_id', 'client_medication_id', 'assessment_id', 'mode', 'previous_mode', 'reason', 'notes', 'recorded_by', 'occurred_at', 'effective_at'];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'effective_at' => 'immutable_datetime'];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
