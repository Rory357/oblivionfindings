<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HsClosureExceptionDecision extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_REVOKED = 'revoked';

    public const PHASE_INITIAL = 'initial';

    public const PHASE_REVOCATION = 'revocation';

    protected $table = 'hs_closure_exception_decisions';

    protected $fillable = [
        'hs_closure_exception_id',
        'decision',
        'decision_phase',
        'decided_by_user_id',
        'reason',
        'previous_decision_id',
        'decision_provenance',
        'provenance_hash',
        'decided_at',
    ];

    protected $casts = [
        'decision_provenance' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $decision): never {
            throw new \LogicException('H&S closure exception decisions are immutable.');
        });
        self::deleting(static function (self $decision): never {
            throw new \LogicException('H&S closure exception decisions are immutable.');
        });
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(HsClosureException::class, 'hs_closure_exception_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function previousDecision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_decision_id');
    }
}
