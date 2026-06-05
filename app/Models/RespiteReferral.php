<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteReferral extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'client_id',
        'referrer_type',
        'referrer_name',
        'referrer_contact',
        'referral_reason',
        'funding_source',
        'funding_reference',
        'urgency',
        'status',
        'received_at',
        'triage_notes',
        'risk_level',
        'linked_booking_request_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
