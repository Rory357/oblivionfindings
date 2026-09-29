<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's personal witness PIN (hash only). Managed through
 * App\Services\Medication\WitnessPinService — never read or set directly.
 *
 * @property int $user_id
 * @property string $pin_hash
 * @property Carbon|null $set_at
 * @property int $failed_attempts
 * @property Carbon|null $last_failed_at
 * @property Carbon|null $locked_until
 * @property bool $must_change
 * @property int|null $reset_by
 * @property Carbon|null $reset_at
 */
class UserWitnessPin extends Model
{
    protected $fillable = [
        'user_id',
        'pin_hash',
        'set_at',
        'failed_attempts',
        'last_failed_at',
        'locked_until',
        'must_change',
        'reset_by',
        'reset_at',
    ];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'set_at' => 'datetime',
            'failed_attempts' => 'integer',
            'last_failed_at' => 'datetime',
            'locked_until' => 'datetime',
            'must_change' => 'boolean',
            'reset_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by');
    }
}
