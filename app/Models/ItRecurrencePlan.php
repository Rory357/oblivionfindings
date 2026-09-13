<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ItRecurrencePlan extends Model
{
    public const STATUSES = ['active', 'paused', 'retired'];

    protected $guarded = ['id'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'exception_dates' => 'array',
        'ticket_template' => 'array',
        'next_due_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ItRecurrenceRun::class, 'plan_id');
    }
}
