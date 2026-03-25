<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalGovernanceIndicator extends Model
{
    protected $fillable = [
        'indicator_code', 'category', 'name', 'definition', 'data_source',
        'unit', 'target_value', 'warning_threshold', 'critical_threshold',
        'frequency', 'is_automated', 'is_active',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'warning_threshold' => 'decimal:2',
        'critical_threshold' => 'decimal:2',
        'is_automated' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const CATEGORIES = [
        'falls' => 'Falls',
        'medication_errors' => 'Medication Errors',
        'pressure_injuries' => 'Pressure Injuries',
        'restraint' => 'Restraint Use',
        'infections' => 'Infections',
        'safeguarding' => 'Safeguarding',
        'complaints' => 'Complaints',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function getStatus(float $value): string
    {
        if ($this->critical_threshold !== null && $value >= $this->critical_threshold) {
            return 'critical';
        }
        if ($this->warning_threshold !== null && $value >= $this->warning_threshold) {
            return 'warning';
        }
        return 'normal';
    }
}
