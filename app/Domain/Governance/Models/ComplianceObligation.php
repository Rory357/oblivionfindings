<?php

namespace App\Domain\Governance\Models;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceObligation extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

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
        'completed_at',
        'completed_by',
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
        if ($this->status === 'complete') {
            return;
        }

        $daysUntilDue = now()->diffInDays($this->due_date, false);

        if ($daysUntilDue < 0) {
            $this->status = 'overdue';
        } elseif ($daysUntilDue <= 7) {
            $this->status = 'due_soon';
        } else {
            $this->status = 'not_due';
        }
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

    public function scopeByFramework($query, string $framework)
    {
        return $query->where('framework', $framework);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopeDueSoon($query, int $days = 30)
    {
        return $query->where('due_date', '<=', now()->addDays($days))
            ->where('status', '!=', 'complete');
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
        return $this->status === 'overdue';
    }

    public function isDueSoon(int $days = 7): bool
    {
        return now()->diffInDays($this->due_date, false) <= $days && ! $this->isOverdue();
    }

    public function markComplete(int $userId): void
    {
        $this->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $userId,
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
     * Canonical framework key => label map. Single source for both the obligation
     * label accessor and any framework picker (e.g. the /compliance "Log obligation"
     * wizard) so labels never drift.
     *
     * @return array<string, string>
     */
    public static function frameworkOptions(): array
    {
        return [
            'charities' => 'Charities Services',
            'nga_paerewa' => 'Ngā Paerewa NZS 8134:2021',
            'hdsa_safety' => 'Health and Disability Services (Safety) Act',
            'privacy_act' => 'Privacy Act 2020',
            'hip_code' => 'Health Information Privacy Code',
            'hswa' => 'Health and Safety at Work Act 2015',
            'employment' => 'Employment Relations Act',
            'funding_moh' => 'MoH/Health NZ Funding',
            'funding_msd' => 'MSD Funding',
            'funding_acc' => 'ACC Funding',
        ];
    }
}
