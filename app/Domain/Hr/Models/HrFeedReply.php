<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply on a community-wall item that is not a kudos — a feed post
 * (`subject_type = post`) or an announcement (`subject_type = announcement`).
 * Kudos replies live on {@see HrKudosReply}.
 */
class HrFeedReply extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'user_id',
        'body',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
