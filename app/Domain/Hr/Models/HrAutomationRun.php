<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAutomationRun extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'rule_id',
        'tenant_id',
        'event_type',
        'event_payload',
        'status',
        'message',
        'executed_at',
    ];

    protected $casts = [
        'event_payload' => 'array',
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(HrAutomationRule::class, 'rule_id');
    }
}
