<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class ClinicalGovernanceSnapshot extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'period_start', 'period_end', 'period_type',
        'indicator_values', 'summary', 'narrative', 'captured_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'indicator_values' => 'array',
        'summary' => 'array',
    ];

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function getCriticalCount(): int
    {
        return collect($this->indicator_values)
            ->where('status', 'critical')
            ->count();
    }

    public function getWarningCount(): int
    {
        return collect($this->indicator_values)
            ->where('status', 'warning')
            ->count();
    }
}
