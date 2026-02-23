<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHazardAction extends Model
{
    use HasFactory;

    protected $table = 'site_hazard_actions';

    protected $fillable = [
        'tenant_id',
        'hazard_id',
        'action_description',
        'status',
        'assigned_to_user_id',
        'completed_at',
        'completed_by_user_id',
        'completion_notes',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function hazard(): BelongsTo
    {
        return $this->belongsTo(SiteHazard::class, 'hazard_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
