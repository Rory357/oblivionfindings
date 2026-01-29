<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LegalHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_reference',
        'hold_type',
        'reason',
        'holdable_type',
        'holdable_id',
        'related_records',
        'status',
        'imposed_at',
        'imposed_by_user_id',
        'released_at',
        'released_by_user_id',
        'release_reason',
        'review_date',
        'legal_authority',
    ];

    protected $casts = [
        'related_records' => 'array',
        'imposed_at' => 'datetime',
        'released_at' => 'datetime',
        'review_date' => 'date',
    ];

    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }

    public function imposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imposed_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
