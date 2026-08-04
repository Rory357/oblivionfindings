<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDocument extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'site_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'folder',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
