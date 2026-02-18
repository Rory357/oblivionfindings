<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrLeaveBalanceLedger extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'leave_type',
        'year',
        'entry_type',
        'hours_delta',
        'balance_hours_before',
        'balance_hours_after',
        'used_hours_before',
        'used_hours_after',
        'pending_hours_before',
        'pending_hours_after',
        'source_type',
        'source_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'hours_delta' => 'decimal:2',
        'balance_hours_before' => 'decimal:2',
        'balance_hours_after' => 'decimal:2',
        'used_hours_before' => 'decimal:2',
        'used_hours_after' => 'decimal:2',
        'pending_hours_before' => 'decimal:2',
        'pending_hours_after' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }
}

