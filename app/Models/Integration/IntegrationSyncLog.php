<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $table = 'integration_sync_logs';

    protected $fillable = [
        'tenant_id',
        'provider',
        'site_id',
        'action',
        'status',
        'items_processed',
        'items_created',
        'items_updated',
        'items_errored',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'items_processed' => 'integer',
        'items_created' => 'integer',
        'items_updated' => 'integer',
        'items_errored' => 'integer',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    public function markCompleted(string $status, ?string $error = null): void
    {
        $this->completed_at = now();
        $this->status = $status;
        $this->error_message = $error;
        $this->save();
    }
}
