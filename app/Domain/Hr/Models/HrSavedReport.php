<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSavedReport extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'report_type',
        'fields',
        'filters',
        'group_by',
        'sort_by',
        'sort_direction',
        'last_run_at',
        'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'filters' => 'array',
        'last_run_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOfType(Builder $query, string $reportType): Builder
    {
        return $query->where('report_type', $reportType);
    }
}
