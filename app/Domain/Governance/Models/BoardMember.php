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
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'user_id',
        'board_role',
        'term_start',
        'term_end',
        'is_independent',
        'committee_memberships',
        'biography',
        'expertise_areas',
        'is_active',
    ];

    protected $casts = [
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
            ->where(function ($q) {
                $q->whereNull('term_end')
                    ->orWhere('term_end', '>=', now());
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

    public function isChair(): bool
    {
        return $this->board_role === 'chair';
    }

    public function isSecretary(): bool
    {
        return $this->board_role === 'secretary';
    }

    public function isObserver(): bool
    {
        return $this->board_role === 'observer';
    }

    public function canVote(): bool
    {
        return in_array($this->board_role, ['chair', 'secretary', 'member'])
            && $this->is_active;
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
