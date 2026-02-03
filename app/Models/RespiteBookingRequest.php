<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteBookingRequest extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'referral_id',
        'client_id',
        'service_context_id',
        'requested_start',
        'requested_end',
        'requirements',
        'preference_notes',
        'funding_reference',
        'status',
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
        'approved_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
