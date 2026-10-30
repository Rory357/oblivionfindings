<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'assignee_type',
        'assignee_id',
        'purpose',
        'assigned_at',
        'receipt_confirmed_at',
        'receipt_confirmed_by_user_id',
        'receipt_note',
        'released_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'receipt_confirmed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
