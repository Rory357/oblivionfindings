<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Models\Shift;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Eligibility\WorkforceQualificationEvidence;
use Illuminate\Support\Facades\DB;

/** Client-specific qualifications; House count/role coverage is a separate rule. */
final class ClientQualificationRule
{
    public function __construct(private readonly WorkforceQualificationEvidence $evidence, private readonly HrEligibilityRuleSettings $settings) {}

    public function evaluateAll(Shift $shift, User $user, bool $current = false): array
    {
        $current = $current || DB::transactionLevel() > 0;
        if (! $shift->client_id) {
            return [];
        }

        $query = StaffQualificationRequirement::query()->where('client_id', $shift->client_id)->where('is_mandatory', true)
            ->where(fn ($context) => $context->whereNull('service_context_id')->orWhere('service_context_id', $shift->service_context_id))
            ->orderBy('id');
        $requirements = $current
            ? CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query($query)->get())
            : $query->get();
        if ($requirements->isEmpty()) {
            return [];
        }

        $ids = $requirements->pluck('hr_compliance_requirement_id')->filter()->unique()->sort()->values();
        $query = HrComplianceRequirement::query()->whereIn('id', $ids)->orderBy('id');
        $mapped = $current
            ? CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query($query)->get())
            : $query->get();
        if ($mapped->count() !== $ids->count()) {
            throw new \RuntimeException('The configured qualification evidence is unavailable.');
        }
        $checks = [];
        if ($requirements->contains(fn ($row) => $row->hr_compliance_requirement_id === null)) {
            $mode = $this->settings->snapshot($current)['values']['unmapped_mandatory_qualification'];
            foreach ($requirements->whereNull('hr_compliance_requirement_id') as $requirement) {
                $checks[] = ['rule' => 'client_qualification', 'passed' => false, 'severity' => $mode === 'block' ? 'block' : 'warning',
                    'overrideable' => $mode === 'warn', 'message' => 'Mandatory Client qualification is not mapped to recorded HR evidence: '.$requirement->qualification_name.'.',
                    'requirement_id' => (int) $requirement->id, 'qualification_status' => 'unmapped',
                    'requires_assignment_acknowledgement' => $mode === 'warn'];
            }
        }
        $result = $this->evidence->check($user, $mapped, $shift, $current);
        foreach ($result['failures'] as $failure) {
            $checks[] = ['rule' => 'client_qualification', 'passed' => false, 'severity' => 'block', 'overrideable' => false,
                'message' => $failure['reason'], 'hr_compliance_requirement_id' => $failure['requirement_id'],
                'qualification_status' => $failure['status']];
        }

        return $checks;
    }
}
