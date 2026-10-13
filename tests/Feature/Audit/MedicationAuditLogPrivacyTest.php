<?php

namespace Tests\Feature\Audit;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * EA-020 / EA-150: medication audit rows in the general audit log
 * (/audit-logs, Settings › Audit log and its CSV, HR's audit view) follow
 * the medication Site scope, the person rule and controlled view; the client
 * evidence zip carries no medication audit rows (they are exported from
 * eMAR › Reports with a purpose and an export record).
 */
class MedicationAuditLogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $houseA;

    private Site $houseB;

    private Client $residentA;

    private Client $residentB;

    private AuditLog $ordinary;

    private AuditLog $doseA;

    private AuditLog $doseB;

    private AuditLog $controlledA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->houseA = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $this->houseB = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $this->residentA = Client::factory()->create(['site_id' => $this->houseA->id, 'first_name' => 'Jane', 'last_name' => 'Kowhai']);
        $this->residentB = Client::factory()->create(['site_id' => $this->houseB->id, 'first_name' => 'Mere', 'last_name' => 'Rimu']);

    }

    private function seedAuditRows(): void
    {
        AuditLog::query()->delete();
        $this->ordinary = AuditLog::create(['client_id' => $this->residentA->id, 'action' => 'clients.profile.updated', 'meta' => []]);
        $this->doseA = AuditLog::create([
            'client_id' => $this->residentA->id,
            'action' => 'medications.administration.record',
            'auditable_type' => (new ClientMedicationAdministration)->getMorphClass(),
            'auditable_id' => 900001,
            'meta' => ['status' => 'given'],
        ]);
        $this->doseB = AuditLog::create([
            'client_id' => $this->residentB->id,
            'action' => 'medications.administration.record',
            'auditable_type' => (new ClientMedicationAdministration)->getMorphClass(),
            'auditable_id' => 900002,
            'meta' => ['status' => 'given'],
        ]);
        $this->controlledA = AuditLog::create([
            'client_id' => $this->residentA->id,
            'action' => 'medications.controlled.entry.record',
            'auditable_type' => (new ClientControlledDrugEntry)->getMorphClass(),
            'auditable_id' => 900003,
            'meta' => ['on_hand_after' => 4],
        ]);
    }

    public function test_audit_readers_without_medication_access_see_no_medication_rows_anywhere(): void
    {
        $hr = $this->staffAt($this->houseA, ['audit.viewAny', 'hr.employees.viewAny']);
        $this->seedAuditRows();

        $general = collect($this->actingAs($hr)->get('/audit-logs')->assertOk()->inertiaProps('logs.data'))->pluck('id');
        $settings = collect($this->actingAs($hr)->get('/settings/audit-logs')->assertOk()->inertiaProps('events.data'))->pluck('id');
        $csv = $this->actingAs($hr)->get('/settings/audit-logs/export')->assertOk()->streamedContent();
        $search = collect($this->actingAs($hr)->get('/audit-logs?q=Kowhai')->assertOk()->inertiaProps('logs.data'))->pluck('id');

        $this->assertSame([$this->ordinary->id], $general->all());
        $this->assertSame([$this->ordinary->id], $settings->all());
        $this->assertSame([$this->ordinary->id], $search->all());
        $this->assertStringNotContainsString('medications.', $csv);
        $this->assertStringContainsString('clients.profile.updated', $csv);
    }

    public function test_medication_auditors_see_only_their_houses_and_no_controlled_rows_without_controlled_view(): void
    {
        $auditor = $this->staffAt($this->houseA, ['audit.viewAny', 'medications.view', 'medications.audit.view', 'clients.viewAny']);
        $this->seedAuditRows();

        $ids = collect($this->actingAs($auditor)->get('/audit-logs')->assertOk()->inertiaProps('logs.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$this->ordinary->id, $this->doseA->id])->sort()->values()->all(), $ids);
    }

    public function test_controlled_readers_see_controlled_register_rows_at_their_house(): void
    {
        $lead = $this->staffAt($this->houseA, ['audit.viewAny', 'medications.view', 'medications.audit.view', 'medications.controlled.view', 'clients.viewAny']);
        $this->seedAuditRows();

        $ids = collect($this->actingAs($lead)->get('/settings/audit-logs')->assertOk()->inertiaProps('events.data'))->pluck('id');

        $this->assertTrue($ids->contains($this->controlledA->id));
        $this->assertFalse($ids->contains($this->doseB->id));
    }

    public function test_the_client_evidence_zip_carries_no_medication_audit_rows(): void
    {
        $manager = $this->staffAt($this->houseA, [], 'provider_manager');
        $this->seedAuditRows();

        $response = $this->actingAs($manager)->get('/audit-exports/clients/'.$this->residentA->id)->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);
        $rows = json_decode((string) $zip->getFromName('audit_logs.json'), true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $actions = collect($rows)->pluck('action')->all();
        $this->assertContains('clients.profile.updated', $actions);
        $this->assertNotContains('medications.administration.record', $actions);
        $this->assertNotContains('medications.controlled.entry.record', $actions);
        $this->assertArrayHasKey('medication_audit', $manifest);
    }

    /** @param array<int, string> $permissions */
    private function staffAt(Site $site, array $permissions, ?string $roleName = null): User
    {
        $user = User::factory()->create(['role' => $roleName ?? 'manager', 'approved_at' => now()]);
        if ($roleName !== null) {
            $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
        } else {
            $role = Role::query()->create(['name' => 'audit_med_'.$user->id, 'label' => 'Audit '.$user->id, 'level' => 20, 'type' => 'custom']);
            $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }
}
