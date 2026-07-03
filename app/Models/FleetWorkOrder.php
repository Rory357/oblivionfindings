<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetWorkOrder extends Model
{
    use AuditableChanges, HasFactory;
    use Concerns\HasReferenceNumber;

    public const REFERENCE_PREFIX = 'WO';

    protected $fillable = [
        'reference_number',
        'tenant_id',
        'asset_id',
        'reported_by_user_id',
        'assigned_to_user_id',
        'title',
        'description',
        'priority',
        'category',
        'status',
        'due_at',
        'started_at',
        'completed_at',
        'estimated_cost',
        'actual_cost',
        'completion_notes',
        'notes',
        'journal_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
