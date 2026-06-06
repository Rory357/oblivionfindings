<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteBookingRequest extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'referral_id',
        'client_id',
        'service_context_id',
        'requested_start',
        'requested_end',
        'requirements',
        'intake_snapshot',
        'preference_notes',
        'funding_source',
        'funding_reference',
        'service_agreement_id',
        'funding_status',
        'funding_approved_ref',
        'funding_approved_at',
        'status',
        'waitlist_position',
        'priority',
        'expected_availability_date',
        'is_emergency',
        'fast_tracked',
        'series_id',
        'recurrence_rule',
        'allocated_days',
        'decision_notes',
        'approved_by_user_id',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requested_start' => 'datetime',
        'requested_end' => 'datetime',
        'requirements' => 'array',
        'intake_snapshot' => 'array',
        'funding_approved_at' => 'datetime',
        'approved_at' => 'datetime',
        'expected_availability_date' => 'date',
        'is_emergency' => 'boolean',
        'fast_tracked' => 'boolean',
        'allocated_days' => 'integer',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(RespiteReferral::class, 'referral_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class, 'service_context_id');
    }

    public function serviceAgreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
