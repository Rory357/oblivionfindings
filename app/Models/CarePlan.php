<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarePlan extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_id',
        'title',
        'status',
        'plan_type',
        'starts_at',
        'ends_at',
        'next_review_at',
        'reviewed_at',
        'reviewed_by',
        'created_by',
        'content',
        'version',
        'parent_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'next_review_at' => 'date',
        'reviewed_at' => 'datetime',
        'content' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goals()
    {
        return $this->hasMany(CarePlanGoal::class);
    }

    public function parent()
    {
        return $this->belongsTo(CarePlan::class, 'parent_id');
    }

    public function versions()
    {
        return $this->hasMany(CarePlan::class, 'parent_id');
    }

    /**
     * Recorded agreements to this plan version (client, whānau, EOR/guardian, etc.).
     * Sign-offs are version-specific and are intentionally NOT copied when a review
     * clones a new version — the reviewed plan must be agreed afresh.
     */
    public function signOffs()
    {
        return $this->hasMany(CarePlanSignOff::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeReviewDue($query)
    {
        return $query->where('next_review_at', '<=', now());
    }

    /**
     * Only the latest draft, active, or in-review version may be changed.
     * Once a newer working version exists, the published source becomes
     * historical even before the review is completed and archived.
     */
    public function isMutableVersion(): bool
    {
        if (! in_array($this->status, ['draft', 'active', 'review'], true)) {
            return false;
        }

        $rootId = $this->parent_id ?? $this->getKey();
        $version = (int) ($this->version ?? 1);

        return ! self::query()
            ->where('client_id', $this->client_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', ['active', 'review'])
            ->where(function ($query) use ($rootId) {
                $query->whereKey($rootId)->orWhere('parent_id', $rootId);
            })
            ->where(function ($query) use ($version) {
                $query->where('version', '>', $version)
                    ->orWhere(function ($sameVersion) use ($version) {
                        $sameVersion->where('version', $version)
                            ->where('id', '>', $this->getKey());
                    });
            })
            ->exists();
    }

    public function allowsGenericTransitionTo(?string $status): bool
    {
        if ($status === null || $status === $this->status) {
            return true;
        }

        return $this->status === 'draft' && $status === 'active';
    }
}
