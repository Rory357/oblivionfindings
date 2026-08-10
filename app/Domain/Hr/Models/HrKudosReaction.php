<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single emoji reaction on a kudos. Unique per (kudos, user, emoji) — clicking
 * the same emoji again removes the row (toggle), so a person can hold at most one
 * of each emoji per kudos.
 */
class HrKudosReaction extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'kudos_id',
        'user_id',
        'emoji',
    ];

    public function kudos(): BelongsTo
    {
        return $this->belongsTo(HrKudos::class, 'kudos_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
