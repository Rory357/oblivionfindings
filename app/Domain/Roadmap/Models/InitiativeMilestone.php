<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InitiativeMilestone extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_milestones';

    protected $fillable = [
        'initiative_id',
        'name',
        'description',
        'due_date',
        'status',
        'owner_user_id',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(InitiativeTask::class, 'initiative_milestone_id');
    }
}
