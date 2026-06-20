<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafetyDataSheet extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    /** Days before the review date at which a current SDS is flagged "expiring". */
    public const REVIEW_HORIZON_DAYS = 30;

    protected $fillable = [
        'hazardous_substance_id',
        'version',
        'issue_date',
        'review_date',
        'supplier_name',
        'supplier_contact',
        'document_path',
        'status',
        'uploaded_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'review_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hazardousSubstance(): BelongsTo
    {
        return $this->belongsTo(HazardousSubstance::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeCurrent($query)
    {
        return $query->where('status', 'current');
    }

    public function scopeForSubstance($query, int $substanceId)
    {
        return $query->where('hazardous_substance_id', $substanceId);
    }

    /**
     * Current SDS sheets due for review within `$days` (includes already overdue).
     */
    public function scopeExpiringWithin($query, int $days = self::REVIEW_HORIZON_DAYS)
    {
        return $query->where('status', 'current')
            ->whereNotNull('review_date')
            ->whereDate('review_date', '<=', now()->addDays($days));
    }

    /* ------------------------------------------------------------------ */
    /*  Computed lifecycle state                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Derived review state of this sheet:
     *  superseded (not the current sheet) · current · expiring (review ≤30d) · expired (review past).
     * A current sheet with no review date is treated as `current`.
     */
    public function getStateAttribute(): string
    {
        if ($this->status !== 'current') {
            return 'superseded';
        }

        if ($this->review_date === null) {
            return 'current';
        }

        $today = now()->startOfDay();

        if ($this->review_date->lt($today)) {
            return 'expired';
        }

        if ($this->review_date->lte($today->copy()->addDays(self::REVIEW_HORIZON_DAYS))) {
            return 'expiring';
        }

        return 'current';
    }
}
