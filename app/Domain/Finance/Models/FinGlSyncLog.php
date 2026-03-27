<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinGlSyncLog extends Model
{
    use HasFactory;

    protected $table = 'fin_gl_sync_logs';

    protected $fillable = [
        'integration_id',
        'direction',
        'entity_type',
        'entity_count',
        'success_count',
        'error_count',
        'errors',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected $casts = [
        'entity_count' => 'integer',
        'success_count' => 'integer',
        'error_count' => 'integer',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function integration(): BelongsTo
    {
        return $this->belongsTo(FinAccountingIntegration::class, 'integration_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function hasErrors(): bool
    {
        return $this->error_count > 0;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
