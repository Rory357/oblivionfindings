<?php

namespace App\Domain\Clinical\Models;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClientMealRestriction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORISED = 'authorised';

    public const FOOD_LEVELS = [
        3 => 'Liquidised',
        4 => 'Pureed',
        5 => 'Minced & Moist',
        6 => 'Soft & Bite-sized',
        7 => 'Regular / Easy to chew',
    ];

    public const FLUID_LEVELS = [
        0 => 'Thin',
        1 => 'Slightly thick',
        2 => 'Mildly thick',
        3 => 'Moderately thick',
        4 => 'Extremely thick',
    ];

    protected $fillable = [
        'site_id',
        'client_id',
        'version',
        'status',
        'replaces_id',
        'proposed_by',
        'proposed_at',
        'approved_by',
        'approved_at',
        'approval_replay_key',
        'effective_from',
        'effective_until',
        'review_due_at',
        'iddsi_food_level',
        'iddsi_food_label',
        'fluid_iddsi_level',
        'fluid_label',
        'allergen_tag_ids',
        'dietary_tag_ids',
        'clinical_notes',
        'amendment_reason',
        'content_hash',
    ];

    protected $casts = [
        'version' => 'integer',
        'proposed_at' => 'datetime',
        'approved_at' => 'datetime',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'review_due_at' => 'date',
        'iddsi_food_level' => 'integer',
        'fluid_iddsi_level' => 'integer',
        'allergen_tag_ids' => 'array',
        'dietary_tag_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (ClientMealRestriction $restriction): void {
            $transitionFields = [
                'status',
                'approved_by',
                'approved_at',
                'approval_replay_key',
                'updated_at',
            ];

            if (array_diff(array_keys($restriction->getDirty()), $transitionFields) !== []) {
                throw new LogicException('Clinical meal restriction content is immutable; create an amendment.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Clinical meal restriction provenance cannot be deleted.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function calculateContentHash(): string
    {
        $payload = [
            'site_id' => (int) $this->site_id,
            'client_id' => (int) $this->client_id,
            'version' => (int) $this->version,
            'replaces_id' => $this->replaces_id ? (int) $this->replaces_id : null,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'review_due_at' => $this->review_due_at?->toDateString(),
            'iddsi_food_level' => $this->iddsi_food_level,
            'iddsi_food_label' => $this->iddsi_food_label,
            'fluid_iddsi_level' => $this->fluid_iddsi_level,
            'fluid_label' => $this->fluid_label,
            'allergen_tag_ids' => self::normaliseIds($this->allergen_tag_ids ?? []),
            'dietary_tag_ids' => self::normaliseIds($this->dietary_tag_ids ?? []),
            'clinical_notes' => $this->clinical_notes,
            'amendment_reason' => $this->amendment_reason,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, mixed> $ids
     * @return list<int>
     */
    public static function normaliseIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
