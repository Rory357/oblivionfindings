<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetScanEvent extends Model
{
    protected $fillable = [
        'asset_id',
        'qr_token',
        'scanned_by_type',
        'scanned_by_id',
        'scanned_at',
        'site_id',
        'client_id',
        'context',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'context' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
