<?php

namespace Tests\Support;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\StrategicGoal;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientNote;
use App\Models\FleetWorkOrder;
use App\Models\IncidentFollowup;
use App\Models\ItChange;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Overview\OverviewAccess;
use App\Services\Overview\OverviewData;
use Carbon\CarbonImmutable;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;

/** Fictional records for browser review; never callable against the working app database. */
final class OverviewVisualFixtures
{
    public static function seed(): void
    {
        if (! app()->environment('testing') || ! preg_match('/\Aoblivion_overview_proof_[0-9]+\z/', (string) config('database.connections.mysql.database'))) {
            throw new \RuntimeException('Overview fixtures require their disposable testing database.');
        }
        foreach ([RbacSeeder::class, OperationsPermissionsSeeder::class] as $seeder) {
            if (Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]) !== 0) {
                throw new \RuntimeException('The visual preview permission fixtures could not be prepared.');
            }
        }
        $sites = collect(['Harbour House', 'Kōwhai House', 'Riverside'])->map(fn ($name) => Site::factory()->create(['name' => $name, 'is_active' => true, 'archived' => false]));
        $admin = self::person('Alex Morgan', 'overview@demo.test', 'admin', $sites[0]);
        $admin->hrEmployeeProfile->update(['secondary_site_ids' => $sites->slice(1)->pluck('id')->all()]);
        foreach (['hr.analytics.view', 'hr.recruitment.view', 'hr.onboarding.view', 'hr.compliance.view', 'hr.performance.view', 'it.view', 'it.knowledge.review', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'governance.view', 'governance.meetings.view', 'governance.resolutions.view', 'governance.risks.view', 'governance.compliance.view', 'governance.strategy.view', 'governance.interests.view', 'governance.actions.view', 'progress_notes.viewAny', 'progress_notes.create'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $admin->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $day = CarbonImmutable::now('Pacific/Auckland')->startOfDay();
        $workers = collect();
        foreach ($sites as $index => $site) {
            $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Example', 'last_name' => 'Resident '.($index + 1)]);
            $client->supportWorkers()->attach($admin->id);
            foreach (['Morning' => ['07:00', '15:00'], 'Afternoon' => ['15:00', '23:00']] as $name => [$start, $end]) {
                SiteCoverageRequirement::create(['site_id' => $site->id, 'name' => $name.' support', 'coverage_type' => 'custom', 'day_of_week' => 'sun', 'starts_time' => $start, 'ends_time' => $end, 'minimum_staff' => 2, 'role_requirements' => [], 'allow_overstaffing' => true, 'is_active' => true]);
            }
            foreach (range(0, 2) as $number) {
                $names = [['Sam Taylor', 'Jordan Lee', 'Casey Green'], ['Mia Walker', 'Leo Wilson', 'Zoe Parker'], ['Ava Brown', 'Noah Clarke', 'Ruby James']];
                $worker = self::person($names[$index][$number], 'worker'.$index.$number.'@demo.test', 'support_worker', $site);
                $workers->push($worker);
                $owner = $index === 0 && $number === 0 ? $admin : $worker;
                $hour = $number === 2 ? 15 : 7;
                $shift = Shift::factory()->published()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $owner->id, 'created_by' => $admin->id, 'starts_at' => $day->addHours($hour)->utc(), 'ends_at' => $day->addHours($hour + 8)->utc(), 'status' => $hour === 7 ? 'in_progress' : 'scheduled', 'actual_starts_at' => $hour === 7 ? $day->addHours($hour)->utc() : null]);
                foreach (['Morning check-in', 'Daily routines', 'Plan the afternoon', 'Complete daily notes'] as $task => $label) {
                    $shift->tasks()->create(['label' => $label, 'task_scope' => 'site', 'is_completed' => $task < 2, 'help_status' => $task === 2 ? 'requested' : null]);
                }
                if ($number === 0) {
                    $incoming = Shift::factory()->published()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $worker->id, 'starts_at' => $day->addHours(15)->utc(), 'ends_at' => $day->addHours(23)->utc()]);
                    ShiftHandover::factory()->create(['outgoing_shift_id' => $shift->id, 'incoming_shift_id' => $incoming->id, 'outgoing_staff_id' => $owner->id, 'incoming_staff_id' => $worker->id, 'client_id' => $client->id, 'handover_notes' => 'Fictional example handover.']);
                }
                HrSupervisionNote::create(['employee_user_id' => $worker->id, 'supervisor_user_id' => $admin->id, 'session_date' => $day->subMonth()->toDateString(), 'next_session_date' => $day->addDays($number + $index)->toDateString(), 'topics_discussed' => 'Fictional example supervision.']);
            }
            foreach (range(-6, 3) as $offset) {
                $historyOwner = $index === 0 ? $admin : $workers[$index * 3];
                $start = $day->addDays($offset)->addHours($offset === 0 ? 1 : 7)->utc();
                $shift = Shift::factory()->published()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $historyOwner->id, 'starts_at' => $start, 'ends_at' => $start->addHours(4), 'status' => $offset <= 0 ? 'completed' : 'scheduled', 'actual_starts_at' => $offset <= 0 ? $start : null, 'actual_ends_at' => $offset <= 0 ? $start->addHours(4) : null]);
                if ($offset <= 0) {
                    $attendance = HrAttendanceSession::create(['user_id' => $historyOwner->id, 'shift_id' => $shift->id, 'site_id' => $site->id, 'clock_in_at' => $start, 'clock_out_at' => $start->addHours(4), 'break_minutes' => 0, 'status' => 'closed', 'source' => 'manual', 'created_by' => $admin->id, 'closed_by' => $historyOwner->id]);
                    Timesheet::factory()->create(['attendance_session_id' => $attendance->id, 'shift_id' => $shift->id, 'user_id' => $historyOwner->id, 'client_id' => $client->id, 'site_id' => $site->id, 'starts_at' => $start, 'ends_at' => $start->addHours(4), 'break_minutes' => 0, 'work_date' => $start->setTimezone('Pacific/Auckland')->toDateString(), 'status' => $offset === 0 ? 'returned' : 'submitted']);
                }
            }
            $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $site->id, 'status' => 'submitted', 'severity' => 'high', 'submitted_at' => now()->subHour()]);
            IncidentFollowup::factory()->create(['client_incident_id' => $incident->id, 'assigned_to_user_id' => $admin->id, 'due_at' => now()->subDay(), 'completed_at' => null]);
            ClientNote::create(['client_id' => $client->id, 'user_id' => $admin->id, 'type' => 'daily_note', 'body' => 'Fictional draft for visual review.', 'is_draft' => true]);
            $asset = Asset::factory()->create(['site_id' => $site->id]);
            FleetWorkOrder::factory()->create(['asset_id' => $asset->id, 'priority' => $index === 0 ? 'urgent' : 'medium', 'status' => 'open']);
            self::devices($site, $index);
        }
        $requirement = HrComplianceRequirement::create(['code' => 'PROOF-TRAINING', 'name' => 'Core support skills', 'category' => 'training', 'check_type' => 'manual', 'is_active' => true, 'hard_stop' => false]);
        $requirement->matrixEntries()->create(['role' => 'support_worker', 'site_type' => 'all', 'is_mandatory' => true]);
        foreach ($workers->take(7) as $index => $worker) {
            HrStaffComplianceStatus::create(['user_id' => $worker->id, 'requirement_id' => $requirement->id, 'status' => 'compliant', 'evidence_type' => 'manual', 'expires_at' => $day->addDays($index < 2 ? 12 : 180)]);
        }
        foreach (['new' => 7, 'screening' => 5, 'interview_scheduled' => 3, 'offer_pending' => 2, 'onboarding' => 2, 'hired' => 4] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $candidate = HrCandidate::factory()->create(['status' => $status]);
                HrApplication::factory()->create(['candidate_id' => $candidate->id, 'target_site_id' => $sites[$i % 3]->id]);
            }
        }
        foreach ($sites as $site) {
            HrJobRequisition::create(['title' => 'Support worker — '.$site->name, 'slug' => 'proof-'.$site->id, 'position_role' => 'support_worker', 'site_id' => $site->id, 'employment_type' => 'full_time', 'status' => 'published', 'openings' => 2, 'created_by' => $admin->id]);
        }
        foreach (['Jamie West', 'Harper Scott'] as $i => $name) {
            $joiner = self::person($name, 'joiner'.$i.'@demo.test', 'support_worker', $sites[$i]);
            $joiner->update(['approved_at' => null]);
            $joiner->hrEmployeeProfile->update(['start_date' => $day->addDays($i + 2)]);
            $checklist = HrOnboardingChecklist::create(['employee_profile_id' => $joiner->hrEmployeeProfile->id, 'template_key' => 'standard', 'status' => $i === 0 ? 'completed' : 'in_progress', 'created_by' => $admin->id]);
            $checklist->tasks()->create(['title' => 'Prepare orientation', 'category' => 'hr', 'status' => $i === 0 ? 'completed' : 'pending']);
        }
        $exit = HrOffboardingChecklist::create(['employee_profile_id' => $workers[0]->hrEmployeeProfile->id, 'template_key' => 'standard', 'status' => 'in_progress', 'due_date' => $day->addDays(4)]);
        foreach (['completed', 'pending', 'pending'] as $status) {
            $exit->tasks()->create(['title' => 'Fictional handover step', 'category' => 'other', 'status' => $status]);
        }
        foreach (range(-6, 0) as $offset) {
            for ($i = 0; $i < 3 + abs($offset % 3); $i++) {
                $resolved = $i < 2;
                ItTicket::factory()->create(['title' => ['Shared device access', 'Wi-Fi connection check', 'Printer connection', 'Account assistance', 'Site tablet update'][$i], 'site_id' => $sites[$i % 3]->id, 'requester_user_id' => $workers[$i]->id, 'assigned_to_user_id' => $admin->id, 'priority' => $i === 3 ? 'urgent' : 'normal', 'status' => $resolved ? 'resolved' : 'open', 'next_response_party' => 'it', 'created_at' => $day->addDays($offset)->addHours(8)->utc(), 'first_response_due_at' => $day->addDays($offset)->addHours(10)->utc(), 'first_responded_at' => $resolved ? $day->addDays($offset)->addHours(9)->utc() : null, 'resolved_at' => $resolved ? $day->addDays($offset)->addHours(10)->utc() : null]);
            }
        }
        ItKbArticle::factory()->create(['title' => 'Getting connected at your site', 'status' => 'published', 'audience' => 'all_staff', 'review_due_at' => $day->subDay(), 'body' => 'Fictional knowledge example.']);
        foreach (ItTicket::where('status', 'open')->limit(3)->get() as $index => $ticket) {
            ItChange::create(['ticket_id' => $ticket->id, 'change_type' => 'standard', 'risk_level' => 'low', 'maintenance_starts_at' => $day->addDays($index + 1)->addHours(19)->utc(), 'maintenance_ends_at' => $day->addDays($index + 1)->addHours(20)->utc()]);
        }
        self::governance($admin, $day);
        auth()->setUser($admin->fresh());
        $audit = [];
        foreach (OverviewAccess::ROLES as $role) {
            $overview = app(OverviewData::class)->build($admin->fresh(), $role, 'week', 'all');
            $audit[$role] = ['partial' => $overview['partial'], 'blocks' => collect($overview['blocks'])->map(fn ($block) => ['state' => $block['state'], 'total' => $block['total'] ?? null, 'message' => $block['message'] ?? null])->all()];
        }
        file_put_contents(storage_path('app/overview-proof/library-capabilities.json'), json_encode($audit, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private static function person(string $name, string $email, string $role, Site $site): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role, 'approved_at' => now(), 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::create(['user_id' => $user->id, 'employee_number' => 'PROOF-'.$user->id, 'work_email' => $email, 'position_title' => 'Visual review example', 'position_role' => $role === 'support_worker' ? 'support_worker' : 'staff', 'employment_type' => 'full_time', 'start_date' => today()->subMonth(), 'is_active' => true, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);

        return $user->fresh();
    }

    private static function devices(Site $site, int $index): void
    {
        $device = Device::factory()->itInfrastructure()->create(['name' => $site->name.' gateway', 'status' => 'active', 'health_status' => $index === 1 ? 'warning' : 'healthy', 'last_seen_at' => now()->subMinute()]);
        DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subMonth()]);
        $monitor = Monitor::factory()->create(['device_id' => $device->id, 'current_state' => $index === 1 ? 'degraded' : 'healthy', 'last_observation_at' => now()->subMinute()]);
        for ($hour = 23; $hour >= 0; $hour--) {
            if ($index === 2 && $hour >= 20) {
                continue;
            }
            MonitorObservation::factory()->create(['monitor_id' => $monitor->id, 'state' => $index === 1 && $hour < 3 ? 'degraded' : ($index === 0 && $hour === 7 ? 'failed' : 'healthy'), 'observed_at' => now()->subHours($hour)->subMinute()]);
        }
    }

    private static function governance(User $user, CarbonImmutable $day): void
    {
        $member = BoardMember::create(['user_id' => $user->id, 'board_role' => 'member', 'term_start' => '2026-01-01', 'is_active' => true]);
        BoardMemberInterest::create(['board_member_id' => $member->id, 'recorded_by' => $user->id, 'interest_type' => 'personal', 'entity_name' => 'Example association', 'description' => 'Fictional declaration.', 'nature' => 'Example', 'declared_at' => '2026-10-01', 'is_current' => true]);
        $meeting = GovernanceMeeting::factory()->create(['meeting_type' => 'board', 'title' => 'October board meeting', 'scheduled_at' => $day->addDays(5)->addHours(10)->utc(), 'preread_deadline' => $day->addDays(3)->addHours(17)->utc(), 'created_by' => $user->id, 'location' => 'Example boardroom']);
        foreach (['Quality improvement priorities', 'Approve the annual service plan', 'Review workforce assurance'] as $title) {
            Resolution::create(['governance_meeting_id' => $meeting->id, 'title' => $title, 'context' => 'Fictional decision paper.', 'options' => [], 'status' => 'open', 'deadline' => $day->addDays(4), 'proposed_by' => $user->id, 'proposed_at' => now()]);
        }
        foreach (['Workforce continuity', 'Service resilience'] as $i => $title) {
            RiskRegisterEntry::create(['risk_reference' => 'PROOF-'.($i + 1), 'title' => $title, 'description' => 'Fictional risk example.', 'identified_at' => $day->subMonth(), 'identified_by' => $user->id, 'category' => 'operational', 'likelihood_score' => 4, 'impact_score' => $i === 0 ? 4 : 3, 'control_effectiveness' => 'none', 'appetite_threshold' => 10, 'next_review_date' => $day->addDays(3), 'risk_owner_id' => $user->id, 'status' => 'active']);
        }
        foreach (['Quarterly assurance review', 'Policy review sign-off'] as $title) {
            ComplianceObligation::create(['obligation_title' => $title, 'description' => 'Fictional assurance example.', 'framework' => 'internal', 'frequency' => 'annual', 'due_date' => $day->addDays(3), 'next_due_date' => $day->addDays(3), 'status' => 'due_soon', 'evidence_required' => true, 'evidence_provided' => false, 'owner_id' => $user->id]);
        }
        $plan = StrategicPlan::create(['title' => '2026 service priorities', 'vision_statement' => 'Great everyday support.', 'mission_statement' => 'Fictional example plan.', 'values' => [], 'planning_horizon' => 'annual', 'period_start' => $day->startOfYear(), 'period_end' => $day->endOfYear(), 'status' => 'approved', 'created_by' => $user->id]);
        foreach (['Consistent quality' => 76, 'A supported workforce' => 48, 'Connected services' => 62] as $title => $progress) {
            StrategicGoal::create(['strategic_plan_id' => $plan->id, 'title' => $title, 'description' => 'Fictional example priority.', 'timeframe' => 'annual', 'pillar' => 'safety', 'progress_pct' => $progress, 'status' => 'in_progress', 'lead_executive_id' => $user->id]);
        }
        foreach (['open', 'in_progress', 'complete'] as $i => $status) {
            ActionItem::create(['title' => ['Complete assurance review', 'Confirm service milestones', 'Update the board register'][$i], 'source_type' => 'meeting', 'source_id' => $meeting->id, 'description' => 'Fictional board action.', 'assigned_to' => $user->id, 'created_by' => $user->id, 'due_date' => $day->addDays($i + 1), 'status' => $status, 'priority' => 'medium', 'completed_at' => $status === 'complete' ? now() : null]);
        }
    }
}
