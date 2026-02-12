<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class GovernanceMeeting extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'meeting_type',
        'board_committee_id',
        'title',
        'scheduled_at',
        'duration_minutes',
        'location',
        'virtual_link',
        'notes',
        'status',
        'quorum_required',
        'quorum_met',
        'chair_id',
        'secretary_id',
        'pack_distributed_at',
        'minutes_approved_at',
        'minutes_approved_by',
        'minutes_signed_at',
        'minutes_signed_by',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'pack_distributed_at' => 'datetime',
        'minutes_approved_at' => 'datetime',
        'minutes_signed_at' => 'datetime',
        'quorum_met' => 'boolean',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(BoardCommittee::class, 'board_committee_id');
    }

    public function chair(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'chair_id');
    }

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'secretary_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('order');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinute::class);
    }

    public function boardPack(): HasOne
    {
        return $this->hasOne(BoardPack::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(Resolution::class);
    }

    public function conflictDeclarations(): HasMany
    {
        return $this->hasMany(ConflictDeclaration::class);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('scheduled_at');
    }

    public function scopePast($query)
    {
        return $query->where('scheduled_at', '<', now())
            ->orderByDesc('scheduled_at');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('meeting_type', $type);
    }

    public function isFullBoard(): bool
    {
        return $this->meeting_type === 'full_board';
    }

    public function isCommittee(): bool
    {
        return in_array($this->meeting_type, ['audit_risk', 'people', 'finance']);
    }

    public function isExecutiveSession(): bool
    {
        return $this->meeting_type === 'executive_session';
    }

    public function isDraft(): bool
    {
        return in_array($this->status, ['scheduled', 'agenda_draft']);
    }

    public function isEditable(): bool
    {
        return !$this->isLocked() && in_array($this->status, ['scheduled', 'agenda_draft', 'agenda_final']);
    }

    public function canDistributePack(): bool
    {
        return in_array($this->status, ['agenda_final', 'in_progress']);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function lock(int $userId): void
    {
        $this->update([
            'locked_at' => now(),
            'locked_by' => $userId,
        ]);
    }

    public function calculateQuorum(): array
    {
        $present = $this->attendances()->where('status', 'present')->count();
        $total = BoardMember::active()->count();
        $required = ceil($total * ($this->quorum_required / 100));

        return [
            'present' => $present,
            'required' => $required,
            'met' => $present >= $required,
        ];
    }

    public function updateQuorumStatus(): void
    {
        $quorum = $this->calculateQuorum();
        $this->update(['quorum_met' => $quorum['met']]);
    }

    public function advanceStatus(string $newStatus): bool
    {
        $validTransitions = [
            'scheduled' => ['agenda_draft', 'cancelled'],
            'agenda_draft' => ['agenda_final', 'cancelled'],
            'agenda_final' => ['in_progress', 'cancelled'],
            'in_progress' => ['minutes_draft'],
            'minutes_draft' => ['minutes_approved'],
            'minutes_approved' => ['archived'],
        ];

        $allowed = $validTransitions[$this->status] ?? [];

        if (!in_array($newStatus, $allowed)) {
            return false;
        }

        $this->update(['status' => $newStatus]);
        return true;
    }
}
