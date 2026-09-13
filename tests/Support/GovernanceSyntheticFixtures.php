<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GovernanceSyntheticFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function seed(): array
    {
        // 1. Roles and Permissions
        (new RbacSeeder())->run();
        (new GovernancePermissionsSeeder())->run();

        // 2. Sites
        $siteA = Site::query()->firstOrCreate(
            ['name' => 'Governance Site Alpha'],
            ['type' => 'facility', 'is_active' => true, 'archived' => false]
        );
        $siteB = Site::query()->firstOrCreate(
            ['name' => 'Governance Site Beta'],
            ['type' => 'facility', 'is_active' => true, 'archived' => false]
        );

        // 3. Synthetic Users
        $defaultPassword = Hash::make('Secret123!');

        $createUser = function (string $email, string $name, array $permissions = [], ?string $role = null) use ($defaultPassword): User {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $defaultPassword,
                    'approved_at' => now(),
                    'role' => $role ?? 'staff',
                ]
            );

            if ($role) {
                $roleModel = Role::where('name', $role)->first();
                if ($roleModel) {
                    $user->roles()->syncWithoutDetaching([$roleModel->id]);
                }
            }

            if (! empty($permissions)) {
                $permissionIds = Permission::whereIn('key', $permissions)->pluck('id');
                $syncData = [];
                foreach ($permissionIds as $pid) {
                    $syncData[$pid] = ['allowed' => true];
                }
                $user->permissionOverrides()->syncWithoutDetaching($syncData);
            }

            return $user;
        };

        // Personas
        $chairUser = $createUser('gov-chair@synthetic.test', 'Synthetic Chair', [
            'governance.view',
            'governance.meetings.view',
            'governance.meetings.manage',
            'governance.resolutions.view',
            'governance.resolutions.vote',
            'governance.resolutions.manage',
            'governance.packs.view',
            'governance.packs.manage',
            'governance.boardPacks.view',
            'governance.boardPacks.manage',
            'governance.actions.view',
            'governance.actions.manage',
            'governance.executive.view',
            'governance.performance.manage',
        ], 'board_chair');

        $secretaryUser = $createUser('gov-secretary@synthetic.test', 'Synthetic Secretary', [
            'governance.view',
            'governance.meetings.view',
            'governance.meetings.manage',
            'governance.resolutions.view',
            'governance.resolutions.manage',
            'governance.packs.view',
            'governance.packs.manage',
            'governance.boardPacks.view',
            'governance.boardPacks.manage',
            'governance.actions.view',
            'governance.actions.manage',
        ], 'board_secretary');

        $memberUser = $createUser('gov-member@synthetic.test', 'Synthetic Member', [
            'governance.view',
            'governance.meetings.view',
            'governance.resolutions.view',
            'governance.resolutions.vote',
            'governance.packs.view',
            'governance.boardPacks.view',
            'governance.actions.view',
        ], 'board_member');

        // Duplicate name user to test identity matching
        $duplicateNameUser = $createUser('gov-member-dup@synthetic.test', 'Synthetic Member', [
            'governance.view',
            'governance.meetings.view',
            'governance.resolutions.view',
            'governance.packs.view',
            'governance.boardPacks.view',
            'governance.actions.view',
        ], 'board_member');

        $financeUser = $createUser('gov-finance@synthetic.test', 'Synthetic Finance Lead', [
            'governance.view',
            'governance.meetings.view',
            'governance.resolutions.view',
            'governance.resolutions.vote',
            'governance.packs.view',
            'governance.boardPacks.view',
            'governance.actions.view',
            'governance.spend.view',
            'governance.spend.approve',
        ], 'board_member');

        $ceoUser = $createUser('gov-ceo@synthetic.test', 'Synthetic CEO', [
            'governance.view',
            'governance.meetings.view',
            'governance.resolutions.view',
            'governance.packs.view',
            'governance.boardPacks.view',
            'governance.actions.view',
        ], 'ceo');

        $observerUser = $createUser('gov-observer@synthetic.test', 'Synthetic Observer', [
            'governance.view',
            'governance.meetings.view',
            'governance.resolutions.view',
            'governance.packs.view',
            'governance.boardPacks.view',
        ], 'board_observer');

        // 4. Board Members & Committees
        $chairMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $chairUser->id],
            [
                'board_role' => 'chair',
                'term_start' => now()->subYears(2)->toDateString(),
                'term_end' => now()->addYears(2)->toDateString(),
                'is_active' => true,
            ]
        );

        $secretaryMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $secretaryUser->id],
            [
                'board_role' => 'secretary',
                'term_start' => now()->subYears(2)->toDateString(),
                'term_end' => now()->addYears(2)->toDateString(),
                'is_active' => true,
            ]
        );

        $regularMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $memberUser->id],
            [
                'board_role' => 'member',
                'term_start' => now()->subYears(1)->toDateString(),
                'term_end' => now()->addYears(1)->toDateString(),
                'is_active' => true,
            ]
        );

        $dupMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $duplicateNameUser->id],
            [
                'board_role' => 'member',
                'term_start' => now()->subYears(1)->toDateString(),
                'term_end' => now()->addYears(1)->toDateString(),
                'is_active' => true,
            ]
        );

        $financeMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $financeUser->id],
            [
                'board_role' => 'member',
                'term_start' => now()->subYears(1)->toDateString(),
                'term_end' => now()->addYears(1)->toDateString(),
                'is_active' => true,
            ]
        );

        $observerMember = BoardMember::query()->firstOrCreate(
            ['user_id' => $observerUser->id],
            [
                'board_role' => 'observer',
                'term_start' => now()->subYears(1)->toDateString(),
                'term_end' => now()->addYears(1)->toDateString(),
                'is_active' => true,
            ]
        );

        // Committees: Finance Committee (active term) & Governance Committee (expired term)
        $financeCommittee = BoardCommittee::query()->firstOrCreate(
            ['name' => 'Synthetic Finance Committee'],
            [
                'committee_type' => 'finance',
                'description' => 'Oversees budgets, financial performance, and capital spend.',
                'chair_id' => $financeMember->id,
                'is_active' => true,
            ]
        );
        $financeCommittee->members()->syncWithoutDetaching([
            $financeMember->id => ['role' => 'chair', 'appointed_at' => now()->subMonths(6)->toDateString(), 'term_end' => now()->addMonths(6)->toDateString(), 'is_active' => true],
            $chairMember->id => ['role' => 'member', 'appointed_at' => now()->subMonths(6)->toDateString(), 'term_end' => now()->addMonths(6)->toDateString(), 'is_active' => true],
        ]);

        $auditCommittee = BoardCommittee::query()->firstOrCreate(
            ['name' => 'Synthetic Audit & Risk Committee'],
            [
                'committee_type' => 'audit_risk',
                'description' => 'Expired terms test committee',
                'chair_id' => $regularMember->id,
                'is_active' => true,
            ]
        );
        $auditCommittee->members()->syncWithoutDetaching([
            $regularMember->id => ['role' => 'member', 'appointed_at' => now()->subYears(2)->toDateString(), 'term_end' => now()->subMonths(1)->toDateString(), 'is_active' => false],
        ]);

        // 5. Meetings:
        // A private executive session earlier than normal meeting (GOV-F01 test)
        $privateMeeting = GovernanceMeeting::query()->firstOrCreate(
            ['title' => 'Synthetic Confidential Executive Session'],
            [
                'meeting_type' => 'executive_session',
                'scheduled_at' => now()->addDays(3)->setTime(9, 0),
                'duration_minutes' => 60,
                'location' => 'Boardroom (Confidential)',
                'status' => 'scheduled',
                'chair_id' => $chairMember->id,
                'secretary_id' => $secretaryMember->id,
                'created_by' => $chairUser->id,
            ]
        );

        // A regular board meeting (Next meeting for ordinary members)
        $regularMeeting = GovernanceMeeting::query()->firstOrCreate(
            ['title' => 'Synthetic Ordinary Board Meeting Q3'],
            [
                'meeting_type' => 'board',
                'scheduled_at' => now()->addDays(7)->setTime(10, 0),
                'duration_minutes' => 150,
                'location' => 'Main Office & Remote',
                'status' => 'scheduled',
                'chair_id' => $chairMember->id,
                'secretary_id' => $secretaryMember->id,
                'created_by' => $secretaryUser->id,
            ]
        );

        // Board packs for regular meeting (published version)
        $snapshot = DashboardSnapshot::query()->firstOrCreate(
            ['checksum' => hash('sha256', 'synthetic snapshot')],
            [
                'snapshot_data' => ['period' => 'month', 'widgets' => []],
                'period_type' => 'month',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'captured_at' => now()->subDays(2),
                'captured_by' => $secretaryUser->id,
                'data_freshness' => [],
            ]
        );

        $pack = BoardPack::query()->firstOrCreate(
            ['governance_meeting_id' => $regularMeeting->id, 'file_path' => 'governance/packs/synthetic-pack-v1.pdf'],
            [
                'dashboard_snapshot_id' => $snapshot->id,
                'document_manifest' => ['agenda' => true, 'reports' => []],
                'generated_at' => now()->subDays(2),
                'generated_by' => $secretaryUser->id,
                'checksum' => hash('sha256', 'synthetic pack content v1'),
                'distributed_at' => now()->subDays(2),
                'distributed_to' => [$regularMember->id, $chairMember->id, $financeMember->id],
                'build_status' => 'published',
                'is_current' => true,
            ]
        );

        // 6. Decisions / Resolutions
        // Draft decision
        $draftRes = Resolution::query()->firstOrCreate(
            ['title' => 'Synthetic Resolution: Facility Maintenance Policy'],
            [
                'governance_meeting_id' => $regularMeeting->id,
                'status' => 'draft',
                'decision_type' => 'resolution',
                'context' => 'Facility maintenance standard drafting and review.',
                'options' => [
                    ['label' => 'Adopt Standard', 'description' => 'Adopt the new facilities maintenance standard.'],
                    ['label' => 'Retain Existing', 'description' => 'Continue using existing site-by-site maintenance processes.'],
                ],
                'recommendation' => 'Adopt the proposed standard for uniform quality.',
                'proposed_by' => $memberUser->id,
                'proposed_at' => now()->subDays(3),
            ]
        );

        // Open decision awaiting vote with valid deadline
        $openRes = Resolution::query()->firstOrCreate(
            ['title' => 'Synthetic Resolution: 2027 Capital Budget Allocation'],
            [
                'governance_meeting_id' => $regularMeeting->id,
                'status' => 'open',
                'decision_type' => 'budget_approval',
                'deadline' => now()->addDays(5),
                'context' => 'Annual capital expenditure planning for residential site upgrades.',
                'options' => [
                    ['label' => 'Approve Envelope', 'description' => 'Approve full $500k capital budget envelope.'],
                    ['label' => 'Defer Review', 'description' => 'Request revision with phased milestones.'],
                ],
                'recommendation' => 'The Finance Committee recommends approval based on projected funding.',
                'proposed_by' => $chairUser->id,
                'proposed_at' => now()->subDays(2),
                'opened_at' => now()->subDay(),
            ]
        );

        // Expired decision
        $expiredRes = Resolution::query()->firstOrCreate(
            ['title' => 'Synthetic Resolution: Expired Fleet Replacement'],
            [
                'governance_meeting_id' => $regularMeeting->id,
                'status' => 'open',
                'decision_type' => 'resolution',
                'deadline' => now()->subDay(),
                'context' => 'Fleet vehicle replacement proposal.',
                'options' => [
                    ['label' => 'Approve Purchase', 'description' => 'Replace 3 operational vans.'],
                    ['label' => 'Extend Lease', 'description' => 'Extend existing vehicle leases.'],
                ],
                'proposed_by' => $chairUser->id,
                'proposed_at' => now()->subDays(10),
                'opened_at' => now()->subDays(5),
            ]
        );

        // Null-deadline decision
        $nullDeadlineRes = Resolution::query()->firstOrCreate(
            ['title' => 'Synthetic Resolution: Null Deadline Governance Policy'],
            [
                'governance_meeting_id' => $regularMeeting->id,
                'status' => 'open',
                'decision_type' => 'policy_change',
                'deadline' => null,
                'context' => 'Governance policy review and amendment.',
                'options' => [
                    ['label' => 'Approve Policy', 'description' => 'Adopt the revised policy.'],
                    ['label' => 'Reject Policy', 'description' => 'Do not adopt revisions.'],
                ],
                'proposed_by' => $secretaryUser->id,
                'proposed_at' => now()->subDays(2),
                'opened_at' => now()->subHours(12),
            ]
        );

        // 7. Actions: 40 actions including same-name owner, last-page personal work, blocked overdue
        for ($i = 1; $i <= 40; $i++) {
            $ref = sprintf('ACT-SYN-%03d', $i);
            $assignee = match (true) {
                $i === 1 => $memberUser,
                $i === 2 => $duplicateNameUser,
                $i === 30 => $memberUser,
                $i % 4 === 0 => $memberUser,
                $i % 4 === 1 => $chairUser,
                $i % 4 === 2 => $secretaryUser,
                default => $financeUser,
            };

            $status = match (true) {
                $i === 1 => 'blocked',
                $i === 30 => 'in_progress',
                $i % 5 === 0 => 'complete',
                default => 'in_progress',
            };

            $dueDate = match (true) {
                $i === 1 => now()->subDays(5)->toDateString(),
                $i % 2 === 0 => now()->addDays($i)->toDateString(),
                default => now()->subDays($i % 10)->toDateString(),
            };

            ActionItem::query()->firstOrCreate(
                ['action_reference' => $ref],
                [
                    'source_type' => 'governance_meeting',
                    'source_id' => $regularMeeting->id,
                    'description' => "Detailed action item #{$i} for governance tracking.",
                    'assigned_to' => $assignee->id,
                    'created_by' => $chairUser->id,
                    'due_date' => $dueDate,
                    'status' => $status,
                    'priority' => $i % 3 === 0 ? 'high' : 'medium',
                    'progress_pct' => $status === 'complete' ? 100 : ($i * 2) % 90,
                    'blocked_reason' => $i === 1 ? 'Awaiting external supply chain quote.' : null,
                    'blocked_at' => $i === 1 ? now()->subDays(3) : null,
                ]
            );
        }

        // 8. Risks: 12 above-appetite risks using valid scoring inputs
        for ($r = 1; $r <= 12; $r++) {
            $ref = sprintf('R-SYN-%03d', $r);
            RiskRegisterEntry::query()->firstOrCreate(
                ['risk_reference' => $ref],
                [
                    'title' => "Synthetic Above-Appetite Risk #{$r}",
                    'description' => "Critical operational risk #{$r} requiring board oversight.",
                    'category' => 'operational',
                    'likelihood_score' => 5,
                    'impact_score' => 5, // inherent 25
                    'control_effectiveness' => 'none', // residual 25
                    'appetite_threshold' => 10, // residual 25 > 10 => within_appetite = false
                    'risk_owner_id' => $chairUser->id,
                    'status' => 'open',
                    'next_review_date' => now()->addDays(14)->toDateString(),
                    'identified_at' => now()->subDays(30)->toDateString(),
                    'identified_by' => $chairUser->id,
                ]
            );
        }

        // 9. CEO Raw Performance Review
        $perfReview = PerformanceReview::query()->firstOrCreate(
            ['reviewee_id' => $ceoUser->id, 'review_cycle' => '2026-Annual'],
            [
                'review_type' => 'annual',
                'period_start' => now()->startOfYear()->toDateString(),
                'period_end' => now()->endOfYear()->toDateString(),
                'status' => 'self_review',
                'self_assessment' => 'Synthetic CEO self-assessment notes and operational reflections.',
                'created_by' => $chairUser->id,
            ]
        );

        // 10. Scoped Spend Approvals on Site A & Site B
        $spendService = app(SpendApprovalCommandService::class);
        $spendA = SpendApproval::query()->where('site_id', $siteA->id)->where('description', 'Site A synthetic OPEX')->first();
        if (! $spendA) {
            $spendA = $spendService->create($chairUser, [
                'title' => 'Synthetic Site A OPEX',
                'description' => 'Site A synthetic OPEX',
                'category' => SpendApproval::CATEGORY_OPEX,
                'amount' => '150.00',
                'currency' => 'NZD',
                'site_id' => $siteA->id,
            ]);
        }

        $spendB = SpendApproval::query()->where('site_id', $siteB->id)->where('description', 'Site B synthetic CAPEX')->first();
        if (! $spendB) {
            $spendB = $spendService->create($chairUser, [
                'title' => 'Synthetic Site B CAPEX',
                'description' => 'Site B synthetic CAPEX',
                'category' => SpendApproval::CATEGORY_CAPEX,
                'amount' => '350.00',
                'currency' => 'NZD',
                'site_id' => $siteB->id,
            ]);
        }

        return [
            'sites' => [
                'alpha' => $siteA->id,
                'beta' => $siteB->id,
            ],
            'users' => [
                'chair' => $chairUser->id,
                'secretary' => $secretaryUser->id,
                'member' => $memberUser->id,
                'member_duplicate_name' => $duplicateNameUser->id,
                'finance' => $financeUser->id,
                'ceo' => $ceoUser->id,
                'observer' => $observerUser->id,
            ],
            'board_members' => [
                'chair' => $chairMember->id,
                'secretary' => $secretaryMember->id,
                'member' => $regularMember->id,
                'finance' => $financeMember->id,
                'observer' => $observerMember->id,
            ],
            'meetings' => [
                'private' => $privateMeeting->id,
                'regular' => $regularMeeting->id,
            ],
            'resolutions' => [
                'draft' => $draftRes->id,
                'open' => $openRes->id,
                'expired' => $expiredRes->id,
                'null_deadline' => $nullDeadlineRes->id,
            ],
            'pack' => $pack->id,
            'performance_review' => $perfReview->id,
        ];
    }
}
