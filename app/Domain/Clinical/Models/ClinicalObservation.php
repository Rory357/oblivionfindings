<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Enums\ObservationType;
use App\Models\Client;
use App\Models\Concerns\AuditableChanges;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalObservation extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Clinical\ClinicalObservationFactory::new();
    }

    protected $table = 'clinical_observations';

    protected $fillable = [
        'client_id',
        'shift_id',
        'site_id',
        'recorded_by',
        'observation_type',
        'recorded_at',
        'data',
        'news2_score',
        'news2_band',
        'notes',
        'protocol_schedule_id',
        'is_flagged',
        'flagged_reason',
        'flagged_by',
        'correction_of_id',
        'correction_status',
        'correction_reason',
        'correction_approved_by',
        'correction_approved_at',
    ];

    protected $casts = [
        'observation_type' => ObservationType::class,
        'recorded_at' => 'datetime',
        'data' => 'array',
        'news2_score' => 'integer',
        'news2_band' => News2Band::class,
        'is_flagged' => 'boolean',
        'correction_approved_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_of_id');
    }

    public function correctionApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'correction_approved_by');
    }

    public function protocolSchedule(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocolSchedule::class, 'protocol_schedule_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOfType($query, ObservationType $type)
    {
        return $query->where('observation_type', $type);
    }

    public function scopeForShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeRecordedBetween($query, $from, $to)
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isCorrection(): bool
    {
        return $this->correction_of_id !== null;
    }
}
