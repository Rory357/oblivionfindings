<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinBillApprovalReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['result' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Approval receipts are immutable.'));
        self::deleting(fn () => throw new \LogicException('Approval receipts are retained.'));
    }
}
