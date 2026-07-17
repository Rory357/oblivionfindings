<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Shift extends Model
{
    public const HANDOVER_NONE = 'none';

    public const HANDOVER_PREPARED = 'prepared';

    public const HANDOVER_ACCEPTED = 'accepted';

    public const EXPECTED_DURATION_HOURS = 8;

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
        'handover_status',
        'handover_snapshot',
        'handover_version',
        'handover_prepared_at',
        'handover_accepted_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'team_members' => 'array',
        'priority_items' => 'array',
        'handover_status' => 'string',
        'handover_snapshot' => 'array',
        'handover_version' => 'integer',
        'handover_prepared_at' => 'datetime',
        'handover_accepted_at' => 'datetime',
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
        return DB::transaction(function () use ($shiftLead, $name, $teamMembers): self {
            if (static::query()->active()->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages([
                    'shift' => 'Complete the active shift through an accepted handover before starting another.',
                ]);
            }

            return static::create([
                'name' => $name ?? 'Shift '.now()->format('Y-m-d H:i'),
                'starts_at' => now(),
                'status' => 'active',
                'shift_lead_user_id' => $shiftLead->id,
                'team_members' => $teamMembers,
                'open_alerts_at_start' => ControlRoomAlert::unresolved()->count(),
            ]);
        });
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
        if (! $this->ends_at) {
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

    /** @return list<int> */
    public function memberUserIds(): array
    {
        return collect([
            $this->shift_lead_user_id,
            ...($this->team_members ?? []),
        ])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function expectedNextShiftAt(?CarbonInterface $from = null): CarbonInterface
    {
        return ($from ?? now())->copy()->addHours(self::EXPECTED_DURATION_HOURS);
    }
}
