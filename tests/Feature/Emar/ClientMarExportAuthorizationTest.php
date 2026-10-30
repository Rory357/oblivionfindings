<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationReportAccess;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientMarExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private Client $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->hidden = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Pacific/Auckland')->utc());
        foreach ([$this->client, $this->hidden] as $client) {
            ClientMedication::factory()->create([
                'client_id' => $client->id, 'name' => $client->id === $this->client->id ? 'Visible scheduled medicine' : 'Hidden scheduled medicine',
                'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'is_prn' => false, 'controlled_drug' => false,
                'dosage' => '1 tablet', 'route' => 'oral', 'form' => 'tablet',
                'start_date' => '2026-10-01', 'end_date' => null, 'dose_times' => ['09:00'],
            ]);
        }
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function aliases(): array
    {
        return [['clients.mar.export_csv'], ['operations.clients.mar.export_csv']];
    }

    #[DataProvider('aliases')]
    public function test_generic_report_permission_never_replaces_exact_medication_report_permissions(string $alias): void
    {
        $actor = $this->actor(['reports.viewAny' => true, 'medications.reports.view' => false, 'medications.reports.export' => false]);
        $this->assertTrue($actor->can('viewMedications', $this->client));
        $before = MedicationEvent::count();
        $this->actingAs($actor)->get($this->url($alias))->assertForbidden();
        $this->assertSame($before, MedicationEvent::count());
    }

    #[DataProvider('aliases')]
    public function test_exact_export_permission_alone_cannot_release_a_person_csv(string $alias): void
    {
        $actor = $this->actor(['medications.reports.view' => false]);
        $before = MedicationEvent::count();
        $this->actingAs($actor)->get($this->url($alias))->assertForbidden();
        $this->assertSame($before, MedicationEvent::count());
    }

    #[DataProvider('aliases')]
    public function test_finance_only_overrides_cannot_export_person_clinical_data(string $alias): void
    {
        $actor = $this->actor(['reports.viewAny' => true], 'finance');
        $this->assertTrue($actor->hasRole('finance'));
        $this->assertTrue(app(MedicationReportAccess::class)->financeOnly($actor));
        $this->assertTrue($actor->can('viewMedications', $this->client));
        $before = MedicationEvent::count();
        $this->actingAs($actor)->get($this->url($alias))->assertForbidden();
        $this->assertSame($before, MedicationEvent::count());
    }

    #[DataProvider('aliases')]
    public function test_authorized_person_export_is_scoped_audited_and_does_not_export_other_people(string $alias): void
    {
        $actor = $this->actor();
        $this->assertTrue($actor->can('viewMedications', $this->hidden));
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Pacific/Auckland')->utc());
        $recorded = ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Recorded scheduled medicine',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'is_prn' => false, 'controlled_drug' => false,
            'dosage' => '2 tablets', 'route' => 'oral', 'form' => 'tablet',
            'start_date' => '2026-10-01', 'end_date' => null, 'dose_times' => ['10:00'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $recorded->id, 'administered_by' => $actor->id,
            'scheduled_for' => Carbon::parse('2026-10-05 10:00:00', 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse('2026-10-05 10:05:00', 'Pacific/Auckland')->utc(),
            'status' => 'given', 'notes' => 'Observed recorded dose',
        ]);
        // The recorded instant is yesterday in UTC but belongs to today's NZ chart.
        $this->assertSame('2026-10-04', now()->toDateString());
        $before = MedicationEvent::where('kind', 'export.created')->count();
        $response = $this->actingAs($actor)->get($this->url($alias))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('Visible scheduled medicine', $response->getContent());
        $this->assertStringContainsString('not_recorded', $response->getContent());
        $this->assertStringNotContainsString('Hidden scheduled medicine', $response->getContent());
        $csv = fopen('php://memory', 'r+');
        fwrite($csv, $response->getContent());
        rewind($csv);
        $rows = [];
        while (($row = fgetcsv($csv, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($csv);
        $this->assertSame(['2026-10-05 09:00', 'Visible scheduled medicine', '1 tablet', 'oral', 'tablet', 'not_recorded', '', '', ''], $rows[1]);
        $this->assertSame(['2026-10-05 10:00', 'Recorded scheduled medicine', '2 tablets', 'oral', 'tablet', 'given', '2026-10-05 10:05', '', 'Observed recorded dose'], $rows[2]);
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before + 1, MedicationEvent::where('kind', 'export.created')->count());
        $event = MedicationEvent::where('kind', 'export.created')->latest('id')->firstOrFail();
        $this->assertSame((int) $this->client->id, (int) $event->client_id);
        $this->assertSame((int) $this->site->id, (int) $event->site_id);
        $this->assertSame((int) $actor->id, (int) $event->actor_id);
        $this->assertSame('Care and handover', $event->facts['purpose']);
    }

    #[DataProvider('aliases')]
    public function test_query_person_cannot_replace_the_route_person(string $alias): void
    {
        $actor = $this->actor();
        $before = MedicationEvent::count();
        $this->actingAs($actor)->get($this->url($alias, ['client_id' => $this->hidden->id]))->assertNotFound();
        // A readable query person cannot conceal a denied route person either.
        $this->actingAs($actor)->get(route($alias, $this->hidden).'?'.http_build_query([
            'purpose' => 'care', 'date' => '2026-10-05', 'client_id' => $this->client->id,
        ]))->assertNotFound();
        $this->assertSame($before, MedicationEvent::count());
    }

    #[DataProvider('aliases')]
    public function test_purpose_and_current_person_access_are_required_before_release(string $alias): void
    {
        $actor = $this->actor();
        $before = MedicationEvent::count();
        $this->actingAs($actor)->getJson(route($alias, $this->client).'?date=2026-10-05')
            ->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $actor->hrEmployeeProfile->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $actor = $actor->fresh();
        $this->actingAs($actor)->get($this->url($alias))->assertNotFound();
        $this->assertSame($before, MedicationEvent::count());
    }

    private function url(string $alias, array $query = []): string
    {
        return route($alias, $this->client).'?'.http_build_query($query + ['purpose' => 'care', 'date' => '2026-10-05']);
    }

    private function actor(array $permissions = [], string $role = 'support_worker'): User
    {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($role === 'finance') {
            $actor->roles()->attach(Role::query()->where('name', 'finance')->firstOrFail());
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-09-01', 'end_date' => null,
        ]);
        $actor->assignedClients()->attach($this->client->id);
        foreach ($permissions + [
            'clients.viewAssigned' => true, 'clients.viewAny' => false,
            'medications.view' => true, 'medications.reports.view' => true, 'medications.reports.export' => true,
            'reports.viewAny' => false, 'medications.stock.update' => false, 'medications.audit.view' => false,
            'medications.controlled.view' => false, 'clinical.accessAllSites' => false, 'sites.viewAll' => false,
        ] as $key => $allowed) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }

        return $actor->fresh();
    }
}
