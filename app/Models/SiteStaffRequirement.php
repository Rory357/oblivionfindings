<?php

namespace App\Models;

use App\Domain\Hr\Models\HrComplianceRequirement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SiteStaffRequirement extends Model
{
    use HasFactory;

    protected $table = 'site_staff_requirements';

    protected $fillable = [
        'site_id',
        'requirement_name',
        'category',
        'description',
        'certification_required',
        'expiry_period_months',
        'is_active',
        'hr_compliance_requirement_id',
        'applicability_mode',
        'minimum_qualified_staff',
    ];

    protected $casts = [
        'certification_required' => 'boolean',
        'is_active' => 'boolean',
        'hr_compliance_requirement_id' => 'integer',
        'minimum_qualified_staff' => 'integer',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function hrComplianceRequirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class);
    }

    public static function newRequirementDefaults(string $approach): array
    {
        return [
            'applicability_mode' => in_array($approach, ['all_workers', 'minimum_staff'], true) ? $approach : null,
            'minimum_qualified_staff' => null,
        ];
    }

    /** Omitted edit fields preserve stored choices, including unresolved legacy rows. */
    public static function applicabilityValues(array $data, string $approach, ?self $existing = null, string $prefix = ''): array
    {
        if ($existing && ! array_key_exists('applicability_mode', $data) && ! array_key_exists('minimum_qualified_staff', $data)) {
            return [];
        }
        $mode = array_key_exists('applicability_mode', $data) ? $data['applicability_mode']
            : ($existing ? $existing->applicability_mode : self::newRequirementDefaults($approach)['applicability_mode']);
        if (! in_array($mode, ['all_workers', 'minimum_staff'], true)) {
            throw ValidationException::withMessages([
                $prefix.'applicability_mode' => 'Choose whether every worker needs this requirement or a minimum number of qualified staff must cover the duty.',
            ]);
        }
        $minimum = array_key_exists('minimum_qualified_staff', $data)
            ? $data['minimum_qualified_staff'] : ($existing?->minimum_qualified_staff);
        if ($mode === 'minimum_staff') {
            if (filter_var($minimum, FILTER_VALIDATE_INT) === false || (int) $minimum < 1 || (int) $minimum > 4294967295) {
                throw ValidationException::withMessages([
                    $prefix.'minimum_qualified_staff' => 'Enter a positive whole number of qualified staff.',
                ]);
            }

            return ['applicability_mode' => $mode, 'minimum_qualified_staff' => (int) $minimum];
        }
        if (array_key_exists('minimum_qualified_staff', $data) && $data['minimum_qualified_staff'] !== null) {
            throw ValidationException::withMessages([
                $prefix.'minimum_qualified_staff' => 'A minimum qualified count applies only to the minimum staff option.',
            ]);
        }

        return ['applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null];
    }

    public function hasConfiguredApplicability(): bool
    {
        return $this->applicability_mode === 'all_workers'
            || ($this->applicability_mode === 'minimum_staff' && $this->minimum_qualified_staff !== null
                && $this->minimum_qualified_staff > 0);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeMandatory($query)
    {
        return $query->where('category', 'mandatory');
    }
}
