<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAttestation extends Model
{
    protected $fillable = [
        'governance_policy_id', 'user_id', 'acknowledged', 'acknowledged_at', 'notes',
    ];

    protected $casts = [
        'acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(GovernancePolicy::class, 'governance_policy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledge(): void
    {
        $this->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);
    }
}
