<?php

namespace Tests\Feature\Emar;

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
