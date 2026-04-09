<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftEligibilityOverride extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'shift_id',
        'user_id',
        'overridden_by',
        'override_reason',
        'rules_overridden',
        'acknowledged_warnings',
    ];

    protected $casts = [
        'rules_overridden' => 'array',
        'acknowledged_warnings' => 'array',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
