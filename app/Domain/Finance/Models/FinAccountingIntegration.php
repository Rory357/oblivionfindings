<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinAccountingIntegration extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_accounting_integrations';

    protected $fillable = [
        'organization_id',
        'provider',
        'tenant_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_sync_at',
        'last_sync_status',
        'last_error',
        'sync_direction',
        'account_mapping',
        'tax_mapping',
        'settings',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'account_mapping' => 'array',
        'tax_mapping' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function syncLogs(): HasMany
    {
        return $this->hasMany(FinGlSyncLog::class, 'integration_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return true;
        }

        return $this->token_expires_at->isPast();
    }

    public function hasValidToken(): bool
    {
        return $this->access_token && ! $this->isTokenExpired();
    }

    public function getAccountMappingForLocal(int $localAccountId): ?string
    {
        $mapping = $this->account_mapping ?? [];

        return $mapping[(string) $localAccountId] ?? null;
    }

    public function getAccountMappingForExternal(string $externalId): ?int
    {
        $mapping = $this->account_mapping ?? [];
        $flipped = array_flip($mapping);

        return isset($flipped[$externalId]) ? (int) $flipped[$externalId] : null;
    }
}
