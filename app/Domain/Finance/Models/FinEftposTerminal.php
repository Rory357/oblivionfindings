<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinEftposTerminal extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_eftpos_terminals';

    protected $fillable = [
        'organization_id',
        'terminal_id',
        'name',
        'location',
        'provider',
        'merchant_id',
        'bank_account_id',
        'gl_account_id',
        'is_active',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'merchant_id' => 'encrypted',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(FinEftposBatch::class, 'terminal_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_account_id');
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
}
