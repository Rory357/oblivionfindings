<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteReferral extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'nhi_number',
        'nhi_hash',
        'referrer_type',
        'referrer_name',
        'referrer_contact',
        'third_party_source_type',
        'third_party_source_name',
        'third_party_collection_consent',
        'referral_reason',
        'funding_source',
        'funding_reference',
        'urgency',
        'status',
        'received_at',
        'triage_notes',
        'risk_level',
        'is_maori',
        'ethnicity',
        'iwi',
        'hapu',
        'marae',
        'interpreter_required',
        'interpreter_language',
        'interpreter_arranged',
        'cultural_considerations',
        'cultural_dietary_needs',
        'primary_carer_name',
        'primary_carer_relationship',
        'primary_carer_contact',
        'carer_strain_level',
        'carer_breakdown_flag',
        'booker_type',
        'linked_booking_request_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'nhi_number' => 'encrypted',
        'third_party_collection_consent' => 'boolean',
        'is_maori' => 'boolean',
        'interpreter_required' => 'boolean',
        'interpreter_arranged' => 'boolean',
        'carer_breakdown_flag' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(RespiteBookingRequest::class, 'referral_id');
    }
}
