<?php

namespace App\Models;

use App\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingExternalReport extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'safeguarding_concern_id',
        'authority_type',
        'authority_name',
        'authority_contact',
        'authority_reference',
        'reported_at',
        'reported_by_user_id',
        'report_method',
        'report_summary',
        'report_document_path',
        'acknowledgement_received',
        'acknowledged_at',
        'acknowledgement_reference',
        'authority_action',
        'authority_feedback',
        'authority_feedback_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'authority_feedback_at' => 'datetime',
        'acknowledgement_received' => 'boolean',
    ];

    /**
     * Safeguarding concern.
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    /**
     * User who made the report.
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Pending acknowledgement.
     */
    public function scopePendingAcknowledgement($query)
    {
        return $query->where('acknowledgement_received', false);
    }

    /**
     * Check if acknowledgement is pending.
     */
    public function isPendingAcknowledgement(): bool
    {
        return !$this->acknowledgement_received;
    }
}
