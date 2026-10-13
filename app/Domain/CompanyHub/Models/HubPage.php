<?php

namespace App\Domain\CompanyHub\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HubPage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['draft' => 'array', 'draft_version' => 'integer', 'archived_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(HubRevision::class);
    }

    public function liveRevision(): ?HubRevision
    {
        if ($this->archived_at) {
            return null;
        }
        // Expiry of the newest release must never resurrect an older release.
        $revision = $this->relationLoaded('currentRelease') ? $this->getRelation('currentRelease') : $this->currentRelease()->first();

        return $revision && (! $revision->expires_at || $revision->expires_at->isFuture()) ? $revision : null;
    }

    public function currentRelease(): HasOne
    {
        return $this->hasOne(HubRevision::class)->ofMany(['publish_at' => 'max', 'id' => 'max'], function (Builder $query): void {
            $query->where('state', 'approved')->whereNotNull('approved_at')->where('publish_at', '<=', now());
        });
    }
}
