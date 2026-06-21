<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the gold-standard Hazards rebuild: the global register prop shape,
 * the full lifecycle (open → in_progress → mitigated → closed) via the new
 * status route, corrective actions, review, close gate + uploads, and the
 * detail payload the modal consumes.
 */
class HazardModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->site = Site::factory()->create(['type' => 'house']);
    }

    private function makeHazard(array $overrides = []): SiteHazard
    {
        return SiteHazard::create(array_merge([
            'site_id' => $this->site->id,
            'reported_by_user_id' => $this->admin->id,
            'hazard_type' => 'slip_trip_fall',
            'severity' => 'low',
            'likelihood' => 'rare',
            'description' => 'Loose mat in the hallway.',
            'status' => 'open',
        ], $overrides));
    }

    /* ---- Private-disk evidence serving (ServesPrivateAttachments) ---- */

    public function test_hazard_media_serves_photo_from_private_disk_with_hardened_headers(): void
    {
        Storage::fake('private');
        $hazard = $this->makeHazard();
        $path = "hazards/{$hazard->id}/photos/evidence.png";
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('private')->put($path, $png);
        $hazard->update(['photo_paths' => [$path]]);

        $res = $this->actingAs($this->admin)->get("/hazards/{$hazard->id}/media/photo/0");

        $res->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'")
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame($png, $res->streamedContent());
    }

    public function test_hazard_detail_emits_authenticated_media_route_not_public_storage(): void
    {
        Storage::fake('private');
        $hazard = $this->makeHazard();
        $path = "hazards/{$hazard->id}/photos/evidence.png";
        Storage::disk('private')->put($path, 'x');
        $hazard->update(['photo_paths' => [$path]]);

        $response = $this->actingAs($this->admin)->get("/compliance/hazards?hazard={$hazard->id}");
        $response->assertOk();

        $url = data_get($response->viewData('page'), 'props.detail.photo_paths.0');
        $this->assertNotNull($url, 'detail.photo_paths[0] should be the authenticated serve URL');
        $this->assertStringContainsString("/hazards/{$hazard->id}/media/photo/0", (string) $url);
        $this->assertStringNotContainsString('/storage/', (string) $url);
    }

    public function test_hazard_media_returns_404_for_out_of_range_index(): void
    {
        Storage::fake('private');
        $hazard = $this->makeHazard(['photo_paths' => []]);

        $this->actingAs($this->admin)
            ->get("/hazards/{$hazard->id}/media/photo/0")
            ->assertNotFound();
    }

    public function test_global_register_returns_events_shaped_props(): void
    {
        $this->makeHazard();

        $this->actingAs($this->admin)
            ->get('/compliance/hazards?tab=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('compliance/hazards/index')
                ->has('hazards.data')
                ->has('tab')
                ->has('tabCounts')
                ->has('hero.live')
                ->has('hero.attention')
                ->has('nzBadges')
                ->has('sites')
                ->has('assignees')
                ->has('can.manage')
                ->where('detail', null)
            );
    }

    public function test_store_creates_hazard_and_observer_fills_reference_and_risk(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->site->id}/hazards", [
                'hazard_type' => 'hot_water_temperature',
                'severity' => 'high',
                'likelihood' => 'likely',
                'description' => 'Hot water exceeds 55C at the basin.',
                'location' => 'Main bathroom',
            ])
            ->assertRedirect();

        $hazard = SiteHazard::latest('id')->first();
        $this->assertNotNull($hazard);
        $this->assertNotNull($hazard->reference_number);
        $this->assertSame('high', $hazard->risk_rating); // high × likely => high
        $this->assertSame('Main bathroom', $hazard->location);
    }

    public function test_detail_payload_loads_only_when_hazard_param_present(): void
    {
        $hazard = $this->makeHazard();

        $this->actingAs($this->admin)
            ->get("/compliance/hazards?hazard={$hazard->id}")
            ->assertInertia(fn ($page) => $page
                ->where('detail.id', $hazard->id)
                ->where('detail.reference_number', $hazard->reference_number)
                ->has('detail.history')
                ->has('detail.close_gate')
                ->where('detail.can.manage', true)
            );
    }

    public function test_lifecycle_open_to_in_progress_to_mitigated(): void
    {
        $hazard = $this->makeHazard();

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/status", ['status' => 'in_progress', 'note' => 'Controls underway.'])
            ->assertRedirect();
        $this->assertSame('in_progress', $hazard->fresh()->status);

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/status", [
                'status' => 'mitigated',
                'control_hierarchy' => ['engineering', 'administrative'],
                'residual_severity' => 'low',
                'residual_likelihood' => 'unlikely',
            ])
            ->assertRedirect();

        $fresh = $hazard->fresh();
        $this->assertSame('mitigated', $fresh->status);
        $this->assertSame(['engineering', 'administrative'], $fresh->control_hierarchy);
        $this->assertSame('low', $fresh->residual_risk_rating); // low × unlikely => low
        $this->assertNotNull($fresh->status_changed_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $hazard = $this->makeHazard(); // open

        // open -> mitigated is not a valid direct transition
        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/status", [
                'status' => 'mitigated',
                'control_hierarchy' => ['ppe'],
                'residual_severity' => 'low',
                'residual_likelihood' => 'rare',
            ])
            ->assertSessionHas('error');

        $this->assertSame('open', $hazard->fresh()->status);
    }

    public function test_corrective_action_add_and_complete(): void
    {
        $hazard = $this->makeHazard();

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/actions", [
                'title' => 'Re-seal the vinyl threshold',
                'action_type' => 'engineering',
            ])
            ->assertRedirect();

        $action = SiteHazardAction::where('hazard_id', $hazard->id)->first();
        $this->assertNotNull($action);
        $this->assertNotNull($action->reference_number);
        $this->assertSame('pending', $action->status);

        $this->actingAs($this->admin)
            ->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Done and verified.'])
            ->assertRedirect();

        $fresh = $action->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->completed_by_user_id);
    }

    public function test_review_records_review_date_and_history(): void
    {
        $hazard = $this->makeHazard();

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/review", ['note' => 'Quarterly control-effectiveness check — still effective.'])
            ->assertRedirect();

        $this->assertNotNull($hazard->fresh()->review_date);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hazard.reviewed',
            'auditable_id' => $hazard->id,
        ]);
    }

    public function test_close_requires_summary_and_closes_hazard(): void
    {
        $hazard = $this->makeHazard();

        // Missing summary fails validation.
        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/close", [])
            ->assertSessionHasErrors('resolution_summary');

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Mat removed and floor re-fixed.'])
            ->assertRedirect();

        $fresh = $hazard->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame($this->admin->id, $fresh->closed_by_user_id);
    }

    public function test_export_returns_csv(): void
    {
        $this->makeHazard();

        $response = $this->actingAs($this->admin)->get('/compliance/hazards/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Reference', $response->getContent());
    }
}
