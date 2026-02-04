<?php

namespace App\Models\ControlRoom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $table = 'control_room_shifts';

    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'status',
        'shift_lead_user_id',
        'team_members',
        'open_alerts_at_start',
        'open_alerts_at_end',
        'alerts_created',
        'alerts_resolved',
        'alerts_escalated',
        'handover_notes',
        'priority_items',
        'handed_over_to_user_id',
        'handed_over_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'team_members' => 'array',
        'priority_items' => 'array',
    ];

    public function shiftLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shift_lead_user_id');
    }

    public function handedOverTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_to_user_id');
    }

    public function operatorNotes(): HasMany
    {
        return $this->hasMany(OperatorNote::class, 'shift_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCurrent($query)
    {
        return $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    public static function getCurrent(): ?self
    {
        return static::current()->first();
    }

    public static function startNew(User $shiftLead, ?string $name = null, array $teamMembers = []): self
    {
        // End any active shifts
        static::active()->update([
            'status' => 'completed',
            'ends_at' => now(),
        ]);

        return static::create([
            'name' => $name ?? 'Shift ' . now()->format('Y-m-d H:i'),
            'starts_at' => now(),
            'status' => 'active',
            'shift_lead_user_id' => $shiftLead->id,
            'team_members' => $teamMembers,
            'open_alerts_at_start' => \App\Models\ControlRoomAlert::unresolved()->count(),
        ]);
    }

    public function handover(User $toUser, string $notes, array $priorityItems = []): void
    {
        $this->update([
            'status' => 'completed',
            'ends_at' => now(),
            'open_alerts_at_end' => \App\Models\ControlRoomAlert::unresolved()->count(),
            'handover_notes' => $notes,
            'priority_items' => $priorityItems,
            'handed_over_to_user_id' => $toUser->id,
            'handed_over_at' => now(),
        ]);
    }

    public function incrementCreated(): void
    {
        $this->increment('alerts_created');
    }

    public function incrementResolved(): void
    {
        $this->increment('alerts_resolved');
    }

    public function incrementEscalated(): void
    {
        $this->increment('alerts_escalated');
    }

    public function getDuration(): ?int
    {
        if (!$this->ends_at) {
            return $this->starts_at->diffInMinutes(now());
        }

        return $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function getTeamMemberUsers()
    {
        if (empty($this->team_members)) {
            return collect();
        }

        return User::whereIn('id', $this->team_members)->get();
    }
}
