<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationReview;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site scope is not person scope. The Site-wide eMAR lists (PRN records,
 * Errors, Reviews, Destructions, Stock, the medicines register and
 * self-administration) show an ordinary support worker only
 * the residents whose chart they may open (ClientPolicy::viewMedications):
 * assigned, or covered by their clocked-in shift. Leads and medication
 * operations roles keep the whole Site.
 */
class EmarListPersonScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $worker;

    private Client $assigned;

    private Client $covered;

    private Client $unassigned;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->worker = $this->supportWorker();
        $this->assigned = $this->resident('Aroha', 'Assigned');
        $this->covered = $this->resident('Rewi', 'Covered');
        $this->unassigned = $this->resident('Hemi', 'Unassigned');
        $this->assigned->supportWorkers()->attach($this->worker->id);
        $this->clockedInShift($this->worker, $this->covered);
    }

    public function test_prn_records_list_only_the_residents_a_support_worker_may_open(): void
    {
        foreach ($this->residents() as $client) {
            $medication = $this->medication($client, 'Paracetamol PRN', prn: true);
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'administered_by' => $this->worker->id,
                'administered_at' => now()->subHour(),
                'status' => 'given',
            ]);
        }

        $page = $this->actingAs($this->worker)->get(route('emar.prn'))->assertOk();
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('administrations'), 'client_id'));
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('history.data'), 'client_id'));
        $this->assertSame(2, $page->inertiaProps('history.meta.total'));
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('pending_reviews'), 'client_id'));

        $lead = $this->actingAs($this->lead())->get(route('emar.prn'))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($lead->inertiaProps('history.data'), 'client_id'));
    }

    public function test_medication_errors_list_and_stats_only_count_residents_a_support_worker_may_open(): void
    {
        $foreign = $this->foreignResident();
        $errorIds = collect([...$this->residents(), $foreign])->mapWithKeys(fn (Client $client) => [
            $client->id => MedicationError::query()->create([
                'client_id' => $client->id,
                'error_type' => 'omission',
                'severity' => 'near_miss',
                'reached_client' => 'no',
                'harm_level' => 'none',
                'description' => 'Dose missed but caught.',
                'status' => 'reported',
                'reported_by' => $this->worker->id,
                'reported_at' => now(),
            ])->id,
        ]);

        $page = $this->actingAs($this->worker)->get(route('emar.errors'))->assertOk();
        $this->assertSame(
            collect([$errorIds[$this->assigned->id], $errorIds[$this->covered->id]])->sort()->values()->all(),
            $this->sortedIds($page->inertiaProps('errors'), 'id'),
        );
        $this->assertSame(2, $page->inertiaProps('stats.total_open'));
        $this->assertSame(2, $page->inertiaProps('stats.near_miss'));
        foreach ([$this->unassigned, $foreign] as $hidden) {
            $this->actingAs($this->worker)
                ->get(route('emar.errors', ['error' => $errorIds[$hidden->id]]))
                ->assertNotFound();
        }

        $lead = $this->lead(['medications.errors.manage']);
        $page = $this->actingAs($lead)->get(route('emar.errors'))->assertOk();
        $this->assertCount(3, $page->inertiaProps('errors'));
        $this->assertSame(3, $page->inertiaProps('stats.total_open'));
        $this->assertSame(3, $page->inertiaProps('stats.near_miss'));
        $this->actingAs($lead)->get(route('emar.errors', ['error' => $errorIds[$foreign->id]]))->assertNotFound();
    }

    public function test_medication_reviews_list_only_the_residents_a_support_worker_may_open(): void
    {
        $foreign = $this->foreignResident();
        $reviewIds = collect([...$this->residents(), $foreign])->mapWithKeys(fn (Client $client) => [
            $client->id => MedicationReview::query()->create([
                'client_id' => $client->id,
                'review_type' => 'routine',
                'status' => 'scheduled',
                'scheduled_date' => now()->addWeek()->toDateString(),
            ])->id,
        ]);

        $page = $this->actingAs($this->worker)->get(route('emar.reviews'))->assertOk();
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('reviews.data'), 'client.id'));
        $this->assertSame(2, $page->inertiaProps('meters.due_30'));
        foreach ([$this->unassigned, $foreign] as $hidden) {
            $this->actingAs($this->worker)
                ->get(route('emar.reviews', ['review' => $reviewIds[$hidden->id]]))
                ->assertNotFound();
        }

        $lead = $this->lead();
        $page = $this->actingAs($lead)->get(route('emar.reviews'))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($page->inertiaProps('reviews.data'), 'client.id'));
        $this->assertSame(3, $page->inertiaProps('meters.due_30'));
        $this->actingAs($lead)->get(route('emar.reviews', ['review' => $reviewIds[$foreign->id]]))->assertNotFound();
    }

    public function test_destructions_register_and_its_medicine_list_only_show_residents_a_support_worker_may_open(): void
    {
        // Controlled-view permission still requires current site and person access.
        $this->assertTrue($this->worker->canDo('medications.controlled.view'));
        $foreign = $this->foreignResident();
        $medicationIds = [];
        $destructionIds = [];
        foreach ([...$this->residents(), $foreign] as $client) {
            $medication = $this->medication($client, 'Synthetic controlled medicine', overrides: [
                'controlled_drug' => true,
                'nz_controlled_class' => 'B',
                'controlled_class_source' => 'Synthetic checked test fixture',
                'controlled_class_reviewed_at' => now(),
                'controlled_class_reviewed_by' => $this->worker->id,
            ]);
            $medicationIds[$client->id] = $medication->id;
            $destructionIds[$client->id] = MedicationDestruction::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'site_id' => $client->site_id,
                'medication_name' => $medication->name,
                'quantity' => 4,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'destroyed_by' => $this->worker->id,
                'witness_1_id' => User::factory()->create()->id,
                'destroyed_at' => now(),
                'is_controlled_drug' => true,
                'controlled_drug_class' => 'B',
            ])->id;
        }

        $this->actingAs($this->worker)->get(route('emar.destructions'))
            ->assertRedirect('/emar/controlled?view=destructions');
        $page = $this->actingAs($this->worker)->get(route('emar.controlled', ['view' => 'destructions']))->assertOk();
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('product.medicines'), 'client_id'));
        $this->assertSame(
            collect($destructionIds)->only($this->visibleIds())->sort()->values()->all(),
            $this->sortedIds($page->inertiaProps('product.destructions'), 'id'),
        );
        $this->assertSame(
            collect($medicationIds)->only($this->visibleIds())->sort()->values()->all(),
            $this->sortedIds($page->inertiaProps('product.destructions'), 'client_medication_id'),
        );
        foreach ([$this->unassigned, $foreign] as $hidden) {
            $this->actingAs($this->worker)
                ->getJson(route('emar.controlled.product', ['client_medication_id' => $medicationIds[$hidden->id]]))
                ->assertNotFound();
        }

        $lead = $this->lead(['medications.controlled.view']);
        $page = $this->actingAs($lead)->get(route('emar.controlled', ['view' => 'destructions']))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($page->inertiaProps('product.medicines'), 'client_id'));
        $this->assertSame(
            collect($destructionIds)->only($this->allIds())->sort()->values()->all(),
            $this->sortedIds($page->inertiaProps('product.destructions'), 'id'),
        );
        $this->actingAs($lead)
            ->getJson(route('emar.controlled.product', ['client_medication_id' => $medicationIds[$foreign->id]]))
            ->assertNotFound();
    }

    public function test_stock_is_only_for_medication_operations_roles_which_keep_the_whole_site(): void
    {
        foreach ($this->residents() as $client) {
            ClientMedicationStock::query()->create([
                'client_medication_id' => $this->medication($client, 'Paracetamol')->id,
                'on_hand' => 20,
                'unit' => 'tablets',
                'reorder_level' => 5,
            ]);
        }

        // An ordinary support worker cannot open the stock register at all.
        $this->actingAs($this->worker)->get(route('emar.stock'))->assertForbidden();

        // medications.stock.update is Site-wide medication authority under
        // viewMedications, so a stock keeper sees every resident's stock.
        $stockKeeper = $this->supportWorker(grant: ['medications.stock.update']);
        $page = $this->actingAs($stockKeeper)->get(route('emar.stock'))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($page->inertiaProps('stockItems'), 'client_id'));
    }

    public function test_the_medicines_register_lists_only_the_residents_a_support_worker_may_open(): void
    {
        foreach ($this->residents() as $client) {
            $this->medication($client, 'Metformin');
        }

        $page = $this->actingAs($this->worker)->get(route('emar.medications'))->assertOk();
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('medications'), 'client_id'));

        // A named person passes the same per-person gate (P02): a resident the
        // worker may not open is not found, like any other record.
        $this->actingAs($this->worker)
            ->get(route('emar.medications', ['client_id' => $this->unassigned->id]))
            ->assertNotFound();
        $this->actingAs($this->worker)
            ->get(route('emar.medications', ['client_id' => $this->assigned->id]))
            ->assertOk();

        $lead = $this->actingAs($this->lead())->get(route('emar.medications'))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($lead->inertiaProps('medications'), 'client_id'));
    }

    public function test_a_medicines_details_follow_the_per_person_gate(): void
    {
        $hidden = $this->medication($this->unassigned, 'Metformin');
        $readable = $this->medication($this->assigned, 'Metformin');

        $this->actingAs($this->worker)
            ->getJson(route('emar.medications.detail', $hidden))
            ->assertNotFound();
        $this->actingAs($this->worker)
            ->getJson(route('emar.medications.detail', $readable))
            ->assertOk();
    }

    public function test_self_administration_lists_only_the_residents_a_support_worker_may_open(): void
    {
        foreach ($this->residents() as $client) {
            MedicationSelfAdminAssessment::query()->create([
                'client_id' => $client->id, 'status' => 'completed', 'outcome' => 'independent',
                'assessment_date' => now()->toDateString(),
                'cognitive_capacity' => 5, 'physical_dexterity' => 5, 'vision_ability' => 5,
                'swallowing_ability' => 5, 'understanding_score' => 5,
                'willing_to_self_admin' => true, 'wishes_to_self_administer' => true,
            ]);
        }

        $page = $this->actingAs($this->worker)->get(route('emar.self_admin'))->assertOk();
        $this->assertSame($this->visibleIds(), $this->sortedIds($page->inertiaProps('register'), 'client_id'));
        $this->assertSame(2, $page->inertiaProps('counts.people'));
        $this->assertSame(0, $page->inertiaProps('counts.self_managed'));

        $lead = $this->actingAs($this->lead())->get(route('emar.self_admin'))->assertOk();
        $this->assertSame($this->allIds(), $this->sortedIds($lead->inertiaProps('register'), 'client_id'));
    }

    /** @return array<int, Client> */
    private function residents(): array
    {
        return [$this->assigned, $this->covered, $this->unassigned];
    }

    /** @return array<int, int> */
    private function visibleIds(): array
    {
        return collect([$this->assigned->id, $this->covered->id])->sort()->values()->all();
    }

    /** @return array<int, int> */
    private function allIds(): array
    {
        return collect($this->residents())->pluck('id')->sort()->values()->all();
    }

    /** @return array<int, int> */
    private function sortedIds(mixed $rows, string $key): array
    {
        return collect($rows)->pluck($key)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    private function resident(string $first, string $last): Client
    {
        return Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
    }

    /** Assignment cannot bypass the reader's approved-site boundary. */
    private function foreignResident(): Client
    {
        $client = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'status' => 'active',
        ]);
        $client->supportWorkers()->attach($this->worker->id);

        return $client;
    }

    private function medication(Client $client, string $name, bool $prn = false, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '500mg',
            'frequency' => $prn ? 'As needed' : 'Daily',
            'dose_times' => $prn ? [] : ['08:00'],
            'is_prn' => $prn,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ], $overrides));
    }

    /** A clocked-in shift covering one resident (recording authority). */
    private function clockedInShift(User $worker, Client $client): Shift
    {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'service_context_id' => ServiceContext::factory()->create([
                'type' => 'residential',
                'is_active' => true,
                'site_id' => $this->site->id,
            ])->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(4),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'started_by' => $worker->id,
        ]);
    }

    /** The seeded Support Worker role (assignment-scoped medication reader). */
    private function supportWorker(array $grant = []): User
    {
        $user = $this->siteStaff();
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $user->permissionOverrides()->sync(
            Permission::query()->whereIn('key', $grant)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );

        return $user->refresh();
    }

    /** A Site-wide reader: clients.viewAny keeps the whole Site. */
    private function lead(array $extra = []): User
    {
        $user = $this->siteStaff();
        $permissions = ['medications.view', 'clients.viewAny', ...$extra];
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $ids);
        $user->permissionOverrides()->sync(
            $ids->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->refresh();
    }

    private function siteStaff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);

        return $user;
    }
}
