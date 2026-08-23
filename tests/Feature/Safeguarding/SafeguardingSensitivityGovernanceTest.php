<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingDeclassificationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\Safeguarding\SafeguardingSensitivityService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class SafeguardingSensitivityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->siteA = Site::factory()->create(['name' => 'SAFE sensitivity Site A']);
        $this->siteB = Site::factory()->create(['name' => 'SAFE sensitivity Site B']);
    }

    public function test_ordinary_update_authority_can_restrict_but_cannot_directly_declassify(): void
    {
        $updater = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => $this->siteA->id,
            'is_sensitive' => false,
        ]);

        $this->actingAs($updater)
            ->post("/safeguarding/{$concern->id}/sensitivity", ['is_sensitive' => true])
            ->assertRedirect();

        $concern->refresh();
        $this->assertTrue($concern->is_sensitive);
        $this->assertSame(1, $concern->sensitivity_version);

        $this->actingAs($updater)
            ->post("/safeguarding/{$concern->id}/sensitivity", ['is_sensitive' => false])
            ->assertForbidden();

        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertSame(1, $concern->fresh()->sensitivity_version);
        $this->assertDatabaseCount('safeguarding_declassification_reviews', 0);
    }

    public function test_request_requires_reason_and_acknowledged_server_preview_and_is_replay_safe(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $this->siteUser($this->siteA, ['safeguarding.viewAny']);
        $concern = $this->sensitiveConcern($requester);
        $preview = app(SafeguardingSensitivityService::class)->audiencePreview($concern);
        $replayKey = (string) Str::uuid();

        $this->actingAs($requester)
            ->post("/safeguarding/{$concern->id}/declassification-requests", [
                'reason' => 'Too short',
                'audience_acknowledged' => false,
                'audience_preview_hash' => $preview['hash'],
                'expected_sensitivity_version' => $concern->sensitivity_version,
                'idempotency_key' => $replayKey,
            ])
            ->assertSessionHasErrors(['reason', 'audience_acknowledged']);
        $this->assertDatabaseCount('safeguarding_declassification_reviews', 0);

        $payload = $this->requestPayload($concern, $preview, $replayKey);
        $this->actingAs($requester)
            ->post("/safeguarding/{$concern->id}/declassification-requests", $payload)
            ->assertRedirect();
        $this->actingAs($requester)
            ->post("/safeguarding/{$concern->id}/declassification-requests", $payload)
            ->assertRedirect();

        $review = SafeguardingDeclassificationReview::query()->sole();
        $this->assertSame(SafeguardingDeclassificationReview::STATUS_PENDING, $review->status);
        $this->assertSame($this->siteA->id, $review->site_id);
        $this->assertSame($concern->sensitivity_version, $review->concern_sensitivity_version);
        $this->assertSame(
            $concern->updated_at?->format('Y-m-d H:i:s'),
            $review->concern_updated_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame($requester->id, $review->requested_by_user_id);
        $this->assertSame(1, $review->audience_snapshot['newly_visible_staff_count']);
        $this->assertArrayHasKey('audience_fingerprint', $review->audience_snapshot);
        $this->assertSame($preview['hash'], $review->audience_hash);
        $this->assertSame($review->content_hash, $review->calculateContentHash());
        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'safeguarding.declassification.requested')
            ->where('auditable_id', $concern->id)
            ->count());
    }

    public function test_only_a_distinct_site_authority_can_approve_and_redaction_holds_until_then(): void
    {
        $requester = $this->siteUser($this->siteA, [
            'safeguarding.viewAny',
            'safeguarding.update',
            'safeguarding.declassification.approve',
        ]);
        $ordinaryUpdater = $this->siteUser($this->siteA, [
            'safeguarding.viewAny',
            'safeguarding.update',
        ]);
        $expandedViewer = $this->siteUser($this->siteA, ['safeguarding.viewAny']);
        $siteApprover = $this->siteUser($this->siteA, [
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
        ]);
        $concern = $this->sensitiveConcern($requester, 'Protected Person');
        $review = $this->requestReview($concern, $requester);

        $this->actingAs($expandedViewer)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.restricted', true)
                ->missing('detail.people'));

        $decisionPayload = [
            'decision_reason' => 'The recorded review confirms wider access is necessary and proportionate.',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($siteApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", [
                'decision_reason' => 'Too short',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('decision_reason');
        $this->actingAs($ordinaryUpdater)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", $decisionPayload)
            ->assertForbidden();
        $this->actingAs($requester)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", $decisionPayload)
            ->assertUnprocessable();
        $this->assertTrue($concern->fresh()->is_sensitive);

        $this->actingAs($siteApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", $decisionPayload)
            ->assertRedirect();
        $this->actingAs($siteApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", $decisionPayload)
            ->assertRedirect();

        $concern->refresh();
        $review->refresh();
        $this->assertFalse($concern->is_sensitive);
        $this->assertSame(2, $concern->sensitivity_version);
        $this->assertSame(SafeguardingDeclassificationReview::STATUS_APPROVED, $review->status);
        $this->assertSame($siteApprover->id, $review->reviewed_by_user_id);
        $this->assertSame($decisionPayload['decision_reason'], $review->decision_reason);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'safeguarding.declassification.approved')
            ->where('auditable_id', $concern->id)
            ->count());

        $this->actingAs($siteApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", [
                ...$decisionPayload,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertConflict();

        $this->actingAs($expandedViewer)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.restricted', false)
                ->where('detail.people.subject.name', 'Protected Person'));
    }

    public function test_global_site_scope_is_separate_from_declassification_authority(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $siteApprover = $this->siteUser($this->siteA, [
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
        ]);
        $globalApprover = $this->userWith([
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
            'reports.viewAny',
        ]);
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => null,
            'is_sensitive' => true,
            'status' => 'reported',
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => 'Organisation-wide protected subject',
            'related_incident_id' => null,
            'assigned_to_user_id' => $requester->id,
            'reported_by_user_id' => User::factory()->create()->id,
        ]);
        $review = $this->requestReview($concern, $requester);

        $this->actingAs($siteApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", [
                'decision_reason' => 'A Site-only authority must not decide organisation-wide disclosure.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->actingAs($globalApprover)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", [
                'decision_reason' => 'The explicit global reviewer confirms wider access is proportionate.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->assertFalse($concern->fresh()->is_sensitive);
        $this->assertSame($globalApprover->id, $review->fresh()->reviewed_by_user_id);
    }

    public function test_decision_replay_key_cannot_be_reused_for_a_different_review(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $approver = $this->siteUser($this->siteA, [
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
        ]);
        $firstConcern = $this->sensitiveConcern($requester, 'First protected subject');
        $secondConcern = $this->sensitiveConcern($requester, 'Second protected subject');
        $firstReview = $this->requestReview($firstConcern, $requester);
        $secondReview = $this->requestReview($secondConcern, $requester);
        $decisionPayload = [
            'decision_reason' => 'The first recorded review supports proportionate wider access.',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($approver)
            ->post(
                "/safeguarding/{$firstConcern->id}/declassification-reviews/{$firstReview->id}/approve",
                $decisionPayload,
            )
            ->assertRedirect();

        $this->actingAs($approver)
            ->post(
                "/safeguarding/{$secondConcern->id}/declassification-reviews/{$secondReview->id}/approve",
                $decisionPayload,
            )
            ->assertConflict();

        $this->assertFalse($firstConcern->fresh()->is_sensitive);
        $this->assertTrue($secondConcern->fresh()->is_sensitive);
        $this->assertSame(
            SafeguardingDeclassificationReview::STATUS_PENDING,
            $secondReview->fresh()->status,
        );
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'safeguarding.declassification.approved')
            ->count());
    }

    public function test_wrong_site_and_cross_parent_direct_ids_are_concealed_without_side_effects(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $wrongSiteUpdater = $this->siteUser($this->siteB, ['safeguarding.viewAny', 'safeguarding.update']);
        $wrongSiteApprover = $this->siteUser($this->siteB, [
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
        ]);
        $globalApprover = $this->userWith([
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
            'reports.viewAny',
        ]);
        $concern = $this->sensitiveConcern($requester);

        $this->actingAs($wrongSiteUpdater)
            ->post("/safeguarding/{$concern->id}/declassification-requests", ['reason' => 'invalid'])
            ->assertNotFound();
        $this->actingAs($wrongSiteUpdater)
            ->post("/safeguarding/{$concern->id}/sensitivity", ['is_sensitive' => false])
            ->assertNotFound();
        $this->assertDatabaseCount('safeguarding_declassification_reviews', 0);

        $review = $this->requestReview($concern, $requester);
        $this->actingAs($wrongSiteApprover)
            ->post(
                "/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve",
                ['decision_reason' => 'invalid'],
            )
            ->assertNotFound();

        $otherConcern = SafeguardingConcern::factory()->create([
            'site_id' => $this->siteB->id,
            'is_sensitive' => true,
        ]);
        $this->actingAs($globalApprover)
            ->post("/safeguarding/{$otherConcern->id}/declassification-reviews/{$review->id}/approve", [
                'decision_reason' => 'This forged parent relationship must never be accepted.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertSame(SafeguardingDeclassificationReview::STATUS_PENDING, $review->fresh()->status);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'safeguarding.declassification.approved')
            ->count());
    }

    public function test_audience_or_concern_drift_blocks_approval_while_rejection_allows_a_fresh_request(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $originalViewer = $this->siteUser($this->siteA, ['safeguarding.viewAny']);
        $approver = $this->userWith([
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
            'reports.viewAny',
        ]);
        $concern = $this->sensitiveConcern($requester);
        $review = $this->requestReview($concern, $requester);

        $viewPermission = Permission::query()->where('key', 'safeguarding.viewAny')->sole();
        $originalViewer->permissionOverrides()->updateExistingPivot($viewPermission->id, ['allowed' => false]);
        $this->siteUser($this->siteA, ['safeguarding.viewAny']);
        $currentPreview = app(SafeguardingSensitivityService::class)->audiencePreview($concern);
        $this->assertSame(1, $review->audience_snapshot['newly_visible_staff_count']);
        $this->assertSame(1, $currentPreview['newly_visible_staff_count']);
        $this->assertNotSame($review->audience_hash, $currentPreview['hash']);

        $this->actingAs($approver)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/approve", [
                'decision_reason' => 'Approval attempted after the audience changed materially.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertConflict();
        $this->assertTrue($concern->fresh()->is_sensitive);

        $this->actingAs($approver)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$review->id}/reject", [
                'decision_reason' => 'The expanded audience changed and requires a fresh request.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect();
        $this->assertSame(SafeguardingDeclassificationReview::STATUS_REJECTED, $review->fresh()->status);

        $newPreview = app(SafeguardingSensitivityService::class)->audiencePreview($concern->fresh());
        $this->actingAs($requester)
            ->post(
                "/safeguarding/{$concern->id}/declassification-requests",
                $this->requestPayload($concern->fresh(), $newPreview),
            )
            ->assertRedirect();
        $this->assertSame(2, SafeguardingDeclassificationReview::query()->count());

        $freshReview = SafeguardingDeclassificationReview::query()
            ->where('status', SafeguardingDeclassificationReview::STATUS_PENDING)
            ->sole();
        $concern->update(['description' => 'The allegation changed after the review request was recorded.']);
        $this->assertSame(2, $concern->fresh()->sensitivity_version);

        $this->actingAs($approver)
            ->post("/safeguarding/{$concern->id}/declassification-reviews/{$freshReview->id}/approve", [
                'decision_reason' => 'Approval must not apply to changed safeguarding content.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertConflict();
        $this->assertTrue($concern->fresh()->is_sensitive);
    }

    public function test_foreign_person_provenance_cannot_be_declassified(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $foreignClient = Client::factory()->create(['site_id' => $this->siteB->id]);
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => $this->siteA->id,
            'is_sensitive' => true,
            'subject_type' => Client::class,
            'subject_id' => $foreignClient->id,
            'assigned_to_user_id' => $requester->id,
        ]);
        $preview = app(SafeguardingSensitivityService::class)->audiencePreview($concern);

        $this->actingAs($requester)
            ->post(
                "/safeguarding/{$concern->id}/declassification-requests",
                $this->requestPayload($concern, $preview),
            )
            ->assertConflict();
        $this->assertDatabaseMissing('safeguarding_declassification_reviews', [
            'safeguarding_concern_id' => $concern->id,
        ]);
    }

    public function test_request_and_concern_guards_block_provenance_rewrite_or_bypass(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $concern = $this->sensitiveConcern($requester);
        $review = $this->requestReview($concern, $requester);
        $originalReason = $review->reason;

        try {
            $review->update(['reason' => 'A silently rewritten declassification reason.']);
            $this->fail('Expected the model immutability guard to reject the rewrite.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('provenance is immutable', $exception->getMessage());
        }

        try {
            $concern->forceFill([
                'is_sensitive' => false,
                'sensitivity_version' => 2,
                'updated_by' => $requester->id,
            ])->save();
            $this->fail('Expected the model to reject direct declassification.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('matching governed approval', $exception->getMessage());
        }

        $this->assertSame('mysql', DB::getDriverName());
        try {
            DB::table('safeguarding_concerns')
                ->where('id', $concern->id)
                ->update([
                    'is_sensitive' => false,
                    'sensitivity_version' => 2,
                    'updated_by' => $requester->id,
                ]);
            $this->fail('Expected the database guard to reject direct declassification.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('matching governed approval', $exception->getMessage());
        }

        try {
            DB::table('safeguarding_declassification_reviews')
                ->where('id', $review->id)
                ->update(['reason' => 'Direct SQL rewrite of governed provenance.']);
            $this->fail('Expected the database immutability trigger to reject the rewrite.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('provenance is immutable', $exception->getMessage());
        }

        try {
            DB::table('safeguarding_declassification_reviews')->where('id', $review->id)->delete();
            $this->fail('Expected the database immutability trigger to reject deletion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('provenance cannot be deleted', $exception->getMessage());
        }

        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertSame($originalReason, $review->fresh()->reason);
    }

    public function test_detail_exposes_preview_and_pending_review_without_private_fingerprint(): void
    {
        $requester = $this->siteUser($this->siteA, ['safeguarding.viewAny', 'safeguarding.update']);
        $this->siteUser($this->siteA, ['safeguarding.viewAny']);
        $concern = $this->sensitiveConcern($requester);
        $review = $this->requestReview($concern, $requester);

        $this->actingAs($requester)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.is_sensitive', true)
                ->where('detail.sensitivity_version', 1)
                ->where('detail.declassification.audience_preview.newly_visible_staff_count', 1)
                ->where('detail.declassification.pending_request.id', $review->id)
                ->where('detail.declassification.pending_request.reason', $review->reason)
                ->where('detail.declassification.pending_request.can_approve', false)
                ->missing('detail.declassification.audience_preview.audience_fingerprint')
                ->missing('detail.declassification.pending_request.audience_snapshot.audience_fingerprint')
                ->where('detail.can.request_declassification', true)
                ->where('detail.can.approve_declassification', false));
    }

    public function test_rbac_keeps_declassification_authority_distinct_from_broad_admin_access(): void
    {
        $admin = User::factory()->create(['approved_at' => now()]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->sole()->id);
        $compliance = User::factory()->create(['approved_at' => now()]);
        $compliance->roles()->attach(Role::query()->where('name', 'compliance_lead')->sole()->id);

        $this->assertTrue($admin->canDo('safeguarding.update'));
        $this->assertFalse($admin->canDo('safeguarding.declassification.approve'));
        $this->assertTrue($compliance->canDo('safeguarding.viewSensitive'));
        $this->assertTrue($compliance->canDo('safeguarding.declassification.approve'));
    }

    /** @param array<int, string> $permissions */
    private function siteUser(Site $site, array $permissions): User
    {
        $user = $this->userWith($permissions);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissionIds = collect($permissions)->map(function (string $key): int {
            return Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'description' => str_replace('.', ' ', $key),
                    'group' => explode('.', $key)[0],
                    'module' => 'Compliance',
                ],
            )->id;
        });
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]]),
        );

        return $user;
    }

    private function sensitiveConcern(User $requester, string $subjectName = 'Protected Subject'): SafeguardingConcern
    {
        return SafeguardingConcern::factory()->create([
            'site_id' => $this->siteA->id,
            'is_sensitive' => true,
            'subject_type' => null,
            'subject_id' => null,
            'subject_name' => $subjectName,
            'status' => 'reported',
            'assigned_to_user_id' => $requester->id,
            'reported_by_user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function requestPayload(
        SafeguardingConcern $concern,
        array $preview,
        ?string $replayKey = null,
    ): array {
        return [
            'reason' => 'The allegation has been reviewed and wider operational access is now necessary.',
            'audience_acknowledged' => true,
            'audience_preview_hash' => $preview['hash'],
            'expected_sensitivity_version' => $concern->sensitivity_version,
            'idempotency_key' => $replayKey ?? (string) Str::uuid(),
        ];
    }

    private function requestReview(
        SafeguardingConcern $concern,
        User $requester,
    ): SafeguardingDeclassificationReview {
        $preview = app(SafeguardingSensitivityService::class)->audiencePreview($concern);
        $this->actingAs($requester)
            ->post(
                "/safeguarding/{$concern->id}/declassification-requests",
                $this->requestPayload($concern, $preview),
            )
            ->assertRedirect();

        return SafeguardingDeclassificationReview::query()
            ->where('safeguarding_concern_id', $concern->id)
            ->where('status', SafeguardingDeclassificationReview::STATUS_PENDING)
            ->sole();
    }
}
