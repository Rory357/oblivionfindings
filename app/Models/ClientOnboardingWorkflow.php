<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientOnboardingWorkflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'client_id',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'assigned_to',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function steps()
    {
        return $this->hasMany(ClientOnboardingStep::class, 'workflow_id');
    }
}
