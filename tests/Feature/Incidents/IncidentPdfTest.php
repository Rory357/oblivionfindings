<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Board M5c note (Stephan, 1 Nov 2026; answer 21): the incident page gets "Download PDF" once the incident closes, for
 * whole-record viewers only, and every download is logged.
 */
class IncidentPdfTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_a_manager_downloads_a_closed_incidents_final_record_and_it_is_logged(): void
    {
        $manager = $this->roleUser('provider_manager');
        $incident = $this->incident('closed');

        $response = $this->actingAs($manager)->get("/incidents/{$incident->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'incidents.pdfDownloaded', 'auditable_id' => $incident->id]);
    }

    public function test_an_open_incident_has_no_pdf(): void
    {
        $manager = $this->roleUser('provider_manager');
        $incident = $this->incident('submitted');

        $this->actingAs($manager)->get("/incidents/{$incident->id}/pdf")->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'incidents.pdfDownloaded']);
    }

    public function test_someone_who_sees_only_their_own_reports_gets_no_pdf(): void
    {
        $worker = $this->roleUser('support_worker');
        $incident = $this->incident('closed', ['reported_by' => $worker->id]);

        $status = $this->actingAs($worker)->get("/incidents/{$incident->id}/pdf")->getStatusCode();

        $this->assertContains($status, [403, 404], 'Only whole-record viewers may download the PDF.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'incidents.pdfDownloaded']);
    }

    /** @param  array<string, mixed>  $extra */
    private function incident(string $status, array $extra = []): ClientIncident
    {
        return ClientIncident::factory()->atSite($this->site)->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'status' => $status,
            'type' => 'fall',
            'description' => 'Aroha stood up without her walker.',
            'closed_at' => $status === 'closed' ? now()->subDay() : null,
            ...$extra,
        ]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
