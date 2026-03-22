<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCheckIn extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'hr_check_ins';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'check_in_date',
        'mood',
        'energy_level',
        'workload_rating',
        'notes',
        'is_anonymous',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'energy_level' => 'integer',
        'workload_rating' => 'integer',
        'is_anonymous' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public function scopeForDate($query, string $date)
    {
        return $query->where('check_in_date', $date);
    }
}
