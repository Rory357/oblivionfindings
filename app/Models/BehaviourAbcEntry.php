<?php

namespace App\Models;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Models\Concerns\AuditableChanges;
use Database\Factories\BehaviourAbcEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A structured Antecedent → Behaviour → Consequence (ABC) record for Positive
 * Behaviour Support charting. Distinct from a clinical "event" — ABC charting
 * covers all behaviours of interest, not just crises — so it has its own store
 * with discrete, queryable columns that feed the behaviour-pattern analytics.
 */
class BehaviourAbcEntry extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): BehaviourAbcEntryFactory
    {
        return BehaviourAbcEntryFactory::new();
    }

    public const INTENSITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'client_id',
        'site_id',
        'shift_id',
        'occurred_at',
        'setting',
        'others_present',
        'antecedent',
        'behaviour',
        'behaviour_tags',
        'consequence',
        'behaviour_function',
        'intensity',
        'duration_seconds',
        'strategies_used',
        'harm_occurred',
        'harm_notes',
        'escalated',
        'requires_followup',
        'followup_notes',
        'followup_completed_at',
        'followup_completed_by',
        'linked_care_plan_id',
        'recorded_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'behaviour_tags' => 'array',
        'behaviour_function' => BehaviourFunction::class,
        'duration_seconds' => 'integer',
        'harm_occurred' => 'boolean',
        'escalated' => 'boolean',
        'requires_followup' => 'boolean',
        'followup_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function followupCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followup_completed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class, 'linked_care_plan_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at');
    }

    public function scopeSince(Builder $query, \DateTimeInterface $from): Builder
    {
        return $query->where('occurred_at', '>=', $from);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function needsFollowUp(): bool
    {
        return $this->requires_followup && $this->followup_completed_at === null;
    }
}
