<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrTimeEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'entry_date',
        'clock_in',
        'clock_out',
        'break_minutes',
        'total_hours',
        'entry_type',
        'status',
        'notes',
        'project_code',
        'cost_centre',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'break_minutes' => 'integer',
        'total_hours' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('clock_out');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }
}
