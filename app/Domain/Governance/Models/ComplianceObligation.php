<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceObligation extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    /**
     * One definition everywhere (header, filters, reports, reminders):
     * due soon = due in the next 30 days, not overdue, not cancelled.
     */
    public const DUE_SOON_DAYS = 30;

    /** Finished statuses the calendar never moves on. */
    public const CLOSED_STATUSES = ['complete', 'cancelled', 'exempt'];

    /** Never counted for or against "on time". */
    public const NOT_COUNTED_STATUSES = ['cancelled', 'exempt'];

    public const STATUS_FILTERS = ['overdue', 'due_soon', 'not_due', 'on_time', 'complete', 'cancelled'];

    protected $fillable = [
        'framework',
        'obligation_code',
        'obligation_title',
        'description',
        'requirements',
        'priority',
        'frequency',
        'workforce_requirement_id',
        'due_date',
        'next_due_date',
        'reminder_days',
        'owner_id',
        'backup_owner_id',
        'status',
        'version_number',
        'parent_obligation_id',
        'recurrence_cycle_key',
        'completed_at',
        'completed_by',
        'completion_notes',
        'evidence_required',
        'evidence_provided',
        'sign_off_required',
        'sign_off_role',
        'signed_off_at',
        'signed_off_by',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'next_due_date' => 'date',
        'completed_at' => 'datetime',
        'signed_off_at' => 'datetime',
        'reminder_days' => 'array',
        'evidence_required' => 'boolean',
        'evidence_provided' => 'boolean',
        'sign_off_required' => 'boolean',
        'version_number' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($model) {
            $model->updateStatus();
        });
    }

    public function updateStatus(): void
    {
        $this->status = $this->currentStatus();
    }

    /** Today's calendar date in New Zealand (app.worker_timezone). */
    public static function nzToday(): string
    {
        $timezone = config('app.worker_timezone');

        return CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : GovernanceLabels::TIMEZONE)
            ->toDateString();
    }

    /** The last NZ date that still counts as "due soon". */
    public static function dueSoonUntil(?string $today = null): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $today ?? self::nzToday())
            ->addDays(self::DUE_SOON_DAYS)
            ->toDateString();
    }

    /**
     * The status worked out from the due date (NZ calendar date), so it is
     * right even before the daily refresh has run: overdue · due_soon ·
     * not_due, or the stored finished status.
     */
    public function currentStatus(?string $today = null): string
    {
        if (in_array($this->status, self::CLOSED_STATUSES, true)) {
            return (string) $this->status;
        }

        if ($this->due_date === null) {
            return 'not_due';
        }

        $today ??= self::nzToday();
        $due = $this->due_date->toDateString();

        return match (true) {
            $due < $today => 'overdue',
            $due <= self::dueSoonUntil($today) => 'due_soon',
            default => 'not_due',
        };
    }

    /** Whole NZ calendar days until the due date (negative once overdue). */
    public function daysUntilDue(?string $today = null): ?int
    {
        if ($this->due_date === null) {
            return null;
        }

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $today ?? self::nzToday(), 'UTC');
        $due = CarbonImmutable::createFromFormat('!Y-m-d', $this->due_date->toDateString(), 'UTC');

        return (int) round(($due->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Optional link to an HR-owned workforce certification / training
     * requirement. The HR module remains source of truth for the underlying
     * staff records; Governance just surfaces the org-level obligation
     * (e.g. "All staff complete H&S induction").
     */
    public function workforceRequirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'workforce_requirement_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ComplianceEvidence::class, 'compliance_obligation_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ComplianceReminder::class, 'compliance_obligation_id');
    }

    public function parentObligation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_obligation_id');
    }

    public function recurrences(): HasMany
    {
        return $this->hasMany(self::class, 'parent_obligation_id');
    }

    public function scopeByFramework($query, string $framework)
    {
        return $query->where('framework', $framework);
    }

    /** Not finished: still something to do. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('status')
            ->orWhereNotIn('status', self::CLOSED_STATUSES));
    }

    /** Counted towards "on time" — everything except cancelled or exempt. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('status')
            ->orWhereNotIn('status', self::NOT_COUNTED_STATUSES));
    }

    /** Open and past its due date (NZ), whatever the stored status says. */
    public function scopeOverdue(Builder $query, ?string $today = null): Builder
    {
        return $query->open()->whereDate('due_date', '<', $today ?? self::nzToday());
    }

    /** Open, due today or in the next 30 days (never overdue). */
    public function scopeDueSoon(Builder $query, int $days = self::DUE_SOON_DAYS, ?string $today = null): Builder
    {
        $today ??= self::nzToday();

        return $query->open()
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', CarbonImmutable::createFromFormat('!Y-m-d', $today)->addDays($days)->toDateString());
    }

    /** Open and due after the due-soon window. */
    public function scopeNotYetDue(Builder $query, ?string $today = null): Builder
    {
        return $query->open()->whereDate('due_date', '>', self::dueSoonUntil($today));
    }

    /** Done, or still open and not overdue. */
    public function scopeOnTime(Builder $query, ?string $today = null): Builder
    {
        $today ??= self::nzToday();

        return $query->where(fn (Builder $q) => $q
            ->where('status', 'complete')
            ->orWhere(fn (Builder $open) => $open->open()->whereDate('due_date', '>=', $today)));
    }

    /** Filter by the status worked out from the due date. */
    public function scopeWithCurrentStatus(Builder $query, string $status, ?string $today = null): Builder
    {
        return match ($status) {
            'overdue' => $query->overdue($today),
            'due_soon' => $query->dueSoon(self::DUE_SOON_DAYS, $today),
            'not_due' => $query->notYetDue($today),
            'on_time' => $query->onTime($today),
            'complete', 'cancelled', 'exempt' => $query->where('status', $status),
            default => $query,
        };
    }

    public function scopeForOwner($query, int $userId)
    {
        return $query->where('owner_id', $userId);
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isOverdue(): bool
    {
        return $this->currentStatus() === 'overdue';
    }

    public function isDueSoon(int $days = self::DUE_SOON_DAYS): bool
    {
        $daysUntil = $this->daysUntilDue();

        return $daysUntil !== null
            && $daysUntil >= 0
            && $daysUntil <= $days
            && ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function markComplete(int $userId, ?string $notes = null, ?int $expectedVersion = null): void
    {
        if ($expectedVersion !== null && (int) $this->version_number !== (int) $expectedVersion) {
            abort(409, 'Someone else changed this requirement while you had it open. Refresh the page and try again.');
        }

        $this->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $userId,
            'completion_notes' => $notes ?? $this->completion_notes,
            'version_number' => (int) ($this->version_number ?? 1) + 1,
        ]);
    }

    public function signOff(int $userId): void
    {
        $this->update([
            'signed_off_at' => now(),
            'signed_off_by' => $userId,
        ]);
    }

    public function getFrameworkLabel(): string
    {
        return self::frameworkOptions()[$this->framework] ?? $this->framework;
    }

    /**
     * Canonical framework key => label map. Single source for the requirement
     * label accessor, every framework picker and filter, and the summary
     * counts, so labels and totals never drift.
     *
     * @return array<string, string>
     */
    public static function frameworkOptions(): array
    {
        return [
            'charities' => 'Charities Act 2005 (Charities Services)',
            'nga_paerewa' => 'Ngā Paerewa Health and Disability Services Standard (NZS 8134:2021)',
            'code_of_rights' => 'Code of Rights (Health and Disability Commissioner)',
            'hdsa_safety' => 'Health and Disability Services (Safety) Act 2001',
            'privacy_act' => 'Privacy Act 2020',
            'hip_code' => 'Health Information Privacy Code 2020',
            'hswa' => 'Health and Safety at Work Act 2015',
            'employment' => 'Employment Relations Act 2000',
            'funding_moh' => 'Health New Zealand funding',
            'funding_dss' => 'Disability Support Services funding',
            'funding_msd' => 'Ministry of Social Development funding',
            'funding_acc' => 'ACC funding',
        ];
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function frameworkSelectOptions(): array
    {
        return collect(self::frameworkOptions())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
