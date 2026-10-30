<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Support\WorkerClock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationReview extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'owner_id',
        'completed_by',
        'revision',
        'trigger_code',
        'appointment_date',
        'appointment_time',
        'appointment_location',
        'clinician_practice',
        'review_location',
        'participants',
        'source_path',
        'source_name',
        'source_mime',
        'source_size',
        'completed_time',
        'happened_at',
        'reviewer_registration_number',
        'booking_request_uuid',
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
        'revision' => 'integer',
        'appointment_date' => 'date',
        'happened_at' => 'immutable_datetime',
        'participants' => 'array',
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'next_review_date' => 'date',
        'medications_reviewed' => 'array',
        'actions' => 'array',
        'whanau_involved' => 'boolean',
        'drug_burden_index' => 'decimal:2',
        'falls_last_quarter' => 'integer',
    ];

    protected $hidden = ['source_path'];

    // Clinical evidence is retained by scoped immutable review events, not generic audit payloads.
    protected $auditExcludedAttributes = ['clinical_summary', 'recommendations', 'actions', 'medications_reviewed',
        'participants', 'whanau_notes', 'trigger_reason', 'appointment_location', 'source_path', 'source_name'];

    public function items()
    {
        return $this->hasMany(MedicationReviewItem::class, 'review_id');
    }

    public function events()
    {
        return $this->hasMany(MedicationReviewEvent::class, 'review_id')->orderBy('id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

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
            ->where('scheduled_date', '<', WorkerClock::today()->toDateString());
    }

    public function scopeUpcoming($query, int $days = 30)
    {
        $today = WorkerClock::today();

        return $query->where('status', 'scheduled')
            ->whereBetween('scheduled_date', [$today->toDateString(), $today->addDays($days)->toDateString()]);
    }

    public function scopeDue($query)
    {
        return $query->whereIn('status', ['scheduled', 'overdue']);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_date !== null
            && WorkerClock::daysUntil($this->scheduled_date) < 0;
    }
}
