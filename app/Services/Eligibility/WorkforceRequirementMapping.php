<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Models\HrComplianceRequirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Explicit canonical identities only; free-text labels never establish a mapping. */
final class WorkforceRequirementMapping
{
    public const CHECK_TYPES = ['training_course', 'credential', 'background_check', 'policy_attestation', 'driver_licence', 'manual'];

    public function validationRules(): array
    {
        return ['nullable', 'integer', Rule::exists('hr_compliance_requirements', 'id')
            ->where(fn ($query) => $query->where('is_active', true)->whereIn('check_type', self::CHECK_TYPES))];
    }

    public function catalog(): array
    {
        return HrComplianceRequirement::query()->where('is_active', true)->whereIn('check_type', self::CHECK_TYPES)
            ->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'check_type'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name, 'check_type' => $row->check_type])->all();
    }

    public function present(Model $row): array
    {
        $id = $row->hr_compliance_requirement_id;
        $requirement = $row->hrComplianceRequirement;
        $status = match (true) {
            $id === null => 'unmapped',
            ! $requirement => 'missing',
            ! $requirement->is_active => 'inactive',
            ! in_array($requirement->check_type, self::CHECK_TYPES, true) => 'unsupported',
            default => 'configured',
        };

        return ['status' => $status, 'requirement_id' => $id === null ? null : (int) $id,
            'label' => $requirement?->name, 'code' => $requirement?->code, 'check_type' => $requirement?->check_type];
    }
}
