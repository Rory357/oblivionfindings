<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SpendApprovalDecision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'evidence_version' => 'integer',
        'submission_version' => 'integer',
        'decided_at' => 'datetime',
        'parent_evidence' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Spend approval decision evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Spend approval decision evidence is immutable.');
        });
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(SpendApproval::class, 'spend_approval_id');
    }
}
