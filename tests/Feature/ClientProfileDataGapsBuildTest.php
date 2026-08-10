<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientBowelEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ClientNote;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientProfileDataGapsBuildTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Z Admin', 'role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    }

    public function test_client_room_assignment_updates_profile_and_site_occupancy(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $room = SiteHouseRoom::query()->create([
            'site_id' => $site->id,
            'name' => 'Room 3',
            'notes' => 'West Wing',
            'is_active' => true,
            'is_assignable' => true,
        ]);

        $this->actingAs($this->admin)
            ->put("/operations/clients/{$this->client->id}", [
                'first_name' => $this->client->first_name,
                'last_name' => $this->client->last_name,
                'status' => $this->client->status,
                'site_id' => $site->id,
                'room_id' => $room->id,
            ])
            ->assertRedirect();

        $this->client->refresh();
        $room->refresh();

        $this->assertSame($room->id, $this->client->room_id);
        $this->assertSame($this->client->id, $room->assigned_client_id);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('client.site.name', $site->name)
                ->where('client.room.name', 'Room 3')
                ->where('client.room.notes', 'West Wing'));

        $this->actingAs($this->admin)
            ->get(
                "/sites/{$site->id}",
                $this->inertiaPartialHeaders('sites/show', 'clientsData'),
            )
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.clientsData.items.0.room.id', $room->id)
            ->assertJsonPath('props.clientsData.items.0.room.name', 'Room 3');
    }

    public function test_meal_log_stores_worker_time_and_feeds_profile_meal_summary(): void
    {
        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/meal-logs", [
                'meal_type' => 'breakfast',
                'status' => 'eaten',
                'occurred_at' => now(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d').'T10:30',
                'portion_note' => 'Full serve',
                'notes' => 'Ate independently.',
            ])
            ->assertRedirect();

        $expected = CarbonImmutable::parse(
            now(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d').'T10:30',
            config('app.worker_timezone', 'Pacific/Auckland')
        )->utc();

        $this->assertDatabaseHas('client_meal_logs', [
            'client_id' => $this->client->id,
            'meal_type' => 'breakfast',
            'status' => 'eaten',
            'portion_note' => 'Full serve',
            'recorded_by' => $this->admin->id,
        ]);

        $stored = CarbonImmutable::parse(
            $this->app['db']->table('client_meal_logs')->where('client_id', $this->client->id)->value('occurred_at')
        );
        $this->assertTrue($stored->equalTo($expected), "occurred_at should be {$expected->toIso8601String()}, got {$stored->toIso8601String()}");

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('meal_logs.today.0.meal_type', 'breakfast')
                ->where('meal_logs.summary.eaten_today', 1)
                ->where('meal_logs.summary.expected_today', 3));
    }

    public function test_sleep_chart_stores_entries_and_profile_exposes_average(): void
    {
        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/health/sleep", [
                'slept_at' => '2026-06-11',
                'hours_slept' => 6.4,
                'quality' => 'fair',
                'interruptions' => 2,
                'settled_by' => '21:30',
                'woke_at' => '06:10',
                'notes' => 'Restless after midnight.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_sleep_entries', [
            'client_id' => $this->client->id,
            'slept_at' => '2026-06-11',
            'hours_slept' => 6.4,
            'quality' => 'fair',
            'recorded_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('health_monitoring.sleep.0.hours_slept', '6.4')
                ->where('health_monitoring.sleep_summary.average_7_nights', 6.4)
                ->where('health_monitoring.sleep_summary.target_hours', 7));
    }

    public function test_respite_allocation_crud_and_profile_computation(): void
    {
        $periodStart = now()->startOfYear()->toDateString();
        $periodEnd = now()->endOfYear()->toDateString();

        $this->actingAs($this->admin)
            ->post('/respite/allocations', [
                'client_id' => $this->client->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'nights_allocated' => 28,
                'funding_source' => 'NASC',
                'notes' => 'Annual carer-support allocation.',
            ])
            ->assertRedirect();

        RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'start_at' => now()->subDays(10)->startOfDay(),
            'end_at' => now()->subDays(1)->startOfDay(),
            'status' => 'completed',
        ]);
        RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'start_at' => now()->addDays(7)->startOfDay(),
            'end_at' => now()->addDays(9)->startOfDay(),
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('respite.allocation.allocated', 28)
                ->where('respite.allocation.used', 9)
                ->where('respite.allocation.booked', 2)
                ->where('respite.allocation.remaining', 17));
    }

    public function test_medication_stock_is_exposed_on_profile_medications(): void
    {
        $medication = ClientMedication::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Quetiapine',
            'active' => true,
            'state' => 'active',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 24,
            'unit' => 'doses',
            'reorder_level' => 30,
        ]);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.medications.0.stock.on_hand', 24)
                ->where('medical.medications.0.stock.unit', 'doses')
                ->where('medical.medications.0.stock.reorder_threshold', 30)
                ->where('medical.medications.0.stock.is_low', true));
    }

    public function test_care_plan_domains_are_validated_and_copied_into_reviews(): void
    {
        $this->actingAs($this->admin)
            ->post('/operations/care-plans', [
                'client_id' => $this->client->id,
                'title' => 'Daily support plan',
                'plan_type' => 'supported_living',
                'content' => [
                    'domains' => [
                        ['key' => 'daily_living', 'label' => '', 'status' => 'active', 'strategies' => []],
                    ],
                ],
            ])
            ->assertSessionHasErrors('content.domains.0.label');

        $this->actingAs($this->admin)
            ->post('/operations/care-plans', [
                'client_id' => $this->client->id,
                'title' => 'Daily support plan',
                'plan_type' => 'supported_living',
                'content' => [
                    'domains' => [
                        [
                            'key' => 'daily_living',
                            'label' => 'Daily living',
                            'status' => 'active',
                            'strategies' => [
                                ['text' => 'Meds prompted after breakfast', 'owner' => 'Key worker'],
                            ],
                        ],
                    ],
                ],
                'status' => 'active',
            ])
            ->assertRedirect();

        $plan = CarePlan::query()->where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('Daily living', $plan->content['domains'][0]['label']);

        $this->actingAs($this->admin)
            ->post("/operations/care-plans/{$plan->id}/start-review")
            ->assertRedirect();

        $review = CarePlan::query()->where('parent_id', $plan->id)->firstOrFail();
        $this->assertSame($plan->content['domains'], $review->content['domains']);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('care_plans_summary.active_plan.content.domains.0.label', 'Daily living'));
    }

    public function test_profile_exposes_assignable_workers_for_worker_editor(): void
    {
        $assigned = User::factory()->create([
            'name' => 'Assigned Worker',
            'email' => 'assigned@example.test',
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $available = User::factory()->create([
            'name' => 'Available Worker',
            'email' => 'available@example.test',
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportWorkerRole = Role::query()->where('name', 'support_worker')->firstOrFail();
        $assigned->roles()->attach($supportWorkerRole);
        $available->roles()->attach($supportWorkerRole);
        foreach ([$assigned, $available] as $worker) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $worker->id,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'end_date' => null,
            ]);
        }
        $this->client->supportWorkers()->attach($assigned->id);

        $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('assignable_workers', 2)
                ->where('assignable_workers.0.name', 'Assigned Worker')
                ->where('assignable_workers.1.name', 'Available Worker'));
    }

    public function test_existing_datetime_local_endpoints_store_worker_timezone_instants(): void
    {
        $expected = CarbonImmutable::parse('2026-06-12T10:30', config('app.worker_timezone', 'Pacific/Auckland'))->utc();

        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/health/bowel", [
                'occurred_at' => '2026-06-12T10:30',
                'bristol_type' => 4,
            ])
            ->assertRedirect();

        $bowel = ClientBowelEntry::query()->where('client_id', $this->client->id)->firstOrFail();
        $this->assertTrue(
            $bowel->occurred_at->equalTo($expected),
            "bowel occurred_at should be {$expected->toIso8601String()}, got {$bowel->occurred_at->toIso8601String()}"
        );

        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/daily-notes", [
                'body' => 'Settled well after lunch.',
                'occurred_at' => '2026-06-12T10:30',
                'follow_up_due_at' => '2026-06-12T10:30',
            ])
            ->assertRedirect();

        $note = ClientNote::query()->where('client_id', $this->client->id)->latest('id')->firstOrFail();
        $this->assertTrue($note->occurred_at->equalTo($expected));
        $this->assertTrue($note->follow_up_due_at->equalTo($expected));
    }
}
