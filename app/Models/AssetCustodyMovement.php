<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCustodyMovement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['kit_snapshot' => 'array', 'received_kit' => 'array', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'returned_at' => 'datetime', 'return_due_on' => 'date'];

    public function originSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'origin_site_id');
    }

    public function destinationSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'destination_site_id');
    }

    public function destinationRoom(): BelongsTo
    {
        return $this->belongsTo(SiteRoom::class, 'destination_room_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
