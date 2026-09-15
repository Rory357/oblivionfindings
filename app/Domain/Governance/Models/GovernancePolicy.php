<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class GovernancePolicy extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    /**
     * "Read and confirm" cadence → months a confirmation stays current. After
     * that the member is asked to confirm the same version again.
     */
    public const CONFIRMATION_FREQUENCY_MONTHS = [
        'annual' => 12,
        'biannual' => 6,
        'quarterly' => 3,
    ];

    protected static function newFactory()
    {
        return \Database\Factories\Governance\GovernancePolicyFactory::new();
    }

    protected $fillable = [
        'policy_code', 'title', 'category', 'purpose', 'content',
        'version_number', 'status', 'requires_attestation', 'attestation_frequency',
        'approval_resolution_id', 'owner_id', 'approved_by', 'approved_at',
        'effective_from', 'review_due', 'next_review_date', 'supersedes_policy_id', 'created_by',
        'change_summary',
    ];

    protected $casts = [
        'requires_attestation' => 'boolean',
        'approved_at' => 'datetime',
        'effective_from' => 'date',
        'review_due' => 'date',
        'next_review_date' => 'date',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_policy_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(PolicyAttestation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeReviewDue($query)
    {
        return $query->where('status', 'approved')
            ->where('next_review_date', '<=', now()->addDays(30));
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function approve(int $userId, ?int $resolutionId = null): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_resolution_id' => $resolutionId,
            'effective_from' => now(),
            'next_review_date' => now()->addYear(),
        ]);
    }

    /**
     * Draft the next version. The approved version stays in effect — and its
     * confirmations stay valid — until the new version is approved
     * (GovernancePolicyController::approve marks it replaced then).
     */
    public function createNewVersion(int $userId): self
    {
        $new = $this->replicate(['approval_resolution_id']);
        $new->version_number = (int) $this->version_number + 1;
        $new->status = 'draft';
        $new->supersedes_policy_id = $this->id;
        $new->approved_by = null;
        $new->approved_at = null;
        $new->created_by = $userId;
        $new->save();

        return $new;
    }

    /** Today's calendar date in New Zealand (app.worker_timezone). */
    public static function nzToday(): string
    {
        $timezone = config('app.worker_timezone');

        return CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : GovernanceLabels::TIMEZONE)
            ->toDateString();
    }

    /** Approved and its effective date has arrived (NZ calendar date). */
    public function isInEffect(?string $today = null): bool
    {
        if (! in_array($this->status, ['approved', 'published', 'active'], true)) {
            return false;
        }

        return $this->effective_from === null
            || $this->effective_from->toDateString() <= ($today ?? self::nzToday());
    }

    /** Approved, but it only comes into effect on a later NZ date. */
    public function comesIntoEffectLater(?string $today = null): bool
    {
        return in_array($this->status, ['approved', 'published', 'active'], true)
            && $this->effective_from !== null
            && $this->effective_from->toDateString() > ($today ?? self::nzToday());
    }

    /** Members are asked to read and confirm this policy. */
    public function needsConfirmation(): bool
    {
        return (bool) $this->requires_attestation;
    }

    /** The NZ date a confirmation must be repeated, or null when it never expires. */
    public function confirmationDueAgainOn(?PolicyAttestation $attestation): ?string
    {
        $months = self::CONFIRMATION_FREQUENCY_MONTHS[(string) $this->attestation_frequency] ?? null;

        if ($months === null || $attestation?->acknowledged_at === null) {
            return null;
        }

        $timezone = config('app.worker_timezone');

        return CarbonImmutable::instance($attestation->acknowledged_at)
            ->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : GovernanceLabels::TIMEZONE)
            ->addMonthsNoOverflow($months)
            ->toDateString();
    }

    /**
     * A confirmation that still counts: this version, acknowledged, and not
     * yet due again under the policy's confirmation frequency.
     */
    public function isCurrentConfirmation(?PolicyAttestation $attestation, ?string $today = null): bool
    {
        if ($attestation === null
            || ! $attestation->acknowledged
            || $attestation->acknowledged_at === null
            || (int) ($attestation->policy_version ?? 0) !== (int) $this->version_number) {
            return false;
        }

        $dueAgain = $this->confirmationDueAgainOn($attestation);

        return $dueAgain === null || $dueAgain > ($today ?? self::nzToday());
    }

    /**
     * Current confirmations from active board members only — the number shown
     * against "{n} of {board members}", so it can never pass 100%.
     *
     * @param  Collection<int, int>|null  $activeBoardUserIds
     */
    public function currentBoardConfirmationCount(?Collection $activeBoardUserIds = null, ?string $today = null): int
    {
        $activeBoardUserIds ??= BoardMember::active()->pluck('user_id');
        $boardUsers = $activeBoardUserIds->map(fn ($id) => (int) $id)->flip();
        $attestations = $this->relationLoaded('attestations') ? $this->attestations : $this->attestations()->get();

        return $attestations
            ->filter(fn (PolicyAttestation $attestation) => $boardUsers->has((int) $attestation->user_id)
                && $this->isCurrentConfirmation($attestation, $today))
            ->unique('user_id')
            ->count();
    }
}
