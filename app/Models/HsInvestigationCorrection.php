<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A proposed correction to a done investigation's findings (answer 20). Its values never change once proposed; only
 * its status moves forward: proposed → reviewed → approved, or → rejected. Never deleted.
 */
class HsInvestigationCorrection extends Model
{
    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const FIELD_FINDINGS = 'findings_summary';

    public const FIELDS = [self::FIELD_FINDINGS];

    private const FROZEN = ['hs_investigation_id', 'hs_event_id', 'field', 'before_value', 'after_value', 'reason', 'proposed_by_id', 'proposed_by_name', 'proposed_at'];

    protected $table = 'hs_investigation_corrections';

    protected $fillable = [
        'hs_investigation_id', 'hs_event_id', 'field', 'before_value', 'after_value', 'reason', 'status',
        'proposed_by_id', 'proposed_by_name', 'proposed_at', 'reviewed_by_id', 'reviewed_at', 'approved_by_id',
        'approved_at', 'rejected_by_id', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'proposed_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $correction): void {
            foreach (self::FROZEN as $column) {
                if ($correction->isDirty($column)) {
                    throw new LogicException('A proposed correction keeps what it proposed.');
                }
            }
            if (in_array($correction->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                throw new LogicException('A decided correction is kept as it was decided.');
            }
        });
        static::deleting(fn () => throw new LogicException('A correction is kept as it was recorded.'));
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(HsInvestigation::class, 'hs_investigation_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PROPOSED, self::STATUS_REVIEWED], true);
    }
}
