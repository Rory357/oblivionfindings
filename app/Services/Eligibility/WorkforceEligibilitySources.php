<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\HsTrainingRequirement;
use App\Models\ItTicketBooking;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\StaffBackgroundCheck;
use App\Models\StaffCredential;
use App\Models\StaffTimeOff;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Only structural eligibility inputs are fingerprinted; never source narratives or attachments. */
final class WorkforceEligibilitySources
{
    /** @return array<class-string<Model>, array{fields: list<string>, scope: string}> */
    public static function definitions(): array
    {
        return [
            User::class => ['scope' => 'account', 'fields' => ['approved_at', 'role']],
            Role::class => ['scope' => 'role', 'fields' => ['name']],
            HrEmployeeProfile::class => ['scope' => 'user', 'fields' => ['user_id', 'is_active', 'start_date', 'end_date', 'primary_site_id', 'secondary_site_ids', 'position_role', 'can_drive_clients', 'deleted_at']],
            StaffCredential::class => ['scope' => 'user', 'fields' => ['user_id', 'type', 'issued_at', 'expires_at']],
            StaffTrainingRecord::class => ['scope' => 'user', 'fields' => ['user_id', 'training_course_id', 'hr_course_id', 'status', 'completed_at', 'expires_at', 'assessment_passed', 'exempted_at', 'deleted_at']],
            StaffBackgroundCheck::class => ['scope' => 'user', 'fields' => ['user_id', 'check_type', 'status', 'issue_date', 'check_date', 'expires_at', 'risk_assessed', 'risk_decision', 'deleted_at']],
            HrCourseEnrollment::class => ['scope' => 'user', 'fields' => ['user_id', 'course_id', 'status', 'completed_at', 'score']],
            HrStaffComplianceStatus::class => ['scope' => 'user', 'fields' => ['user_id', 'requirement_id', 'status', 'evidence_type', 'evidence_id', 'valid_from', 'expires_at', 'exempted_at', 'exempted_until', 'exemption_reason']],
            HrPolicyAttestation::class => ['scope' => 'user', 'fields' => ['user_id', 'policy_id', 'policy_version_id', 'attested_at']],
            HrDriverEligibility::class => ['scope' => 'user', 'fields' => ['user_id', 'licence_class', 'licence_endorsements', 'licence_expires_at', 'can_drive_clients', 'can_drive_clients_approved_by', 'can_drive_clients_approved_at', 'status']],
            MedicationCompetencyAssessment::class => ['scope' => 'user', 'fields' => ['user_id', 'assessor_id', 'status', 'assessment_date', 'expiry_date', 'assessor_declared_at', 'staff_acknowledged_at', 'can_administer_unsupervised', 'restricted']],
            MedicationCompetencyExemption::class => ['scope' => 'user', 'fields' => ['user_id', 'site_id', 'scope', 'approved_by', 'approved_at', 'starts_at', 'expires_at', 'revoked_at']],
            HrOnboardingChecklist::class => ['scope' => 'profile', 'fields' => ['employee_profile_id', 'status']],
            StaffAvailability::class => ['scope' => 'user', 'fields' => ['user_id', 'day_of_week', 'starts_at', 'ends_at', 'ends_next_day']],
            StaffTimeOff::class => ['scope' => 'user', 'fields' => ['user_id', 'starts_at', 'ends_at', 'type', 'period']],
            HrLeaveRequest::class => ['scope' => 'user', 'fields' => ['user_id', 'starts_at', 'ends_at', 'status', 'period']],
            Site::class => ['scope' => 'site', 'fields' => ['is_active', 'archived', 'archived_at', 'type', 'deleted_at']],
            Client::class => ['scope' => 'client', 'fields' => ['site_id', 'service_context_id', 'is_active', 'status', 'deleted_at']],
            Shift::class => ['scope' => 'shift', 'fields' => ['user_id', 'client_id', 'site_id', 'service_context_id', 'starts_at', 'ends_at', 'status', 'coverage_roles', 'shift_type', 'is_sleepover', 'is_on_call', 'is_lone_worker', 'expected_break_minutes', 'required_licence_class', 'required_licence_endorsements', 'published_at']],
            HrComplianceRequirement::class => ['scope' => 'global', 'fields' => ['code', 'check_type', 'reference_id', 'validity_months', 'renewal_reminder_days', 'hard_stop', 'is_active']],
            HrComplianceMatrix::class => ['scope' => 'matrix', 'fields' => ['requirement_id', 'role', 'site_type', 'is_mandatory']],
            HrCourse::class => ['scope' => 'global', 'fields' => ['compliance_requirement_id', 'is_active', 'requires_renewal', 'validity_period_months', 'requires_assessment', 'pass_mark_percentage']],
            HrPolicy::class => ['scope' => 'global', 'fields' => ['is_active', 'requires_attestation', 'attestation_frequency_months']],
            HrPolicyVersion::class => ['scope' => 'global', 'fields' => ['policy_id', 'is_current', 'version_number', 'effective_from']],
            HsTrainingRequirement::class => ['scope' => 'hs_requirement', 'fields' => ['hr_compliance_requirement_id', 'scope_type', 'scope_roles', 'scope_site_ids', 'scope_client_ids', 'enforcement_mode', 'validity_months', 'grace_period_days', 'is_active', 'deleted_at']],
            ItTicketBooking::class => ['scope' => 'technician', 'fields' => ['technician_user_id', 'starts_at', 'ends_at', 'status']],
            ServiceContext::class => ['scope' => 'context', 'fields' => ['site_id', 'type', 'is_active']],
        ];
    }

