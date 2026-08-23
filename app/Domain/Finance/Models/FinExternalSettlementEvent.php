<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinExternalSettlementEvent extends Model
{
    protected $table = 'fin_external_settlement_events';

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('External settlement evidence is append-only.');
        });
        static::deleting(function (): never {
            throw new \LogicException('External settlement evidence is append-only.');
        });
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinExternalSettlement::class, 'external_settlement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
