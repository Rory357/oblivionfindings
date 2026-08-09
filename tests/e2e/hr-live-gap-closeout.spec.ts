import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

const marker =
    process.env.HR_LIVE_GAP_MARKER ??
    `CODEX-LIVE-HR-GAPS-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}`;

type Fixture = {
    marker: string;
    adminId: number;
    employeeUserId: number;
    employeeProfileId: number;
    payrollUserId: number;
    payrollProfileId: number;
    calendarEventId: number;
    salaryBandId: number;
    offboardingId: number;
    offboardingTaskId: number;
    offerId: number;
    offerToken: string;
    overrideCandidateId: number;
    overrideApplicationId: number;
    approvalChainId: number;
    leaveChainIds: number[];
    payrollRunId: number;
    payslipId: number;
    payPeriodStart: string;
    payPeriodEnd: string;
    attachmentPath: string;
};

function parseLaravelJson<T>(output: string): T {
    const line = output
        .trim()
        .split(/\r?\n/)
        .reverse()
        .find((candidate) => candidate.trim().startsWith('{'));

    if (!line) {
        throw new Error(`Laravel helper returned no JSON record:\n${output}`);
    }

    return JSON.parse(line) as T;
}

function formatPayrollDate(value: string): string {
    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function formatMyPayPeriod(start: string, end: string): string {
    const startDate = new Date(`${start}T00:00:00`);
    const endDate = new Date(`${end}T00:00:00`);
    const startMonth = startDate.toLocaleDateString('en-NZ', {
        month: 'short',
    });
    const endMonth = endDate.toLocaleDateString('en-NZ', {
        month: 'short',
        year: 'numeric',
    });

    return `${startDate.getDate()} ${startMonth} – ${endDate.getDate()} ${endMonth}`;
}

function cleanupFixtures(targetMarker = marker) {
    runLaravelPhp(`
$marker = ${JSON.stringify(targetMarker)};

$eventIds = \\App\\Domain\\Hr\\Models\\HrCalendarEvent::query()
    ->where('title', $marker.' Team event')->pluck('id');
$attachmentIds = \\App\\Domain\\Hr\\Models\\HrCalendarEventAttachment::query()
    ->whereIn('event_id', $eventIds)->pluck('id');
$attachmentPaths = \\App\\Domain\\Hr\\Models\\HrCalendarEventAttachment::query()
    ->whereIn('event_id', $eventIds)->get(['disk', 'path']);
foreach ($attachmentPaths as $attachment) {
    \\Illuminate\\Support\\Facades\\Storage::disk($attachment->disk ?: 'private')->delete($attachment->path);
}
\\Illuminate\\Support\\Facades\\DB::table('hr_calendar_event_attachments')->whereIn('event_id', $eventIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_calendar_event_reminders')->whereIn('event_id', $eventIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_calendar_event_attendees')->whereIn('event_id', $eventIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_calendar_events')->whereIn('id', $eventIds)->delete();

$profileIds = \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::withTrashed()
    ->whereIn('employee_number', [$marker.'-EMP', $marker.'-PAY'])->pluck('id');
$userIds = \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::withTrashed()
    ->whereIn('id', $profileIds)->pluck('user_id');
$userIds = $userIds->merge(
    \\App\\Models\\User::query()->whereIn('email', [
        strtolower($marker).'.worker@example.test',
        strtolower($marker).'.payroll@example.test',
    ])->pluck('id')
)->unique()->values();
$checklistIds = \\Illuminate\\Support\\Facades\\DB::table('hr_offboarding_checklists')
    ->whereIn('employee_profile_id', $profileIds)->pluck('id');
$taskIds = \\Illuminate\\Support\\Facades\\DB::table('hr_offboarding_tasks')
    ->whereIn('offboarding_checklist_id', $checklistIds)->pluck('id');
$exitInterviewIds = \\Illuminate\\Support\\Facades\\DB::table('hr_exit_interviews')
    ->whereIn('employee_profile_id', $profileIds)->pluck('id');
$payslipIds = \\Illuminate\\Support\\Facades\\DB::table('hr_payslips')
    ->whereIn('employee_profile_id', $profileIds)->pluck('id');
$leaveChainIds = \\Illuminate\\Support\\Facades\\DB::table('hr_leave_approval_chains')
    ->whereIn('user_id', $userIds)->pluck('id');
$deletedLeaveChainAuditIds = collect();
if ($userIds->isNotEmpty()) {
    $deletedLeaveChainAuditIds = \\Illuminate\\Support\\Facades\\DB::table('audit_logs')
        ->where('auditable_type', \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::class)
        ->where(function ($query) use ($userIds) {
            foreach ($userIds as $userId) {
                $query->orWhere('meta', 'like', '%"user_id": "'.$userId.'"%')
                    ->orWhere('meta', 'like', '%"user_id": '.$userId.'%');
            }
        })->pluck('id');
}
\\Illuminate\\Support\\Facades\\DB::table('hr_offboarding_tasks')->whereIn('offboarding_checklist_id', $checklistIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_exit_interviews')->whereIn('employee_profile_id', $profileIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_offboarding_checklists')->whereIn('id', $checklistIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_payslips')->whereIn('employee_profile_id', $profileIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_leave_approval_chains')->whereIn('user_id', $userIds)->delete();

$candidateIds = \\App\\Domain\\Hr\\Models\\HrCandidate::withTrashed()
    ->whereIn('notes', [$marker.' candidate', $marker.' override candidate'])->pluck('id');
$applicationIds = \\Illuminate\\Support\\Facades\\DB::table('hr_applications')
    ->whereIn('candidate_id', $candidateIds)->pluck('id');
$interviewIds = \\Illuminate\\Support\\Facades\\DB::table('hr_interviews')
    ->whereIn('application_id', $applicationIds)->pluck('id');
$referenceIds = \\Illuminate\\Support\\Facades\\DB::table('hr_reference_checks')
    ->whereIn('application_id', $applicationIds)->pluck('id');
$offerIds = \\Illuminate\\Support\\Facades\\DB::table('hr_offers')
    ->whereIn('application_id', $applicationIds)->pluck('id');
\\Illuminate\\Support\\Facades\\DB::table('hr_offers')->whereIn('application_id', $applicationIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_interview_scores')->whereIn('interview_id', $interviewIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_interviews')->whereIn('id', $interviewIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_reference_checks')->whereIn('id', $referenceIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_applications')->whereIn('id', $applicationIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_candidates')->whereIn('id', $candidateIds)->delete();

$chainIds = \\Illuminate\\Support\\Facades\\DB::table('hr_approval_chains')
    ->where('name', $marker.' Generic approvals')->pluck('id');
$bandIds = \\Illuminate\\Support\\Facades\\DB::table('hr_salary_bands')
    ->where('band_name', $marker.' Band')->pluck('id');
$payrollRunIds = \\Illuminate\\Support\\Facades\\DB::table('hr_payroll_runs')
    ->where('notes', $marker.' payroll run')->pluck('id');
\\Illuminate\\Support\\Facades\\DB::table('hr_approval_chain_steps')->whereIn('approval_chain_id', $chainIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_approval_chains')->whereIn('id', $chainIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_salary_bands')->whereIn('id', $bandIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('hr_payroll_runs')->whereIn('id', $payrollRunIds)->delete();

\\Illuminate\\Support\\Facades\\DB::table('hr_employee_profiles')->whereIn('id', $profileIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('role_user')->whereIn('user_id', $userIds)->delete();
\\Illuminate\\Support\\Facades\\DB::table('users')->whereIn('id', $userIds)->delete();

$auditableIds = [
    \\App\\Domain\\Hr\\Models\\HrCalendarEvent::class => $eventIds,
    \\App\\Domain\\Hr\\Models\\HrCalendarEventAttachment::class => $attachmentIds,
    \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::class => $profileIds,
    \\App\\Domain\\Hr\\Models\\HrOffboardingChecklist::class => $checklistIds,
    \\App\\Domain\\Hr\\Models\\HrOffboardingTask::class => $taskIds,
    \\App\\Domain\\Hr\\Models\\HrExitInterview::class => $exitInterviewIds,
    \\App\\Domain\\Hr\\Models\\HrCandidate::class => $candidateIds,
    \\App\\Domain\\Hr\\Models\\HrApplication::class => $applicationIds,
    \\App\\Domain\\Hr\\Models\\HrInterview::class => $interviewIds,
    \\App\\Domain\\Hr\\Models\\HrReferenceCheck::class => $referenceIds,
    \\App\\Domain\\Hr\\Models\\HrOffer::class => $offerIds,
    \\App\\Domain\\Hr\\Models\\HrApprovalChain::class => $chainIds,
    \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::class => $leaveChainIds,
    \\App\\Domain\\Hr\\Models\\HrSalaryBand::class => $bandIds,
    \\App\\Domain\\Hr\\Models\\HrPayrollRun::class => $payrollRunIds,
    \\App\\Domain\\Hr\\Models\\HrPayslip::class => $payslipIds,
    'staff' => $userIds,
];

\\Illuminate\\Support\\Facades\\DB::table('audit_logs')
    ->where(function ($query) use ($marker, $auditableIds, $deletedLeaveChainAuditIds) {
        $query->where(function ($markerQuery) use ($marker) {
            $markerQuery->where(function ($runQuery) use ($marker) {
                $runQuery->whereIn('action', [
                    'codex.live_hr_gaps',
                    'hr.playwright.live_gaps',
                ])
                    ->where('meta', 'like', '%'.$marker.'%');
            })->orWhere(function ($scopeQuery) use ($marker) {
                $scopeQuery->where('action', 'codex.cleanup_scope')
                    ->where('meta->marker', $marker);
            });
        });
        foreach ($auditableIds as $type => $ids) {
            if ($ids->isEmpty()) {
                continue;
            }
            $query->orWhere(function ($q) use ($type, $ids) {
                $q->where('auditable_type', $type)->whereIn('auditable_id', $ids);
            });
        }
        if ($deletedLeaveChainAuditIds->isNotEmpty()) {
            $query->orWhereIn('id', $deletedLeaveChainAuditIds);
        }
    })->delete();
`);
}

function seedFixtures(): Fixture {
    cleanupFixtures();

    const output = runLaravelPhp(`
$marker = ${JSON.stringify(marker)};
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$role = \\App\\Models\\Role::query()->where('name', 'support_worker')->firstOrFail();
$siteId = collect([
    $admin->hrEmployeeProfile?->primary_site_id,
    ...($admin->hrEmployeeProfile?->secondary_site_ids ?? []),
])->filter()->map(fn ($id) => (int) $id)->first()
    ?? \\App\\Models\\Site::query()->active()->notArchived()->orderBy('id')->value('id');
throw_unless($siteId, \\RuntimeException::class, 'A canonical active Site is required for the HR lifecycle fixture.');

$employee = \\App\\Models\\User::query()->create([
    'name' => $marker.' Worker',
    'email' => strtolower($marker).'.worker@example.test',
    'role' => 'support_worker',
    'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
    'approved_at' => now(),
]);
$employee->roles()->syncWithoutDetaching([$role->id]);
$profile = \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::query()->create([
    'user_id' => $employee->id,
    'employee_number' => $marker.'-EMP',
    'work_email' => $employee->email,
    'position_title' => 'Smoke Support Worker',
    'position_role' => $marker.'-role',
    'employment_type' => 'full_time',
    'contract_type' => 'permanent',
    'annual_salary' => 60000,
    'hourly_rate' => 30,
    'start_date' => now()->subYear()->toDateString(),
    'is_active' => true,
    'primary_site_id' => $siteId,
    'team' => 'Community Support',
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
]);

$payrollEmployee = \\App\\Models\\User::query()->create([
    'name' => $marker.' Payroll Worker',
    'email' => strtolower($marker).'.payroll@example.test',
    'role' => 'support_worker',
    'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
    'approved_at' => now(),
]);
$payrollEmployee->roles()->syncWithoutDetaching([$role->id]);
$payrollProfile = \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::query()->create([
    'user_id' => $payrollEmployee->id,
    'employee_number' => $marker.'-PAY',
    'work_email' => $payrollEmployee->email,
    'position_title' => 'Payroll Smoke Worker',
    'position_role' => 'support_worker',
    'employment_type' => 'full_time',
    'contract_type' => 'permanent',
    'annual_salary' => 60000,
    'hourly_rate' => 30,
    'start_date' => now()->subYear()->toDateString(),
    'is_active' => true,
    'primary_site_id' => $siteId,
    'team' => 'Community Support',
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
]);

$band = \\App\\Domain\\Hr\\Models\\HrSalaryBand::query()->create([
    'position_role' => $profile->position_role,
    'band_name' => $marker.' Band',
    'min_salary' => 50000,
    'mid_salary' => 60000,
    'max_salary' => 70000,
    'min_hourly' => 25,
    'max_hourly' => 35,
    'currency' => 'NZD',
    'effective_from' => now()->subMonth()->toDateString(),
    'is_active' => true,
    'created_by' => $admin->id,
]);

$attachmentPath = 'hr/calendar/1/'.$marker.'.txt';

$checklist = \\App\\Domain\\Hr\\Models\\HrOffboardingChecklist::query()->create([
    'employee_profile_id' => $profile->id,
    'template_key' => 'standard',
    'status' => 'in_progress',
    'started_at' => now(),
    'due_date' => now()->addWeeks(2)->toDateString(),
    'created_by' => $admin->id,
]);
$exitTask = $checklist->tasks()->create([
    'category' => 'exit_interview',
    'title' => 'Schedule interview — '.$marker,
    'description' => 'Record the canonical exit interview.',
    'is_required' => true,
    'sort_order' => 1,
    'assigned_to_user_id' => $admin->id,
    'status' => 'pending',
    'due_date' => now()->addWeek()->toDateString(),
    'notes' => 'workflow_key=exit_interview; marker='.$marker,
]);
$checklist->tasks()->create([
    'category' => 'equipment_return',
    'title' => 'Return equipment — '.$marker,
    'description' => 'Keep the employee current until the remaining required offboarding work is complete.',
    'is_required' => true,
    'sort_order' => 2,
    'assigned_to_user_id' => $admin->id,
    'status' => 'pending',
    'due_date' => now()->addWeek()->toDateString(),
    'notes' => 'workflow_key=equipment_return; marker='.$marker,
]);

$candidate = \\App\\Domain\\Hr\\Models\\HrCandidate::query()->create([
    'first_name' => 'Codex',
    'last_name' => $marker,
    'personal_email' => strtolower($marker).'.candidate@example.test',
    'source' => 'referral',
    'status' => 'offer_sent',
    'notes' => $marker.' candidate',
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
]);
$application = \\App\\Domain\\Hr\\Models\\HrApplication::query()->create([
    'candidate_id' => $candidate->id,
    'target_site_id' => $siteId,
    'position_title' => 'Smoke Support Worker',
    'position_role' => 'support_worker',
    'status' => 'active',
]);
$offerToken = 'codex-live-'.\\Illuminate\\Support\\Str::random(40);
$offer = \\App\\Domain\\Hr\\Models\\HrOffer::query()->create([
    'application_id' => $application->id,
    'primary_site_id' => $siteId,
    'position_title' => 'Smoke Support Worker',
    'position_role' => 'support_worker',
    'proposed_start_date' => now()->addWeeks(3)->toDateString(),
    'employment_type' => 'full_time',
    'hours_per_week' => 40,
    'hourly_rate' => 30,
    'approval_status' => 'approved',
    'approved_by' => $admin->id,
    'approved_at' => now(),
    'sent_at' => now()->subDay(),
    'candidate_portal_token' => $offerToken,
    'portal_expires_at' => now()->addWeeks(2),
    'created_by' => $admin->id,
]);

$overrideCandidate = \\App\\Domain\\Hr\\Models\\HrCandidate::query()->create([
    'first_name' => 'Codex',
    'last_name' => $marker.' Override',
    'personal_email' => strtolower($marker).'.override@example.test',
    'source' => 'referral',
    'status' => 'interview_completed',
    'notes' => $marker.' override candidate',
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
]);
$overrideApplication = \\App\\Domain\\Hr\\Models\\HrApplication::query()->create([
    'candidate_id' => $overrideCandidate->id,
    'target_site_id' => $siteId,
    'position_title' => 'Smoke Support Worker',
    'position_role' => 'support_worker',
    'status' => 'active',
]);
\\App\\Domain\\Hr\\Models\\HrInterview::query()->create([
    'application_id' => $overrideApplication->id,
    'scheduled_at' => now()->subDay(),
    'duration_minutes' => 60,
    'interview_type' => 'panel',
    'interviewers' => [$admin->id, $employee->id],
    'status' => 'completed',
    'completed_by' => $admin->id,
]);
\\App\\Domain\\Hr\\Models\\HrReferenceCheck::query()->create([
    'application_id' => $overrideApplication->id,
    'referee_name' => 'Synthetic Reference',
    'referee_relationship' => 'Manager',
    'status' => 'requested',
    'requested_at' => now(),
]);

$chain = \\App\\Domain\\Hr\\Models\\HrApprovalChain::query()->create([
    'name' => $marker.' Generic approvals',
    'process_type' => 'expense',
    'is_active' => true,
    'created_by' => $admin->id,
]);
$chain->steps()->create(['step_order' => 1, 'approver_type' => 'manager', 'created_at' => now()]);
$chain->steps()->create(['step_order' => 2, 'approver_type' => 'user', 'approver_user_id' => $admin->id, 'created_at' => now()]);
$leaveOne = \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::query()->create([
    'user_id' => $employee->id, 'approver_user_id' => $admin->id,
    'approval_level' => 1, 'escalation_after_hours' => 24, 'is_active' => true,
    'created_by' => $admin->id, 'updated_by' => $admin->id,
]);
$leaveTwo = \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::query()->create([
    'user_id' => $employee->id, 'approver_user_id' => $admin->id,
    'approval_level' => 2, 'escalation_after_hours' => 48, 'is_active' => true,
    'created_by' => $admin->id, 'updated_by' => $admin->id,
]);

$run = \\App\\Domain\\Hr\\Models\\HrPayrollRun::query()->create([
    'period_start' => now()->subWeeks(2)->startOfWeek()->toDateString(),
    'period_end' => now()->subWeek()->endOfWeek()->toDateString(),
    'status' => 'locked',
    'locked_at' => now()->subDay(),
    'locked_by' => $admin->id,
    'total_hours' => 80,
    'total_gross' => 2400,
    'total_staff' => 1,
    'notes' => $marker.' payroll run',
    'created_by' => $admin->id,
]);
$payslip = \\App\\Domain\\Hr\\Models\\HrPayslip::query()->create([
    'payroll_run_id' => $run->id,
    'employee_profile_id' => $payrollProfile->id,
    'user_id' => $payrollEmployee->id,
    'pay_period_start' => $run->period_start,
    'pay_period_end' => $run->period_end,
    'payment_date' => now()->subDays(2)->toDateString(),
    'gross_pay' => 2400,
    'regular_hours' => 80,
    'overtime_hours' => 0,
    'hourly_rate' => 30,
    'paye' => 480,
    'acc_levy' => 34,
    'kiwisaver_employee' => 72,
    'kiwisaver_employer' => 72,
    'esct' => 12,
    'student_loan' => 0,
    'holiday_pay' => 0,
    'total_deductions' => 586,
    'net_pay' => 1814,
    'allowances' => [],
    'other_deductions' => [],
    'tax_code' => 'M',
    'kiwisaver_rate' => 3,
    'status' => 'final',
    'created_by' => $admin->id,
]);

\\App\\Models\\AuditLog::query()->create([
    'user_id' => null,
    'action' => 'hr.playwright.live_gaps',
    'auditable_type' => null,
    'auditable_id' => null,
    'meta' => ['before' => ['state' => 'before'], 'after' => ['state' => $marker]],
]);

echo json_encode([
    'marker' => $marker,
    'adminId' => $admin->id,
    'employeeUserId' => $employee->id,
    'employeeProfileId' => $profile->id,
    'payrollUserId' => $payrollEmployee->id,
    'payrollProfileId' => $payrollProfile->id,
    'calendarEventId' => 0,
    'salaryBandId' => $band->id,
    'offboardingId' => $checklist->id,
    'offboardingTaskId' => $exitTask->id,
    'offerId' => $offer->id,
    'offerToken' => $offerToken,
    'overrideCandidateId' => $overrideCandidate->id,
    'overrideApplicationId' => $overrideApplication->id,
    'approvalChainId' => $chain->id,
    'leaveChainIds' => [$leaveOne->id, $leaveTwo->id],
    'payrollRunId' => $run->id,
    'payslipId' => $payslip->id,
    'payPeriodStart' => $run->period_start->toDateString(),
    'payPeriodEnd' => $run->period_end->toDateString(),
    'attachmentPath' => $attachmentPath,
]);
`);

    return parseLaravelJson<Fixture>(output);
}

test.describe.serial('HR live gap closeout', () => {
    let fixture: Fixture;

    test.beforeAll(() => {
        fixture = seedFixtures();
        console.log(`[hr-live-gap] fixture=${JSON.stringify(fixture)}`);
    });

    test.afterAll(() => {
        cleanupFixtures();
    });

    test('cleanup deletes only the requested marker', () => {
        const probeMarker = `${fixture.marker}-CLEANUP-PROBE`;
        const sentinelMarker = `${fixture.marker}-CLEANUP-SENTINEL`;
        runLaravelPhp(`
foreach ([${JSON.stringify(probeMarker)}, ${JSON.stringify(sentinelMarker)}] as $probe) {
    \\App\\Domain\\Hr\\Models\\HrCalendarEvent::query()->create([
        'title' => $probe.' Team event',
        'event_type' => 'team',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'is_all_day' => false,
        'created_by' => ${fixture.adminId},
    ]);
    \\App\\Models\\AuditLog::query()->create([
        'user_id' => null,
        'action' => 'codex.cleanup_scope',
        'auditable_type' => null,
        'auditable_id' => null,
        'meta' => ['marker' => $probe],
    ]);
}
`);

        cleanupFixtures(probeMarker);
        const remaining = Number(
            runLaravelPhp(`
echo \\App\\Domain\\Hr\\Models\\HrCalendarEvent::query()
    ->where('title', ${JSON.stringify(`${sentinelMarker} Team event`)})
    ->count();
`).trim(),
        );
        expect(remaining).toBe(1);
        const probeAuditCount = Number(
            runLaravelPhp(`
echo \\App\\Models\\AuditLog::query()
    ->where('action', 'codex.cleanup_scope')
    ->where('meta', 'like', '%'.${JSON.stringify(probeMarker)}.'%')
    ->count();
`).trim(),
        );
        const sentinelAuditCount = Number(
            runLaravelPhp(`
echo \\App\\Models\\AuditLog::query()
    ->where('action', 'codex.cleanup_scope')
    ->where('meta', 'like', '%'.${JSON.stringify(sentinelMarker)}.'%')
    ->count();
`).trim(),
        );
        expect(probeAuditCount).toBe(0);
        expect(sentinelAuditCount).toBe(1);
        cleanupFixtures(sentinelMarker);

        const deletedRouteId = Number(
            runLaravelPhp(`
$route = \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::query()->create([
    'user_id' => ${fixture.payrollUserId},
    'approver_user_id' => ${fixture.adminId},
    'approval_level' => 99,
    'escalation_after_hours' => 12,
    'is_active' => true,
    'created_by' => ${fixture.adminId},
    'updated_by' => ${fixture.adminId},
]);
$id = $route->id;
$route->delete();
echo $id;
`).trim(),
        );
        expect(
            Number(
                runLaravelPhp(`
echo \\App\\Models\\AuditLog::query()
    ->where('auditable_type', \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::class)
    ->where('auditable_id', ${deletedRouteId})
    ->count();
`).trim(),
            ),
        ).toBeGreaterThan(0);

        cleanupFixtures();
        expect(
            Number(
                runLaravelPhp(`
echo \\App\\Models\\AuditLog::query()
    ->where('auditable_type', \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::class)
    ->where('auditable_id', ${deletedRouteId})
    ->count();
`).trim(),
            ),
        ).toBe(0);
        fixture = seedFixtures();
    });

    test('proves marker-scoped HR lifecycle surfaces and controls', async ({
        page,
    }) => {
        test.setTimeout(900_000);
        page.setDefaultTimeout(15_000);
        const consoleErrors = collectConsoleErrors(page);
        const failedHrRequests: string[] = [];
        page.on('response', (response) => {
            if (response.url().includes('/hr/') && response.status() >= 400) {
                failedHrRequests.push(
                    `${response.status()} ${response.request().method()} ${response.url()}`,
                );
            }
        });

        await loginAsStaff(page);

        await page.goto(
            '/hr/settings/audit-log?action=hr.playwright.live_gaps',
        );
        await expect(
            page.getByRole('heading', { name: 'Audit Log' }),
        ).toBeVisible();
        const auditRow = page.locator('tr').filter({
            hasText: 'hr.playwright.live_gaps',
        });
        await expect(auditRow).toContainText('System');
        await auditRow.click();
        await expect(page.getByText('Changes', { exact: true })).toBeVisible();
        await expect(page.getByText(fixture.marker)).toBeVisible();

        await page.goto('/hr/calendar');
        await expect(
            page.getByRole('heading', { name: /Calendar/i }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'New event', exact: true })
            .first()
            .click();
        const eventDialog = page.getByRole('dialog', { name: 'New event' });
        await eventDialog
            .getByPlaceholder('e.g. All-staff hui, Fire drill, Team lunch')
            .fill(`${fixture.marker} Team event`);
        await eventDialog.getByText('Team', { exact: true }).click();
        await eventDialog.getByRole('button', { name: 'Continue' }).click();
        const startsAt = new Date(Date.now() + 2 * 86_400_000)
            .toISOString()
            .slice(0, 16);
        const endsAt = new Date(Date.now() + 2 * 86_400_000 + 3_600_000)
            .toISOString()
            .slice(0, 16);
        await eventDialog
            .locator('input[type="datetime-local"]')
            .nth(0)
            .fill(startsAt);
        await eventDialog
            .locator('input[type="datetime-local"]')
            .nth(1)
            .fill(endsAt);
        await eventDialog.getByRole('button', { name: 'Continue' }).click();
        await eventDialog.getByRole('button', { name: 'A team' }).click();
        await eventDialog
            .getByRole('combobox', { name: 'Audience team' })
            .click();
        await page.getByRole('option', { name: 'Community Support' }).click();
        await eventDialog.getByRole('button', { name: 'Continue' }).click();
        await eventDialog.getByRole('button', { name: 'Continue' }).click();
        const createCompleted = page.waitForResponse(
            (response) =>
                response.url().endsWith('/hr/calendar/events') &&
                response.request().method() === 'POST',
        );
        await eventDialog.getByRole('button', { name: 'Create event' }).click();
        expect((await createCompleted).status()).toBeLessThan(400);
        await eventDialog.getByRole('button', { name: 'Done' }).click();
        fixture.calendarEventId = Number(
            runLaravelPhp(`
$event = \\App\\Domain\\Hr\\Models\\HrCalendarEvent::query()
    ->where('title', ${JSON.stringify(`${fixture.marker} Team event`)})
    ->firstOrFail();
$event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
$path = ${JSON.stringify(fixture.attachmentPath)};
\\Illuminate\\Support\\Facades\\Storage::disk('private')->put($path, ${JSON.stringify(fixture.marker)});
$event->attachments()->create([
    'uploaded_by' => ${fixture.adminId},
    'disk' => 'private',
    'original_name' => ${JSON.stringify(`${fixture.marker}.txt`)},
    'path' => $path,
    'mime' => 'text/plain',
    'size' => strlen(${JSON.stringify(fixture.marker)}),
]);
echo $event->id;
`).trim(),
        );
        await page.getByPlaceholder('Search events…').fill(fixture.marker);
        const calendarEvent = page
            .locator('button[title]')
            .filter({ hasText: fixture.marker });
        await expect(calendarEvent).toBeVisible();
        await calendarEvent.click();
        await expect(
            page.getByRole('button', { name: 'Archive', exact: true }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Archive', exact: true })
            .click();
        await expect(
            page.getByRole('button', { name: 'Archive event' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Archive event' }).click();
        await page.getByRole('button', { name: /Archived events/ }).click();
        const archivedDialog = page.getByRole('dialog', {
            name: 'Archived events',
        });
        await expect(archivedDialog).toContainText(fixture.marker);
        await archivedDialog.getByRole('button', { name: 'Restore' }).click();
        await expect(archivedDialog).not.toBeVisible();
        const retained = parseLaravelJson<{
            archived: boolean;
            attendees: number;
            reminders: number;
            attachments: number;
        }>(
            runLaravelPhp(`
$event = \\App\\Domain\\Hr\\Models\\HrCalendarEvent::query()->findOrFail(${fixture.calendarEventId});
echo json_encode([
    'archived' => $event->archived_at !== null,
    'attendees' => $event->attendees()->count(),
    'reminders' => $event->reminders()->count(),
    'attachments' => $event->attachments()->count(),
]);
`),
        );
        expect(retained).toEqual({
            archived: false,
            attendees: 1,
            reminders: 1,
            attachments: 1,
        });

        await page.goto(
            `/hr/compensation/bands?role=${encodeURIComponent(fixture.marker)}`,
        );
        await expect(page.getByText(`${fixture.marker} Band`)).toBeVisible();
        await page
            .getByRole('button', {
                name: `Actions for ${fixture.marker}-role ${fixture.marker} Band`,
            })
            .click();
        await page.getByRole('menuitem', { name: 'View people' }).click();
        await expect(page.getByRole('dialog')).toContainText(
            `${fixture.marker} Worker`,
        );
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Close' })
            .click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
        await page
            .getByRole('button', {
                name: `Actions for ${fixture.marker}-role ${fixture.marker} Band`,
            })
            .click();
        await page.getByRole('menuitem', { name: 'Deactivate band' }).click();
        await page.getByRole('button', { name: 'Deactivate band' }).click();
        await expect(page.getByText('Inactive').first()).toBeVisible();
        await page
            .getByRole('button', {
                name: `Actions for ${fixture.marker}-role ${fixture.marker} Band`,
            })
            .click();
        await page.getByRole('menuitem', { name: 'Reactivate band' }).click();
        await page.getByRole('button', { name: 'Reactivate band' }).click();

        await page.goto(`/hr/offboarding/${fixture.offboardingId}`);
        await expect(
            page.getByText(`Schedule interview — ${fixture.marker}`),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Record exit interview' })
            .click();
        const exitDialog = page.getByRole('dialog', {
            name: 'Record exit interview',
        });
        await exitDialog.getByRole('combobox').nth(0).click();
        await page.getByRole('option', { name: 'Demo Admin' }).click();
        await exitDialog
            .locator('#interview_date')
            .fill(new Date().toISOString().slice(0, 10));
        await exitDialog.getByRole('combobox').nth(1).click();
        await page.getByRole('option').first().click();
        await exitDialog
            .getByRole('button', { name: 'Record interview' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Record exit interview' }),
        ).toHaveCount(0);
        await page.reload();
        expect(
            Number(
                runLaravelPhp(
                    `echo \\App\\Domain\\Hr\\Models\\HrExitInterview::query()->where('employee_profile_id', ${fixture.employeeProfileId})->count();`,
                ).trim(),
            ),
        ).toBe(1);

        await page.goto('/hr/recruitment?tab=offers');
        const offerCard = page
            .getByText(`Codex ${fixture.marker}`, { exact: true })
            .locator('..')
            .locator('..');
        await expect(
            offerCard.getByRole('button', { name: 'Expire' }),
        ).toBeVisible();
        await offerCard.getByRole('button', { name: 'Expire' }).click();
        const expireDialog = page.getByRole('dialog', {
            name: new RegExp(`Expire Codex ${fixture.marker}`),
        });
        const expireOfferButton = expireDialog.getByRole('button', {
            name: 'Expire offer',
        });
        await expect(expireOfferButton).toBeDisabled();
        await expireDialog
            .getByRole('textbox')
            .fill(`${fixture.marker} lifecycle proof`);
        await expect(expireOfferButton).toBeEnabled();
        const expiryCompleted = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .includes(
                        `/hr/recruitment/offers/${fixture.offerId}/expire`,
                    ) && response.request().method() === 'POST',
        );
        await expireOfferButton.click();
        expect((await expiryCompleted).status()).toBeLessThan(400);
        const expiredOffer = parseLaravelJson<{
            tokenInvalidated: boolean;
            expiredBy: number | null;
            expiryReason: string | null;
        }>(
            runLaravelPhp(`
$offer = \\App\\Domain\\Hr\\Models\\HrOffer::query()->findOrFail(${fixture.offerId});
echo json_encode([
    'tokenInvalidated' => $offer->candidate_portal_token === null,
    'expiredBy' => $offer->expired_by,
    'expiryReason' => $offer->expiry_reason,
]);
`),
        );
        expect(expiredOffer).toEqual({
            tokenInvalidated: true,
            expiredBy: fixture.adminId,
            expiryReason: `${fixture.marker} lifecycle proof`,
        });
        const expiredPortal = await page.context().newPage();
        await expiredPortal.goto(`/careers/offers/${fixture.offerToken}`);
        await expect(
            expiredPortal.getByRole('heading', {
                name: 'Offer link is invalid',
            }),
        ).toBeVisible();
        await expiredPortal.close();
        await expect(
            offerCard.getByRole('button', { name: 'Resend link' }),
        ).toBeVisible();
        const mailSafety = parseLaravelJson<{
            defaultMailer: string;
            transport: string;
            safe: boolean;
        }>(
            runLaravelPhp(`
$defaultMailer = (string) config('mail.default');
$transport = (string) config('mail.mailers.'.$defaultMailer.'.transport', $defaultMailer);
echo json_encode([
    'defaultMailer' => $defaultMailer,
    'transport' => $transport,
    'safe' => in_array($transport, ['array', 'log'], true),
]);
`),
        );
        expect(
            mailSafety.safe,
            `Offer resend requires a non-delivering mail transport, got ${mailSafety.defaultMailer}/${mailSafety.transport}`,
        ).toBe(true);
        const resendCompleted = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .includes(
                        `/hr/recruitment/offers/${fixture.offerId}/resend`,
                    ) && response.request().method() === 'POST',
        );
        await offerCard.getByRole('button', { name: 'Resend link' }).click();
        expect((await resendCompleted).status()).toBeLessThan(400);
        const refreshedOffer = parseLaravelJson<{
            tokenRotated: boolean;
            hasToken: boolean;
            expiredBy: number | null;
            expiryReason: string | null;
        }>(
            runLaravelPhp(`
$offer = \\App\\Domain\\Hr\\Models\\HrOffer::query()->findOrFail(${fixture.offerId});
echo json_encode([
    'tokenRotated' => $offer->candidate_portal_token !== ${JSON.stringify(fixture.offerToken)},
    'hasToken' => $offer->candidate_portal_token !== null,
    'expiredBy' => $offer->expired_by,
    'expiryReason' => $offer->expiry_reason,
]);
`),
        );
        expect(refreshedOffer).toEqual({
            tokenRotated: true,
            hasToken: true,
            expiredBy: null,
            expiryReason: null,
        });

        await page.goto('/hr/recruitment?tab=pipeline');
        const overrideCandidateButton = page
            .getByRole('button', {
                name: new RegExp(`Codex ${fixture.marker} Override`),
            })
            .first();
        await expect(overrideCandidateButton).toBeVisible();
        await overrideCandidateButton
            .locator('..')
            .getByRole('button', { name: 'Row menu' })
            .click();
        await page.getByText('Advance stage', { exact: true }).click();
        const overrideDialog = page.getByRole('dialog', {
            name: 'Advance without every scorecard?',
        });
        await expect(overrideDialog).toBeVisible();
        const advanceWithOverride = overrideDialog.getByRole('button', {
            name: 'Advance with override',
        });
        await expect(advanceWithOverride).toBeDisabled();
        const overrideReason = `${fixture.marker} panel member unavailable`;
        await overrideDialog.getByRole('textbox').fill(overrideReason);
        await expect(advanceWithOverride).toBeEnabled();
        const overrideCompleted = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .includes(
                        `/hr/recruitment/applications/${fixture.overrideApplicationId}/advance`,
                    ) && response.request().method() === 'POST',
        );
        await advanceWithOverride.click();
        expect((await overrideCompleted).status()).toBeLessThan(400);
        const overrideProof = parseLaravelJson<{
            status: string;
            auditRecorded: boolean;
        }>(
            runLaravelPhp(`
$candidate = \\App\\Domain\\Hr\\Models\\HrCandidate::query()->findOrFail(${fixture.overrideCandidateId});
$auditRecorded = \\App\\Models\\AuditLog::query()
    ->where('action', 'recruitment.scorecard_quorum_overridden')
    ->where('auditable_type', \\App\\Domain\\Hr\\Models\\HrCandidate::class)
    ->where('auditable_id', $candidate->id)
    ->where('meta', 'like', '%'.${JSON.stringify(overrideReason)}.'%')
    ->exists();
echo json_encode(['status' => $candidate->status, 'auditRecorded' => $auditRecorded]);
`),
        );
        expect(overrideProof).toEqual({
            status: 'reference_check',
            auditRecorded: true,
        });

        await page.goto('/hr/approvals/chains');
        await expect(
            page.getByText('Native leave approval routing'),
        ).toBeVisible();
        await expect(
            page.getByText(`${fixture.marker} Generic approvals`),
        ).toBeVisible();
        const leaveForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Add route' }),
        });
        await leaveForm.getByRole('combobox').nth(0).click();
        await page
            .getByRole('option', {
                name: `${fixture.marker} Payroll Worker`,
            })
            .click();
        await leaveForm.getByRole('combobox').nth(1).click();
        await page.getByRole('option', { name: 'Demo Admin' }).click();
        await leaveForm.getByPlaceholder('Level').fill('1');
        await leaveForm.getByPlaceholder('Escalate hours').fill('12');
        const addRouteCompleted = page.waitForResponse(
            (response) =>
                response.url().endsWith('/hr/approvals/leave-chains') &&
                response.request().method() === 'POST',
        );
        await leaveForm.getByRole('button', { name: 'Add route' }).click();
        expect((await addRouteCompleted).status()).toBeLessThan(400);
        const addedLeaveRoute = page
            .locator('tr')
            .filter({ hasText: `${fixture.marker} Payroll Worker` });
        await expect(addedLeaveRoute).toContainText('12h');

        const leaveRoute = page
            .locator('tr')
            .filter({ hasText: `${fixture.marker} Worker` })
            .first();
        await expect(leaveRoute).toBeVisible();
        await leaveRoute
            .getByRole('button', { name: 'Edit leave approval route' })
            .click();
        const editLeaveForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Save', exact: true }),
        });
        await editLeaveForm.getByPlaceholder('Escalate hours').fill('36');
        const editRouteCompleted = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .includes(
                        `/hr/approvals/leave-chains/${fixture.leaveChainIds[0]}`,
                    ) && response.request().method() === 'PUT',
        );
        await editLeaveForm
            .getByRole('button', { name: 'Save', exact: true })
            .click();
        expect((await editRouteCompleted).status()).toBeLessThan(400);
        await expect(leaveRoute).toContainText('36h');

        const moveDownCompleted = page.waitForResponse(
            (response) =>
                response.url().includes('/hr/approvals/leave-chains/reorder') &&
                response.request().method() === 'POST',
        );
        await leaveRoute
            .getByRole('button', { name: 'Move approval level down' })
            .click();
        expect((await moveDownCompleted).status()).toBeLessThan(400);
        const movedLeaveRoute = page
            .locator('tr')
            .filter({ hasText: `${fixture.marker} Worker` })
            .filter({ hasText: '36h' });
        await expect(movedLeaveRoute).toBeVisible();
        await expect(movedLeaveRoute.locator('td').nth(1)).toHaveText('2');
        const [moveUpCompleted] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    response
                        .url()
                        .includes('/hr/approvals/leave-chains/reorder') &&
                    response.request().method() === 'POST',
            ),
            movedLeaveRoute
                .getByRole('button', { name: 'Move approval level up' })
                .click(),
        ]);
        expect(moveUpCompleted.status()).toBeLessThan(400);
        await expect(movedLeaveRoute.locator('td').nth(1)).toHaveText('1');

        const deactivateCompleted = page.waitForResponse(
            (response) =>
                response.url().includes('/hr/approvals/leave-chains/') &&
                response.url().endsWith('/active') &&
                response.request().method() === 'PATCH',
        );
        await movedLeaveRoute
            .getByRole('button', { name: 'Deactivate' })
            .click();
        expect((await deactivateCompleted).status()).toBeLessThan(400);
        const inactiveLeaveRoute = page
            .locator('tr')
            .filter({ hasText: `${fixture.marker} Worker` })
            .filter({ hasText: 'Inactive' });
        await expect(inactiveLeaveRoute).toBeVisible();
        const activateCompleted = page.waitForResponse(
            (response) =>
                response.url().includes('/hr/approvals/leave-chains/') &&
                response.url().endsWith('/active') &&
                response.request().method() === 'PATCH',
        );
        await inactiveLeaveRoute
            .getByRole('button', { name: 'Activate' })
            .click();
        expect((await activateCompleted).status()).toBeLessThan(400);
        const addedRouteId = Number(
            runLaravelPhp(`
echo \\App\\Domain\\Hr\\Models\\HrLeaveApprovalChain::query()
    ->where('user_id', ${fixture.payrollUserId})
    ->where('escalation_after_hours', 12)
    ->value('id');
`).trim(),
        );
        const removeCompleted = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .endsWith(`/hr/approvals/leave-chains/${addedRouteId}`) &&
                response.request().method() === 'DELETE',
        );
        await addedLeaveRoute
            .getByRole('button', { name: 'Remove leave approval route' })
            .click();
        expect((await removeCompleted).status()).toBeLessThan(400);
        await expect(addedLeaveRoute).toHaveCount(0);

        await page.goto('/hr/payroll');
        const payrollRun = page
            .locator('tr')
            .filter({ hasText: formatPayrollDate(fixture.payPeriodStart) })
            .filter({ hasText: formatPayrollDate(fixture.payPeriodEnd) });
        await expect(payrollRun).toContainText('Locked');
        await expect(payrollRun).toContainText('$2,400.00');
        await page.goto('/hr/payroll/payslips');
        const desktopPayslipRow = page
            .locator('tr')
            .filter({ hasText: `${fixture.marker} Payroll Worker` });
        await expect(desktopPayslipRow).toBeVisible();
        await desktopPayslipRow.getByRole('link', { name: 'View' }).click();
        await expect(page).toHaveURL(
            new RegExp(`/hr/payroll/payslips/${fixture.payslipId}$`),
        );
        await expect(
            page.getByText(`${fixture.marker} Payroll Worker`).first(),
        ).toBeVisible();
        const managerDownload = await page.request.get(
            `/hr/payroll/payslips/${fixture.payslipId}/download`,
        );
        expect(managerDownload.status()).toBe(200);
        expect(managerDownload.headers()['content-type']).toContain(
            'application/pdf',
        );
        await page.goto('/hr/payroll/payslips');
        await page.setViewportSize({ width: 390, height: 844 });
        const mobilePayslips = page.locator('[data-payroll-mobile]');
        await expect(mobilePayslips).toBeVisible();
        await expect(mobilePayslips).toContainText(
            `${fixture.marker} Payroll Worker`,
        );
        await expect(
            mobilePayslips.getByRole('link', { name: 'View' }),
        ).toBeVisible();
        await expect(
            mobilePayslips.getByRole('link', { name: 'Download' }),
        ).toBeVisible();

        await page.context().clearCookies();
        await loginAs(
            page,
            `${fixture.marker.toLowerCase()}.payroll@example.test`,
            'password',
        );
        await page.goto('/hr/my/payslips');
        await expect(
            page.getByText(
                formatMyPayPeriod(fixture.payPeriodStart, fixture.payPeriodEnd),
            ),
        ).toBeVisible();
        await expect(page.getByText('$1,814.00').first()).toBeVisible();
        const employeeDownload = await page.request.get(
            `/hr/my/payslips/${fixture.payslipId}/download`,
        );
        expect(employeeDownload.status()).toBe(200);
        expect(employeeDownload.headers()['content-type']).toContain(
            'application/pdf',
        );

        expectNoConsoleErrors(consoleErrors);
        expect(failedHrRequests).toEqual([]);
    });

    test('rechecks the five previously green HR controls', async ({ page }) => {
        test.setTimeout(120_000);
        page.setDefaultTimeout(15_000);
        const consoleErrors = collectConsoleErrors(page);
        const failedTargetRequests: string[] = [];
        page.on('response', (response) => {
            if (
                (response.url().includes('/hr/') ||
                    response.url().includes('/operations/shifts')) &&
                response.status() >= 400
            ) {
                failedTargetRequests.push(
                    `${response.status()} ${response.request().method()} ${response.url()}`,
                );
            }
        });

        await loginAsStaff(page);
        await page.goto('/hr/training/catalog');
        await page.getByRole('tab', { name: /^Catalog/ }).click();
        const trainingSearch = page.getByPlaceholder(
            'Search title, code, provider…  ( / )',
        );
        await trainingSearch.fill(fixture.marker);
        await expect(trainingSearch).toHaveValue(fixture.marker);

        await page.goto('/hr/feedback');
        await page.getByRole('button', { name: /^Pending/ }).click();
        await expect(
            page.getByText(/No pending feedback requests|Feedback for/).first(),
        ).toBeVisible();

        await page.goto('/hr/performance?tab=supervision');
        const supervisionSearch = page.getByPlaceholder(
            'Search supervision notes…',
        );
        await supervisionSearch.fill(fixture.marker);
        await expect(supervisionSearch).toHaveValue(fixture.marker);

        await page.goto('/hr/time?tab=overview');
        const overviewTab = page.getByRole('tab', { name: /^Overview/ });
        await expect(overviewTab).toHaveAttribute('aria-selected', 'true');
        const softRefresh = page.waitForResponse(
            (response) => {
                const partialData =
                    response.request().headers()['x-inertia-partial-data'] ??
                    '';
                return (
                    response.url().includes('/hr/time') &&
                    response.request().method() === 'GET' &&
                    partialData.includes('entries') &&
                    partialData.includes('onNow')
                );
            },
            { timeout: 35_000 },
        );
        expect((await softRefresh).status()).toBe(200);
        await expect(overviewTab).toHaveAttribute('aria-selected', 'true');
        await expect(page).toHaveURL(/\/hr\/time\?tab=overview/);

        await page.goto('/operations/shifts');
        await page.getByRole('tab', { name: /^Open/ }).click();
        await expect(page.getByRole('tab', { name: /^Open/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        await page.getByRole('button', { name: 'Calendar view' }).click();
        await expect(
            page.getByRole('button', { name: 'Calendar view' }),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
        expect(failedTargetRequests).toEqual([]);
    });
});
