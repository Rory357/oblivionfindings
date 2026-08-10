<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLogEntry extends Model
{
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_change_log_entries';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'change_type',
        'field_deltas',
        'reason',
        'changed_by',
        'correlation_id',
    ];

    protected $casts = [
        'field_deltas' => 'array',
    ];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
