<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class LoneWorkerSession extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'site_id',
        'client_id',
        'shift_id',
        'started_at',
        'expected_end_at',
        'ended_at',
        'location',
        'planned_location',
        'location_lat',
        'location_lng',
        'activity_description',
        'check_in_interval_minutes',
        'last_check_in_at',
        'status',
        'emergency_triggered_at',
        'emergency_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'lock_version' => 'integer',
        'planned_location' => 'array',
        'emergency_episode_number' => 'integer',
        'last_monitor_checked_at' => 'datetime',
        'monitor_failed_at' => 'datetime',
        'started_at' => 'datetime',
        'expected_end_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_check_in_at' => 'datetime',
        'emergency_triggered_at' => 'datetime',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(LoneWorkerCheckIn::class);
    }

    public function emergencyEpisodes(): HasMany
    {
        return $this->hasMany(LoneWorkerEmergencyEpisode::class);
    }

    public function currentEmergencyEpisode(): HasOne
    {
        return $this->hasOne(LoneWorkerEmergencyEpisode::class)->ofMany('episode_number', 'max');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(LoneWorkerAlert::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeEmergency($query)
    {
        return $query->where('status', 'emergency');
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    /** The latest real, already received observation; startup is never a check-in. */
    public function latestReceivedCheckInQuery(): Builder
    {
        return LoneWorkerCheckIn::query()->where('lone_worker_session_id', $this->id)
            ->where('checked_in_at', '<=', now())
            ->where(fn ($receipt) => $receipt->whereNull('received_at')->orWhere('received_at', '<=', now()))
            ->orderByRaw('COALESCE(received_at, created_at, checked_in_at) DESC')->orderByDesc('id');
    }

    /** Scheduling deadline only; it does not establish receipt or monitoring availability. */
    public function checkInDueAt(?LoneWorkerCheckIn $check = null): ?Carbon
    {
        if (func_num_args() === 0 && $this->last_check_in_at === null) {
            $check = $this->latestReceivedCheckInQuery()->first();
        }
        $baseline = $this->last_check_in_at ?? $check?->checked_in_at ?? $this->started_at;

        return $baseline && (int) $this->check_in_interval_minutes > 0
            ? $baseline->copy()->addMinutes((int) $this->check_in_interval_minutes) : null;
    }

    public function isCheckInOverdue(?Carbon $due = null): bool
    {
        if ($this->ended_at !== null || ! in_array($this->status, ['active', 'overdue'], true)) {
            return false;
        }
        if ($this->status === 'overdue') {
            return true;
        }
        $due = func_num_args() === 0 ? $this->checkInDueAt() : $due;

        return $due !== null && $due->lte(now());
    }

    /** SQL counterpart of checkInDueAt/isCheckInOverdue for accurate source pagination. */
    public function scopeCheckInOverdue(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $instant = now()->toDateTimeString();
        $observation = LoneWorkerCheckIn::query()->select('checked_in_at')
            ->whereColumn('lone_worker_session_id', $table.'.id')
            ->where('checked_in_at', '<=', $instant)
            ->where(fn ($receipt) => $receipt->whereNull('received_at')->orWhere('received_at', '<=', $instant))
            ->orderByRaw('COALESCE(received_at, created_at, checked_in_at) DESC')->orderByDesc('id')->limit(1)->lock('for share nowait');
        $deadline = 'DATE_ADD(COALESCE('.$table.'.last_check_in_at, ('.$observation->toSql().'), '.$table.'.started_at), INTERVAL '.$table.'.check_in_interval_minutes MINUTE) <= ?';

        return $query->whereNull($table.'.ended_at')->where(fn ($live) => $live
            ->where($table.'.status', 'overdue')
            ->orWhere(fn ($active) => $active->where($table.'.status', 'active')
                ->where($table.'.check_in_interval_minutes', '>', 0)
                ->whereRaw($deadline, [...$observation->getBindings(), $instant])));
    }
}
