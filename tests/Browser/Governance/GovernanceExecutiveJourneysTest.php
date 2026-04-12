<?php

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\Resolution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

function ensureGovernanceRole(string $roleName, array $permissionKeys): Role
{
    $role = Role::firstOrCreate(
        ['name' => $roleName],
        ['label' => Str::headline($roleName), 'level' => 80, 'type' => 'system']
    );

    foreach ($permissionKeys as $key) {
        $permission = Permission::firstOrCreate(
            ['key' => $key],
            ['description' => Str::headline(str_replace('.', ' ', $key)), 'group' => 'governance']
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    return $role;
}

function createGovernanceBrowserUser(string $roleName, string $boardRole, array $permissionKeys, ?string $email = null): array
{
    $role = ensureGovernanceRole($roleName, $permissionKeys);

    $user = User::factory()->withoutTwoFactor()->create([
        'name' => Str::headline(str_replace('_', ' ', $roleName)) . ' ' . Str::upper(Str::random(4)),
        'email' => $email ?? Str::lower($roleName) . '+' . Str::uuid() . '@test.com',
        'approved_at' => now(),
        'role' => $roleName,
    ]);

    $user->roles()->syncWithoutDetaching([$role->id]);

    $boardMember = BoardMember::create([
        'user_id' => $user->id,
        'board_role' => $boardRole,
        'term_start' => now()->subMonths(6)->toDateString(),
        'term_end' => now()->addYears(2)->toDateString(),
        'is_independent' => true,
        'is_active' => true,
    ]);

    return [$user, $boardMember];
}

function createGovernanceSnapshot(User $capturedBy): DashboardSnapshot
{
    $snapshotData = [
        'period' => [
            'type' => 'month',
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->toDateString(),
        ],
        'widgets' => [
            'financial' => [
                'budget_utilization' => 42.5,
                'variance' => 1.4,
                'budget_total' => 100000,
                'actual_total' => 42500,
                'status' => 'good',
            ],
            'decisions_required' => [
                'count' => 1,
                'overdue' => 0,
                'items' => [
                    ['reference' => 'RES-BOARD-1', 'title' => 'Approve quarterly plan'],
                ],
            ],
        ],
    ];

    return DashboardSnapshot::create([
        'snapshot_data' => $snapshotData,
        'period_type' => 'month',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'checksum' => DashboardSnapshot::generateChecksum($snapshotData),
        'captured_at' => now(),
        'captured_by' => $capturedBy->id,
        'data_freshness' => [],
    ]);
}

test('board member can work through the executive governance journey', function () {
    Storage::disk('local')->put('board-packs/browser-board-pack.pdf', 'browser-board-pack');

    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    [$chairUser, $chair] = createGovernanceBrowserUser('board_chair', 'chair', [
        'governance.view',
        'governance.meetings.view',
        'governance.meetings.manage',
        'governance.packs.view',
        'governance.packs.manage',
    ]);
    [$secretaryUser, $secretary] = createGovernanceBrowserUser('board_secretary', 'secretary', [
        'governance.view',
        'governance.meetings.view',
        'governance.meetings.manage',
        'governance.packs.view',
        'governance.packs.manage',
    ]);
    [$memberUser, $member] = createGovernanceBrowserUser('board_member', 'member', [
        'governance.view',
        'governance.meetings.view',
        'governance.packs.view',
        'governance.interests.view',
        'governance.interests.manage',
        'governance.evaluations.view',
    ]);

    $meeting = GovernanceMeeting::create([
        'meeting_type' => 'full_board',
        'title' => 'Board Journey Meeting ' . Str::upper(Str::random(5)),
        'scheduled_at' => now()->addHour(),
        'duration_minutes' => 90,
        'status' => 'agenda_final',
        'quorum_required' => 50,
        'chair_id' => $chair->id,
        'secretary_id' => $secretary->id,
        'created_by' => $admin->id,
    ]);

    MeetingAgendaItem::create([
        'governance_meeting_id' => $meeting->id,
        'order' => 1,
        'title' => 'Executive dashboard review',
        'description' => 'Review current board cockpit position.',
        'presenter_id' => $chairUser->id,
        'duration_minutes' => 20,
        'item_type' => 'standard',
        'is_confidential' => false,
    ]);

    $snapshot = createGovernanceSnapshot($admin);

    $pack = BoardPack::create([
        'governance_meeting_id' => $meeting->id,
        'dashboard_snapshot_id' => $snapshot->id,
        'document_manifest' => [
            'manifest_sections' => [
                ['id' => 'cover', 'title' => 'Cover & Meeting Overview', 'type' => 'auto', 'included' => true],
                ['id' => 'dashboard', 'title' => 'Executive Dashboard Snapshot', 'type' => 'auto', 'included' => true],
            ],
            'content_sections' => [
                'cover' => ['type' => 'Full Board Meeting', 'date' => now()->toDateString()],
                'dashboard' => ['financial' => ['variance' => 1.4]],
            ],
        ],
        'generated_at' => now(),
        'generated_by' => $admin->id,
        'file_path' => 'board-packs/browser-board-pack.pdf',
        'file_size' => 18,
        'checksum' => hash('sha256', 'browser-board-pack'),
        'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
        'distributed_at' => now(),
        'distributed_to' => [$member->id],
        'download_tracking' => [],
        'read_tracking' => [],
    ]);

    $evaluation = BoardEvaluation::create([
        'title' => 'Board Journey Evaluation ' . Str::upper(Str::random(4)),
        'evaluation_type' => 'board',
        'year' => now()->year,
        'status' => 'open',
        'questions' => [
            ['id' => 1, 'question' => 'Board papers arrive with enough lead time', 'type' => 'rating'],
            ['id' => 2, 'question' => 'What should improve next cycle?', 'type' => 'text'],
        ],
        'created_by' => $admin->id,
        'opened_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($memberUser, $meeting, $evaluation, $pack) {
        $browser->loginAs($memberUser)
            ->visit('/governance/dashboard')
            ->waitFor('@governance-cockpit-heading', 30)
            ->assertSee('Executive & Board Cockpit')
            ->waitFor('@cockpit-open-meeting_readiness', 60)
            ->click('@cockpit-open-meeting_readiness')
            ->waitFor('@meeting-title', 30)
            ->assertSee($meeting->title)
            ->visit('/governance/interests/mine')
            ->waitFor('@declare-interest', 30)
            ->click('@declare-interest')
            ->waitFor('@interest-organization', 10)
            ->type('@interest-organization', 'Acme Governance Advisory')
            ->type('@interest-nature', 'Advisory panel member')
            ->type('@interest-description', 'Board strategy advisory role.')
            ->click('@submit-interest')
            ->waitForText('Board strategy advisory role.', 30)
            ->visit("/governance/evaluations/{$evaluation->id}")
            ->waitFor('@evaluation-title', 30)
            ->click('@rating-0-4')
            ->type('@answer-1', 'Tighter links between packs and board decisions.')
            ->type('@overall-comments', 'The cockpit makes board oversight much easier.')
            ->click('@submit-evaluation-response')
            ->waitForText($evaluation->title, 30)
            ->visit("/governance/packs/{$pack->id}")
            ->waitFor('@pack-heading', 30)
            ->assertSee('Pack distributed')
            ->assertPresent('@download-pack')
            ->waitUsing(15, 500, function () use ($pack) {
                $pack->refresh();

                return count($pack->read_tracking ?? []) === 1;
            });
    });

    expect(\App\Domain\Governance\Models\BoardMemberInterest::query()
        ->where('board_member_id', $member->id)
        ->where('entity_name', 'Acme Governance Advisory')
        ->exists())->toBeTrue();

    expect(\App\Domain\Governance\Models\BoardEvaluationResponse::query()
        ->where('board_evaluation_id', $evaluation->id)
        ->where('board_member_id', $member->id)
        ->whereNotNull('submitted_at')
        ->exists())->toBeTrue();

    $pack->refresh();
    expect($pack->read_tracking)->toHaveCount(1);
});

test('board secretary can prepare a meeting and clear workflow items', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    [$chairUser, $chair] = createGovernanceBrowserUser('board_chair', 'chair', [
        'governance.view',
        'governance.meetings.view',
        'governance.meetings.manage',
        'governance.packs.view',
        'governance.packs.manage',
    ]);
    [$secretaryUser, $secretary] = createGovernanceBrowserUser('board_secretary', 'secretary', [
        'governance.view',
        'governance.meetings.view',
        'governance.meetings.manage',
        'governance.packs.view',
        'governance.packs.manage',
    ]);
    createGovernanceBrowserUser('board_member', 'member', [
        'governance.view',
        'governance.meetings.view',
        'governance.packs.view',
    ]);

    $meeting = GovernanceMeeting::create([
        'meeting_type' => 'full_board',
        'title' => 'Secretary Workflow Meeting ' . Str::upper(Str::random(5)),
        'scheduled_at' => now()->addDays(2),
        'duration_minutes' => 120,
        'status' => 'agenda_final',
        'quorum_required' => 50,
        'chair_id' => $chair->id,
        'secretary_id' => $secretary->id,
        'created_by' => $admin->id,
    ]);

    MeetingAgendaItem::create([
        'governance_meeting_id' => $meeting->id,
        'order' => 1,
        'title' => 'Approve quarterly operating plan',
        'description' => 'Decision paper and dashboard review.',
        'presenter_id' => $chairUser->id,
        'duration_minutes' => 30,
        'item_type' => 'decision',
        'is_confidential' => false,
    ]);

    Resolution::create([
        'governance_meeting_id' => $meeting->id,
        'resolution_reference' => 'RES-' . Str::upper(Str::random(6)),
        'title' => 'Approve quarterly operating plan',
        'context' => 'Quarterly operating plan approval',
        'options' => [],
        'voting_threshold' => 'simple_majority',
        'status' => 'draft',
        'proposed_by' => $admin->id,
        'proposed_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($secretaryUser, $meeting) {
        $browser->loginAs($secretaryUser)
            ->visit("/governance/meetings/{$meeting->id}")
            ->waitFor('@meeting-title', 30)
            ->assertSee($meeting->title)
            ->assertSeeIn('@workflow-status-agenda', 'done')
            ->assertSeeIn('@workflow-status-pack_generated', 'todo')
            ->waitFor('@meeting-tab-attendance', 10)
            ->click('@meeting-tab-attendance')
            ->waitFor('@record-attendance', 10)
            ->click('@record-attendance')
            ->waitFor('@save-attendance', 10)
            ->click('@save-attendance')
            ->waitFor('@workflow-status-quorum', 30)
            ->assertSeeIn('@workflow-status-quorum', 'done')
            ->waitFor('@generate-pack', 30)
            ->click('@generate-pack')
            ->waitFor('@view-pack', 120)
            ->click('@view-pack')
            ->waitFor('@distribute-pack', 120)
            ->click('@distribute-pack')
            ->waitForText('Pack distributed', 60)
            ->visit("/governance/meetings/{$meeting->id}")
            ->waitFor('@workflow-status-pack_generated', 30)
            ->assertSeeIn('@workflow-status-pack_generated', 'done')
            ->assertSeeIn('@workflow-status-pack_distributed', 'done')
            ->assertSeeIn('@workflow-status-quorum', 'done');
    });

    $meeting->refresh();
    $pack = $meeting->boardPack;

    expect($meeting->quorum_met)->toBeTrue();
    expect($pack)->not->toBeNull();
    expect($pack?->distributed_at)->not->toBeNull();
    expect($pack?->distributed_to ?? [])->not->toBeEmpty();
});
