<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class RiskAppetiteSetting extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'category', 'threshold', 'rationale',
        'approved_by', 'approved_at', 'approval_resolution_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public static function getThreshold(string $category): ?int
    {
        $setting = static::where('category', $category)->first();
        return $setting?->threshold;
    }
}
