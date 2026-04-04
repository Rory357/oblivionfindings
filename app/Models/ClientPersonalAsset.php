<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientPersonalAsset extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'category',
        'description',
        'serial_number',
        'estimated_value',
        'condition',
        'location',
        'photo_path',
        'acquired_at',
        'notes',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'acquired_at' => 'date',
    ];

    protected $appends = ['photo_url'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
