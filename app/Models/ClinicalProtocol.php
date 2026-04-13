<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalProtocol extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_TWICE_DAILY = 'twice_daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_FORTNIGHTLY = 'fortnightly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_CUSTOM = 'custom';

    public const FREQUENCIES = [
        self::FREQUENCY_DAILY,
        self::FREQUENCY_TWICE_DAILY,
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_FORTNIGHTLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_CUSTOM,
    ];

    protected $fillable = [
        'client_id',
        'observation_type',
        'frequency',
        'custom_interval_days',
        'next_due_at',
        'last_recorded_at',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'next_due_at' => 'datetime',
        'last_recorded_at' => 'datetime',
        'custom_interval_days' => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────

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

    public function observations(): HasMany
    {
        return $this->hasMany(ClinicalObservation::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<', now());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->next_due_at !== null
            && $this->next_due_at->isPast();
    }

    public function getIntervalDays(): int
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => 1,
            self::FREQUENCY_TWICE_DAILY => 1,
            self::FREQUENCY_WEEKLY => 7,
            self::FREQUENCY_FORTNIGHTLY => 14,
            self::FREQUENCY_MONTHLY => 30,
            self::FREQUENCY_CUSTOM => $this->custom_interval_days ?? 1,
            default => 1,
        };
    }
}
