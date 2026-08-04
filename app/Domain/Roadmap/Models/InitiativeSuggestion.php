<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InitiativeSuggestion extends Model
{
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_suggestions';

    public const STATUS_TRIAGE_PENDING = 'triage_pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SNOOZED = 'snoozed';

    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'source',
        'source_key',
        'title',
        'summary',
        'triage_notes',
        'raw_payload',
        'dedupe_key',
        'score_hint',
        'status',
        'triage_owner_id',
        'snoozed_until',
        'converted_initiative_id',
        'first_seen_at',
        'last_seen_at',
        'hit_count',
        'rate_limited_until',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'score_hint' => 'decimal:2',
        'snoozed_until' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'rate_limited_until' => 'datetime',
    ];

    public function triageOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triage_owner_id');
    }

    public function convertedInitiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'converted_initiative_id');
    }

    public function isRateLimited(): bool
    {
        return $this->rate_limited_until !== null && $this->rate_limited_until->isFuture();
    }

    public function bumpHitCounter(): void
    {
        $this->hit_count = (int) $this->hit_count + 1;
        $this->last_seen_at = now();
        $this->save();
    }
}
