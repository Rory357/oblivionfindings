<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationRefusalFollowup;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
                'second_person_scope' => 1,
            ], MedicationReadQueryInventory::counts($reads), MedicationReadQueryInventory::describe($reads));
            $confirmationRead = $reads['second_person_scope'][0];
            $this->assertStringContainsString('has_effective_administration', $confirmationRead['query']);
            $this->assertMatchesRegularExpression('/`nominated_user_id` = \\? and `status` = \\?/i', $confirmationRead['query']);
            $this->assertSame([$worker->id, 'pending'], array_slice($confirmationRead['bindings'], -2));
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

    public function test_query_inventory_distinguishes_named_confirmation_scope_from_unknown_parent_reads(): void
    {
        $baseline = [
            'scheduled_window' => 0, 'board_day' => 0, 'prn_unresolved' => 0,
            'administration_batch' => 0, 'followup_scope' => 0, 'refusal_scope' => 0, 'unexpected' => 0,
        ];
        $this->assertSame($baseline, MedicationReadQueryInventory::counts(MedicationReadQueryInventory::fromLog([])));
        $confirmation = [
            'query' => 'select `medication_second_person_confirmations`.*, exists(select * from `client_medication_administrations` where `medication_second_person_confirmations`.`administration_id` = `client_medication_administrations`.`id`) as `has_effective_administration` from `medication_second_person_confirmations` where `nominated_user_id` = ? and `status` = ?',
            'bindings' => [42, 'pending'],
        ];
        $unknown = [
            'query' => 'select * from `unrecognised_confirmation_parent` where exists(select * from `medication_second_person_confirmations` where exists(select * from `client_medication_administrations`))',
            'bindings' => [],
        ];
        $reads = MedicationReadQueryInventory::fromLog([$confirmation, $unknown]);
        $this->assertSame([$confirmation], $reads['second_person_scope']);
        $this->assertSame([$unknown], $reads['unexpected']);
        $this->assertSame([
            ...$baseline, 'unexpected' => 1, 'second_person_scope' => 1,
        ], MedicationReadQueryInventory::counts($reads));
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

    public function test_round_export_capability_requires_the_exact_medication_report_permissions(): void
    {
        ['worker' => $worker] = $this->personSelectionFixture();
        $before = $this->personSelectionEvidence();

        $this->grantPermissions($worker, ['reports.viewAny']);
        $genericReader = $worker->fresh();
        $this->assertTrue($genericReader->canDo('reports.viewAny'));
        $this->assertFalse($genericReader->canDo('medications.reports.view'));
        $this->assertFalse($genericReader->canDo('medications.reports.export'));
        $this->actingAs($genericReader)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.export_round', false));

        $this->grantPermissions($worker, ['medications.reports.export']);
        $exportOnly = $worker->fresh();
        $this->assertFalse($exportOnly->canDo('medications.reports.view'));
        $this->assertTrue($exportOnly->canDo('medications.reports.export'));
        $this->actingAs($exportOnly)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.export_round', false));

        $this->grantPermissions($worker, ['medications.reports.view']);
        $medicationReporter = $worker->fresh();
        $this->assertTrue($medicationReporter->canDo('medications.reports.view'));
        $this->assertTrue($medicationReporter->canDo('medications.reports.export'));
        $this->actingAs($medicationReporter)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.export_round', true));

        $this->denyPermissions($worker, ['medications.reports.view']);
        $revokedReader = $worker->fresh();
        $this->assertFalse($revokedReader->canDo('medications.reports.view'));
        $this->assertTrue($revokedReader->canDo('medications.reports.export'));
        $this->actingAs($revokedReader)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.export_round', false));

        $this->assertSame($before, $this->personSelectionEvidence());
    }

    #[DataProvider('personSelectionAliases')]
    public function test_person_selection_scopes_the_whole_board_and_partial_activity(string $alias, bool $withConflictingAlias = false): void
    {
        $fixture = $this->personSelectionFixture();
        ['worker' => $worker, 'people' => $people, 'orders' => $orders, 'round' => $round] = $fixture;
        [$selected, $other, $offShift] = $people;
        $query = [$alias => $selected->id, 'view' => 'activity', 'round' => $round->id, 'date' => '2026-04-30'];
        if ($withConflictingAlias) {
            $query += ['client' => $other->id, 'pp' => $other->id];
        }
        $before = $this->personSelectionEvidence();
        $response = $this->actingAs($worker)->get('/meds/today?'.http_build_query($query))->assertOk();
        $this->assertSame($selected->id, $response->inertiaProps('selected_client_id'));
        $this->assertEqualsCanonicalizing(
            [$selected->id, $other->id, $offShift->id],
            collect($response->inertiaProps('person_options'))->pluck('id')->all(),
        );
        $this->assertSame([$selected->id], collect($response->inertiaProps('clients'))->pluck('id')->all());
        foreach (['schedule', 'due_now', 'due_later', 'prn_medications', 'prn_follow_ups', 'refusal_follow_ups', 'prn_recorded_today', 'activity_page.data', 'guidedRound.items'] as $path) {
            $this->assertNotEmpty($response->inertiaProps($path), $path);
            $this->assertSame([$selected->id], collect($response->inertiaProps($path))->pluck('client_id')->unique()->values()->all(), $path);
        }
        $this->assertSame([
            'meds_due' => 2, 'meds_overdue' => 0, 'due_now' => 1, 'due_later' => 1, 'upcoming_rounds' => 1,
        ], $response->inertiaProps('stats'));
        // The future fixture is beyond the inclusive due-soon boundary,
        // while the current dose is inside its canonical NZ window.
        $this->assertSame('due', $response->inertiaProps('due_now.0.status'));
        $this->assertSame('upcoming', $response->inertiaProps('due_later.0.status'));
        $this->assertSame('2026-04-30 09:30', Carbon::parse($response->inertiaProps('due_now.0.scheduled_for'))->timezone(config('app.worker_timezone'))->format('Y-m-d H:i'));
        $this->assertSame('2026-04-30 11:30', Carbon::parse($response->inertiaProps('due_later.0.scheduled_for'))->timezone(config('app.worker_timezone'))->format('Y-m-d H:i'));
        $this->assertSame(1, $response->inertiaProps('hidden_controlled_doses'));
        $this->assertSame(0, $response->inertiaProps('hidden_controlled_overdue'));
        $this->assertSame(0, $response->inertiaProps('people_after_clock_in'));
        $this->assertSame([], $response->inertiaProps('off_shift'));
        $this->assertSame([$selected->id], $response->inertiaProps('mar_client_ids'));
        $this->assertSame([$orders[$selected->id]['stock']->id], collect($response->inertiaProps('stock_alerts'))->pluck('id')->all());
        $this->assertSame([$orders[$selected->id]['event']->id], collect($response->inertiaProps('activity'))->pluck('id')->all());
        $this->assertSame(2, $response->inertiaProps('activity_page.total'));
        $roundKey = $orders[$selected->id]['scheduled']->id.':'.Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'))->utc()->format('YmdHi');
        foreach (['active_round', 'rounds.0', 'upcoming_rounds.0'] as $path) {
            $this->assertSame([$roundKey], $response->inertiaProps($path.'.dose_keys'));
            $this->assertSame(1, $response->inertiaProps($path.'.total'));
        }
        $this->assertSame(2, $response->inertiaProps('guidedRound.progress.total'));
        $this->assertSame(1, $response->inertiaProps('guidedRound.selected_progress.total'));
        $this->assertFalse($response->inertiaProps('guidedRound.can_complete'));
        $this->assertStringNotContainsString('PRIVATE FILTER CONTROLLED', $response->getContent());
        $this->assertSame($before, $this->personSelectionEvidence());

        // Optional activity is also selected when Inertia requests only that
        // prop while the page URL still has its default schedule view.
        unset($query['view'], $query['round']);
        $partial = $this->get(
            '/meds/today?'.http_build_query($query),
            $this->inertiaPartialHeaders('meds/today/index', 'activity_page'),
        )->assertOk();
        $this->assertSame(2, $partial->json('props.activity_page.total'));
        $this->assertSame([$selected->id], collect($partial->json('props.activity_page.data'))->pluck('client_id')->unique()->values()->all());
        $this->assertSame($before, $this->personSelectionEvidence());
    }

    public static function personSelectionAliases(): array
    {
        return [
            'canonical' => ['client_id'],
            'legacy_client' => ['client'],
            'legacy_pp' => ['pp'],
            'canonical_wins' => ['client_id', true],
        ];
    }

    public function test_selected_off_shift_person_stays_off_shift_and_keeps_their_name_available(): void
    {
        ['worker' => $worker, 'people' => $people] = $this->personSelectionFixture();
        $offShift = $people[2];
        $before = $this->personSelectionEvidence();
        $response = $this->actingAs($worker)->get('/meds/today?client_id='.$offShift->id.'&view=activity')->assertOk();
        $this->assertSame($offShift->id, $response->inertiaProps('selected_client_id'));
        $this->assertCount(3, $response->inertiaProps('person_options'));
        $option = collect($response->inertiaProps('person_options'))->firstWhere('id', $offShift->id);
        $this->assertSame($offShift->first_name, $option['preferred']);
        foreach (['clients', 'schedule', 'prn_medications', 'prn_follow_ups', 'refusal_follow_ups', 'prn_recorded_today', 'stock_alerts', 'activity', 'rounds', 'upcoming_rounds', 'mar_client_ids'] as $path) {
            $this->assertSame([], $response->inertiaProps($path), $path);
        }
        $this->assertSame(null, $response->inertiaProps('active_round'));
        $this->assertSame(0, $response->inertiaProps('stats.meds_due'));
        $this->assertSame(0, $response->inertiaProps('activity_page.total'));
        $this->assertSame(0, $response->inertiaProps('hidden_controlled_doses'));
        $this->assertCount(1, $response->inertiaProps('off_shift'));
        $this->assertSame($offShift->id, $response->inertiaProps('off_shift.0.client_id'));
        $this->assertSame('notOnShift', $response->inertiaProps('off_shift.0.req.block_all'));
        $this->assertSame($before, $this->personSelectionEvidence());
    }

    public function test_inaccessible_or_invalid_person_selection_does_not_fall_back_to_everyone(): void
    {
        ['worker' => $worker, 'people' => $people, 'foreign' => $foreign, 'unassigned' => $unassigned] = $this->personSelectionFixture();
        $before = $this->personSelectionEvidence();
        foreach ([
            ['client_id' => $foreign->id], ['client' => $unassigned->id], ['pp' => $foreign->id],
            ['client_id' => 'not-a-person', 'pp' => $people[0]->id], ['client_id' => 0], ['client_id' => -1],
            ['client_id' => [$people[0]->id]], ['client_id' => 999999999],
        ] as $query) {
            $this->actingAs($worker)->get('/meds/today?'.http_build_query($query))->assertNotFound();
            $this->assertSame($before, $this->personSelectionEvidence());
        }
        $all = $this->actingAs($worker)->get('/meds/today?client_id=&pp='.$people[0]->id)->assertOk();
        $this->assertNull($all->inertiaProps('selected_client_id'));
        $this->assertEqualsCanonicalizing([$people[0]->id, $people[1]->id], collect($all->inertiaProps('schedule'))->pluck('client_id')->unique()->all());
        $this->assertSame([$people[2]->id], collect($all->inertiaProps('off_shift'))->pluck('client_id')->unique()->all());
        $this->assertEqualsCanonicalizing([$people[0]->id, $people[1]->id, $people[2]->id], collect($all->inertiaProps('person_options'))->pluck('id')->all());
        $this->assertStringNotContainsString('PRIVATE FILTER FOREIGN', $all->getContent());
        $this->assertStringNotContainsString('PRIVATE FILTER UNASSIGNED', $all->getContent());
        $this->assertSame($before, $this->personSelectionEvidence());
    }

    public function test_selected_round_doses_cannot_make_the_shared_round_complete(): void
    {
        ['worker' => $worker, 'people' => $people, 'orders' => $orders, 'round' => $round] = $this->personSelectionFixture();
        $selected = $people[0];
        ClientMedicationAdministration::query()->create([
            'client_id' => $selected->id, 'client_medication_id' => $orders[$selected->id]['scheduled']->id,
            'medication_round_id' => $round->id, 'scheduled_for' => Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'))->utc(),
            'administered_at' => now(), 'administered_by' => $worker->id, 'status' => 'given',
        ]);
        $before = $this->personSelectionEvidence();
        $response = $this->actingAs($worker)->get('/meds/today?client_id='.$selected->id.'&round='.$round->id)->assertOk();
        $this->assertSame(1, $response->inertiaProps('guidedRound.selected_progress.total'));
        $this->assertSame(1, $response->inertiaProps('guidedRound.selected_progress.completed'));
        $this->assertSame(2, $response->inertiaProps('guidedRound.progress.total'));
        $this->assertSame(1, $response->inertiaProps('guidedRound.progress.completed'));
        $this->assertFalse($response->inertiaProps('guidedRound.can_complete'));
        $this->postJson(route('meds.round.complete', $round), [
            'return_to' => 'meds-today', 'client_id' => $selected->id, 'site_id' => $selected->site_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('round');
        $this->assertSame('in_progress', $round->fresh()->status);
        $this->assertSame($before, $this->personSelectionEvidence());
    }

    public function test_round_entry_and_transitions_keep_validated_person_day_and_site_context(): void
    {
        ['worker' => $worker, 'people' => $people, 'orders' => $orders, 'round' => $round, 'foreign' => $foreign] = $this->personSelectionFixture();
        $selected = $people[0];
        $round->forceFill(['status' => 'pending', 'started_by' => null, 'started_at' => null])->save();
        $context = ['client_id' => $selected->id, 'site_id' => $selected->site_id];
        $target = route('meds.today', ['view' => 'rounds', 'round' => $round->id, 'date' => '2026-04-30', ...$context]);
        $entry = route('meds.round.show', ['round' => $round->id, 'date' => '2026-04-30', ...$context]);
        $before = $this->personSelectionEvidence();
        $board = $this->actingAs($worker)->get('/meds/today?'.http_build_query($context))->assertOk();
        $this->assertSame($entry, $board->inertiaProps('active_round.url'));
        $this->assertSame($entry, $board->inertiaProps('rounds.0.url'));
        $this->get($entry)->assertRedirect($target);
        $this->assertSame($before, $this->personSelectionEvidence());
        foreach ([
            ['client_id' => $foreign->id], ['client_id' => $people[2]->id],
            ['client_id' => 'invalid'], ['site_id' => $foreign->site_id], ['site_id' => 'invalid'],
        ] as $invalid) {
            $badContext = [...$context, ...$invalid];
            $this->get(route('meds.round.show', ['round' => $round->id, ...$badContext]))->assertNotFound();
            $this->postJson(route('meds.round.start', $round), ['return_to' => 'meds-today', ...$badContext])->assertNotFound();
            $this->assertSame($before, $this->personSelectionEvidence());
        }
        // Supplied dates and return URLs cannot override the canonical round
        // day or introduce a destination outside the fixed board route.
        $this->post(route('meds.round.start', $round), [
            'return_to' => 'meds-today', 'date' => '2026-05-01', 'return_url' => 'https://example.invalid/', ...$context,
        ])->assertRedirect($target);
        $this->assertSame('in_progress', $round->fresh()->status);
        $started = $this->personSelectionEvidence();
        $this->post(route('meds.round.start', $round), ['return_to' => 'meds-today', ...$context])->assertRedirect($target);
        $this->assertSame($started, $this->personSelectionEvidence());
        $this->postJson(route('meds.round.complete', $round), [
            'return_to' => 'meds-today', ...$context, 'site_id' => $foreign->site_id,
        ])->assertNotFound();
        $this->postJson(route('meds.round.complete', $round), ['return_to' => 'meds-today', ...$context])
            ->assertUnprocessable()->assertJsonValidationErrors('round');
        $this->assertSame($started, $this->personSelectionEvidence());

        // Existing completed history needs a real round-linked dose to bind
        // the selected person. This tests replay, not clinical completion.
        $historicalDose = ClientMedicationAdministration::query()->create([
            'client_id' => $selected->id, 'client_medication_id' => $orders[$selected->id]['scheduled']->id,
            'medication_round_id' => $round->id, 'scheduled_for' => Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'))->utc(),
            'administered_at' => now(), 'administered_by' => $worker->id, 'status' => 'given',
        ]);
        $round->forceFill(['status' => 'completed', 'completed_by' => $worker->id, 'completed_at' => now()])->save();
        $history = app(GuidedRoundService::class)->items($round->fresh(), false, [$selected->id]);
        $this->assertCount(1, $history);
        $this->assertSame($selected->id, $history[0]['client_id']);
        $this->assertSame($historicalDose->id, $history[0]['administration']['id']);
        $this->assertSame('given', $history[0]['administration']['status']);
        $completed = $this->personSelectionEvidence();
        $this->post(route('meds.round.complete', $round), ['return_to' => 'meds-today', ...$context])->assertRedirect($target);
        $this->post(route('meds.round.complete', $round), $context)
            ->assertRedirect(route('meds.round.show', ['round' => $round->id, ...$context]));
        $this->assertSame($completed, $this->personSelectionEvidence());
    }

    private function personSelectionFixture(): array
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', $timezone)->utc());
        $this->seed(RbacSeeder::class);
        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.view', 'medications.administer.record']);
        $this->denyPermissions($worker, [
            'medications.controlled.view', 'medications.controlled.record', 'clinical.accessAllSites', 'sites.viewAll', 'clients.viewAny',
            'medications.stock.update', 'medications.audit.view', 'medications.reports.view', 'medications.reports.export',
        ]);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true,
        ]);
        $context = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);
        $people = [];
        $orders = [];
        foreach (['Aroha', 'Hemi', 'Mere'] as $index => $name) {
            $person = Client::factory()->create([
                'first_name' => $name, 'last_name' => 'Filter fixture', 'preferred_name' => null,
                'site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active',
            ]);
            $person->supportWorkers()->attach($worker->id);
            $people[] = $person;
            if ($index < 2) {
                Shift::factory()->create([
                    'client_id' => $person->id, 'site_id' => $site->id, 'service_context_id' => $context->id, 'user_id' => $worker->id,
                    'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(4), 'actual_starts_at' => now()->subHour(), 'status' => 'in_progress',
                ]);
            }
            $scheduled = Carbon::withTestNow(Carbon::parse('2026-04-30 00:00', $timezone)->utc(), fn () => ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => 'Scheduled '.$name, 'dosage' => '1 tablet', 'frequency' => 'Daily',
                'dose_times' => $index < 2 ? ['08:00', '09:30', '11:30'] : ['09:00'], 'is_prn' => false,
                'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-04-01',
            ]));
            $orders[$person->id]['scheduled'] = $scheduled;
            if ($index === 2) {
                continue;
            }
            $prn = ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => 'PRN '.$name, 'dosage' => '1 tablet', 'frequency' => 'As needed',
                'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Pain', 'active' => true, 'state' => 'active',
            ]);
            $dose = ClientMedicationAdministration::query()->create([
                'client_id' => $person->id, 'client_medication_id' => $prn->id, 'administered_by' => $worker->id,
                'administered_at' => now()->subMinutes(30), 'effect_check_due_at' => now()->addMinutes(30), 'status' => 'given',
            ]);
            $refusal = ClientMedicationAdministration::query()->create([
                'client_id' => $person->id, 'client_medication_id' => $scheduled->id, 'administered_by' => $worker->id,
                'scheduled_for' => Carbon::parse('2026-04-30 08:00', $timezone)->utc(), 'administered_at' => now()->subHour(), 'status' => 'refused',
            ]);
            MedicationRefusalFollowup::query()->create([
                'client_id' => $person->id, 'client_medication_administration_id' => $refusal->id, 'reason_category' => 'personal_choice',
                'follow_up_due_at' => now()->addHour(), 'created_by' => $worker->id, 'owner_id' => $worker->id,
            ]);
            $orders[$person->id]['stock'] = ClientMedicationStock::query()->create([
                'client_medication_id' => $prn->id, 'on_hand' => 1, 'reorder_level' => 2, 'unit' => 'tablets',
            ]);
            $orders[$person->id]['event'] = TimelineEvent::query()->create([
                'source_type' => ClientMedicationAdministration::class, 'source_id' => $dose->id,
                'occurred_at' => $dose->administered_at, 'type' => 'medication_given', 'actor_user_id' => $worker->id,
                'client_id' => $person->id, 'subject' => 'Given PRN '.$name, 'visibility' => 'internal', 'is_pinned' => false, 'created_by' => $worker->id,
            ]);
            Carbon::withTestNow(Carbon::parse('2026-04-30 00:00', $timezone)->utc(), fn () => ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => 'PRIVATE FILTER CONTROLLED '.$name, 'dosage' => '1 tablet', 'frequency' => 'Daily',
                'dose_times' => $index === 0 ? ['09:30'] : ['09:30', '10:30'], 'is_prn' => false, 'controlled_drug' => true,
                'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-04-01',
            ]));
        }
        $foreign = Client::factory()->create([
            'first_name' => 'PRIVATE FILTER FOREIGN', 'site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active',
        ]);
        $foreign->supportWorkers()->attach($worker->id);
        $unassigned = Client::factory()->create(['first_name' => 'PRIVATE FILTER UNASSIGNED', 'site_id' => $site->id, 'status' => 'active']);
        $round = MedicationRound::query()->create([
            'site_id' => $site->id, 'service_context_id' => $context->id, 'name' => 'Filter morning', 'round_date' => '2026-04-30',
            'scheduled_time' => '09:30', 'window_minutes' => 5, 'assigned_to' => $worker->id, 'status' => 'in_progress',
            'started_by' => $worker->id, 'started_at' => now()->subMinutes(5),
        ]);

        return compact('worker', 'people', 'orders', 'round', 'foreign', 'unassigned');
    }

    private function personSelectionEvidence(): array
    {
        return collect(['client_medication_administrations', 'medication_refusal_followups', 'client_medication_stocks', 'medication_rounds', 'medication_followups'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])
            ->all();
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
