<?php

namespace App\Models;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffQualificationRequirement extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $table = 'staff_qualification_requirements';

    protected $fillable = [
        'client_id',
        'service_context_id',
        'hr_compliance_requirement_id',
        'qualification_name',
        'qualification_type',
        'is_mandatory',
        'description',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'hr_compliance_requirement_id' => 'integer',
    ];

    public function hrComplianceRequirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }
}
