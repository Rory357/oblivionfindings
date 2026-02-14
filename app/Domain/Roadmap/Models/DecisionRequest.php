<?php

namespace App\Domain\Roadmap\Models;

use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DecisionRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'roadmap_decision_requests';

    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_id',
        'request_type',
        'status',
        'delegation_rule_id',
        'amount',
        'risk_level',
        'risk_delta',
        'required_role',
        'requested_by',
        'due_date',
        'rationale',
        'recommendation',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
        'governance_resolution_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'risk_delta' => 'decimal:2',
        'due_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DelegationOfAuthorityRule::class, 'delegation_rule_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function governanceResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'governance_resolution_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function resolve(string $status, int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => $status,
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_notes' => $notes,
        ]);
    }
}
