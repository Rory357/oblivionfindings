<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetInspection extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'inspected_by_user_id',
        'inspected_at',
        'result',
        'notes',
        'next_due_at',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
        'next_due_at' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }
}
