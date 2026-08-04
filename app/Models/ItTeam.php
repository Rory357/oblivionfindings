<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItTeam extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const MEMBER_ROLES = ['member', 'lead', 'manager'];

    protected $fillable = [
        'manager_user_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'it_team_members', 'team_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function queues(): HasMany
    {
        return $this->hasMany(ItQueue::class, 'team_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ItTicket::class, 'team_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ItWorkTask::class, 'team_id');
    }
}
