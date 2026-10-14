<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EA-106 correction delegation: a correction through /api/medications goes
 * through the person record's correction path — the per-person rule, the
 * canonical correction writer and its P09 event. Someone who holds only the
 * correction right keeps correcting wherever the person rule lets them reach
 * the person (assigned there, or clocked in on a shift covering them).
 */
final class ApiCorrectionDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $person;

    private ClientMedicationAdministration $dose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $medication = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Paracetamol', 'controlled_drug' => false,
        ]);
        $this->dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->person->id, 'client_medication_id' => $medication->id,
            'administered_by' => User::factory()->create()->id, 'status' => 'given', 'dose_given' => '1 tablet',
            'administered_at' => now()->subHour(), 'is_correction' => false,
        ]);
    }

    public function test_ea106_a_correction_only_actor_who_may_not_reach_the_person_is_not_found(): void
    {
        // Compare the bodies as production renders them (no debug trace).
        config()->set('app.debug', false);
        $actor = $this->staff(['medications.administer.correct']);

        $missing = $this->actingAs($actor)->postJson($this->url(999999), $this->payload())->assertNotFound();
        $this->actingAs($actor)->postJson($this->url(), $this->payload())->assertNotFound()
            ->assertContent($missing->getContent());
        $this->assertSame(0, ClientMedicationAdministration::query()->where('is_correction', true)->count());
    }

    public function test_ea106_a_reader_at_the_house_who_may_not_open_the_person_is_not_found(): void
    {
        $actor = $this->staff(['clients.viewAssigned', 'medications.view', 'medications.administer.correct']);

        $this->actingAs($actor)->postJson($this->url(), $this->payload())->assertNotFound();
        $this->assertSame(0, ClientMedicationAdministration::query()->where('is_correction', true)->count());
    }

    public function test_ea106_a_correction_only_actor_assigned_to_the_person_corrects_through_the_canonical_path(): void
    {
        $actor = $this->staff(['medications.administer.correct']);
        $this->person->supportWorkers()->attach($actor->id);

        $response = $this->actingAs($actor)->postJson($this->url(), $this->payload())->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('correction.status', 'refused')
            ->assertJsonPath('correction.is_correction', true)
            ->assertJsonPath('correction.correction_status', 'pending');

        $correction = ClientMedicationAdministration::query()->findOrFail($response->json('correction.id'));
        $this->assertSame($this->dose->id, (int) $correction->corrected_of_id);
        $this->assertSame($actor->id, (int) $correction->correction_requested_by);
        // The P09 event the person record's corrections write.
        $event = MedicationEvent::query()->where('kind', 'correction.requested')->sole();
        $this->assertSame($this->person->id, $event->client_id);
        $this->assertSame((string) $correction->id, (string) $event->subject_id);

        // A second pending correction is still a conflict on this API.
        $this->actingAs($actor)->postJson($this->url(), $this->payload())->assertConflict();
    }

    public function test_ea106_a_correction_only_actor_clocked_in_on_the_persons_shift_corrects(): void
    {
        $actor = $this->staff(['medications.administer.correct']);
        Shift::factory()->create([
            'client_id' => $this->person->id, 'site_id' => $this->site->id, 'user_id' => $actor->id,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(6),
            'actual_starts_at' => now()->subHours(2), 'status' => 'in_progress',
        ]);

        $this->actingAs($actor)->postJson($this->url(), $this->payload())->assertOk()
            ->assertJsonPath('correction.correction_status', 'pending');
    }

    /** @param list<string> $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        ]);

        return $user->fresh();
    }

    private function url(?int $administration = null): string
    {
        return route('api.medications.administrations.correct', ['client' => $this->person->id, 'administration' => $administration ?? $this->dose->id]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['status' => 'refused', 'reason' => 'Declined', 'correction_reason' => 'Charted as given by mistake'];
    }
}
