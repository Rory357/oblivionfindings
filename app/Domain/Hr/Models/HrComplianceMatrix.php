<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrComplianceMatrix extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'hr_compliance_matrix';

    protected $fillable = [
        'tenant_id',
        'requirement_id',
        'role',
        'site_type',
        'is_mandatory',
        'notes',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'requirement_id');
    }
}
