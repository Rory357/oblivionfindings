<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle's link to an existing Finance record (fixed asset, purchase order
 * or supplier invoice), made from the vehicle profile with a reason. The
 * Finance record is never changed; removing a link keeps it as history.
 */
class FleetVehicleFinanceLink extends Model
{
    public const TYPES = ['fixed_asset', 'purchase_order', 'bill'];

    protected $fillable = [
        'asset_id', 'record_type', 'record_id', 'active_slot', 'reason', 'linked_by_user_id',
        'unlinked_at', 'unlinked_by_user_id', 'unlink_reason', 'request_key', 'request_fingerprint',
        'unlink_request_key', 'unlink_request_fingerprint',
    ];

    protected $casts = [
        'record_id' => 'integer',
        'active_slot' => 'integer',
        'unlinked_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->unlinked_at === null;
    }
}
