<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Models\Client;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicalProtocol extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Clinical\ClinicalProtocolFactory::new();
    }

    protected $table = 'clinical_protocols';

    protected $fillable = [
        'client_id',
        'created_by',
        'name',
        'observation_type',
        'frequency',
        'custom_frequency_hours',
        'instructions',
        'alert_if_missed_hours',
        'threshold_rules',
        'is_active',
        'starts_at',
        'ends_at',
        'schedule_anchor_at',
        'schedule_version',
    ];

    protected $casts = [
        'observation_type' => ObservationType::class,
        'frequency' => ProtocolFrequency::class,
        'threshold_rules' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'schedule_anchor_at' => 'immutable_datetime',
        'schedule_version' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClinicalProtocolSchedule::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOfType($query, ObservationType $type)
    {
        return $query->where('observation_type', $type);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Get the effective interval in hours for schedule generation.
     */
    public function effectiveIntervalHours(): ?int
    {
        if ($this->frequency === ProtocolFrequency::Custom) {
            return $this->custom_frequency_hours;
        }

        return $this->frequency->defaultIntervalHours();
    }

    /**
     * Whether this protocol is currently within its date range.
     */
    public function isCurrentlyApplicable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->starts_at && $today->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $today->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}
