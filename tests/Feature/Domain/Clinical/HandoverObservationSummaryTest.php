<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverObservationSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected User $incomingStaff;

    protected Shift $outgoingShift;

    protected Site $site;

    protected User $staffUser;

    protected ShiftHandoverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);

        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staffUser = $this->createUserWithRole('coordinator');
        $this->incomingStaff = $this->createUserWithRole('support_worker');

        $this->outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staffUser->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now(),
            'status' => 'in_progress',
        ]);

        $this->service = app(ShiftHandoverService::class);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    protected function createIncomingShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->incomingStaff->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'status' => 'scheduled',
        ]);
    }

    // ── Handover with observations ───────────────────────────────────────

    public function test_handover_includes_observations_summary(): void
    {
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->outgoingShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->staffUser->id,
            'data' => ['systolic' => 130, 'diastolic' => 85, 'pulse' => 78],
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->outgoingShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->staffUser->id,
            'data' => ['weight_kg' => 72.0],
        ]);

        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'Good shift, client in good spirits.',
            'submit' => false,
        ]);

        $handover = $result['handover'];

        $this->assertNotNull($handover->observations_summary);
        $this->assertIsArray($handover->observations_summary);
        $this->assertCount(2, $handover->observations_summary);

        // Check structure
        $first = $handover->observations_summary[0];
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('type_label', $first);
        $this->assertArrayHasKey('summary', $first);
        $this->assertArrayHasKey('recorded_at', $first);
        $this->assertArrayHasKey('recorder', $first);
    }

    public function test_handover_summary_contains_correct_observation_data(): void
    {
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->outgoingShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->staffUser->id,
            'data' => ['weight_kg' => 73.5],
        ]);

        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'Weight taken.',
            'submit' => false,
        ]);

        $summary = $result['handover']->observations_summary;
        $this->assertEquals('weight', $summary[0]['type']);
        $this->assertEquals('Weight', $summary[0]['type_label']);
        $this->assertStringContainsString('73.5', $summary[0]['summary']);
    }

    public function test_handover_without_observations_has_null_summary(): void
    {
        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'No observations this shift.',
            'submit' => false,
        ]);

        $this->assertNull($result['handover']->observations_summary);
    }

    public function test_handover_summary_excludes_other_shift_observations(): void
    {
        $otherShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->incomingStaff->id,
        ]);

        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'shift_id' => $otherShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->incomingStaff->id,
        ]);

        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'No observations on my shift.',
            'submit' => false,
        ]);

        $this->assertNull($result['handover']->observations_summary);
    }

    public function test_observations_summary_persists_after_reload(): void
    {
        ClinicalObservation::factory()->bowel()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->outgoingShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->staffUser->id,
            'data' => ['bristol_type' => 4],
        ]);

        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'Bowel chart recorded.',
            'submit' => false,
        ]);

        $reloaded = ShiftHandover::find($result['handover']->id);
        $this->assertNotNull($reloaded->observations_summary);
        $this->assertCount(1, $reloaded->observations_summary);
        $this->assertEquals('bowel', $reloaded->observations_summary[0]['type']);
    }

    public function test_vitals_summary_format(): void
    {
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'shift_id' => $this->outgoingShift->id,
            'site_id' => $this->site->id,
            'recorded_by' => $this->staffUser->id,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72, 'temperature' => 36.8],
        ]);

        $this->createIncomingShift();

        $result = $this->service->save($this->outgoingShift, $this->staffUser, [
            'handover_notes' => 'Vitals taken.',
            'submit' => false,
        ]);

        $summary = $result['handover']->observations_summary[0]['summary'];
        $this->assertStringContainsString('BP 120/80', $summary);
        $this->assertStringContainsString('P72', $summary);
    }
}
