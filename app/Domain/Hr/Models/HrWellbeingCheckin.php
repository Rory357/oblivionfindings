<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrWellbeingCheckin extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'staff_user_id',
        'manager_user_id',
        'type',
        'notes',
        'mood',
        'follow_up_date',
        'follow_up_reminder_sent_at',
        'is_private',
        'acknowledged_at',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'follow_up_reminder_sent_at' => 'datetime',
        'is_private' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
