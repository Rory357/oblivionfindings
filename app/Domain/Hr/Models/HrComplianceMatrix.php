<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrComplianceMatrix extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_compliance_matrix';

    protected $fillable = [
        'requirement_id',
        'role',
        'site_type',
        'is_mandatory',
        'notes',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    /** Store one explicit value so the global matrix identity is race-safe. */
    public function setSiteTypeAttribute(mixed $value): void
    {
        $siteType = is_string($value) ? trim($value) : '';
        $this->attributes['site_type'] = $siteType === '' ? 'all' : $siteType;
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'requirement_id');
    }
}
