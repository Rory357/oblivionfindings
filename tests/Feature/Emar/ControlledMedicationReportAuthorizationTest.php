<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ControlledMedicationReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_dedicated_controlled_exports_conjoin_report_access_with_the_exact_reader(): void
    {
        foreach ([
            'emar.reports.export_discrepancies',
            'reports.medications.export_discrepancies',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];

            $this->assertContains('permission:reports.viewAny', $middleware, $routeName);
            $this->assertContains('permission:medications.controlled.view', $middleware, $routeName);
        }

        $pdfMiddleware = Route::getRoutes()->getByName('emar.pdf.cd_register')?->gatherMiddleware() ?? [];
        $this->assertContains('permission:medications.reports.export|reports.viewAny', $pdfMiddleware);
        $this->assertContains('permission:medications.controlled.view', $pdfMiddleware);

        foreach ([
            'emar.reports',
            'emar.reports.export',
            'emar.reports.export_mar',
            'emar.pdf.mar',
            'reports.medications',
            'reports.medications.export_mar',
            'api.medications.reports',
            'api.medications.reports.export',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];

            $this->assertNotContains('permission:medications.controlled.view', $middleware, $routeName);
        }
    }

    public function test_report_access_alone_cannot_request_controlled_report_types_or_exports(): void
    {
        $actor = $this->userWithPermissions(['reports.viewAny']);

        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'controlled_balance']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'controlled_discrepancies']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('api.medications.reports.export', ['type' => 'controlled_discrepancies']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('emar.reports.export', ['report_type' => 'controlled']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('emar.reports', ['report_type' => 'controlled']))
            ->assertForbidden();

        foreach ([
            'emar.reports.export_discrepancies',
            'reports.medications.export_discrepancies',
            'emar.pdf.cd_register',
        ] as $routeName) {
            $this->actingAs($actor)->get(route($routeName))->assertForbidden();
        }
    }

    public function test_mixed_report_pages_omit_controlled_datasets_without_blocking_ordinary_reports(): void
    {
        $actor = $this->userWithPermissions([
            'reports.viewAny',
            'medications.view',
            'sites.viewAll',
        ]);
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Controlled MAR fixture',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'end_date' => null,
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'administered_at' => now(),
            'scheduled_for' => now(),
            'status' => 'given',
            'dose_given' => '5 mg',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'difference' => -1,
            'reason' => 'Controlled-only fixture',
            'reported_at' => now(),
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);

        $this->actingAs($actor)
            ->get(route('reports.medications'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/medications')
                ->where('can_view_controlled', false)
                ->has('discrepancies', 0)
                ->has('administrations', 1)
                ->where('administrations.0.medication.name', 'Controlled MAR fixture')
                ->where('administrations.0.medication.controlled_drug', true));

        $this->actingAs($actor)
            ->get(route('emar.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Reports')
                ->where('can_view_controlled', false)
                ->where('can_record_controlled', false)
                ->where('adminSummary.total', 1)
                ->where('adminSummary.given', 1)
                ->has('cdMedications', 0)
                ->where('controlledDrugs.administrations', 0)
                ->where('controlledDrugs.destructions', 0)
                ->where('controlledDrugs.discrepancies', 0)
                ->has('controlledDrugs.byMedication', 0));

        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'mar']))
            ->assertOk()
            ->assertJsonPath('meta.total_records', 1)
            ->assertJsonPath('records.0.medication', 'Controlled MAR fixture')
            ->assertJsonPath('records.0.controlled_drug', true);

        $emarCsv = $this->actingAs($actor)
            ->get(route('emar.reports.export', ['report_type' => 'administration']))
            ->assertOk();
        $this->assertStringContainsString('Controlled MAR fixture', $emarCsv->streamedContent());

        $medicationsCsv = $this->actingAs($actor)
            ->get(route('reports.medications.export_mar'))
            ->assertOk();
        $this->assertStringContainsString('Controlled MAR fixture', $medicationsCsv->streamedContent());

        $this->actingAs($actor)
            ->get(route('emar.pdf.mar', ['client_id' => $client->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_exact_reader_restores_controlled_reports_but_not_writer_affordances(): void
    {
        $actor = $this->userWithPermissions([
            'reports.viewAny',
            'medications.controlled.view',
        ]);

        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'controlled_balance']))
            ->assertOk();
        $this->actingAs($actor)
            ->getJson(route('api.medications.reports', ['type' => 'controlled_discrepancies']))
            ->assertOk();
        $this->actingAs($actor)
            ->get(route('api.medications.reports.export', ['type' => 'controlled_discrepancies']))
            ->assertOk();
        $this->actingAs($actor)
            ->get(route('emar.reports.export', ['report_type' => 'controlled']))
            ->assertOk();
        $this->actingAs($actor)
            ->get(route('emar.reports.export_discrepancies'))
            ->assertOk();
        $this->actingAs($actor)
            ->get(route('reports.medications.export_discrepancies'))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('emar.reports', ['report_type' => 'controlled']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_view_controlled', true)
                ->where('can_record_controlled', false));
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $grants = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($grants);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');

        return $user;
    }
}
