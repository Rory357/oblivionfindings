<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Budget extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    /** Budget changes of this share of the budget or more need a board decision. */
    public const CHANGE_BOARD_THRESHOLD_PCT = 5.0;

    /** Resolution statuses in which a passed vote is final. */
    public const PASSED_RESOLUTION_STATUSES = ['closed', 'implemented', 'archived'];

    /*
     * Where a budget's approval stands, derived from its linked resolution
     * (see resolutionState()).
     */
    public const RESOLUTION_NONE = 'none';

    public const RESOLUTION_UNLINKED = 'unlinked';

    public const RESOLUTION_DRAFTED = 'drafted';

    public const RESOLUTION_VOTING_OPEN = 'voting_open';

    public const RESOLUTION_PASSED = 'passed';

    public const RESOLUTION_NOT_PASSED = 'not_passed';

    public const RESOLUTION_USED = 'used';

    protected $fillable = [
        'fiscal_year',
        'title',
        'description',
        'total_budget',
        'currency',
        'status',
        'proposed_by',
        'proposed_at',
        'approval_resolution_id',
        'approved_by_board_at',
        'actuals_recorded_at',
        'external_approval_reference',
        'version_number',
        'supersedes_budget_id',
        'created_by',
    ];

    protected $casts = [
        'total_budget' => 'decimal:2',
        'proposed_at' => 'datetime',
        'approved_by_board_at' => 'datetime',
        'actuals_recorded_at' => 'datetime',
        'version_number' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if ($model->version_number === null) {
                $max = (int) static::query()->where('fiscal_year', $model->fiscal_year)->max('version_number');
                $model->version_number = $max + 1;
            }
        });
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'supersedes_budget_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class);
    }

    public function scopeByFiscalYear($query, string $year)
    {
        return $query->where('fiscal_year', $year);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function isDrafting(): bool
    {
        return $this->status === 'drafting';
    }

    public function isProposed(): bool
    {
        return $this->status === 'proposed';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** "2025/26 budget" when the budget has no title of its own. */
    public function displayName(): string
    {
        return $this->title ?: GovernanceLabels::financialYear((string) $this->fiscal_year).' budget';
    }

    /**
     * Send the budget to the board: prepares a draft resolution, linked to
     * this exact budget version and its lines, for the secretary to put on a
     * meeting agenda.
     *
     * A budget already waiting for the board can be sent again when its
     * resolution can no longer approve it — the resolution is missing, did
     * not pass, or the budget was edited after it was prepared. A resolution
     * that is still a draft is re-linked to the current figures instead of
     * preparing a second one.
     *
     * @throws ValidationException when the budget cannot be sent now
     */
    public function propose(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($userId);
            $authority = app(GovernanceResolutionAuthorityService::class);

            $existingDraft = null;

            if ($locked->isApproved()) {
                throw ValidationException::withMessages([
                    'budget' => 'This budget is already approved. Use a budget change to adjust it.',
                ]);
            }

            if ($locked->isProposed()) {
                $state = $locked->resolutionState();

                if ($state['state'] === self::RESOLUTION_VOTING_OPEN) {
                    throw ValidationException::withMessages([
                        'budget' => "Voting is open on this budget's resolution. Wait for the result before sending the budget to the board again.",
                    ]);
                }

                if ($state['state'] === self::RESOLUTION_PASSED && ! $state['stale']) {
                    throw ValidationException::withMessages([
                        'budget' => "The board has passed this budget's resolution. Record the board's approval instead of sending it again.",
                    ]);
                }

                if ($state['state'] === self::RESOLUTION_DRAFTED && ! $state['stale']) {
                    throw ValidationException::withMessages([
                        'budget' => "This budget's resolution is already prepared and matches the budget. The secretary adds it to a meeting agenda.",
                    ]);
                }

                if ($state['state'] === self::RESOLUTION_DRAFTED) {
                    $existingDraft = Resolution::query()
                        ->whereKey($locked->approval_resolution_id)
                        ->lockForUpdate()
                        ->first();
                }
            } elseif (! $locked->isDrafting()) {
                throw ValidationException::withMessages([
                    'budget' => 'Only a draft budget can be sent to the board.',
                ]);
            }

            $locked->load('lineItems');
            $name = $locked->displayName();
            $financialYear = GovernanceLabels::financialYear((string) $locked->fiscal_year);
            $total = GovernanceLabels::money($locked->total_budget);
            $lineCount = $locked->lineItems->count();
            $costImpact = [
                'amount' => (float) $locked->total_budget,
                'currency' => $locked->currency ?? 'NZD',
                'description' => "Total budget for the {$financialYear} financial year",
            ];

            if ($existingDraft) {
                // Refresh the wording the system wrote; anything the secretary
                // rewrote by hand is left alone.
                $existingDraft->update([
                    'cost_impact' => [...(array) $existingDraft->cost_impact, ...$costImpact],
                    ...(self::isGeneratedContext((string) $existingDraft->context)
                        ? ['context' => self::generatedContext($name, $financialYear, $total, $lineCount)]
                        : []),
                    ...(preg_match('/^That the board approves the .+ as presented, totalling \$[\d,.]+\.$/u', (string) $existingDraft->exact_motion) === 1
                        ? ['exact_motion' => self::generatedMotion($name, $total)]
                        : []),
                ]);
                $resolution = $existingDraft;
            } else {
                $resolution = Resolution::create([
                    'title' => $locked->title ? "Approve the budget: {$locked->title}" : "Approve the {$name}",
                    'exact_motion' => self::generatedMotion($name, $total),
                    'decision_type' => 'budget_approval',
                    'context' => self::generatedContext($name, $financialYear, $total, $lineCount),
                    'options' => [],
                    'recommendation' => 'Approve the budget as presented.',
                    'cost_impact' => $costImpact,
                    'voting_threshold' => 'simple_majority',
                    'status' => 'draft',
                    'proposed_by' => $userId,
                    'proposed_at' => now(),
                ]);
            }

            // The resolution is linked, while still a draft, to this exact
            // budget version and its lines. Any change to those terms before
            // approval means the board must see the updated budget.
            $authority->bind($resolution, GovernanceResolutionBinding::SUBJECT_BUDGET, (int) $locked->getKey(), $actor);

            $locked->update([
                'status' => 'proposed',
                'proposed_by' => $userId,
                'proposed_at' => now(),
                'approval_resolution_id' => $resolution->id,
            ]);
        }, 3);

        $this->refresh();
    }

    /**
     * Take a budget that is waiting for the board back to draft — when its
     * resolution did not pass, is missing, or no longer matches the figures.
     *
     * @throws ValidationException when the board can still decide the budget as it is
     */
    public function returnToDrafting(): void
    {
        DB::transaction(function (): void {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isProposed()) {
                throw ValidationException::withMessages([
                    'budget' => 'Only a budget that is waiting for the board can be returned to drafting.',
                ]);
            }

            $state = $locked->resolutionState();

            if ($state['state'] === self::RESOLUTION_VOTING_OPEN) {
                throw ValidationException::withMessages([
                    'budget' => "Voting is open on this budget's resolution. Wait for the result before returning it to drafting.",
                ]);
            }

            if ($state['state'] === self::RESOLUTION_PASSED && ! $state['stale']) {
                throw ValidationException::withMessages([
                    'budget' => "The board has passed this budget's resolution. Record the board's approval instead.",
                ]);
            }

            if ($state['state'] === self::RESOLUTION_DRAFTED) {
                throw ValidationException::withMessages([
                    'budget' => "This budget's resolution hasn't gone to a vote yet. Edit the budget directly, then send the updated budget to the board.",
                ]);
            }

            $locked->update([
                'status' => 'drafting',
                'approval_resolution_id' => null,
                'proposed_by' => null,
                'proposed_at' => null,
            ]);
        }, 3);

        $this->refresh();
    }

    /**
     * Approve this budget under a passed resolution.
     *
     * Authority comes only from an explicit link created while the resolution
     * was a draft, naming this exact budget, version and budgeted lines.
     * Titles, cost-impact amounts and wording never confer authority. The
     * link is verified under row locks and used once.
     *
     * @throws ValidationException when the resolution does not authorise this budget
     */
    public function approve(int $resolutionId, ?int $actorId = null): void
    {
        $authority = app(GovernanceResolutionAuthorityService::class);

        DB::transaction(function () use ($resolutionId, $actorId, $authority) {
            $lockedBudget = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $resolution = Resolution::query()->whereKey($resolutionId)->lockForUpdate()->first();

            if (! $resolution) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'That resolution no longer exists.',
                ]);
            }

            if (! in_array($resolution->status, self::PASSED_RESOLUTION_STATUSES, true) || $resolution->outcome !== 'carried') {
                throw ValidationException::withMessages([
                    'resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed.",
                ]);
            }

            if ($lockedBudget->isApproved()) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'This budget is already approved.',
                ]);
            }

            $alreadyUsed = static::query()
                ->where('approval_resolution_id', $resolutionId)
                ->where('id', '!=', $lockedBudget->id)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'This resolution has already been used to approve another budget.',
                ]);
            }

            // Hold the budgeted lines steady while the terms are compared.
            $lockedBudget->lineItems()->lockForUpdate()->get(['id']);

            try {
                $authority->verifyAndConsume(
                    $resolution,
                    GovernanceResolutionBinding::SUBJECT_BUDGET,
                    (int) $lockedBudget->getKey(),
                    $authority->budgetTerms($lockedBudget),
                    $actorId ?? auth()->id(),
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    'resolution_id' => $exception->getMessage(),
                ]);
            }

            $lockedBudget->update([
                'status' => 'approved',
                'approval_resolution_id' => $resolutionId,
                'approved_by_board_at' => now(),
            ]);
        }, 3);

        $this->refresh();
    }

    /**
     * Where this budget's board approval stands, from its linked resolution:
     * `state` is one of the RESOLUTION_* constants; `stale` is true when the
     * budget was edited after the resolution was prepared (so it can no
     * longer approve these figures). Read-only — nothing is used up.
     *
     * @return array{state: string, stale: bool}
     */
    public function resolutionState(): array
    {
        $resolution = $this->approval_resolution_id
            ? Resolution::query()->find($this->approval_resolution_id)
            : null;

        if (! $resolution) {
            return ['state' => self::RESOLUTION_NONE, 'stale' => false];
        }

        $binding = GovernanceResolutionBinding::query()
            ->where('resolution_id', $resolution->getKey())
            ->where('subject_type', GovernanceResolutionBinding::SUBJECT_BUDGET)
            ->where('subject_id', $this->getKey())
            ->first();

        if (! $binding) {
            return ['state' => self::RESOLUTION_UNLINKED, 'stale' => false];
        }

        if ($binding->isConsumed()) {
            return ['state' => self::RESOLUTION_USED, 'stale' => false];
        }

        $authority = app(GovernanceResolutionAuthorityService::class);
        $stale = ! hash_equals(
            (string) $binding->subject_fingerprint,
            GovernanceResolutionAuthorityService::fingerprint($authority->budgetTerms($this)),
        );

        $state = match (true) {
            $resolution->status === 'open' => self::RESOLUTION_VOTING_OPEN,
            in_array($resolution->status, self::PASSED_RESOLUTION_STATUSES, true) && $resolution->outcome === 'carried' => self::RESOLUTION_PASSED,
            in_array($resolution->status, ['draft', 'proposed'], true) => self::RESOLUTION_DRAFTED,
            default => self::RESOLUTION_NOT_PASSED,
        };

        return ['state' => $state, 'stale' => $stale];
    }

    public function getTotalAllocated(): float
    {
        return $this->lineItems->sum('budget_amount');
    }

    public function getTotalActual(): float
    {
        return $this->lineItems->sum('actual_amount');
    }

    public function getTotalVariance(): float
    {
        return $this->getTotalActual() - $this->getTotalAllocated();
    }

    public function getVariancePercentage(): float
    {
        if ($this->getTotalAllocated() == 0) {
            return 0;
        }

        return ($this->getTotalVariance() / $this->getTotalAllocated()) * 100;
    }

    public function getRemainingBudget(): float
    {
        return $this->total_budget - $this->getTotalAllocated();
    }

    public function recalculateTotals(): void
    {
        $this->total_budget = $this->lineItems()->sum('budget_amount');
        $this->save();
    }

    /**
     * Check if a budget change requires a board resolution based on threshold.
     * Changes of 5% or more of the total budget need a board decision.
     */
    public function requiresBoardApproval(float $adjustmentAmount, float $thresholdPct = self::CHANGE_BOARD_THRESHOLD_PCT): bool
    {
        if ($this->total_budget == 0) {
            return true;
        }
        $pct = abs($adjustmentAmount) / $this->total_budget * 100;

        return $pct >= $thresholdPct;
    }

    /** The dollar amount at which a budget change needs a board decision. */
    public function boardChangeThresholdAmount(): float
    {
        return round((float) $this->total_budget * self::CHANGE_BOARD_THRESHOLD_PCT / 100, 2);
    }

    /** "Changes of $75,000 or more (5% of this budget) need a board decision." */
    public function boardChangeThresholdSentence(): string
    {
        if ((float) $this->total_budget <= 0) {
            return 'Every change to this budget needs a board decision, because its total is $0.';
        }

        return sprintf(
            'Changes of %s or more (%s%% of this budget) need a board decision.',
            GovernanceLabels::money($this->boardChangeThresholdAmount()),
            rtrim(rtrim(number_format(self::CHANGE_BOARD_THRESHOLD_PCT, 1), '0'), '.'),
        );
    }

    private static function generatedContext(string $name, string $financialYear, string $total, int $lineCount): string
    {
        return sprintf(
            'The %s for the %s financial year totals %s across %d budget line%s. The board is asked to approve it as presented.',
            $name,
            $financialYear,
            $total,
            $lineCount,
            $lineCount === 1 ? '' : 's',
        );
    }

    private static function generatedMotion(string $name, string $total): string
    {
        return "That the board approves the {$name} as presented, totalling {$total}.";
    }

    private static function isGeneratedContext(string $context): bool
    {
        return preg_match('/^The .+ totals \$[\d,.]+ across \d+ budget lines?\. The board is asked to approve it as presented\.$/u', $context) === 1
            || preg_match('/^The .+ budget totalling \$[\d,.]+ across \d+ line items has been submitted for board approval\.$/u', $context) === 1;
    }
}
