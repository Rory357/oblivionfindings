<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrCompensationReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCompensationReview extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrCompensationReviewFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'review_cycle',
        'effective_date',
        'status',
        'budget_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'budget_amount' => 'encrypted',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function items(): HasMany
    {
        return $this->hasMany(HrCompensationReviewItem::class, 'compensation_review_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

}
