<?php

namespace App\Domain\Roadmap\Models;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Initiative extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'roadmap_initiatives';

    protected $fillable = [
        'tenant_id',
        'code',
        'title',
        'summary',
        'category_id',
        'stream',
        'status',
        'priority_band',
        'priority_score',
        'score_breakdown',
        'score_profile',
        'impact_profile',
        'cost_estimate_low',
        'cost_estimate_high',
        'benefit_summary',
        'risk_summary',
        'dependency_summary',
        'owner_user_id',
        'sponsor_user_id',
        'next_decision',
        'decision_due_at',
        'target_fiscal_year',
        'target_quarter',
        'start_date',
        'target_date',
        'completed_at',
        'budget_mode',
        'manual_priority_override',
        'manual_priority_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'score_breakdown' => 'array',
        'impact_profile' => 'array',
        'cost_estimate_low' => 'decimal:2',
        'cost_estimate_high' => 'decimal:2',
        'priority_score' => 'decimal:2',
        'manual_priority_override' => 'boolean',
        'decision_due_at' => 'date',
        'start_date' => 'date',
        'target_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $initiative): void {
            if (empty($initiative->code)) {
                $initiative->code = self::generateCode($initiative->tenant_id);
            }

            if (empty($initiative->status)) {
                $initiative->status = self::STATUS_DRAFT;
            }
        });
    }

    public static function generateCode(?int $tenantId): string
    {
        $prefix = now()->format('Y').'-RI-';
        $count = self::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereYear('created_at', now()->year)
            ->count() + 1;

        return $prefix.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InitiativeCategory::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function siteScope(): HasMany
    {
        return $this->hasMany(InitiativeSiteScope::class, 'initiative_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(InitiativeBudget::class, 'initiative_id');
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(InitiativeBenefit::class, 'initiative_id');
    }

    public function riskLinks(): HasMany
    {
        return $this->hasMany(InitiativeRiskLink::class, 'initiative_id');
    }

    public function qualityLinks(): HasMany
    {
        return $this->hasMany(InitiativeQualityLink::class, 'initiative_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(InitiativeDependency::class, 'initiative_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(InitiativeMilestone::class, 'initiative_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(InitiativeTask::class, 'initiative_id');
    }

    public function assurancePlans(): HasMany
    {
        return $this->hasMany(AssuranceEvidencePlan::class, 'initiative_id');
    }

    public function quarterlyPlanItems(): HasMany
    {
        return $this->hasMany(QuarterlyRoadmapPlanItem::class, 'initiative_id');
    }

    public function decisionRequests(): MorphMany
    {
        return $this->morphMany(DecisionRequest::class, 'source', 'source_type', 'source_id');
    }

    public function contractRefs(): HasMany
    {
        return $this->hasMany(VendorContractRef::class, 'initiative_id');
    }

    public function relatedSites()
    {
        return Site::query()
            ->whereIn('id', function ($query) {
                $query->select('site_id')
                    ->from('roadmap_initiative_site_scope_sites')
                    ->whereIn('initiative_site_scope_id', function ($sub) {
                        $sub->select('id')
                            ->from('roadmap_initiative_site_scopes')
                            ->where('initiative_id', $this->id);
                    });
            });
    }

    public function linkedRisks()
    {
        return RiskRegisterEntry::query()->whereIn('id', $this->riskLinks()->pluck('risk_register_entry_id'));
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function scopeForQuarter($query, int $fiscalYear, int $quarter)
    {
        return $query->where('target_fiscal_year', $fiscalYear)
            ->where('target_quarter', $quarter);
    }

    public function canTransitionTo(string $status): bool
    {
        $map = [
            self::STATUS_DRAFT => [self::STATUS_PROPOSED, self::STATUS_CANCELLED],
            self::STATUS_PROPOSED => [self::STATUS_APPROVED, self::STATUS_DEFERRED, self::STATUS_CANCELLED],
            self::STATUS_APPROVED => [self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED, self::STATUS_ON_HOLD, self::STATUS_DEFERRED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_BLOCKED, self::STATUS_ON_HOLD],
            self::STATUS_BLOCKED => [self::STATUS_IN_PROGRESS, self::STATUS_DEFERRED, self::STATUS_CANCELLED],
            self::STATUS_ON_HOLD => [self::STATUS_IN_PROGRESS, self::STATUS_DEFERRED, self::STATUS_CANCELLED],
            self::STATUS_DEFERRED => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ];

        return in_array($status, $map[$this->status] ?? [], true);
    }

    public function transitionTo(string $status): bool
    {
        if (! $this->canTransitionTo($status)) {
            return false;
        }

        $updates = ['status' => $status];

        if ($status === self::STATUS_COMPLETED) {
            $updates['completed_at'] = now();
        }

        $this->update($updates);

        return true;
    }

    public function totalBudgetUpper(): float
    {
        $budgetHigh = (float) ($this->budgets()->max('forecast_total') ?? 0);
        $directHigh = (float) ($this->cost_estimate_high ?? 0);

        return max($budgetHigh, $directHigh);
    }
}
