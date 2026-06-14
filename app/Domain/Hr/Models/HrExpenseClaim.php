<?php

namespace App\Domain\Hr\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class HrExpenseClaim extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrExpenseClaimFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'claim_number',
        'title',
        'status',
        'total_amount',
        'currency',
        'submitted_at',
        'approved_by',
        'approved_at',
        'paid_at',
        'rejection_reason',
        'notes',
        'journal_id',
        'gl_posted_at',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gl_posted_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrExpenseItem::class, 'expense_claim_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
