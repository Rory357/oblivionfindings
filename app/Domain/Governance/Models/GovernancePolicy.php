<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GovernancePolicy extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\Governance\GovernancePolicyFactory::new();
    }

    protected $fillable = [
        'policy_code', 'title', 'category', 'purpose', 'content',
        'version_number', 'status', 'approval_resolution_id',
        'owner_id', 'approved_by', 'approved_at', 'effective_from',
        'review_due', 'next_review_date', 'supersedes_policy_id', 'created_by',
    ];

    protected $casts = [
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

    public function createNewVersion(int $userId): self
    {
        $new = $this->replicate();
        $new->version_number = $this->version_number + 1;
        $new->status = 'draft';
        $new->supersedes_policy_id = $this->id;
        $new->approved_by = null;
        $new->approved_at = null;
        $new->created_by = $userId;
        $new->save();

        $this->update(['status' => 'superseded']);

        return $new;
    }
}
