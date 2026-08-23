<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SafeguardingDeclassificationReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'safeguarding_concern_id',
        'site_id',
        'active_concern_id',
        'concern_sensitivity_version',
        'concern_updated_at',
        'status',
        'requested_by_user_id',
        'requested_at',
        'reason',
        'audience_snapshot',
        'audience_hash',
        'request_replay_key',
        'content_hash',
        'reviewed_by_user_id',
        'reviewed_at',
        'decision_reason',
        'decision_replay_key',
    ];

    protected $casts = [
        'concern_sensitivity_version' => 'integer',
        'concern_updated_at' => 'datetime',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'audience_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            $transitionFields = [
                'status',
                'active_concern_id',
                'reviewed_by_user_id',
                'reviewed_at',
                'decision_reason',
                'decision_replay_key',
                'updated_at',
            ];

            if (array_diff(array_keys($review->getDirty()), $transitionFields) !== []) {
                throw new LogicException('Safeguarding declassification request provenance is immutable.');
            }

            if ($review->getOriginal('status') !== self::STATUS_PENDING
                || ! in_array($review->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                throw new LogicException('Safeguarding declassification decisions are terminal.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Safeguarding declassification provenance cannot be deleted.');
        });
    }

    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function calculateContentHash(): string
    {
        $content = [
            'safeguarding_concern_id' => (int) $this->safeguarding_concern_id,
            'site_id' => $this->site_id ? (int) $this->site_id : null,
            'concern_sensitivity_version' => (int) $this->concern_sensitivity_version,
            'concern_updated_at' => $this->concern_updated_at?->format('Y-m-d H:i:s'),
            'requested_by_user_id' => (int) $this->requested_by_user_id,
            'requested_at' => $this->requested_at?->format('Y-m-d H:i:s'),
            'reason' => $this->reason,
            'audience_snapshot' => $this->audience_snapshot,
            'audience_hash' => $this->audience_hash,
            'request_replay_key' => $this->request_replay_key,
        ];

        return hash('sha256', json_encode(
            $this->canonicalizeForHash($content),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
    }
}
