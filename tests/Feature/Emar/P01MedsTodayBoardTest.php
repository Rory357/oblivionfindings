<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Tasks\Providers\MedicationRoundProvider;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P01 C3 — Meds today to the approved mockup: each open row carries the same
 * requirements the recording dialog reads, the header knows whether you are
 * clocked in, people you may open who aren't on your shift are listed so
 * nothing is missed, follow-ups list your open refusals and as-needed checks
 * at the time the recorder chose, and Activity pages 10 at a time.
 */
class P01MedsTodayBoardTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Pacific/Auckland';

    private User $worker;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', self::TZ)->utc());
        $this->seed(RbacSeeder::class);

        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $this->worker->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->setPermissions(['medications.view' => true, 'medications.administer.record' => true]);

        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->context = ServiceContext::factory()->create([
            'name' => 'Kōwhai residential',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $this->site->id,
        ]);
        $this->client = $this->person('Aroha', 'Ngata');
        $this->client->supportWorkers()->attach($this->worker->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_open_rows_carry_the_dialogs_requirements_and_the_header_its_facts(): void
    {
        $this->shift(clockedIn: true);
        $order = $this->order($this->client, 'Paracetamol 500mg', ['09:30']);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('clocked_in', true)
                ->where('house_label', 'Kōwhai House')
                ->where('schedule.0.medication_id', $order->id)
                ->where('schedule.0.status', 'due')
                ->where('schedule.0.req.block_all', null)
                ->where('schedule.0.req.window', 'due')
                ->where('schedule.0.window_opens_at', fn ($at) => is_string($at) && $at !== '')
                ->where('schedule.0.window_ends_at', fn ($at) => is_string($at) && $at !== '')
                ->where('mar_client_ids', [$this->client->id])
                ->where('board_extra_can.report_error', true)
                // Activity loads with its tab, not on every board load.
                ->missing('activity_page')
            );
    }

    public function test_a_worker_not_clocked_in_reads_the_doses_but_each_says_why_it_cant_be_recorded(): void
    {
        $this->shift(clockedIn: false);
        $this->order($this->client, 'Paracetamol 500mg', ['09:30']);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clocked_in', false)
                ->where('schedule.0.status', 'due')
                ->where('schedule.0.req.block_all', 'notClockedIn')
            );
    }

    public function test_people_you_may_open_who_arent_on_your_shift_are_listed_with_why(): void
    {
        $this->shift(clockedIn: true);
        $this->order($this->client, 'Paracetamol 500mg', ['09:30']);
        $hemi = $this->person('Hemi', 'Walker');
        $hemi->supportWorkers()->attach($this->worker->id);
        $hemiOrder = $this->order($hemi, 'Levetiracetam 500mg', ['09:00']);
        // Someone at the house who isn't theirs: never listed.
        $other = $this->person('Mere', 'Tane');
        $this->order($other, 'Metformin 500mg', ['09:00']);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule', fn ($rows) => collect($rows)->pluck('client_id')->unique()->values()->all() === [$this->client->id])
                ->has('off_shift', 1)
                ->where('off_shift.0.client_id', $hemi->id)
                ->where('off_shift.0.medication_id', $hemiOrder->id)
                ->where('off_shift.0.req.block_all', 'notOnShift')
            );
    }

    public function test_follow_ups_list_open_refusals_with_their_owner_and_hide_controlled_from_a_reader_without_access(): void
    {
        $this->setPermissions(['medications.controlled.view' => false, 'medications.controlled.record' => false]);
        $this->shift(clockedIn: true);
        $order = $this->order($this->client, 'Sertraline 50mg', ['08:00', '20:00']);
        $controlled = $this->order($this->client, 'PRIVATE CONTROLLED FOLLOW-UP', ['08:00', '20:00'], ['controlled_drug' => true]);
        $open = $this->refusal($order, '08:00', '10:00');
        $this->refusal($controlled, '08:00', '10:00');
        $closed = $this->refusal($order, '08:00', '09:00', alreadyClosed: true);

        $response = $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('refusal_follow_ups', 1)
                ->where('refusal_follow_ups.0.id', $open->id)
                ->where('refusal_follow_ups.0.medication_name', 'Sertraline 50mg')
                ->where('refusal_follow_ups.0.preferred', 'Aroha')
                ->where('refusal_follow_ups.0.owner', $this->worker->name)
                ->where('refusal_follow_ups.0.due_time', '10:00 am')
                ->where('refusal_follow_ups.0.overdue', false)
                ->where('refusal_follow_ups', fn ($rows) => collect($rows)->pluck('id')->doesntContain($closed->id))
            );
        $this->assertStringNotContainsString('PRIVATE CONTROLLED FOLLOW-UP', $response->getContent());
    }

    public function test_an_as_needed_follow_up_uses_the_check_time_the_recorder_chose(): void
    {
        $this->shift(clockedIn: true);
        $prn = $this->order($this->client, 'Paracetamol PRN', [], ['is_prn' => true, 'prn_reason' => 'Pain', 'max_per_day' => 4]);
        $chosen = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => Carbon::parse('2026-04-30 09:00', self::TZ)->utc(),
            'effect_check_due_at' => Carbon::parse('2026-04-30 09:20', self::TZ)->utc(),
            'status' => 'given',
            'dose_given' => '500mg',
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prn_follow_ups.0.administration_id', $chosen->id)
                ->where('prn_follow_ups.0.check_at', '9:20 am')
                ->where('prn_follow_ups.0.check_due_at', Carbon::parse('2026-04-30 09:20', self::TZ)->toIso8601String())
                ->where('prn_follow_ups.0.by', $this->worker->name)
                ->where('prn_recorded_today.0.id', $chosen->id)
                ->where('prn_recorded_today.0.check_at', '9:20 am')
            );
    }

    public function test_follow_up_readers_do_not_disclose_mismatched_person_or_medicine_links(): void
    {
        $this->shift(clockedIn: true);
        $other = $this->person('Restricted', 'Person');
        $foreignOrder = $this->order($other, 'Private medicine', ['08:00']);
        $foreignFollowUp = $this->refusal($foreignOrder, '08:00', '10:00');
        // Neither attaching somebody else's administration to a visible person,
        // nor forging the administration's medicine link grants read authority.
        $foreignFollowUp->forceFill(['client_id' => $this->client->id])->save();
        $visibleOrder = $this->order($this->client, 'Visible medicine', ['08:00']);
        $forgedFollowUp = $this->refusal($visibleOrder, '08:00', '10:00');
        $forgedFollowUp->administration->forceFill(['client_medication_id' => $foreignOrder->id])->save();
        $validFollowUp = $this->refusal($visibleOrder, '08:00', '11:00');
        $foreignPrn = $this->order($other, 'Private as-needed medicine', [], ['is_prn' => true]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $foreignPrn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subMinutes(10),
            'status' => 'given',
        ]);

        $response = $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('refusal_follow_ups', 1)
                ->where('refusal_follow_ups.0.id', $validFollowUp->id)
                ->where('refusal_follow_ups.0.medication_name', 'Visible medicine')
                ->where('prn_follow_ups', [])
            );
        $response->assertDontSee('Private medicine')->assertDontSee('Private as-needed medicine');
    }

    public function test_due_soon_retains_the_canonical_window_at_both_edges(): void
    {
        config(['medications.mar.window_before_minutes' => 15, 'medications.mar.window_after_minutes' => 15, 'medications.mar.due_soon_minutes' => 60]);
        $this->shift(clockedIn: true);
        $this->order($this->client, '10 am medicine', ['10:00']);
        foreach ([
            ['09:15', 'not_due', 'due', 'notdue'],
            ['09:45', 'due', 'due', 'due'],
            ['10:15', 'due', 'due', 'due'],
            ['10:16', 'late', 'overdue', 'late'],
        ] as [$at, $state, $status, $window]) {
            Carbon::setTestNow(Carbon::parse('2026-04-30 '.$at, self::TZ)->utc());
            $this->actingAs($this->worker)->get('/meds/today')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('schedule.0.state', $state)
                    ->where('schedule.0.status', $status)
                    ->where('schedule.0.requirements.window', $window)
                );
        }
    }

    public function test_concealed_controlled_obligations_are_counted_without_named_rows(): void
    {
        $this->setPermissions(['medications.controlled.view' => false, 'medications.controlled.record' => false]);
        $this->shift(clockedIn: true);
        $this->order($this->client, 'PRIVATE CONTROLLED ORDER', ['08:00'], ['controlled_drug' => true]);

        $response = $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule', [])
                ->where('hidden_controlled_overdue', 1)
                ->where('concealed_schedule.total', 1)
                ->where('concealed_schedule.overdue', 1)
                ->where('concealed_schedule.open', 1)
                ->where('concealed_schedule.due_so_far', 1)
                ->where('concealed_schedule.recorded_so_far', 0)
            );
        $response->assertDontSee('PRIVATE CONTROLLED ORDER');
    }

    public function test_activity_loads_with_its_tab_ten_a_page_with_outcome_filter(): void
    {
        $this->shift(clockedIn: true);
        $prn = $this->order($this->client, 'Paracetamol PRN', [], ['is_prn' => true, 'prn_reason' => 'Pain']);
        foreach (range(1, 12) as $i) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $this->client->id,
                'client_medication_id' => $prn->id,
                'administered_by' => $this->worker->id,
                'administered_at' => now()->subMinutes(10 * $i),
                'status' => 'given',
            ]);
        }
        $scheduled = $this->order($this->client, 'Sertraline 50mg', ['08:00']);
        $this->refusal($scheduled, '08:00', '10:00');

        $this->actingAs($this->worker)
            ->get('/meds/today?view=activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity_page.total', 13)
                ->has('activity_page.data', 10)
                ->where('activity_page.last_page', 2)
                ->where('activity_page.data.0.outcome', 'Given (as needed)')
                ->where('activity_page.data.0.preferred', 'Aroha')
            );

        $this->actingAs($this->worker)
            ->get('/meds/today?view=activity&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('activity_page.data', 3));

        $this->actingAs($this->worker)
            ->get('/meds/today?view=activity&outcome=notgiven')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity_page.total', 1)
                ->where('activity_page.data.0.outcome', 'Refused')
                ->where('activity_page.data.0.medication_name', 'Sertraline 50mg')
            );

        $this->actingAs($this->worker)
            ->get('/meds/today?view=activity&q=sertra')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activity_page.total', 1));
    }

    public function test_rostered_round_tasks_are_read_only_and_share_the_calendar_source(): void
    {
        $this->shift(clockedIn: true);
        $this->order($this->client, 'Morning medicine', ['09:30']);
        $round = $this->round();
        $provider = app(MedicationRoundProvider::class);

        $tasks = $provider->authorizedTasks($this->worker);
        $this->assertCount(1, $tasks);
        $this->assertNull($tasks[0]->assignee);
        $this->assertStringContainsString('view=schedule', $tasks[0]->link);
        $this->assertStringContainsString('A lead can assign', $tasks[0]->actionHelp);
        $this->actingAs($this->worker)->postJson('/meds/rounds/'.$round->id.'/guided/start')->assertForbidden();

        $events = $provider->calendar($this->worker, now(self::TZ)->startOfDay(), now(self::TZ)->endOfDay());
        $this->assertCount(1, $events);
        $this->assertSame($tasks[0]->id, $events[0]['id']);
        $this->assertSame('2026-04-30T09:30:00+12:00', $events[0]['start']);
        $this->assertArrayNotHasKey('end', $events[0]);
    }

    public function test_round_tasks_recheck_roster_person_scope_and_current_permission(): void
    {
        $this->order($this->client, 'Visible medicine', ['09:30']);
        $this->round();
        $provider = app(MedicationRoundProvider::class);
        $this->assertSame([], $provider->authorizedTasks($this->worker));
        $this->shift(clockedIn: true);
        $this->assertCount(1, $provider->authorizedTasks($this->worker));
        $this->setPermissions(['medications.view' => false, 'medications.administer.record' => false]);
        $this->assertSame([], $provider->authorizedTasks($this->worker));
    }

    public function test_rostered_round_progress_counts_concealed_work_without_disclosing_it(): void
    {
        $this->setPermissions(['medications.controlled.view' => false, 'medications.controlled.record' => false]);
        $this->shift(clockedIn: true);
        $order = $this->order($this->client, 'PRIVATE CONTROLLED TASK MEDICINE', ['09:30'], ['controlled_drug' => true]);
        $other = $this->person('PRIVATE TASK PERSON', 'Outside');
        $this->order($other, 'PRIVATE OTHER MEDICINE', ['09:30']);
        $this->round();
        $provider = app(MedicationRoundProvider::class);
        $tasks = $provider->authorizedTasks($this->worker);
        $this->assertCount(1, $tasks);
        $this->assertSame('0/1 staff doses recorded', $tasks[0]->description);
        $this->assertStringNotContainsString('PRIVATE', json_encode($tasks));

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id, 'administered_at' => now(),
            'scheduled_for' => now(), 'status' => 'given',
        ]);
        $this->assertSame([], $provider->authorizedTasks($this->worker));
        $this->assertSame('completed', $provider->authorizedTasks($this->worker, ['include_done' => true])[0]->status);
    }

    public function test_a_mismatched_effect_child_cannot_hide_an_unresolved_as_needed_check(): void
    {
        $this->shift(clockedIn: true);
        $order = $this->order($this->client, 'Visible as needed', [], ['is_prn' => true]);
        $other = $this->person('Restricted', 'Person');
        $foreign = $this->order($other, 'PRIVATE EFFECT CHILD MEDICINE', [], ['is_prn' => true]);
        $dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id, 'administered_at' => now()->subHour(), 'status' => 'given',
        ]);
        MedicationPrnEffectiveness::query()->create([
            'client_medication_administration_id' => $dose->id,
            'client_id' => $other->id, 'client_medication_id' => $foreign->id,
            'reviewed_by' => $this->worker->id, 'reviewed_at' => now(), 'effectiveness' => 'effective',
        ]);
        $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('prn_follow_ups', 1)->where('prn_follow_ups.0.administration_id', $dose->id));
    }

    private function round(): MedicationRound
    {
        return MedicationRound::query()->create([
            'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'name' => 'Morning', 'round_date' => '2026-04-30', 'scheduled_time' => '09:30',
            'window_minutes' => 30, 'status' => 'pending', 'assigned_to' => null,
        ]);
    }

    private function person(string $first, string $last): Client
    {
        return Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'preferred_name' => null,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'status' => 'active',
        ]);
    }

    private function shift(bool $clockedIn): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse('2026-04-30 07:00', self::TZ)->utc(),
            'ends_at' => Carbon::parse('2026-04-30 15:00', self::TZ)->utc(),
            'actual_starts_at' => $clockedIn ? Carbon::parse('2026-04-30 07:00', self::TZ)->utc() : null,
            'status' => $clockedIn ? 'in_progress' : 'scheduled',
        ]);
    }

    /** An order entered at the start of the day (a dose due before entry is not owed). */
    private function order(Client $client, string $name, array $times, array $extra = []): ClientMedication
    {
        $now = Carbon::now();
        Carbon::setTestNow(Carbon::parse('2026-04-30 00:00', self::TZ)->utc());
        $order = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => $times === [] ? 'As needed' : 'Daily',
            'dose_times' => $times,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            ...$extra,
        ]);
        Carbon::setTestNow($now);

        return $order;
    }

    private function refusal(ClientMedication $order, string $slot, string $dueAt, bool $alreadyClosed = false): MedicationRefusalFollowup
    {
        $refusal = ClientMedicationAdministration::query()->create([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => Carbon::parse('2026-04-30 '.$slot, self::TZ)->utc(),
            'administered_at' => Carbon::parse('2026-04-30 '.$slot, self::TZ)->utc()->addMinutes(5),
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);

        return MedicationRefusalFollowup::query()->create([
            'client_id' => $order->client_id,
            'client_medication_administration_id' => $refusal->id,
            'reason_category' => 'personal_choice',
            'follow_up_action' => RecordingContract::REFUSAL_FOLLOW_UP_ACTION,
            'follow_up_due_at' => Carbon::parse('2026-04-30 '.$dueAt, self::TZ)->utc(),
            'follow_up_completed_at' => $alreadyClosed ? now() : null,
            'created_by' => $this->worker->id,
            'owner_id' => $this->worker->id,
        ]);
    }

    /** @param  array<string, bool>  $keys */
    private function setPermissions(array $keys): void
    {
        $ids = Permission::query()->whereIn('key', array_keys($keys))->pluck('id', 'key');
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            collect($keys)->mapWithKeys(fn (bool $allowed, string $key) => [$ids[$key] => ['allowed' => $allowed]])->all(),
        );
        $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
