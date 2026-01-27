<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDocument extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'version',
        'effective_date',
        'expiry_date',
        'notes',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
