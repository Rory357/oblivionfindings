<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationExportAudit;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MedicationAuditEventExportReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Client $person;

    private ClientMedication $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id]);
        $this->medicine = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Synthetic export medicine', 'dosage' => '10 mg',
            'controlled_drug' => false, 'active' => true, 'state' => 'active',
        ]);
        $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'is_active' => true]);
        $this->actor = $this->actor->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_filters_cannot_substitute_another_person_or_site_for_the_selected_event(): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->actingAs($this->actor)->getJson($this->url(['client_id' => $other->id]))->assertNotFound();
        $this->getJson($this->url(['site_id' => $otherSite->id]))->assertNotFound();
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.created')->count());
    }

    public function test_export_requires_a_purpose_and_records_only_the_actual_source_scope(): void
    {
        Site::factory()->create(['is_active' => true]);
        $this->actingAs($this->actor)->getJson(route('emar.audit.event.export', ['id' => 'med_start_'.$this->medicine->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $response = $this->get($this->url())->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertStringContainsString('Synthetic export medicine', $response->getContent());
        $events = MedicationEvent::query()->where('kind', 'export.created')->get();
        $this->assertCount(1, $events);
        $this->assertSame($this->site->id, $events[0]->site_id);
        $this->assertSame($this->person->id, $events[0]->client_id);
    }

    public function test_a_changed_exported_record_releases_no_bytes_or_export_event(): void
    {
        $this->duringRelease(fn () => ClientMedication::withoutEvents(fn () => $this->medicine->update(['dosage' => '20 mg'])));
        $response = $this->actingAs($this->actor)->getJson($this->url())->assertConflict();
        $this->assertStringNotContainsString('Synthetic export medicine', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.created')->count());
    }

    public function test_a_moved_source_person_releases_no_bytes_or_export_event(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->duringRelease(fn () => $this->person->update(['site_id' => $otherSite->id]));
        $response = $this->actingAs($this->actor)->getJson($this->url())->assertNotFound();
        $this->assertStringNotContainsString('Synthetic export medicine', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.created')->count());
    }

    public function test_reclassified_controlled_content_is_denied_at_release(): void
    {
        $permission = Permission::query()->where('key', 'medications.controlled.view')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->duringRelease(fn () => ClientMedication::withoutEvents(fn () => $this->medicine->update(['controlled_drug' => true])));
        $response = $this->actingAs($this->actor->fresh())->getJson($this->url())->assertNotFound();
        $this->assertStringNotContainsString('Synthetic export medicine', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.created')->count());
    }

    private function url(array $filters = []): string
    {
        return route('emar.audit.event.export', ['id' => 'med_start_'.$this->medicine->id, 'purpose' => 'audit', ...$filters]);
    }

    private function duringRelease(callable $mutation): void
    {
        $audit = Mockery::mock(new MedicationExportAudit);
        $audit->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) use ($mutation): void {
            $mutation();
            (new MedicationExportAudit)->record(...$arguments);
        });
        $this->app->instance(MedicationExportAudit::class, $audit);
    }
}
