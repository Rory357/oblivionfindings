<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientPhoto extends Model
{
    protected $fillable = [
        'client_id',
        'uploaded_by_user_id',
        'storage_path',
        'thumbnail_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'caption',
        'tags',
        'visibility',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'approved_at' => 'datetime',
    ];

    protected $appends = ['url', 'thumbnail_url'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->storage_path ? Storage::disk('public')->url($this->storage_path) : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeVisibleToFamily($query)
    {
        return $query->whereIn('visibility', ['family', 'all_portal_users']);
    }
}
