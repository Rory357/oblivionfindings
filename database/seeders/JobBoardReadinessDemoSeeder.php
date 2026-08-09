<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\StaffBackgroundCheck;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class JobBoardReadinessDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Keep job-board fatigue/claim evidence isolated from the frontline
        // lifecycle worker whose roster is intentionally dense.
        $worker = User::query()->where('email', 'sw8@demo.test')->first();
        $currentStaff = User::query()->where('email', 'sw2@demo.test')->first();
        $admin = User::query()->where('role', 'admin')->first();
        $serviceContext = ServiceContext::query()->first();

        if (! $worker || ! $currentStaff || ! $admin || ! $serviceContext) {
            return;
        }

        $operationalSite = fn ($query) => $query
            ->where('is_active', true)
            ->where('archived', false);

        $client = Client::query()
            ->whereNotNull('site_id')
            ->whereHas('site', $operationalSite)
            ->whereHas('supportWorkers', fn ($query) => $query->where('users.id', $worker->id))
            ->first() ?? Client::query()
            ->whereNotNull('site_id')
            ->whereHas('site', $operationalSite)
            ->first();

        if (! $client) {
            return;
        }

        if (! $client->suburb) {
            $client->forceFill(['suburb' => 'Mount Eden'])->save();
        }

        foreach ([$worker, $currentStaff, $admin] as $staff) {
            $this->grantSiteAccess($staff, $client);
        }

        $this->seedWorkerEligibilityEvidence($worker, $admin);
        $this->seedOpenPosition($admin, $worker, $currentStaff, $client, $serviceContext);
        $this->seedPendingClaim($admin, $worker, $currentStaff, $client, $serviceContext);
    }

    private function seedWorkerEligibilityEvidence(User $worker, User $admin): void
    {
        $requirements = HrComplianceRequirement::query()
            ->whereIn('code', ['MED_COMP', 'POLICE_VET'])
            ->get()
            ->keyBy('code');
        $medicationRequirement = $requirements->get('MED_COMP');
        $policeRequirement = $requirements->get('POLICE_VET');
        $tenantId = (int) ($worker->tenant_id ?? 1);

        if ($medicationRequirement) {
            $course = HrCourse::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => 'PW-JOB-BOARD-MED-COMP',
                ],
                [
                    'title' => 'Playwright Medication Competency',
                    'description' => 'Source-backed readiness evidence for the desktop job-board journey.',
                    'category' => 'clinical',
                    'delivery_method' => 'blended',
                    'duration_hours' => 4,
                    'provider' => 'Oblivion Findings Demo',
                    'is_mandatory' => true,
                    'compliance_requirement_id' => $medicationRequirement->id,
                    'is_active' => true,
                ],
            );

            $completedAt = Carbon::now()->subMonth();
            $training = StaffTrainingRecord::updateOrCreate(
                [
                    'user_id' => $worker->id,
                    'hr_course_id' => $course->id,
                ],
                [
                    'training_course_id' => null,
                    'status' => 'completed',
                    'enrolled_at' => $completedAt->copy()->subWeek(),
                    'enrolled_by_user_id' => $admin->id,
                    'completed_at' => $completedAt,
                    'completion_date' => $completedAt->toDateString(),
                    'expires_at' => $completedAt->copy()->addMonths(12),
                    'assessment_score' => 95,
                    'assessment_passed' => true,
                    'provider' => 'Oblivion Findings Demo',
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );

            $this->upsertCompliantStatus(
                $worker,
                $medicationRequirement,
                'training_record',
                $training->id,
                $completedAt,
                $completedAt->copy()->addMonths(12),
                $admin->id,
            );
        }

        if ($policeRequirement) {
            $checkedAt = Carbon::now()->subMonth();
            $check = StaffBackgroundCheck::updateOrCreate(
                [
                    'user_id' => $worker->id,
                    'check_type' => 'police_check',
                ],
                [
                    'status' => 'clear',
                    'reference_number' => 'PW-JOB-BOARD-POLICE-CLEAR',
                    'provider' => 'Oblivion Findings Demo',
                    'check_date' => $checkedAt->toDateString(),
                    'issue_date' => $checkedAt->toDateString(),
                    'expires_at' => $checkedAt->copy()->addMonths(36)->toDateString(),
                    'disclosures_present' => false,
                    'verified_by_user_id' => $admin->id,
                    'verified_at' => $checkedAt,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );

            $this->upsertCompliantStatus(
                $worker,
                $policeRequirement,
                'background_check',
                $check->id,
                $checkedAt,
                $checkedAt->copy()->addMonths(36),
                $admin->id,
            );
        }
    }

    private function upsertCompliantStatus(
        User $worker,
        HrComplianceRequirement $requirement,
        string $evidenceType,
        int $evidenceId,
        Carbon $validFrom,
        Carbon $expiresAt,
        int $recordedBy,
    ): void {
        HrStaffComplianceStatus::updateOrCreate(
            [
                'user_id' => $worker->id,
                'requirement_id' => $requirement->id,
            ],
            [
                'tenant_id' => (int) ($worker->tenant_id ?? 1),
                'status' => 'compliant',
                'evidence_type' => $evidenceType,
                'evidence_id' => $evidenceId,
                'recorded_by' => $recordedBy,
                'valid_from' => $validFrom->toDateString(),
                'expires_at' => $expiresAt->toDateString(),
                'last_checked_at' => Carbon::now(),
                'next_check_at' => Carbon::now()->addDay(),
            ],
        );
    }

    private function seedOpenPosition(
        User $admin,
        User $worker,
        User $currentStaff,
        Client $client,
        ServiceContext $serviceContext,
    ): void {
        $shift = $this->upsertShift(
            $admin,
            $currentStaff,
            $client,
            $serviceContext,
            'PW:job-board-open',
            $this->nextConflictFreeStart($worker),
        );

        $replacement = ShiftReplacementRequest::updateOrCreate(
            ['shift_id' => $shift->id],
            [
                'requested_by' => $admin->id,
                'current_staff_id' => $currentStaff->id,
                'replacement_user_id' => null,
                'status' => 'requested',
                'reason' => 'Playwright open job board cover',
                'notes' => 'Seeded for job board readiness tests.',
                'required_skills' => ['NZSL'],
                'requested_at' => Carbon::now()->subHours(2),
                'claimed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ],
        );

        ShiftOpenPosition::updateOrCreate(
            ['replacement_request_id' => $replacement->id],
            [
                'shift_id' => $shift->id,
                'status' => 'open',
                'required_skills' => ['NZSL'],
                'coverage_roles' => [],
                'notes' => 'Playwright open job board cover',
                'claimed_by' => null,
                'claimed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'expires_at' => Carbon::now()->addDays(2),
            ],
        );
    }

    private function nextConflictFreeStart(User $worker): Carbon
    {
        $anchor = Carbon::now()->startOfDay();
        $maxDailyHours = (float) config('hr.fatigue.max_hours_per_day', 12);

        foreach (range(1, 6) as $dayOffset) {
            foreach ([9, 15, 21] as $hour) {
                $startsAt = $anchor->copy()->addDays($dayOffset)->setTime($hour, 0);
                $endsAt = $startsAt->copy()->addHours(4);
                $minimumRestHours = (int) config('hr.fatigue.min_rest_between_shifts_hours', 10);
                $conflicts = Shift::query()
                    ->where('user_id', $worker->id)
                    ->whereNotIn('status', ['cancelled', 'completed'])
                    ->where('starts_at', '<', $endsAt->copy()->addHours($minimumRestHours))
                    ->where('ends_at', '>', $startsAt->copy()->subHours($minimumRestHours))
                    ->exists();

                $dayStart = $startsAt->copy()->startOfDay();
                $dayEnd = $startsAt->copy()->endOfDay();
                $existingDailyHours = Shift::query()
                    ->where('user_id', $worker->id)
                    ->where('status', '!=', 'cancelled')
                    ->where('starts_at', '<', $dayEnd)
                    ->where('ends_at', '>', $dayStart)
                    ->get(['starts_at', 'ends_at'])
                    ->sum(function (Shift $shift) use ($dayStart, $dayEnd): float {
                        $overlapStart = $shift->starts_at->max($dayStart);
                        $overlapEnd = $shift->ends_at->min($dayEnd);

                        return $overlapStart->lt($overlapEnd)
                            ? $overlapStart->diffInMinutes($overlapEnd) / 60
                            : 0;
                    });

                if (! $conflicts && $existingDailyHours + 4 <= $maxDailyHours) {
                    return $startsAt;
                }
            }
        }

        throw new \UnexpectedValueException('No fatigue-safe Playwright job-board window is available in the next six days.');
    }

    private function seedPendingClaim(
        User $admin,
        User $worker,
        User $currentStaff,
        Client $client,
        ServiceContext $serviceContext,
    ): void {
        $claimedAt = Carbon::now()->subHour();
        $shift = $this->upsertShift(
            $admin,
            $currentStaff,
            $client,
            $serviceContext,
            'PW:job-board-claimed-by-sw1',
            Carbon::now()->addDays(4)->setTime(13, 0)->startOfMinute(),
        );

        $replacement = ShiftReplacementRequest::updateOrCreate(
            ['shift_id' => $shift->id],
            [
                'requested_by' => $admin->id,
                'current_staff_id' => $currentStaff->id,
                'replacement_user_id' => $worker->id,
                'status' => 'claimed',
                'reason' => 'Playwright claimed job board cover',
                'notes' => 'Seeded for job board readiness tests.',
                'required_skills' => ['NZSL'],
                'requested_at' => Carbon::now()->subHours(3),
                'claimed_at' => $claimedAt,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ],
        );

        ShiftOpenPosition::updateOrCreate(
            ['replacement_request_id' => $replacement->id],
            [
                'shift_id' => $shift->id,
                'status' => 'claimed',
                'required_skills' => ['NZSL'],
                'coverage_roles' => [],
                'notes' => 'Playwright claimed job board cover',
                'claimed_by' => $worker->id,
                'claimed_at' => $claimedAt,
                'approved_by' => null,
                'approved_at' => null,
                'expires_at' => Carbon::now()->addDays(2),
            ],
        );
    }

    private function upsertShift(
        User $admin,
        User $currentStaff,
        Client $client,
        ServiceContext $serviceContext,
        string $notes,
        Carbon $startsAt,
    ): Shift {
        $shift = Shift::query()->where('notes', $notes)->first();
        $attributes = [
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $currentStaff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'location' => $client->city ?: 'Auckland',
            'status' => 'scheduled',
            'notes' => $notes,
            'created_by' => $admin->id,
        ];

        if ($shift) {
            $shift->forceFill($attributes)->save();
        } else {
            $shift = Shift::create($attributes);
        }

        return $shift->fresh() ?? $shift;
    }

    private function grantSiteAccess(User $worker, Client $client): void
    {
        if (! $client->site_id) {
            return;
        }

        $profile = HrEmployeeProfile::query()->where('user_id', $worker->id)->first();
        if (! $profile) {
            HrEmployeeProfile::create([
                'user_id' => $worker->id,
                'employee_number' => 'EMP-JOB-BOARD-'.$worker->id,
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => Carbon::now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $client->site_id,
                'secondary_site_ids' => [],
            ]);

            return;
        }

        $secondary = is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [];
        if ($profile->primary_site_id === $client->site_id || in_array($client->site_id, $secondary, true)) {
            $profile->update([
                'is_active' => true,
                'end_date' => null,
            ]);

            return;
        }

        $profile->update([
            'is_active' => true,
            'end_date' => null,
            'secondary_site_ids' => array_values(array_unique([...$secondary, $client->site_id])),
        ]);
    }
}