    /** @return array{fingerprint: string, scope: array}|null */
    public function describe(Model $model, bool $deleted = false): ?array
    {
        $definition = self::definitions()[$model::class] ?? null;
        if (! $definition) {
            return null;
        }
        $attributes = array_intersect_key($model->getAttributes(), array_flip($definition['fields']));
        $casts = $model->getCasts();
        foreach ($attributes as $field => $value) {
            $cast = explode(':', (string) ($casts[$field] ?? ''), 2)[0];
            if (in_array($cast, ['date', 'immutable_date'], true) && is_string($value)
                && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?)?$/D', $value, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                // Eloquent's saved DATE attributes include a midnight clock;
                // SQL DATE reloads omit it. Hash their unchanged logical date,
                // without converting its timezone or collapsing DATETIME input.
                $attributes[$field] = substr($value, 0, 10);
            }
        }
        if (array_key_exists('exemption_reason', $attributes)) {
            // Existing exemption readers use presence only. Neither hash nor retain the private text.
            $attributes['has_exemption_reason'] = filled($attributes['exemption_reason']);
            unset($attributes['exemption_reason']);
        }
        $attributes['deleted'] = $deleted || (method_exists($model, 'trashed') && $model->trashed());
        $scope = ['user_ids' => [], 'site_ids' => [], 'client_ids' => [], 'shift_ids' => [], 'all_assigned' => false];
        $values = fn (string $field): array => array_values(array_filter([$model->getRawOriginal($field), $model->getAttribute($field)]));

        switch ($definition['scope']) {
            case 'account': $scope['user_ids'] = [(int) $model->id];
                break;
            case 'role':
                $scope['all_assigned'] = $deleted; // Cascaded pivot rows no longer expose former members.
                $scope['user_ids'] = $model->users()->pluck('users.id')->all();
                break;
            case 'user': $scope['user_ids'] = $values('user_id');
                break;
            case 'technician': $scope['user_ids'] = $values('technician_user_id');
                break;
            case 'profile':
                $scope['user_ids'] = HrEmployeeProfile::withTrashed()->whereIn('id', $values('employee_profile_id'))->pluck('user_id')->all();
                break;
            case 'site':
                $scope['site_ids'] = [(int) $model->id];
                // Site type affects matrix applicability at every approved primary/secondary membership.
                $scope['user_ids'] = HrEmployeeProfile::withTrashed()->where(function ($query) use ($model): void {
                    $query->where('primary_site_id', $model->id)->orWhereJsonContains('secondary_site_ids', (int) $model->id);
                })->pluck('user_id')->all();
                break;
            case 'client': $scope['client_ids'] = [(int) $model->id];
                break;
            case 'context': $scope['shift_ids'] = Shift::query()->where('service_context_id', $model->id)->pluck('id')->all();
                break;
            case 'shift':
                // A moved/cancelled duty also changes the old and new worker's conflict/fatigue evidence.
                $scope['shift_ids'] = [(int) $model->id];
                $scope['user_ids'] = $values('user_id');
                break;
            case 'matrix':
                $roles = $values('role');
                $roles = collect($roles)->map(fn ($role): string => mb_strtolower(trim((string) $role)))->unique()->values()->all();
                $scope['user_ids'] = User::query()->whereHas('roles', fn ($query) => $query->whereIn(DB::raw('LOWER(TRIM(roles.name))'), $roles))->pluck('id')->all();
                break;
            case 'hs_requirement':
                $states = [$model->getAttributes()];
                $original = $model->getRawOriginal();
                // A newly created record has no former global scope to retain.
                if (array_key_exists('scope_type', $original)) {
                    array_unshift($states, $original);
                }
                foreach ($states as $state) {
                    $type = $state['scope_type'] ?? 'global';
                    if ($type === 'site' || $type === 'client') {
                        $field = $type === 'site' ? 'scope_site_ids' : 'scope_client_ids';
                        $ids = is_array($state[$field] ?? null) ? $state[$field] : json_decode($state[$field] ?? '[]', true);
                        $target = $type === 'site' ? 'site_ids' : 'client_ids';
                        $scope[$target] = [...$scope[$target], ...($ids ?? [])];
                    } elseif ($type === 'role') {
                        $roles = is_array($state['scope_roles'] ?? null) ? $state['scope_roles'] : json_decode($state['scope_roles'] ?? '[]', true);
                        $scope['user_ids'] = [...$scope['user_ids'], ...User::query()->whereIn('role', $roles ?? [])->pluck('id')->all()];
                    } else {
                        $scope['all_assigned'] = true;
                    }
                }
                break;
            default: $scope['all_assigned'] = true;
        }

        return ['fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)), 'scope' => $scope];
    }
}
