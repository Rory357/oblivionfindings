<?php

namespace App\Domain\CompanyHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'content' => 'array', 'draft_version' => 'integer',
        'approved_at' => 'datetime', 'publish_at' => 'datetime', 'expires_at' => 'datetime', 'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->isDirty(['hub_page_id', 'created_by', 'draft_version', 'content'])) {
                throw new \LogicException('A submitted snapshot is immutable. Restore it into a draft to make changes.');
            }
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(HubPage::class, 'hub_page_id');
    }
}
