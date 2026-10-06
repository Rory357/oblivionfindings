<?php

namespace Tests\Feature\Emar;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Clients\ClientLeaveWorkflow;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class ActualAbsenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15T16:00:00+12:00')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', [
            'clients.update', 'clients.viewAny', 'clinical.events.record', 'clinical.events.viewAssigned', 'shifts.viewAssigned',
        ])->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'position_role' => 'support_worker', 'employment_type' => 'full_time',
            'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->actor = $this->actor->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_leave_http_records_actual_departure_and_early_return_without_rewriting_the_plan(): void
    {
        config(['medications.away.from_leave' => true]);
        $this->actingAs($this->actor)->postJson('/operations/clients/'.$this->client->id.'/leave', [
            'starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'destination' => 'Family visit',
        ])->assertRedirect();
        $leave = ClientLeaveRequest::where('client_id', $this->client->id)->sole();
        $this->assertSame(1, $leave->version);
        $this->assertNull($leave->departed_at);
        $this->assertSame(['approve', 'decline', 'withdraw'], $leave->allowedActions());
        $this->action($leave, ['action' => 'approve'])->assertRedirect();
        $leave->refresh();
        $this->assertSame($this->actor->id, $leave->approved_by);
        $this->action($leave, ['action' => 'depart', 'occurred_at' => '2026-06-15T07:00:00+12:00'])->assertRedirect();
        $leave->refresh();
        $this->assertSame(['return'], $leave->allowedActions());
        $this->assertSame('2026-06-14 19:00:00', $leave->getRawOriginal('departed_at'));
        $departure = $leave->getRawOriginal('departed_at');
        $this->action($leave, ['action' => 'return', 'occurred_at' => '2026-06-15T12:00:00+12:00'])->assertRedirect();
        $leave->refresh();
        $this->assertSame('completed', $leave->status);
        $this->assertSame(4, $leave->version);
        $this->assertSame('2026-06-20', $leave->ends_on->toDateString());
        $this->assertSame($departure, $leave->getRawOriginal('departed_at'));
        $this->assertSame('2026-06-15 00:00:00', $leave->getRawOriginal('returned_at'));
        $this->assertSame($this->actor->id, $leave->returned_by);
        $this->assertSame([], $leave->allowedActions());
        $this->assertSame(['created', 'approve', 'depart', 'return'], $leave->transitions()->get()->pluck('meta.action')->all());
        $this->assertSame([1, 2, 3, 4], $leave->transitions()->get()->pluck('meta.version')->all());
        $dto = $this->actingAs($this->actor)->get('/operations/clients/'.$this->client->id)->assertOk()->inertiaProps('leave_excursions.leave.0');
        $this->assertSame(4, $dto['version']);
        $this->assertSame([], $dto['allowed_actions']);
        $this->assertTrue($dto['medication_away_enabled']);
        $this->assertSame('2026-06-14T19:00:00.000000Z', $dto['departed_at']);
        $this->assertSame('2026-06-15T00:00:00.000000Z', $dto['returned_at']);
        $this->assertSame(['created', 'approve', 'depart', 'return'], array_column($dto['history'], 'action'));
        $this->assertSame(array_fill(0, 4, $this->actor->id), array_column($dto['history'], 'actor_id'));
        $before = $this->facts();
        config(['medications.away.from_leave' => false]);
        try {
            $disabled = $this->get('/operations/clients/'.$this->client->id)->assertOk()->inertiaProps('leave_excursions.leave.0');
            $this->assertSame(array_replace($dto, ['medication_away_enabled' => false]), $disabled);
            $after = $this->facts();
            $viewAudits = array_slice($after['audit_logs'], count($before['audit_logs']));
            $this->assertCount(1, $viewAudits);
            $this->assertSame([
                'user_id' => $this->actor->id, 'client_id' => $this->client->id,
                'action' => 'clients.view', 'auditable_type' => 'client', 'auditable_id' => $this->client->id,
                'meta' => '[]', 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony',
                'created_at' => '2026-06-15 04:00:00', 'updated_at' => '2026-06-15 04:00:00',
            ], array_intersect_key($viewAudits[0], array_flip([
                'user_id', 'client_id', 'action', 'auditable_type', 'auditable_id', 'meta',
                'ip_address', 'user_agent', 'created_at', 'updated_at',
            ])));
            $after['audit_logs'] = array_slice($after['audit_logs'], 0, count($before['audit_logs']));
            $this->assertSame($before, $after);
        } finally {
            config(['medications.away.from_leave' => true]);
        }
        $before = $this->facts();
        $this->action($leave, ['action' => 'depart', 'occurred_at' => '2026-06-15T13:00:00+12:00'])->assertUnprocessable();
        $this->assertSame($before, $this->facts());
    }

    public function test_withdrawal_retains_planned_leave_and_history_without_inventing_actual_presence(): void
    {
        $leave = $this->leave();
        $this->actingAs($this->actor)->deleteJson($this->leaveUrl($leave), ['version' => 1, 'reason' => 'The visit has been cancelled'])->assertRedirect();
        $leave->refresh();
        $this->assertSame('cancelled', $leave->status);
        $this->assertNull($leave->deleted_at);
        $this->assertNull($leave->departed_at);
        $this->assertNull($leave->returned_at);
        $this->assertSame($this->actor->id, $leave->withdrawn_by);
        $this->assertSame('The visit has been cancelled', $leave->withdrawal_reason);
        $this->assertSame(['created', 'withdraw'], $leave->transitions()->get()->pluck('meta.action')->all());
        $before = $this->facts();
        $this->action($leave, ['action' => 'approve'])->assertUnprocessable();
        $this->assertSame($before, $this->facts());
    }

    public function test_an_actual_departure_cannot_be_withdrawn_or_physically_deleted(): void
    {
        $leave = $this->departed();
        $before = $this->facts();
        $this->action($leave, ['action' => 'withdraw', 'reason' => 'The visit was cancelled later'])->assertUnprocessable();
        $this->actingAs($this->actor)->deleteJson($this->leaveUrl($leave), ['version' => $leave->version,
            'reason' => 'Remove this active absence'])->assertUnprocessable();
        $this->assertSame($before, $this->facts());
        $this->assertNull($leave->fresh()->returned_at);
        $this->assertSame(['return'], $leave->fresh()->allowedActions());
    }

    #[DataProvider('invalidLeaveActions')]
    public function test_leave_guards_preserve_all_facts_and_history(string $state, array $input): void
    {
        $leave = $state === 'departed' ? $this->departed() : $this->leave($state);
        $before = $this->facts();
        $this->action($leave, $input)->assertUnprocessable();
        $this->assertSame($before, $this->facts());
    }

    public static function invalidLeaveActions(): array
    {
        return [
            'approval required' => ['requested', ['action' => 'depart', 'occurred_at' => '2026-06-15T07:00:00+12:00']],
            'future departure' => ['approved', ['action' => 'depart', 'occurred_at' => '2026-06-16T07:00:00+12:00']],
            'missing actual time' => ['approved', ['action' => 'depart']],
            'stale version' => ['approved', ['action' => 'depart', 'version' => 99, 'occurred_at' => '2026-06-15T07:00:00+12:00']],
            'return one minute before departure' => ['departed', ['action' => 'return', 'occurred_at' => '2026-06-15T06:59:00+12:00']],
            'no guessed daylight saving time' => ['approved', ['action' => 'depart', 'occurred_at' => '2026-04-05 02:30:00']],
            'declined is terminal' => ['declined', ['action' => 'approve']],
            'no invented approval time' => ['requested', ['action' => 'approve', 'occurred_at' => '2026-06-15T07:00:00+12:00']],
            'blank withdrawal reason' => ['approved', ['action' => 'withdraw', 'reason' => '     ']],
        ];
    }

    public function test_legacy_approval_needs_explicit_confirmation_and_overlapping_leave_is_rejected(): void
    {
        $legacy = ClientLeaveRequest::create(['client_id' => $this->client->id, 'requested_by' => $this->actor->id,
            'starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'status' => 'approved']);
        $legacy->refresh();
        $this->assertSame(['approve', 'withdraw'], $legacy->allowedActions());
        $this->action($legacy, ['action' => 'depart', 'occurred_at' => '2026-06-15T07:00:00+12:00'])->assertUnprocessable();
        $this->assertNull($legacy->fresh()->departed_at);
        $this->action($legacy, ['action' => 'approve'])->assertRedirect();
        $legacy->refresh();
        $this->action($legacy, ['action' => 'depart', 'occurred_at' => '2026-06-15T07:00:00+12:00'])->assertRedirect();
        $second = $this->leave('approved');
        $before = $this->facts();
        $this->action($second, ['action' => 'depart', 'occurred_at' => '2026-06-15T08:00:00+12:00'])->assertUnprocessable();
        $this->assertSame($before, $this->facts());
    }

    public function test_leave_commands_recheck_current_authority_and_conceal_wrong_person_rows(): void
    {
        $leave = $this->leave('approved');
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $before = $this->facts();
        $this->actingAs($this->actor)->putJson('/operations/clients/'.$other->id.'/leave/'.$leave->id,
            ['action' => 'depart', 'version' => 1, 'occurred_at' => '2026-06-15T07:00:00+12:00'])->assertNotFound();
        $this->assertSame($before, $this->facts());
        $permission = Permission::where('key', 'clients.update')->sole();
        $staleActor = $this->actor->fresh();
        $this->assertTrue($staleActor->canDo('clients.update'));
        $this->actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $before = $this->facts();
        $this->assertDeniedTransition($staleActor, $leave, 403);
        $this->assertSame($before, $this->facts());
        $this->actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $before = $this->facts();
        $this->assertDeniedTransition($this->actor->fresh(), $leave, 403);
        $this->assertSame($before, $this->facts());
    }

    public function test_hospital_http_pairs_an_early_discharge_and_returns_only_open_current_person_references(): void
    {
        $response = $this->actingAs($this->actor)->postJson($this->hospitalUrl(), $this->eventInput('hospital_admission', '2026-06-15T07:00:00+12:00'))->assertCreated();
        $admission = ClinicalEvent::findOrFail($response->json('id'));
        $this->assertSame('2026-06-14 19:00:00', $admission->getRawOriginal('hospital_admitted_at'));
        $original = $admission->getAttributes();
        $refs = $this->getJson($this->hospitalUrl('hospital-admissions'))->assertOk()->json('admissions');
        $this->assertSame([['id' => $admission->id, 'occurred_at' => '2026-06-14T19:00:00.000000Z',
            'reported_at' => '2026-06-15T04:00:00.000000Z']], $refs);
        $this->postJson($this->hospitalUrl(), $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id))
            ->assertCreated()->assertJsonPath('hospital_admission_id', $admission->id);
        $this->assertSame($original, $admission->fresh()->getAttributes());
        $this->getJson($this->hospitalUrl('hospital-admissions'))->assertOk()->assertExactJson(['admissions' => []]);
        $this->assertSame(1, ClinicalEvent::where('hospital_admission_id', $admission->id)->count());
        $this->assertSame(2, TimelineEvent::where('type', 'clinical_event')->where('client_id', $this->client->id)->count());
        $before = $this->facts();
        $this->postJson($this->hospitalUrl(), $this->eventInput('hospital_discharge', '2026-06-15T13:00:00+12:00', $admission->id))->assertUnprocessable();
        $this->assertSame($before, $this->facts());
    }

    public function test_hospital_denies_wrong_person_foreign_missing_and_legacy_admission_references_without_drift(): void
    {
        $admission = $this->hospital();
        $sameSiteOther = Client::factory()->create(['site_id' => $this->site->id]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreign = ClinicalEvent::factory()->create(['client_id' => $sameSiteOther->id, 'site_id' => $foreignSite->id,
            'event_type' => 'hospital_admission', 'hospital_admitted_at' => $admission->hospital_admitted_at, 'occurred_at' => $admission->occurred_at]);
        $legacy = ClinicalEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'event_type' => 'hospital_admission', 'hospital_admitted_at' => null]);
        $before = $this->facts();
        $this->actingAs($this->actor)->postJson('/clients/'.$sameSiteOther->id.'/clinical/events',
            $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id))->assertNotFound();
        foreach ([$foreign->id, $legacy->id, 999999999] as $id) {
            $this->postJson($this->hospitalUrl(), $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $id))->assertNotFound();
        }
        $this->getJson($this->hospitalUrl('hospital-admissions'))->assertOk()->assertJsonCount(1, 'admissions')->assertJsonPath('admissions.0.id', $admission->id);
        $this->assertSame($before, $this->facts());
    }

    public function test_hospital_rejects_future_out_of_order_and_ambiguous_admissions_but_allows_adjacent_readmission(): void
    {
        $admission = $this->hospital();
        $before = $this->facts();
        foreach ([$this->eventInput('hospital_discharge', '2026-06-15T06:59:00+12:00', $admission->id),
            $this->eventInput('hospital_admission', '2026-04-05 02:30:00'),
            $this->eventInput('hospital_admission', '2026-06-15T08:00:00+12:00'),
            $this->eventInput('hospital_discharge', '2026-06-16T07:00:00+12:00', $admission->id)] as $input) {
            $this->actingAs($this->actor)->postJson($this->hospitalUrl(), $input)->assertUnprocessable();
            $this->assertSame($before, $this->facts());
        }
        $this->postJson($this->hospitalUrl(), $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id))->assertCreated();
        $this->postJson($this->hospitalUrl(), $this->eventInput('hospital_admission', '2026-06-15T12:00:00+12:00'))->assertCreated();
        $this->getJson($this->hospitalUrl('hospital-admissions'))->assertOk()->assertJsonCount(1, 'admissions');
    }

    public function test_hospital_recording_rechecks_current_site_and_approval_without_creating_evidence(): void
    {
        $stale = $this->actor->fresh();
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => false]);
        $before = $this->facts();
        $this->assertDeniedHospital($stale, 404);
        $this->assertSame($before, $this->facts());
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => true]);
        User::whereKey($this->actor->id)->update(['approved_at' => null]);
        $before = $this->facts();
        $this->assertDeniedHospital($stale, 403);
        $this->assertSame($before, $this->facts());
    }

    public function test_shift_hospital_form_resolves_the_same_person_and_denies_removed_or_foreign_context(): void
    {
        $admission = $this->hospital();
        $shift = Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $this->actor->id]);
        $this->actingAs($this->actor)->getJson('/shifts/'.$shift->id.'/clinical/hospital-admissions')->assertOk()->assertJsonPath('admissions.0.id', $admission->id);
        $this->postJson('/shifts/'.$shift->id.'/clinical/events', $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id))
            ->assertCreated()->assertJsonPath('hospital_admission_id', $admission->id)->assertJsonPath('shift_id', $shift->id);
        $removed = Client::factory()->create(['site_id' => $this->site->id]);
        $missing = Shift::factory()->create(['client_id' => $removed->id, 'site_id' => $this->site->id, 'user_id' => $this->actor->id]);
        $removed->delete();
        $before = $this->facts();
        $this->getJson('/shifts/'.$missing->id.'/clinical/hospital-admissions')->assertForbidden();
        $this->postJson('/shifts/'.$missing->id.'/clinical/events', $this->eventInput('hospital_admission', '2026-06-15T13:00:00+12:00'))->assertForbidden();
        $this->assertSame($before, $this->facts());
        $foreign = Shift::factory()->create(['client_id' => $this->client->id,
            'site_id' => Site::factory()->create(['is_active' => true])->id, 'user_id' => $this->actor->id]);
        $before = $this->facts();
        $this->getJson('/shifts/'.$foreign->id.'/clinical/hospital-admissions')->assertForbidden();
        $this->assertSame($before, $this->facts());
    }

    public function test_real_mysql_contender_cannot_commit_a_second_discharge_of_the_same_admission(): void
    {
        $admission = $this->hospital();
        $originalAdmission = (array) DB::table('clinical_events')->where('id', $admission->id)->sole();
        $this->withCommittedSessions(function (Connection $primary, Connection $contender) use ($admission): void {
            $dispatcher = $primary->getEventDispatcher();
            $primary->setEventDispatcher(clone $dispatcher);
            $attempts = 0;
            $blocked = false;
            try {
                $primary->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $query) use ($primary, $contender, $admission, &$attempts, &$blocked): void {
                    if ($attempts !== 0 || ! str_starts_with(strtolower($query->sql), 'insert into `clinical_events`')
                        || ! in_array('hospital_discharge', $query->bindings, true)) {
                        return;
                    }
                    $attempts++;
                    $this->assertTrue($primary->getPdo()->inTransaction());
                    $old = DB::getDefaultConnection();
                    DB::setDefaultConnection($contender->getName());
                    try {
                        $this->assertSame($contender, DB::connection());
                        $this->assertSame($contender->getPdo(), (new ClinicalEvent)->getConnection()->getPdo());
                        app(ClinicalEventService::class)->record(Client::findOrFail($this->client->id), User::findOrFail($this->actor->id),
                            $this->eventInput('hospital_discharge', '2026-06-15T13:00:00+12:00', $admission->id));
                        $this->fail('A competing discharge must wait for the canonical person mutex.');
                    } catch (QueryException $exception) {
                        $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
                        $blocked = true;
                    } finally {
                        DB::setDefaultConnection($old);
                    }
                });
                app(ClinicalEventService::class)->record($this->client, $this->actor,
                    $this->eventInput('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id));
                $this->assertSame(1, $attempts);
                $this->assertTrue($blocked);
                $before = $this->facts();
                try {
                    app(ClinicalEventService::class)->record($this->client, $this->actor,
                        $this->eventInput('hospital_discharge', '2026-06-15T13:00:00+12:00', $admission->id));
                    $this->fail('After the winner commits, a duplicate discharge must still be denied.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('hospital_admission_id', $exception->errors());
                }
                $this->assertSame($before, $this->facts());
                $this->assertSame(1, ClinicalEvent::where('hospital_admission_id', $admission->id)->count());
            } finally {
                $primary->setEventDispatcher($dispatcher);
            }
        });
        $this->assertSame($originalAdmission, (array) DB::table('clinical_events')->where('id', $admission->id)->sole());
    }

    private function leave(string $status = 'requested'): ClientLeaveRequest
    {
        return app(ClientLeaveWorkflow::class)->create($this->actor, $this->client, [
            'starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'status' => $status,
        ]);
    }

    private function departed(): ClientLeaveRequest
    {
        $leave = $this->leave('approved');

        return app(ClientLeaveWorkflow::class)->transition($this->actor, $this->client, $leave,
            ['action' => 'depart', 'version' => $leave->version, 'occurred_at' => '2026-06-15T07:00:00+12:00']);
    }

    private function leaveUrl(ClientLeaveRequest $leave): string
    {
        return '/operations/clients/'.$this->client->id.'/leave/'.$leave->id;
    }

    private function action(ClientLeaveRequest $leave, array $input)
    {
        return $this->actingAs($this->actor->fresh())->putJson($this->leaveUrl($leave), $input + ['version' => $leave->version]);
    }

    private function eventInput(string $type, string $at, ?int $admissionId = null): array
    {
        return ['event_type' => $type, 'severity' => 'medium', 'description' => 'Actual hospital attendance recorded by staff',
            'occurred_at' => $at, 'hospital_admission_id' => $admissionId];
    }

    private function hospital(): ClinicalEvent
    {
        return app(ClinicalEventService::class)->record($this->client, $this->actor,
            $this->eventInput('hospital_admission', '2026-06-15T07:00:00+12:00'));
    }

    private function hospitalUrl(string $path = 'events'): string
    {
        return '/clients/'.$this->client->id.'/clinical/'.$path;
    }

    private function facts(): array
    {
        return collect(['client_leave_requests', 'clinical_events', 'timeline_events', 'audit_logs'])->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()],
        )->all();
    }

    private function assertDeniedTransition(User $actor, ClientLeaveRequest $leave, int $status): void
    {
        try {
            app(ClientLeaveWorkflow::class)->transition($actor, $this->client, $leave,
                ['action' => 'depart', 'version' => $leave->version, 'occurred_at' => '2026-06-15T07:00:00+12:00']);
            $this->fail('Current Client update authority is required.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function assertDeniedHospital(User $actor, int $status): void
    {
        try {
            app(ClinicalEventService::class)->record($this->client, $actor, $this->eventInput('hospital_admission', '2026-06-15T07:00:00+12:00'));
            $this->fail('Current clinical authority is required.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function withCommittedSessions(callable $exercise): void
    {
        $primary = DB::connection();
        $database = $primary->getDatabaseName();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $primary->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($database, getmypid()));
        $this->assertSame($database, $primary->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $primary->transactionLevel());
        $this->assertTrue($primary->getPdo()->inTransaction());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $name = 'emar_actual_absence_contender';
        $originalConfig = config('database.connections.'.$name);
        $default = DB::getDefaultConnection();
        $manager = $this->app['db.transactions'];
        $contender = null;
        $timeout = null;
        DB::commit();
        $productionManager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $productionManager);
        $primary->setTransactionManager($productionManager);
        try {
            config(['database.connections.'.$name => array_replace($primary->getConfig(), ['name' => $name])]);
            DB::purge($name);
            $contender = DB::connection($name);
            $this->assertSame('emar_actual_absence_contender', $contender->getName());
            $this->assertNotSame($primary->getName(), $contender->getName());
            $this->assertSame($database, $contender->getPdo()->query('SELECT DATABASE()')->fetchColumn());
            $this->assertNotSame($primary->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $contender->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
            $timeout = (int) $contender->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
            $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $exercise($primary, $contender);
        } finally {
            DB::setDefaultConnection($default);
            if ($contender !== null) {
                while ($contender->transactionLevel() > 0) {
                    $contender->rollBack();
                }
                if ($timeout !== null) {
                    $contender->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
                }
            }
            DB::purge($name);
            config(['database.connections.'.$name => $originalConfig]);
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            $this->app->instance('db.transactions', $manager);
            $primary->setTransactionManager($manager);
            $primary->beginTransaction();
        }
    }
}
