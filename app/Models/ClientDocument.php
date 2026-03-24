<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDocument extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'folder',
        'version',
        'effective_date',
        'expiry_date',
        'portal_visible',
        'notes',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'openai_file_id',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'portal_visible' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
