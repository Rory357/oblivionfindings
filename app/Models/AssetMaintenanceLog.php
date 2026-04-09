<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMaintenanceLog extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'performed_by_user_id',
        'performed_at',
        'type',
        'vendor',
        'cost',
        'notes',
        'next_due_at',
        'journal_id',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'next_due_at' => 'date',
        'cost' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
