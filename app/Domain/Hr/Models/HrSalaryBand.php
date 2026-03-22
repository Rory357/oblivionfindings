<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSalaryBand extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'position_role',
        'band_name',
        'min_salary',
        'mid_salary',
        'max_salary',
        'min_hourly',
        'max_hourly',
        'currency',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'min_salary' => 'encrypted',
        'mid_salary' => 'encrypted',
        'max_salary' => 'encrypted',
        'min_hourly' => 'encrypted',
        'max_hourly' => 'encrypted',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('effective_from', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            });
    }
}
