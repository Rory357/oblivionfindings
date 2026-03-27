<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinMatchRule extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_match_rules';

    protected $fillable = [
        'organization_id',
        'name',
        'priority',
        'rule_type',
        'conditions',
        'auto_confirm_threshold',
        'is_active',
        'match_count',
        'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'auto_confirm_threshold' => 'decimal:2',
        'is_active' => 'boolean',
        'match_count' => 'integer',
        'priority' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderByDesc('priority');
    }
}
