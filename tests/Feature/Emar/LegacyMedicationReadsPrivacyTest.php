<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use App\Services\Portal\PortalMedicationList;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EA-107 / EA-126 / EA-169 / EA-099 / EA-203: legacy and peripheral
 * medication reads follow the P02 person rule, read canonical allergies,
 * show families only current checked orders, and answer 404 out of scope.
 */
class LegacyMedicationReadsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private Client $mine;

    private Client $theirs;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->house = Site::factory()->create(['is_active' => true]);
        $this->mine = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Aroha', 'last_name' => 'Mine', 'status' => 'active']);
        $this->theirs = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Hemi', 'last_name' => 'Notmine', 'status' => 'active']);
        $this->worker = $this->staff('support_worker', $this->house);
        $this->mine->supportWorkers()->attach($this->worker->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_alerts_list_only_people_the_reader_may_open(): void
    {
        foreach ([[$this->mine, 'Missed dose: Metformin'], [$this->theirs, 'Missed dose: Methadone']] as [$client, $message]) {
            $medication = ClientMedication::factory()->create(['client_id' => $client->id, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
            MedicationDashboardAlert::query()->create([
                'client_id' => $client->id, 'client_medication_id' => $medication->id,
                'alert_type' => 'missed_dose', 'severity' => 'warning', 'message' => $message, 'status' => 'active',
            ]);
        }

        $body = $this->actingAs($this->worker)->getJson('/api/medications/alerts')->assertOk()->getContent();

        $this->assertStringContainsString('Metformin', $body);
        $this->assertStringNotContainsString('Methadone', $body);
        $this->actingAs($this->worker)->getJson('/api/medications/clients/'.$this->theirs->id.'/alerts')->assertNotFound();
    }

    public function test_out_of_scope_legacy_chart_reads_are_not_found(): void
    {
        $this->actingAs($this->worker)->getJson('/api/medications/clients/'.$this->theirs->id.'/mar')->assertNotFound();
        $this->actingAs($this->worker)->getJson('/api/medications/clients/'.$this->theirs->id.'/allergies')->assertNotFound();
    }

    public function test_the_next_shift_briefing_lists_no_medicines_for_someone_the_worker_does_not_support(): void
    {
        ClientMedication::factory()->create([
            'client_id' => $this->theirs->id, 'name' => 'Sertraline', 'dosage' => '50mg', 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', 'is_prn' => false, 'controlled_drug' => false,
            'start_date' => '2026-10-01', 'end_date' => null, 'dose_times' => ['08:00'],
        ]);
        Shift::factory()->create([
            'client_id' => $this->theirs->id, 'site_id' => $this->house->id, 'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse('2026-10-06 07:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-06 15:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled', 'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->worker)->get('/my-day')->assertOk();

        $this->assertStringNotContainsString('Sertraline', json_encode($response->inertiaProps('next_shift_briefing')));
    }

    public function test_the_door_card_reads_canonical_allergies_and_only_for_medical_readers(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->mine->id,
            'allergies' => ['Peanuts'],
            'blood_type' => 'O+',
            'allergy_records' => [[
                'key' => 'peanut', 'allergen' => 'Peanuts', 'severity' => 'life_threatening', 'reaction' => 'Anaphylaxis',
                'notes' => null, 'identified_date' => null, 'identified_by' => null, 'source_register_ids' => [], 'removed_at' => null,
            ]],
            'allergies_canonical_at' => now(),
        ]);
        $room = SiteHouseRoom::query()->create([
            'site_id' => $this->house->id, 'name' => 'Bedroom 1',
            'is_active' => true, 'is_assignable' => true, 'assigned_client_id' => $this->mine->id,
        ]);
        $coordinator = $this->staff('coordinator', $this->house);
        $maintenance = $this->staff('maintenance_coordinator', $this->house);

        $this->actingAs($coordinator)
            ->get('/sites/'.$this->house->id.'/rooms/'.$room->id.'/door-card')
            ->assertOk()
            ->assertSee('Peanuts');
        $this->actingAs($maintenance)
            ->get('/sites/'.$this->house->id.'/rooms/'.$room->id.'/door-card')
            ->assertOk()
            ->assertDontSee('Peanuts')
            ->assertDontSee('O+');
    }

    public function test_family_portal_medicines_are_current_checked_orders_as_a_plain_list(): void
    {
        $self = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $self->roles()->attach(Role::where('name', 'client')->firstOrFail());
        $this->mine->portalUsers()->attach($self->id, ['relation' => 'client']);
        $make = fn (string $name, array $state) => ClientMedication::factory()->create(array_merge([
            'client_id' => $this->mine->id, 'name' => $name, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'controlled_drug' => false,
        ], $state));
        $make('Checked current', []);
        $make('Sent back', ['approval_status' => 'rejected', 'rejection_reason' => 'Wrong strength']);
        $make('Awaiting check', ['approval_status' => 'pending_verification']);
        $make('Ceased order', ['state' => 'ceased', 'active' => false]);
        $make('Controlled current', ['controlled_drug' => true]);

        foreach (['/portal/clients/'.$this->mine->id => 'medications', '/portal/clients/'.$this->mine->id.'/health' => 'medications'] as $url => $prop) {
            $medicines = $this->actingAs($self)->get($url)->assertOk()->inertiaProps($prop);
            $names = collect($medicines)->pluck('name')->sort()->values()->all();
            $this->assertSame(['Checked current', 'Controlled current'], $names, $url);
            $this->assertArrayNotHasKey('rejection_reason', $medicines[0]);
            $this->assertArrayNotHasKey('created_by', $medicines[0]);
        }

        $forFamily = collect(app(PortalMedicationList::class)->forViewer($this->mine, false))->pluck('name')->all();
        $this->assertSame(['Checked current'], $forFamily);
    }

    private function staff(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null,
        ]);

        return $user->fresh();
    }
}
