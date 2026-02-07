<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakGlassAccess extends Model
{
    use HasFactory;

    protected $table = 'break_glass_accesses';

    protected $fillable = [
        'requested_by',
        'requested_at',
        'reason',
        'requested_resource',
        'approved_by',
        'approved_at',
        'approval_notes',
        'access_granted',
        'access_start',
        'access_end',
        'actions_taken',
        'closure_notes',
        'closed_at',
        'closed_by',
        'board_notified',
        'board_notified_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'access_start' => 'datetime',
        'access_end' => 'datetime',
        'closed_at' => 'datetime',
        'board_notified_at' => 'datetime',
        'access_granted' => 'boolean',
        'board_notified' => 'boolean',
        'actions_taken' => 'array',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }

    public function scopeActive($query)
    {
        return $query->where('access_granted', true)
            ->where('access_end', '>', now());
    }

    public function scopeBoardNotNotified($query)
    {
        return $query->where('board_notified', false);
    }

    public function isPending(): bool
    {
        return is_null($this->approved_at);
    }

    public function isApproved(): bool
    {
        return $this->access_granted;
    }

    public function isActive(): bool
    {
        return $this->access_granted && 
               $this->access_end && 
               $this->access_end->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->access_end && $this->access_end->isPast();
    }

    public function canApprove(User $user): bool
    {
        // Cannot approve own request
        if ($user->id === $this->requested_by) {
            return false;
        }

        // Must be board tier
        return $user->hasRole('board_chair', 'board_secretary', 'board_member');
    }

    public function approve(int $userId, ?string $notes = null, int $durationHours = 4): void
    {
        $this->update([
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes,
            'access_granted' => true,
            'access_start' => now(),
            'access_end' => now()->addHours($durationHours),
        ]);
    }

    public function reject(int $userId, string $reason): void
    {
        $this->update([
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_notes' => $reason,
            'access_granted' => false,
        ]);
    }

    public function logAction(string $action, array $details = []): void
    {
        $actions = $this->actions_taken ?? [];
        $actions[] = [
            'timestamp' => now()->toIso8601String(),
            'action' => $action,
            'details' => $details,
        ];
        $this->update(['actions_taken' => $actions]);
    }

    public function close(int $userId, ?string $notes = null): void
    {
        $this->update([
            'closed_at' => now(),
            'closed_by' => $userId,
            'closure_notes' => $notes,
            'access_end' => now(),
        ]);
    }

    public function markBoardNotified(): void
    {
        $this->update([
            'board_notified' => true,
            'board_notified_at' => now(),
        ]);
    }
}
