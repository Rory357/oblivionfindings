<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardMember extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'board_role',
        'has_voting_seat',
        'term_start',
        'term_end',
        'is_independent',
        'committee_memberships',
        'biography',
        'expertise_areas',
        'is_active',
    ];

    protected $casts = [
        'has_voting_seat' => 'boolean',
        'term_start' => 'date',
        'term_end' => 'date',
        'is_independent' => 'boolean',
        'is_active' => 'boolean',
        'committee_memberships' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function committees(): BelongsToMany
    {
        return $this->belongsToMany(BoardCommittee::class, 'committee_memberships')
            ->withPivot(['role', 'appointed_at', 'term_end', 'is_active'])
            ->withTimestamps();
    }

    public function committeeMemberships(): HasMany
    {
        return $this->hasMany(CommitteeMembership::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(BoardMemberPreference::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function conflictDeclarations(): HasMany
    {
        return $this->hasMany(ConflictDeclaration::class);
    }

    public function chairedMeetings(): HasMany
    {
        return $this->hasMany(GovernanceMeeting::class, 'chair_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereDate('term_start', '<=', today())
            ->where(function ($q) {
                $q->whereNull('term_end')
                    ->orWhereDate('term_end', '>=', today());
            });
    }

    public function scopeChair($query)
    {
        return $query->where('board_role', 'chair');
    }

    public function scopeSecretary($query)
    {
        return $query->where('board_role', 'secretary');
    }

    public function scopeEligibleVoters($query)
    {
        return $query->active()
            ->where('board_role', '!=', 'observer')
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereIn('board_role', ['chair', 'member', 'treasurer'])
                        ->where(function ($sq) {
                            $sq->whereNull('has_voting_seat')
                                ->orWhere('has_voting_seat', true);
                        });
                })->orWhere(function ($sub) {
                    $sub->where('board_role', 'secretary')
                        ->where('has_voting_seat', true);
                });
            });
    }

    public function isChair(): bool
    {
        return $this->board_role === 'chair';
    }

    public function isSecretary(): bool
    {
        return $this->board_role === 'secretary';
    }

    public function isTreasurer(): bool
    {
        return $this->board_role === 'treasurer';
    }

    public function isObserver(): bool
    {
        return $this->board_role === 'observer';
    }

    public function canVote(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isObserver()) {
            return false;
        }

        $today = today();
        if ($this->term_start && $this->term_start->isAfter($today)) {
            return false;
        }

        if ($this->term_end && $this->term_end->isBefore($today)) {
            return false;
        }

        // Secretary vote depends on explicit voting appointment, not administrative title
        if ($this->board_role === 'secretary') {
            return (bool) $this->has_voting_seat;
        }

        // Chair, member, and treasurer have voting seats unless explicitly revoked
        if (in_array($this->board_role, ['chair', 'member', 'treasurer'], true)) {
            return $this->has_voting_seat ?? true;
        }

        return (bool) $this->has_voting_seat;
    }

    public function isCommitteeMember(string $committeeType): bool
    {
        return $this->committees()
            ->where('committee_type', $committeeType)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function getFullNameAttribute(): string
    {
        return $this->user?->name ?? 'Unknown';
    }

    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }
}
