<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FleetKeyLog extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'site_id',
        'user_id',
        'action',
        'transferred_to_user_id',
        'key_number',
        'location',
        'notes',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $log): void {
            if ($log->isDirty('site_id')) {
                throw new LogicException('Fleet key event Site provenance is immutable.');
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transferredToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_to_user_id');
    }
}
