<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteComplianceCheck extends Model
{
    use HasFactory;

    protected $table = 'site_compliance_checks';

    protected $fillable = [
        'site_id',
        'check_type',
        'scheduled_date',
        'completed_date',
        'completed_by',
        'status',
        'findings',
        'corrective_actions',
        'risk_rating',
        'notes',
        'follow_up_date',
        'follow_up_notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'follow_up_date' => 'date',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_date', '<', now()->toDateString());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_date', '>=', now()->toDateString())
            ->where('scheduled_date', '<=', now()->addDays(14)->toDateString());
    }
}
