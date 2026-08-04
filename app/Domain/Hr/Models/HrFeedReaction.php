<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An emoji reaction on a community-wall item that is not a kudos — a feed post
 * (`subject_type = post`) or an announcement (`subject_type = announcement`).
 * Kudos reactions live on {@see HrKudosReaction}.
 */
class HrFeedReaction extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'user_id',
        'emoji',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
