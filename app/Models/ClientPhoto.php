<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPhoto extends Model
{
    protected $fillable = [
        'client_id',
        'uploaded_by_user_id',
        'storage_disk',
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

    protected $hidden = [
        'storage_path',
        'thumbnail_path',
    ];

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

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeVisibleToFamily($query)
    {
        return $query->whereIn('visibility', ['family', 'all_portal_users']);
    }
}
