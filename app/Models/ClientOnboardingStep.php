<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientOnboardingStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'workflow_id',
        'step_name',
        'step_order',
        'is_required',
        'status',
        'completed_at',
        'completed_by',
        'notes',
        'due_date',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'completed_at' => 'datetime',
        'due_date' => 'date',
    ];

    public function workflow()
    {
        return $this->belongsTo(ClientOnboardingWorkflow::class, 'workflow_id');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
