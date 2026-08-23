<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Observers\SubstanceExposureRecordObserver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Chemical register redesign — gold-standard index payload, SDS-state signal,
 * extended create/update contract, status lifecycle, premium SDS supersede,
 * storage/exposure capture, and the exposure → WorkSafe-notifiable observer path.
 */
class HazardousSubstanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    protected function supportWorker(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    /* ---- Register payload ---- */

    public function test_index_renders_gold_standard_payload(): void
    {
        Site::factory()->create(['is_active' => true]);
        HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/substances')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/substances/index')
                ->has('rows.data', 1)
                ->has('tabCounts.all')
                ->has('tabCounts.sds_missing')
                ->has('hero.live.active')
                ->has('hero.attention.sds_expiring')
                ->has('badges.sds_to_action')
                ->has('sites')
                ->where('can.create', true)
                ->where('detail', null)
            );
    }

    public function test_detail_loads_only_when_substance_param_present(): void
    {
        $substance = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->create(['hazardous_substance_id' => $substance->id]);

        $this->actingAs($this->officer())
            ->get("/health-safety/substances?substance={$substance->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.id', $substance->id)
                ->has('detail.sds_records', 1)
                ->has('detail.storage_locations')
                ->has('detail.exposure_records')
                ->has('detail.counts.sds')
                ->has('detail.staff')
                ->where('detail.can.manage', true)
            );
    }

    /* ---- SDS-state signal + tab counts ---- */

    public function test_sds_state_is_missing_without_current_sheet(): void
    {
        HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/substances')
            ->assertInertia(fn (Assert $p) => $p
                ->where('rows.data.0.sds_state', 'missing')
                ->where('tabCounts.sds_missing', 1)
            );
    }

    public function test_sds_state_is_expiring_when_review_due_soon(): void
    {
        $substance = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->expiring()->create(['hazardous_substance_id' => $substance->id]);

        $this->actingAs($this->officer())
            ->get('/health-safety/substances?tab=sds_expiring')
            ->assertInertia(fn (Assert $p) => $p
                ->where('rows.data.0.sds_state', 'expiring')
                ->where('tabCounts.sds_expiring', 1)
            );
    }

    public function test_controlled_tab_counts_only_controlled(): void
    {
        HazardousSubstance::factory()->controlled()->create();
        HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/substances')
            ->assertInertia(fn (Assert $p) => $p->where('tabCounts.controlled', 1)->where('tabCounts.all', 2));
    }

    /* ---- Create / store (extended contract) ---- */

    public function test_store_persists_the_full_field_set(): void
    {
        $this->actingAs($this->officer())
            ->post('/health-safety/substances', [
                'name' => 'Sodium hypochlorite 12.5%',
                'common_name' => 'Sanitiser bleach',
                'physical_form' => 'liquid',
                'hsno_approval' => 'HSR002515',
                'hsno_classification' => '5.1.1B / 6.1D / 8.2B',
                'hazard_classifications' => ['Oxidising', 'Corrosive'],
                'ghs_pictograms' => ['GHS03', 'GHS05'],
                'signal_word' => 'Danger',
                'hazard_statements' => 'H314 Causes severe skin burns.',
                'un_number' => 'UN1791',
                'is_controlled_substance' => true,
                'requires_tracking' => true,
                'ppe_required' => 'Gloves, goggles.',
                'exposure_limit_type' => 'WES-TWA',
                'exposure_limit_value' => '0.5 ppm',
            ])
            ->assertRedirect();

        $substance = HazardousSubstance::firstWhere('name', 'Sodium hypochlorite 12.5%');
        $this->assertNotNull($substance);
        $this->assertSame('HSR002515', $substance->hsno_approval);
        $this->assertSame('Danger', $substance->signal_word);
        $this->assertSame(['GHS03', 'GHS05'], $substance->ghs_pictograms);
        $this->assertSame(['Oxidising', 'Corrosive'], $substance->hazard_classifications);
        $this->assertSame('WES-TWA', $substance->exposure_limit_type);
        $this->assertTrue($substance->is_controlled_substance);
        $this->assertSame('active', $substance->status);
    }

    public function test_store_with_stay_flashes_created_substance_id(): void
    {
        $this->actingAs($this->officer())
            ->post('/health-safety/substances', ['name' => 'Acetone', 'physical_form' => 'liquid', 'stay' => true])
            ->assertSessionHas('created_substance_id');
    }

    public function test_create_redirects_to_register_with_new_param(): void
    {
        $this->actingAs($this->officer())
            ->get('/health-safety/substances/create')
            ->assertRedirect('/health-safety/substances?new=1');
    }

    public function test_support_worker_cannot_create_substance(): void
    {
        $this->actingAs($this->supportWorker())
            ->post('/health-safety/substances', ['name' => 'X', 'physical_form' => 'liquid'])
            ->assertForbidden();
    }

    /* ---- Update + status lifecycle ---- */

    public function test_update_status_requires_reason_to_deactivate(): void
    {
        $substance = HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->patch("/health-safety/substances/{$substance->id}/status", ['status' => 'inactive'])
            ->assertSessionHas('error');

        $this->assertSame('active', $substance->fresh()->status);
    }

    public function test_update_status_sets_status_and_reason(): void
    {
        $substance = HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->patch("/health-safety/substances/{$substance->id}/status", ['status' => 'inactive', 'reason' => 'No longer used on site.'])
            ->assertSessionHas('success');

        $substance->refresh();
        $this->assertSame('inactive', $substance->status);
        $this->assertSame('No longer used on site.', $substance->status_reason);
    }

    /* ---- SDS premium upload (supersede) ---- */

    public function test_store_sds_supersedes_the_prior_current_sheet(): void
    {
        Storage::fake('private');
        $substance = HazardousSubstance::factory()->create();
        $old = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $substance->id, 'status' => 'current']);

        $this->actingAs($this->officer())
            ->post("/health-safety/substances/{$substance->id}/sds", [
                'version' => '3.0',
                'issue_date' => now()->toDateString(),
                'review_date' => now()->addYear()->toDateString(),
                'supplier_name' => 'ChemCo',
                'file' => UploadedFile::fake()->create('sds.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame(1, $substance->safetyDataSheets()->where('status', 'current')->count());
    }

    /* ---- Storage capture ---- */

    public function test_store_storage_location_captures_container_and_audit(): void
    {
        $site = Site::factory()->create();
        $substance = HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->post("/health-safety/substances/{$substance->id}/storage-locations", [
                'site_id' => $site->id,
                'location_description' => 'Chemical store, bay 2',
                'current_quantity' => 20,
                'maximum_quantity' => 50,
                'quantity_unit' => 'L',
                'container_type' => 'HDPE drum',
                'properly_labelled' => true,
                'segregation_compliant' => false,
                'last_audit_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $loc = SubstanceStorageLocation::firstWhere('hazardous_substance_id', $substance->id);
        $this->assertSame('HDPE drum', $loc->container_type);
        $this->assertFalse($loc->segregation_compliant);
        $this->assertNotNull($loc->last_audit_date);
    }

    /* ---- Exposure capture + WorkSafe observer ---- */

    public function test_store_exposure_derives_medical_attention_from_treatment(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $worker = $this->supportWorker();

        $this->actingAs($this->officer())
            ->post("/health-safety/substances/{$substance->id}/exposure-records", [
                'user_id' => $worker->id,
                'exposed_at' => now()->toDateTimeString(),
                'exposure_type' => 'inhalation',
                'medical_treatment' => 'hospitalisation',
            ])
            ->assertRedirect();

        $record = SubstanceExposureRecord::firstWhere('hazardous_substance_id', $substance->id);
        $this->assertSame('hospitalisation', $record->medical_treatment);
        $this->assertTrue($record->medical_attention_sought);
    }

    public function test_exposure_observer_escalates_hospitalisation_for_qualified_worksafe_review(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->notifiable()->create(['hazardous_substance_id' => $substance->id]);

        // ShouldHandleEventsAfterCommit won't fire inside RefreshDatabase's transaction —
        // exercise the observer's classify + recordEvent convergence directly.
        app(SubstanceExposureRecordObserver::class)->created($record);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(HsEvent::CATEGORY_EXPOSURE, $event->event_category);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_decided_at);
        $this->assertNull($event->worksafe_decided_by_user_id);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
    }

    public function test_exposure_observer_leaves_reduced_medical_treatment_input_undecided(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::factory()->requiringMedicalAttention()->create(['hazardous_substance_id' => $substance->id]);

        app(SubstanceExposureRecordObserver::class)->created($record);

        $event = HsEvent::where('source_type', SubstanceExposureRecord::class)->where('source_id', $record->id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->worksafe_notifiable);
    }

    /* ---- Deep-link fallback ---- */

    public function test_show_redirects_to_register_deep_link(): void
    {
        $substance = HazardousSubstance::factory()->create();

        $this->actingAs($this->officer())
            ->get("/health-safety/substances/{$substance->id}")
            ->assertRedirect("/health-safety/substances?substance={$substance->id}");
    }

    /* ---- Adversarial-review regression guards ---- */

    public function test_hero_counts_are_site_scoped_under_a_site_filter(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $subA = HazardousSubstance::factory()->create();
        SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $subA->id, 'site_id' => $siteA->id]);
        $subB = HazardousSubstance::factory()->create();
        SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $subB->id, 'site_id' => $siteB->id]);

        // No filter → hero counts both active substances.
        $this->actingAs($this->officer())->get('/health-safety/substances')
            ->assertInertia(fn (Assert $p) => $p->where('hero.live.active', 2));

        // Site A filter → hero + storage are scoped to site A only (review finding #1).
        $this->actingAs($this->officer())->get("/health-safety/substances?site_id={$siteA->id}")
            ->assertInertia(fn (Assert $p) => $p->where('hero.live.active', 1)->where('hero.live.storage_locations', 1));
    }

    public function test_current_sds_filter_excludes_expired_sheets(): void
    {
        $upToDate = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->create(['hazardous_substance_id' => $upToDate->id, 'status' => 'current', 'review_date' => now()->addYear()]);
        $expired = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->expired()->create(['hazardous_substance_id' => $expired->id]);

        // The 'current' SDS filter returns only the genuinely up-to-date substance (review finding #3).
        $this->actingAs($this->officer())->get('/health-safety/substances?sds_state=current')
            ->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $upToDate->id));
    }
}
