<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteHouseRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'tenant_id',
        'name',
        'notes',
        'assigned_client_id',
        'assigned_from',
        'assigned_until',
        'is_active',
        'is_assignable',
        'sort_order',
    ];

    protected $casts = [
        'assigned_from' => 'date',
        'assigned_until' => 'date',
        'is_active' => 'boolean',
        'is_assignable' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_assignable' => true,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'assigned_client_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SiteHouseRoomHistory::class, 'room_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
                     ->where('is_assignable', true)
                     ->whereNull('assigned_client_id');
    }

    public function scopeAssignable($query)
    {
        return $query->where('is_assignable', true);
    }

    public function scopeAssigned($query)
    {
        return $query->whereNotNull('assigned_client_id');
    }

    public function isAssigned(): bool
    {
        return !is_null($this->assigned_client_id);
    }

    public function isCurrentlyAssigned(): bool
    {
        if (!$this->isAssigned()) {
            return false;
        }

        $now = now()->toDateString();
        return $this->assigned_from <= $now &&
               (is_null($this->assigned_until) || $this->assigned_until >= $now);
    }
}
