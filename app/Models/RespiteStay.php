<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteStay extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'booking_id',
        'client_id',
        'status',
        'actual_start',
        'actual_end',
        'arrival_checklist',
        'arrival_checklist_complete',
        'discharge_summary',
        'discharge_checklist',
        'discharge_checklist_complete',
        'post_respite_summary',
        'transport_arrangements',
        'evidence_pack_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'arrival_checklist' => 'array',
        'arrival_checklist_complete' => 'boolean',
        'discharge_checklist' => 'array',
        'discharge_checklist_complete' => 'boolean',
        'transport_arrangements' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RespiteBooking::class, 'booking_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function evidencePack(): BelongsTo
    {
        return $this->belongsTo(RespiteEvidencePack::class, 'evidence_pack_id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(RespiteHandoverNote::class, 'stay_id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(RespiteCommunicationLog::class, 'stay_id');
    }

    public function dailyNotes(): HasMany
    {
        return $this->hasMany(RespiteDailyNote::class, 'stay_id');
    }

    public function riskPlanActivations(): HasMany
    {
        return $this->hasMany(RespiteRiskPlanActivation::class, 'stay_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
