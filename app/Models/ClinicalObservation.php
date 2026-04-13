<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalObservation extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    // ── Observation Types ──────────────────────────────────────────────

    public const TYPE_VITALS = 'vitals';
    public const TYPE_WEIGHT = 'weight';
    public const TYPE_FOOD_FLUID = 'food_fluid';
    public const TYPE_BOWEL = 'bowel';
    public const TYPE_SLEEP = 'sleep';
    public const TYPE_SEIZURE = 'seizure';
    public const TYPE_PAIN = 'pain';
    public const TYPE_MOOD = 'mood';
    public const TYPE_SKIN_INTEGRITY = 'skin_integrity';
    public const TYPE_GENERAL = 'general';

    public const TYPES = [
        self::TYPE_VITALS,
        self::TYPE_WEIGHT,
        self::TYPE_FOOD_FLUID,
        self::TYPE_BOWEL,
        self::TYPE_SLEEP,
        self::TYPE_SEIZURE,
        self::TYPE_PAIN,
        self::TYPE_MOOD,
        self::TYPE_SKIN_INTEGRITY,
        self::TYPE_GENERAL,
    ];

    public const TYPE_LABELS = [
        self::TYPE_VITALS => 'Vitals',
        self::TYPE_WEIGHT => 'Weight',
        self::TYPE_FOOD_FLUID => 'Food & Fluid',
        self::TYPE_BOWEL => 'Bowel',
        self::TYPE_SLEEP => 'Sleep',
        self::TYPE_SEIZURE => 'Seizure',
        self::TYPE_PAIN => 'Pain',
        self::TYPE_MOOD => 'Mood & Wellbeing',
        self::TYPE_SKIN_INTEGRITY => 'Skin Integrity',
        self::TYPE_GENERAL => 'General',
    ];

    protected $fillable = [
        'client_id',
        'shift_id',
        'clinical_protocol_id',
        'observation_type',
        'data',
        'notes',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'data' => 'array',
        'recorded_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocol::class, 'clinical_protocol_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClinicalEvent::class, 'linked_observation_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopeOfType($query, string $type)
    {
        return $query->where('observation_type', $type);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeRecordedBetween($query, $from, $to)
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->observation_type] ?? ucfirst($this->observation_type);
    }
}
