<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Medication Reviews page resolves the active site's brand colour,
 * surfaces the deprescribing pipeline from completed reviews' actions[], persists
 * the Drug Burden Index on completion, and advances a recommendation's lifecycle.
 */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function seedReviews(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return compact('user', 'site', 'client');
    }

    public function test_page_serves_brand_colour_and_deprescribing_pipeline(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedReviews();

        MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'comprehensive', 'status' => 'completed',
            'scheduled_date' => now()->subWeek()->toDateString(), 'completed_date' => now()->toDateString(),
            'clinical_summary' => 'Polypharmacy reduced.',
            'actions' => [
                ['drug' => 'Diazepam', 'action' => 'Stop', 'rationale' => 'Falls risk', 'gp_status' => 'pending', 'stage' => 'gp'],
                ['drug' => 'Paracetamol', 'action' => 'Continue', 'gp_status' => 'pending', 'stage' => 'gp'],
            ],
        ]);

        $this->actingAs($user)
            ->get('/emar/reviews?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Reviews')
                ->where('site_brand_colour', '#5E35B1')
                ->has('reviews', 1)
                ->has('deprescribing', 1) // only the non-Continue action becomes a pipeline card
                ->where('deprescribing.0.drug', 'Diazepam')
                ->has('kpis')
            );
    }

    public function test_complete_review_stores_dbi_and_actions(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $review = MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'routine', 'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->from('/emar/reviews')
            ->post("/emar/reviews/{$review->id}/complete", [
                'clinical_summary' => 'Reviewed all medicines.',
                'drug_burden_index' => 1.5,
                'falls_last_quarter' => 2,
                'medications_reviewed' => ['Zopiclone'],
                'actions' => [['drug' => 'Zopiclone', 'action' => 'Reduce', 'gp_status' => 'pending', 'stage' => 'gp']],
            ])
            ->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertSame('completed', $review->status);
        $this->assertSame('1.50', (string) $review->drug_burden_index);
        $this->assertSame(2, $review->falls_last_quarter);
        $this->assertCount(1, $review->actions);
    }

    public function test_advance_action_moves_stage_and_records_gp_acceptance(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $review = MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'comprehensive', 'status' => 'completed',
            'scheduled_date' => now()->subWeek()->toDateString(), 'completed_date' => now()->toDateString(),
            'clinical_summary' => 'x',
            'actions' => [['drug' => 'Diazepam', 'action' => 'Stop', 'gp_status' => 'pending', 'stage' => 'gp']],
        ]);

        $this->actingAs($user)
            ->from('/emar/reviews')
            ->post("/emar/reviews/{$review->id}/actions/advance", ['index' => 0])
            ->assertSessionHasNoErrors();

        $actions = $review->refresh()->actions;
        $this->assertSame('implemented', $actions[0]['stage']);
        $this->assertSame('accepted', $actions[0]['gp_status']);
    }

    public function test_reviewer_ids_are_resolved_as_current_staff_at_the_exact_client_site(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedReviews();
        $reviewer = $this->currentReviewStaffAt($site);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignReviewer = $this->currentReviewStaffAt($foreignSite);
        $endedReviewer = $this->currentReviewStaffAt($site, [
            'end_date' => today()->subDay(),
        ]);
        $missingReviewerId = (int) User::query()->max('id') + 1000;
        $createPayload = [
            'client_id' => $client->id,
            'review_type' => 'routine',
            'scheduled_date' => today()->addWeek()->toDateString(),
        ];

        foreach ([$missingReviewerId, $foreignReviewer->id, $endedReviewer->id] as $concealedReviewerId) {
            $this->actingAs($user)
                ->post(route('emar.reviews.store'), [
                    ...$createPayload,
                    'reviewer_user_id' => $concealedReviewerId,
                ])
                ->assertNotFound();
        }
        $this->assertDatabaseCount('medication_reviews', 0);

        $this->actingAs($user)
            ->post(route('emar.reviews.store'), [
                ...$createPayload,
                'reviewer_user_id' => $reviewer->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $review = MedicationReview::query()->sole();
        $this->assertSame($reviewer->id, (int) $review->reviewer_user_id);

        foreach ([$missingReviewerId, $foreignReviewer->id, $endedReviewer->id] as $concealedReviewerId) {
            $this->actingAs($user)
                ->put(route('emar.reviews.update', $review), [
                    'reviewer_user_id' => $concealedReviewerId,
                ])
                ->assertNotFound();
            $this->assertSame($reviewer->id, (int) $review->fresh()->reviewer_user_id);
        }

        $replacementReviewer = $this->currentReviewStaffAt($site);
        $this->actingAs($user)
            ->put(route('emar.reviews.update', $review), [
                'reviewer_user_id' => $replacementReviewer->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame($replacementReviewer->id, (int) $review->fresh()->reviewer_user_id);
    }

    public function test_review_cancellation_requires_a_bounded_reason_and_strictly_audits_the_transition(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $review = MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today(),
        ]);

        $this->actingAs($user)
            ->delete(route('emar.reviews.destroy', $review))
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)
            ->delete(route('emar.reviews.destroy', $review), [
                'reason' => str_repeat('x', 501),
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame('scheduled', $review->fresh()->status);

        $reason = 'The review was scheduled against the wrong clinical episode.';
        $this->actingAs($user)
            ->delete(route('emar.reviews.destroy', $review), ['reason' => $reason])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $review->fresh()->status);
        $audit = AuditLog::query()
            ->where('action', 'medications.review.cancelled')
            ->where('auditable_type', MedicationReview::class)
            ->where('auditable_id', $review->id)
            ->sole();
        $this->assertSame($reason, data_get($audit->meta, 'reason'));
        $this->assertSame('scheduled', data_get($audit->meta, 'status_before'));
        $this->assertSame('cancelled', data_get($audit->meta, 'status_after'));

        $rollbackReview = MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today(),
        ]);
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $log) use (&$injectFailure): void {
            if ($injectFailure && $log->action === 'medications.review.cancelled') {
                throw new \RuntimeException('Injected medication review cancellation audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->delete(route('emar.reviews.destroy', $rollbackReview), [
                'reason' => 'This cancellation must roll back with its audit evidence.',
            ]);
            $this->fail('The cancellation audit failure did not escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected medication review cancellation audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertSame('scheduled', $rollbackReview->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.review.cancelled',
            'auditable_type' => MedicationReview::class,
            'auditable_id' => $rollbackReview->id,
        ]);
    }

    public function test_terminal_reviews_are_read_only_and_actions_only_advance_after_completion(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $actions = [['drug' => 'Diazepam', 'action' => 'Stop', 'gp_status' => 'pending', 'stage' => 'gp']];
        $completed = MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'comprehensive',
            'status' => 'completed',
            'scheduled_date' => today()->subWeek(),
            'completed_date' => today(),
            'clinical_summary' => 'Original completed evidence.',
            'actions' => $actions,
        ]);
        $cancelled = MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'cancelled',
            'scheduled_date' => today(),
            'actions' => $actions,
        ]);
        $scheduled = MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today(),
            'actions' => $actions,
        ]);

        foreach ([$completed, $cancelled] as $terminal) {
            $this->actingAs($user)
                ->put(route('emar.reviews.update', $terminal), ['reviewer_name' => 'Rewritten'])
                ->assertSessionHasErrors('review');
            $this->actingAs($user)
                ->post(route('emar.reviews.complete', $terminal), ['clinical_summary' => 'Rewritten'])
                ->assertSessionHasErrors('review');
            $this->actingAs($user)
                ->delete(route('emar.reviews.destroy', $terminal), ['reason' => 'Attempted terminal rewrite.'])
                ->assertSessionHasErrors('review');
        }

        foreach ([$scheduled, $cancelled] as $nonCompleted) {
            $this->actingAs($user)
                ->post(route('emar.reviews.actions.advance', $nonCompleted), ['index' => 0])
                ->assertSessionHasErrors('review');
        }

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('Original completed evidence.', $completed->fresh()->clinical_summary);
        $this->assertSame('gp', $completed->fresh()->actions[0]['stage']);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('gp', $cancelled->fresh()->actions[0]['stage']);
        $this->assertSame('scheduled', $scheduled->fresh()->status);
        $this->assertSame('gp', $scheduled->fresh()->actions[0]['stage']);
    }

    public function test_review_mutations_are_confined_to_the_actors_approved_sites(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, ['medications.orders.manage']);
        $siteA = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $siteB = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create([
            'site_id' => $siteB->id,
            'status' => 'active',
            'next_chart_review_date' => today()->addYear(),
        ]);
        $makeReview = fn (Client $client, array $attributes = []): MedicationReview => MedicationReview::query()->create([
            'client_id' => $client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today(),
            ...$attributes,
        ]);
        $foreignReview = $makeReview($clientB, [
            'actions' => [['drug' => 'Foreign drug', 'action' => 'Stop', 'stage' => 'gp']],
        ]);
        $foreignClientReviewDate = $clientB->next_chart_review_date?->toDateString();

        $this->actingAs($user)->post(route('emar.reviews.store'), ['client_id' => $clientB->id])->assertNotFound();
        $this->actingAs($user)->put(route('emar.reviews.update', $foreignReview), [
            'scheduled_date' => 'not-a-date',
        ])->assertNotFound();
        $this->actingAs($user)->post(route('emar.reviews.complete', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->post(route('emar.reviews.actions.advance', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->delete(route('emar.reviews.destroy', $foreignReview))->assertNotFound();

        $this->assertDatabaseCount('medication_reviews', 1);
        $this->assertSame('scheduled', $foreignReview->fresh()->status);
        $this->assertSame('gp', $foreignReview->fresh()->actions[0]['stage']);
        $this->assertSame($foreignClientReviewDate, $clientB->fresh()->next_chart_review_date?->toDateString());

        $this->actingAs($user)->post(route('emar.reviews.store'), [
            'client_id' => $clientA->id,
            'review_type' => 'routine',
            'scheduled_date' => today()->addWeek()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $updatable = $makeReview($clientA);
        $this->actingAs($user)->put(route('emar.reviews.update', $updatable), [
            'reviewer_name' => 'Site A reviewer',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Site A reviewer', $updatable->fresh()->reviewer_name);

        $completable = $makeReview($clientA);
        $nextReviewDate = today()->addMonths(2)->toDateString();
        $this->actingAs($user)->post(route('emar.reviews.complete', $completable), [
            'clinical_summary' => 'Site A review complete.',
            'next_review_date' => $nextReviewDate,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $completable->fresh()->status);
        $this->assertSame($nextReviewDate, $clientA->fresh()->next_chart_review_date?->toDateString());

        $advanceable = $makeReview($clientA, [
            'status' => 'completed',
            'completed_date' => today(),
            'actions' => [['drug' => 'Site A drug', 'action' => 'Stop', 'stage' => 'gp']],
        ]);
        $this->actingAs($user)->post(route('emar.reviews.actions.advance', $advanceable), [
            'index' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('implemented', $advanceable->fresh()->actions[0]['stage']);

        $cancellable = $makeReview($clientA);
        $this->actingAs($user)->delete(route('emar.reviews.destroy', $cancellable), [
            'reason' => 'This duplicate review was created in error.',
        ])->assertRedirect();
        $this->assertSame('cancelled', $cancellable->fresh()->status);
    }

    private function currentReviewStaffAt(Site $site, array $profileOverrides = []): User
    {
        $staff = $this->makeRoleUser('support_worker');
        HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ], $profileOverrides));

        return $staff;
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
