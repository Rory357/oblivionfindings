<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\GuidedRoundService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicationReadQueryInventory;
use Tests\TestCase;

class WorkerMedsTodayPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_worker_meds_today_payload_sorts_due_now_and_prn_limit_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);
        $this->denyPermissions($worker, [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Worker Meds',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-04-30 13:00:00', config('app.worker_timezone'))->utc(),
            // Clocked in at 09:00: Meds today shows the people a worker is
            // assigned to or clocked in with (the person rule, C6).
            'actual_starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'status' => 'in_progress',
        ]);

        // Entered at the start of the day: a dose due before an order's entry is not owed.
        Carbon::setTestNow(Carbon::parse('2026-04-30 00:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Three times daily',
            'dose_times' => ['08:00', '10:00', '16:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());

        $prn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain, Headache',
            'max_per_day' => 2,
            'active' => true,
            'state' => 'active',
        ]);

        $untouchedPrn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Zulu untouched PRN',
            'dosage' => '5mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Breakthrough symptom',
            'max_per_day' => 2,
            'min_hours_between_doses' => 4,
            'active' => true,
            'state' => 'active',
        ]);

        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'PRIVATE CONTROLLED BOARD SCHEDULE',
            'dosage' => '5mg',
            'frequency' => 'Three times daily',
            'dose_times' => ['08:00', '10:00', '16:00'],
            'is_prn' => false,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledPrn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'PRIVATE CONTROLLED BOARD PRN',
            'dosage' => '2mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(30),
            'status' => 'given',
            'dose_given' => 'PRIVATE CONTROLLED DOSE',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledPrn->id,
            'on_hand' => 1,
            'reorder_level' => 2,
            'unit' => 'tablets',
        ]);
        TimelineEvent::query()->create([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $controlledAdministration->id,
            'occurred_at' => now()->subMinutes(30),
            'type' => 'medication_given',
            'actor_user_id' => $worker->id,
            'client_id' => $client->id,
            'subject' => 'PRIVATE CONTROLLED BOARD ACTIVITY',
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $worker->id,
        ]);

        $foreignClient = Client::factory()->create(['status' => 'active']);
        $foreignPrn = ClientMedication::query()->create([
            'client_id' => $foreignClient->id,
            'name' => 'FORGED foreign PRN',
            'dosage' => '1mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $forgedDayAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(20),
            'status' => 'given',
            'notes' => 'FORGED local-client foreign-medication administration',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $untouchedPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(10),
            'status' => 'given',
            'notes' => 'FORGED foreign-client local-medication administration',
        ]);

        $response = $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('stats.meds_due', 3)
                ->where('stats.due_now', 2)
                ->where('stats.due_later', 1)
                ->where('has_shift_context', true)
                ->where('due_now.0.status', 'overdue')
                ->where('due_now.1.status', 'due')
                ->where('due_later.0.status', 'upcoming')
                ->where('prn_medications.0.id', $prn->id)
                ->where('prn_medications.0.given_last_24h', 1)
                ->where('prn_medications.0.remaining_today', 1)
                ->where('prn_medications.0.near_limit', false)
                ->where('prn_medications.0.over_limit', false)
                ->where('prn_medications', function ($rows) use ($untouchedPrn): bool {
                    $row = collect($rows)->firstWhere('id', $untouchedPrn->id);

                    return data_get($row, 'given_last_24h') === 0
                        && data_get($row, 'remaining_today') === 2
                        && data_get($row, 'last_given_at') === null
                        && data_get($row, 'next_allowed_at') === null
                        && data_get($row, 'interval_blocked') === false;
                })
                ->where('prn_follow_ups', fn ($rows) => collect($rows)
                    ->pluck('administration_id')
                    ->doesntContain($forgedDayAdministration->id))
            );
        $this->assertStringNotContainsString('PRIVATE CONTROLLED BOARD', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE CONTROLLED DOSE', $response->getContent());
    }

    public function test_meds_due_matches_administrations_with_a_single_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);

        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Worker Meds N+1',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-04-30 13:00:00', config('app.worker_timezone'))->utc(),
            'status' => 'scheduled',
        ]);
        // The badge counts the people the worker supports or may open (C6d).
        $client->supportWorkers()->attach($worker->id);

        $this->denyPermissions($worker, ['medications.controlled.view', 'medications.controlled.record']);
        $orders = [];
        $createOrder = function (Client $person, array $times, bool $controlled = false) use (&$orders): ClientMedication {
            $order = ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => 'Query fixture medicine '.(count($orders) + 1),
                'dosage' => '1 tablet', 'frequency' => 'Scheduled', 'dose_times' => $times,
                'is_prn' => false, 'controlled_drug' => $controlled, 'active' => true, 'state' => 'active',
                'approval_status' => 'verified', 'start_date' => '2026-04-01', 'end_date' => null,
            ]);
            if (! $controlled) {
                $orders[] = $order;
            }

            return $order;
        };
        $createOrder($client, ['10:00']);
        $unassigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreign = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active',
        ]);
        $foreign->supportWorkers()->attach($worker->id);
        $hidden = [
            $createOrder($unassigned, ['10:00']), $createOrder($foreign, ['10:00']),
        ];
        $orders = [$orders[0]];

        foreach ([1, 7, 25] as $slotCount) {
            if ($slotCount === 7) {
                $createOrder($client, ['10:00', '11:00', '12:00']);
                $createOrder($client, ['10:00', '11:00', '12:00']);
            } elseif ($slotCount === 25) {
                for ($personIndex = 0; $personIndex < 2; $personIndex++) {
                    $person = Client::factory()->create([
                        'site_id' => $site->id, 'service_context_id' => $serviceContext->id, 'status' => 'active',
                    ]);
                    $person->supportWorkers()->attach($worker->id);
                    Shift::factory()->create([
                        'client_id' => $person->id, 'site_id' => $site->id, 'service_context_id' => $serviceContext->id,
                        'user_id' => $worker->id, 'starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHours(4),
                        'status' => 'scheduled',
                    ]);
                    for ($orderIndex = 0; $orderIndex < 3; $orderIndex++) {
                        $createOrder($person, ['10:00', '11:00', '12:00']);
                    }
                }
            }
            app()->forgetScopedInstances();
            auth()->forgetUser();
            Cache::forget("user:{$worker->id}:task-nav:v1");
            Cache::forget(HandleInertiaRequests::medsOverdueBadgeCacheKey(
                $worker->id, Carbon::now(config('app.worker_timezone'))->toDateString(),
            ));
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->actingAs($worker->fresh())->get('/meds/today')->assertOk();
                $reads = MedicationReadQueryInventory::fromLog(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertCount($slotCount, $response->inertiaProps('schedule'));
            $this->assertSame(
                collect($orders)->pluck('id')->sort()->values()->all(),
                collect($response->inertiaProps('schedule'))->pluck('medication_id')->unique()->sort()->values()->all(),
            );
            // Keep the original two fixed board/badge reads, and account for
            // independent canonical evidence by its actual top-level purpose.
            $this->assertSame(2, count($reads['board_day']) + count($reads['scheduled_window']));
            $this->assertSame([
                'scheduled_window' => 1, 'board_day' => 1, 'prn_unresolved' => 2,
                'administration_batch' => 0, 'followup_scope' => 1, 'refusal_scope' => 1, 'unexpected' => 0,
            ], MedicationReadQueryInventory::counts($reads), MedicationReadQueryInventory::describe($reads));
            $this->assertDatabaseCount('client_medication_administrations', 0);
        }
        // Concealed controlled doses have their own canonical count read.
        // Check that privacy contract after the ordinary board/badge growth budget.
        $hidden[] = $createOrder($client, ['10:00'], controlled: true);
        app()->forgetScopedInstances();
        auth()->forgetUser();
        $response = $this->actingAs($worker->fresh())->get('/meds/today')->assertOk();
        $this->assertSame(1, $response->inertiaProps('concealed_schedule.total'));
        $this->assertCount(25, $response->inertiaProps('schedule'));
        $this->assertSame(
            collect($orders)->pluck('id')->sort()->values()->all(),
            collect($response->inertiaProps('schedule'))->pluck('medication_id')->unique()->sort()->values()->all(),
        );
        $this->assertDatabaseCount('client_medication_administrations', 0);
        foreach ($hidden as $order) {
            $this->actingAs($worker->fresh())->getJson(route('emar.medications.detail', $order))->assertNotFound();
        }
    }

    public function test_sidebar_badge_keeps_an_overnight_shift_after_midnight(): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:20:00', $timezone)->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);

        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Overnight worker meds',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 23:20:00', $timezone)->utc(),
            'ends_at' => Carbon::parse('2026-05-01 05:00:00', $timezone)->utc(),
            'status' => 'in_progress',
        ]);
        // The badge counts the people the worker supports or may open (C6d).
        $client->supportWorkers()->attach($worker->id);

        // Entered before its 00:05 dose (a dose due before an order's entry is not owed).
        Carbon::setTestNow(Carbon::parse('2026-04-30 22:00:00', $timezone)->utc());
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Overnight tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['00:05'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        // 01:20: the 00:05 dose's window (to 01:05) has ended — overdue (C6d).
        Carbon::setTestNow(Carbon::parse('2026-05-01 01:20:00', $timezone)->utc());

        $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.medications.overdueTodayCount', 1)
                ->where('has_shift_context', true)
                ->where('clients.0.id', $client->id)
            );
    }

    public function test_round_summaries_and_keys_follow_board_people_site_and_exact_round_window(): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $now = Carbon::parse('2026-04-30 09:30:00', $timezone)->utc();
        Carbon::setTestNow($now);
        $this->seed(RbacSeeder::class);
        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);
        $this->denyPermissions($worker, ['medications.controlled.view', 'medications.controlled.record', 'clinical.accessAllSites', 'sites.viewAll']);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $secondSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [$secondSite->id],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true,
        ]);
        $context = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $hiddenClient = Client::factory()->create(['first_name' => 'CONCEALED', 'last_name' => 'Unassigned', 'site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $secondClient = Client::factory()->create(['site_id' => $secondSite->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['first_name' => 'CONCEALED', 'last_name' => 'Foreign', 'site_id' => $foreignSite->id, 'status' => 'active']);
        foreach ([$client, $secondClient, $foreignClient] as $person) {
            $person->supportWorkers()->attach($worker->id);
            Shift::factory()->create([
                'user_id' => $worker->id, 'client_id' => $person->id, 'site_id' => $person->site_id,
                'service_context_id' => $person->service_context_id, 'starts_at' => $now->copy()->subHour(),
                'ends_at' => $now->copy()->addHours(4),
                'actual_starts_at' => $person->is($client) ? $now->copy()->subHour() : null,
                'status' => $person->is($client) ? 'in_progress' : 'scheduled',
            ]);
        }
        $createOrder = function (Client $person, array $times, bool $controlled = false) use ($timezone): ClientMedication {
            return Carbon::withTestNow(Carbon::parse('2026-04-30 00:00:00', $timezone)->utc(), fn () => ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => $controlled ? 'CONCEALED Controlled' : 'Round medicine '.$person->id,
                'dosage' => '1 tablet', 'frequency' => 'Scheduled', 'dose_times' => $times, 'is_prn' => false,
                'controlled_drug' => $controlled, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
                'start_date' => '2026-04-01', 'end_date' => null,
            ]));
        };
        $order = $createOrder($client, ['09:00', '10:00']);
        $hiddenOrder = $createOrder($hiddenClient, ['08:00', '09:00']);
        $secondOrder = $createOrder($secondClient, ['09:00']);
        $foreignOrder = $createOrder($foreignClient, ['09:00']);
        $controlledOrder = $createOrder($client, ['09:00'], true);
        $dueAt = Carbon::parse('2026-04-30 09:00:00', $timezone);
        ClientMedicationAdministration::query()->create([
            'client_id' => $hiddenClient->id, 'client_medication_id' => $hiddenOrder->id, 'status' => 'given',
            'scheduled_for' => $dueAt->copy()->utc(), 'administered_at' => $dueAt->copy()->utc(), 'administered_by' => $worker->id,
        ]);
        $createRound = fn (Site $roundSite, string $time, ?int $contextId = null, string $status = 'pending') => MedicationRound::query()->create([
            'site_id' => $roundSite->id, 'service_context_id' => $contextId, 'name' => 'Round '.$roundSite->id.' '.$time,
            'round_date' => '2026-04-30', 'scheduled_time' => $time, 'window_minutes' => 10,
            'assigned_to' => $worker->id, 'status' => $status,
            'started_by' => $status === 'in_progress' ? $worker->id : null,
            'started_at' => $status === 'in_progress' ? $now : null,
        ]);
        $emptyForWorker = $createRound($site, '08:00', $context->id, 'in_progress');
        // This earlier assigned round has an actual dose, but only for an
        // unassigned person. It must not hide the next readable active round.
        $this->assertSame(1, app(GuidedRoundService::class)->progress($emptyForWorker, false)['total']);
        $this->assertSame(0, app(GuidedRoundService::class)->progress($emptyForWorker, false, [$client->id, $secondClient->id])['total']);
        $morning = $createRound($site, '09:00', $context->id, 'in_progress');
        $laterMorning = $createRound($site, '10:00', $context->id);
        $secondSiteRound = $createRound($secondSite, '09:00');
        $foreignRound = $createRound($foreignSite, '09:00');
        // The stored round really has an unassigned person's recorded dose;
        // viewer projection must narrow counts as well as named items.
        $canonical = app(GuidedRoundService::class)->progress($morning, false);
        $this->assertSame(2, $canonical['total']);
        $this->assertSame(1, $canonical['completed']);
        $keyAt = fn (ClientMedication $medication, string $time): string => $medication->id.':'.Carbon::parse('2026-04-30 '.$time, $timezone)->utc()->format('YmdHi');
        $morningKey = $keyAt($order, '09:00');
        $laterKey = $keyAt($order, '10:00');
        $secondKey = $keyAt($secondOrder, '09:00');
        $response = $this->actingAs($worker)->get('/meds/today?round='.$morning->id)->assertOk();
        $rounds = collect($response->inertiaProps('rounds'))->keyBy('id');
        $this->assertCount(3, $rounds);
        $this->assertFalse($rounds->has($emptyForWorker->id));
        $this->assertFalse($rounds->has($foreignRound->id));
        foreach ([$morning->id => $morningKey, $laterMorning->id => $laterKey, $secondSiteRound->id => $secondKey] as $roundId => $key) {
            $this->assertSame([$key], $rounds[$roundId]['dose_keys']);
            $this->assertSame(1, $rounds[$roundId]['total']);
            $this->assertSame(0, $rounds[$roundId]['completed']);
            $this->assertSame(0, $rounds[$roundId]['percent']);
        }
        $this->assertSame($dueAt->toIso8601String(), $rounds[$morning->id]['scheduled_at']);
        $this->assertSame($morning->id, $response->inertiaProps('active_round.id'));
        $this->assertSame([$morningKey], $response->inertiaProps('active_round.dose_keys'));
        $this->assertSame(1, $response->inertiaProps('active_round.total'));
        $this->assertSame(0, $response->inertiaProps('active_round.completed'));
        $this->assertSame(0, $response->inertiaProps('active_round.given'));
        $this->assertSame($dueAt->toIso8601String(), $response->inertiaProps('active_round.scheduled_at'));
        $this->assertSame(1, $response->inertiaProps('guidedRound.progress.total'));
        $this->assertSame(0, $response->inertiaProps('guidedRound.progress.completed'));
        $this->assertFalse($response->inertiaProps('guidedRound.can_complete'));
        $this->assertSame([$client->id], collect($response->inertiaProps('guidedRound.items'))->pluck('client_id')->all());
        $this->assertEqualsCanonicalizing([$morningKey, $laterKey, $secondKey], collect($response->inertiaProps('schedule'))->pluck('key')->all());
        foreach ([$hiddenOrder, $foreignOrder, $controlledOrder] as $concealed) {
            $this->assertNotContains($keyAt($concealed, '09:00'), $rounds->flatMap(fn (array $round) => $round['dose_keys'])->all());
        }
        $this->assertStringNotContainsString('CONCEALED Unassigned', $response->getContent());
        $this->assertStringNotContainsString('CONCEALED Foreign', $response->getContent());
        $this->assertStringNotContainsString('CONCEALED Controlled', $response->getContent());
        $this->assertDatabaseCount('client_medication_administrations', 1);

        // Completed-round evidence is narrowed by the same current reader scope.
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $order->id, 'status' => 'given',
            'medication_round_id' => $morning->id, 'scheduled_for' => $dueAt->copy()->utc(),
            'administered_at' => $dueAt->copy()->utc(), 'administered_by' => $worker->id,
        ]);
        $morning->update(['status' => 'completed', 'completed_by' => $worker->id, 'completed_at' => $now]);
        $completedResponse = $this->actingAs($worker->fresh())->get('/meds/today')->assertOk();
        $completed = collect($completedResponse->inertiaProps('rounds'))->firstWhere('id', $morning->id);
        $this->assertSame([$morningKey], $completed['dose_keys']);
        $this->assertSame(1, $completed['total']);
        $this->assertSame(1, $completed['completed']);
        $this->assertSame(100, $completed['percent']);
        $this->assertStringNotContainsString('CONCEALED Unassigned', $completedResponse->getContent());
        $this->assertSame($secondSiteRound->id, $completedResponse->inertiaProps('active_round.id'));
        $this->assertSame(1, $completedResponse->inertiaProps('active_round.total'));
        $this->assertDatabaseCount('client_medication_administrations', 2);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function denyPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
