<?php

/** Called only by the guarded fresh-schema bootstrap. No standalone mutation. */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\It\Services\ItTicketApprovalResponsibilityService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

function w06BrowserCreateFixtures(array $context): array
{
    w06BrowserRequire(DB::scalar('SELECT DATABASE()') === $context['database']
        && DB::table('users')->count() === 0 && DB::table('it_tickets')->count() === 0
        && DB::table('it_attachments')->count() === 0 && DB::table('sites')->count() === 0,
        'Only an empty synthetic schema may receive these fixtures.');
    app(RbacSeeder::class)->run();

    return DB::transaction(function () use ($context): array {
        $source = file_get_contents(base_path('tests/e2e/helpers.ts'));
        w06BrowserRequire(preg_match("/password = '([^']+)'/", $source, $match) === 1, 'Existing repository E2E credential convention is unavailable.');
        $password = $match[1];
        unset($source, $match);
        $prefix = 'W06 '.$context['token'];
        $sites = [];
        foreach (['a', 'b', 'c'] as $key) {
            $sites[$key] = Site::factory()->create([
                'name' => $prefix.' synthetic Site '.strtoupper($key), 'type' => 'head_office',
                'address_line_1' => 'Synthetic browser verification', 'city' => 'Auckland', 'region' => 'Auckland',
                'phone' => null, 'email' => null, 'notes' => 'Disposable local verification; no operational assignment.',
                'is_active' => true, 'archived' => false,
            ]);
        }
        $actors = [];
        $grants = [
            'requester' => ['it.request'], 'other' => ['it.request'],
            'tech' => ['it.request', 'it.view', 'it.manage', 'it.viewSensitive'],
            'restricted' => ['it.request', 'it.view', 'it.manage'],
            'cover' => ['it.request', 'it.view', 'it.manage'],
            'absent' => ['it.request', 'it.view', 'it.manage'],
            // Separate synthetic settings administrator; ordinary IT fixtures keep their existing grants.
            'settings' => ['it.request', 'settings.access.manage'],
            'settings_viewer' => ['it.request', 'settings.access.manage'],
            'audit' => ['it.request', 'it.view', 'it.manage', 'audit.viewAny'],
        ];
        foreach ($grants as $key => $keys) {
            $role = Role::query()->create(['name' => 'w06-browser-'.$key, 'label' => $prefix.' '.$key,
                'level' => 10, 'type' => 'custom', 'landing_route' => '/it', 'description' => 'Synthetic browser verification only.']);
            $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
            w06BrowserRequire($permissions->count() === count($keys), 'Required canonical permissions are missing.');
            $role->permissions()->attach($permissions);
            $actor = User::factory()->withoutTwoFactor()->create(['name' => $prefix.' '.$key,
                'email' => 'w06-'.$key.'@demo.test', 'password' => $password, 'role' => 'support_worker',
                'approved_at' => now(), 'email_verified_at' => now(), 'remember_token' => null, 'landing_route_preference' => '/it']);
            $actor->roles()->attach($role->id);
            HrEmployeeProfile::query()->create(['user_id' => $actor->id, 'employee_number' => 'W06-'.$key,
                'work_email' => $actor->email, 'position_title' => 'Synthetic fixture', 'position_role' => 'support_worker',
                'employment_type' => 'casual', 'start_date' => today()->subDay(), 'is_active' => true,
                'primary_site_id' => $sites['a']->id, 'secondary_site_ids' => [$sites[$key === 'other' ? 'c' : 'b']->id],
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $actor = $actor->fresh();
            foreach (['it.request', 'it.view', 'it.manage', 'it.viewSensitive', 'it.organisationWide', 'settings.access.manage', 'audit.viewAny'] as $permission) {
                w06BrowserRequire($actor->canDo($permission) === in_array($permission, $keys, true), 'Synthetic effective grants differ from the declared role.');
            }
            $actors[$key] = $actor;
        }
        unset($password);
        $leave = HrLeaveRequest::query()->create(['user_id' => $actors['absent']->id,
            'leave_type' => 'annual', 'period' => 'full_day', 'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(), 'hours_requested' => 8, 'status' => 'approved',
            'reason' => 'Synthetic approval-cover fixture only; no actual leave request.',
            'submitted_at' => now()->subDays(2), 'reviewed_by' => $actors['tech']->id, 'reviewed_at' => now()->subDay(),
            'review_notes' => 'Seeded disposable scenario; not evidence of an HR approval journey.']);
        $tickets = [];
        foreach (['public_workspace', 'sensitive_participant', 'unapproved_site', 'resolve_workspace', 'approval_workspace', 'approval_history', 'duplicate_workspace'] as $case) {
            $tickets[$case] = ItTicket::createWithReference([
                'title' => $prefix.' '.($case === 'duplicate_workspace' ? 'public_workspace' : $case),
                'description' => 'Synthetic draft verification; no real support request.',
                'requester_user_id' => $actors[$case === 'sensitive_participant' ? 'restricted' : ($case === 'unapproved_site' ? 'other' : 'requester')]->id,
                'site_id' => $sites[$case === 'unapproved_site' ? 'c' : 'a']->id,
                'is_sensitive' => $case === 'sensitive_participant', 'is_organisation_wide' => false,
                'category' => 'other', 'priority' => 'normal', 'impact' => 'individual', 'urgency' => 'normal',
                'source' => 'portal', 'work_type' => 'incident', 'status' => 'open', 'workflow_state' => 'submitted',
                'requires_approval' => in_array($case, ['approval_workspace', 'approval_history'], true),
                'first_response_due_at' => null, 'resolution_due_at' => null,
            ]);
        }
        $historyIds = [];
        for ($index = 0; $index < 12; $index++) {
            $requested = now()->subDays(13 - $index);
            $historical = (new ItTicketApproval([
                'it_ticket_id' => $tickets['approval_history']->id, 'requested_by' => $actors['tech']->id,
                'status' => 'rejected', 'request_reason' => 'Synthetic historical request '.($index + 1),
                'request_reason_recorded_at' => $requested,
                'primary_approver_user_id' => $actors['restricted']->id, 'assignment_recorded_at' => $requested,
                'approver_id' => $actors['restricted']->id, 'decided_at' => $requested->copy()->addHour(),
                'decision_reason' => 'Seeded historical decision for pagination; no operational decision.',
                'decision_reason_recorded_at' => $requested->copy()->addHour(), 'decision_authority' => 'primary',
            ]))->forceFill(['created_at' => $requested]);
            $historical->save();
            $historyIds[] = (int) $historical->id;
        }
        $responsibility = app(ItTicketApprovalResponsibilityService::class);
        w06BrowserRequire($responsibility->eligible((int) $actors['absent']->id, $tickets['approval_workspace']) !== null
            && $responsibility->eligible((int) $actors['absent']->id, $tickets['approval_workspace'], available: true) === null,
            'Synthetic absence must affect availability without changing employment or Site eligibility.');
        $access = app(ItWorkAccessService::class);
        w06BrowserRequire(! $access->canWork($actors['restricted'], $tickets['sensitive_participant'])
            && $access->canView($actors['restricted'], $tickets['sensitive_participant'])
            && ! $access->canView($actors['tech'], $tickets['unapproved_site'])
            && $access->canWork($actors['tech'], $tickets['public_workspace']), 'Synthetic privacy boundaries do not match intended cases.');

        return [
            'sites' => array_map(fn ($site) => (int) $site->id, $sites),
            'actors' => array_map(fn ($actor) => ['id' => (int) $actor->id, 'login' => $actor->email], $actors),
            'permissions' => $grants,
            'tickets' => array_map(fn ($ticket) => ['id' => (int) $ticket->id, 'reference' => $ticket->reference], $tickets),
            'approval_history_ids' => $historyIds,
            'synthetic_approved_leave_id' => (int) $leave->id,
            'retention_is_synthetic_only' => true,
        ];
    });
}
