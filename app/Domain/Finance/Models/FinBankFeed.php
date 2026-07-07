<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinBankFeed extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_bank_feeds';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'provider',
        'consent_token',
        'consent_expires_at',
        'last_sync_at',
        'last_sync_status',
        'last_error',
        'sync_from_date',
        'is_active',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'consent_token' => 'encrypted',
        'consent_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'sync_from_date' => 'date',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FinBankFeedLog::class, 'bank_feed_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
