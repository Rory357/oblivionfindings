<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationReview extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'client_id',
        'review_type',
        'status',
        'scheduled_date',
        'completed_date',
        'reviewer_name',
        'reviewer_role',
        'reviewer_user_id',
        'requested_by',
        'trigger_reason',
        'medications_reviewed',
        'clinical_summary',
        'drug_burden_index',
        'falls_last_quarter',
        'recommendations',
        'actions',
        'whanau_involved',
        'whanau_notes',
        'next_review_date',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'next_review_date' => 'date',
        'medications_reviewed' => 'array',
        'actions' => 'array',
        'whanau_involved' => 'boolean',
        'drug_burden_index' => 'decimal:2',
        'falls_last_quarter' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_date', '<', now()->toDateString());
    }

    public function scopeUpcoming($query, int $days = 30)
    {
        return $query->where('status', 'scheduled')
            ->whereBetween('scheduled_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function scopeDue($query)
    {
        return $query->whereIn('status', ['scheduled', 'overdue']);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_date->isPast();
    }
}
