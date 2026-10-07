<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Operations\ShiftNoteController;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class ShiftNoteCommandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreign;

    private User $actor;

    private User $worker;

    private Shift $shift;

    private ClientNote $note;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 05:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->user(['rostering.viewAny', 'shifts.viewAny', 'shifts.manageAny', 'progress_notes.update', 'progress_notes.review']);
        $this->worker = $this->user([]);
        $context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $this->site->id, 'organization_id' => 1]);
        $this->shift = Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'user_id' => $this->worker->id, 'service_context_id' => $context->id, 'organization_id' => 1,
            'created_by' => $this->actor->id, 'starts_at' => Carbon::parse('2026-10-05 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-05 13:00:00', 'Pacific/Auckland')->utc()]);
        $this->note = ClientNote::create(['shift_id' => $this->shift->id, 'client_id' => $client->id, 'user_id' => $this->worker->id,
            'type' => 'shift_note', 'body' => 'Original Māori wording / with line'.PHP_EOL.'Two.', 'visibility' => 'internal',
            'is_private' => false, 'is_flagged' => false, 'appears_on_timeline' => true]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('shift_note_evidence_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('actions')]
    public function test_root_receipts_match_actual_saved_identity_values_and_existing_status(string $action): void
    {
        $shiftBefore = $this->shift->fresh()->getRawOriginal();
        $this->commitFixtures();
        $response = $this->actingAs($this->actor)->withSession(['shift_note_result' => ['action' => 'stale']])
            ->command($action)->assertRedirect()->assertSessionHasNoErrors();
        $receipt = session('shift_note_result');
        $this->assertSame($action, $receipt['action']);
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame($this->shift->id, $receipt['shift_id']);
        $this->assertSame($this->shift->client_id, $receipt['client_id']);
        $saved = ClientNote::findOrFail($receipt['note_id']);
        $this->assertSame($this->hash($saved), $receipt['values_hash']);
        $this->assertTrue($receipt['changed']);
        $this->assertSame((bool) $saved->is_private, $receipt['is_private']);
        $this->assertSame((bool) $saved->is_flagged, $receipt['is_flagged']);
        foreach (['edited_at', 'reviewed_at'] as $field) {
            $this->assertSame($saved->$field?->toISOString(), $receipt[$field]);
        }
        foreach (['edited_by', 'reviewed_by'] as $field) {
            $this->assertSame($saved->$field === null ? null : (int) $saved->$field, $receipt[$field]);
        }
        $response->assertSessionHas('success', match ($action) {
            'create' => 'Shift note added.', 'update' => 'Shift note updated.',
            'flag' => 'Note flagged.', 'review' => 'Note marked as reviewed.',
        });
        $this->assertSame($shiftBefore, $this->shift->fresh()->getRawOriginal());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertArrayNotHasKey('body', $receipt);
        $this->assertArrayNotHasKey('flagged_reason', $receipt);
        $this->assertDatabaseHas('timeline_events', ['source_type' => ClientNote::class, 'source_id' => $saved->id]);
        $this->get(route('operations.shift_notes.index', ['week' => '2026-10-05']))->assertInertia(fn (Assert $page) => $page
            ->where('flash.shift_note_result', fn ($value) => $value->all() === $receipt));
    }

    public static function actions(): array
    {
        return ['create' => ['create'], 'update' => ['update'], 'flag' => ['flag'], 'review' => ['review']];
    }

    #[DataProvider('nestedActions')]
    public function test_nested_commands_never_publish_before_or_after_real_outer_commit_or_rollback(string $action, bool $commit): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $this->physicalCallbacks(function () use ($action, $commit, $before): void {
            DB::beginTransaction();
            $this->actingAs($this->actor)->withSession(['shift_note_result' => ['action' => 'old']])
                ->command($action)->assertRedirect()->assertSessionMissing('shift_note_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('shift_note_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if ($commit) {
                $this->assertNotSame($before['client_notes'], $this->state()['client_notes']);
                $this->assertSame($before['shifts'], $this->state()['shifts']);
            } else {
                $this->assertSame($before, $this->state());
            }
        });
    }

    public static function nestedActions(): array
    {
        $cases = [];
        foreach (['create', 'update', 'flag', 'review'] as $action) {
            foreach ([true, false] as $commit) {
                $cases[$action.' '.($commit ? 'commit' : 'rollback')] = [$action, $commit];
            }
        }

        return $cases;
    }

    #[DataProvider('currentChanges')]
    public function test_committed_current_authority_source_or_note_privacy_changes_cannot_be_resurrected(string $action, string $change, int $status): void
    {
        $roleId = $this->actor->roles()->sole()->id;
        if (in_array($change, ['flag grant', 'note private', 'note author'], true)) {
            $this->actor->roles()->sole()->permissions()->detach(Permission::where('key', 'progress_notes.review')->value('id'));
        }
        if ($change === 'note author') {
            $this->actor->roles()->sole()->permissions()->detach(Permission::where('key', 'shifts.manageAny')->value('id'));
            $this->note->forceFill(['user_id' => $this->actor->id])->saveQuietly();
        }
        $this->actor = $this->actor->fresh();
        $portal = Role::firstOrCreate(['name' => 'client'], ['label' => 'Client', 'type' => 'custom', 'level' => 0]);
        $permission = Permission::where('key', match ($change) {
            'flag grant' => 'progress_notes.update', 'review grant' => 'progress_notes.review', default => 'shifts.viewAny',
        })->sole()->id;
        $this->commitFixtures();
        $writer = $this->writer();
        [$table, $where, $attribute, $replacement] = match ($change) {
            'approval' => ['users', ['id' => $this->actor->id], 'approved_at', null],
            'entry grant', 'flag grant', 'review grant' => ['role_permission', ['role_id' => $roleId, 'permission_id' => $permission], null, null],
            'actor profile' => ['hr_employee_profiles', ['user_id' => $this->actor->id], 'is_active', false],
            'actor site' => ['hr_employee_profiles', ['user_id' => $this->actor->id], 'primary_site_id', $this->foreign->id],
            'site inactive' => ['sites', ['id' => $this->site->id], 'is_active', false],
            'site archived' => ['sites', ['id' => $this->site->id], 'archived', true],
            'client site' => ['clients', ['id' => $this->shift->client_id], 'site_id', $this->foreign->id],
            'worker approval' => ['users', ['id' => $this->worker->id], 'approved_at', null],
            'worker profile' => ['hr_employee_profiles', ['user_id' => $this->worker->id], 'is_active', false],
            'worker site' => ['hr_employee_profiles', ['user_id' => $this->worker->id], 'primary_site_id', $this->foreign->id],
            'worker portal' => ['role_user', ['user_id' => $this->worker->id, 'role_id' => $portal->id], null, 'insert'],
            'note private' => ['client_notes', ['id' => $this->note->id], 'is_private', true],
            'note draft' => ['client_notes', ['id' => $this->note->id], 'is_draft', true],
            'note author' => ['client_notes', ['id' => $this->note->id], 'user_id', $this->worker->id],
        };
        $evidence = fn (Connection $db) => $attribute === null ? $db->table($table)->where($where)->count()
            : $db->table($table)->where($where)->value($attribute);
        DB::beginTransaction();
        $old = $evidence(DB::connection());
        $before = $this->state();
        $fired = false;
        $afterExternalChange = null;
        DB::connection()->beforeExecuting(function (string $sql) use ($writer, $table, $where, $attribute, $replacement, $evidence, $old, &$fired, &$afterExternalChange): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($writer->getPdo()->inTransaction());
            if ($replacement === 'insert') {
                $writer->table($table)->insert($where);
            } elseif ($attribute === null) {
                $writer->table($table)->where($where)->delete();
            } else {
                $writer->table($table)->where($where)->update([$attribute => $replacement]);
            }
            $this->assertNotSame($old, $evidence($writer));
            $this->assertSame($old, $evidence(DB::connection()), 'The ordinary read still sees the prior RR evidence.');
            $afterExternalChange = $this->state($writer);
        });
        try {
            $this->actingAs($this->actor)->withSession(['shift_note_result' => ['action' => 'old']])
                ->command($action, true)->assertStatus($status)->assertSessionMissing('shift_note_result');
            $this->assertTrue($fired);
            $this->assertSame($afterExternalChange, $this->state($writer), 'Independent current state proves no partial command write.');
            if ($table !== 'client_notes') {
                $this->assertSame($before, $this->state());
            } else {
                foreach (['shifts', 'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'] as $unchanged) {
                    $this->assertSame($before[$unchanged], $this->state()[$unchanged]);
                }
                $this->assertSame($this->note->getRawOriginal('body'), DB::table('client_notes')->where('id', $this->note->id)->value('body'));
            }
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function currentChanges(): array
    {
        return ['actor approval' => ['create', 'approval', 403], 'exact entry grant' => ['update', 'entry grant', 403],
            'exact flag grant' => ['flag', 'flag grant', 403], 'exact review grant' => ['review', 'review grant', 403],
            'actor profile' => ['create', 'actor profile', 404], 'actor Site membership' => ['update', 'actor site', 404],
            'inactive Site' => ['create', 'site inactive', 404], 'archived Site' => ['create', 'site archived', 404],
            'Client Site' => ['create', 'client site', 404], 'worker approval' => ['create', 'worker approval', 404],
            'worker profile' => ['create', 'worker profile', 404], 'worker Site membership' => ['create', 'worker site', 404],
            'worker portal role' => ['create', 'worker portal', 404], 'note became private' => ['update', 'note private', 404],
            'note became draft' => ['update', 'note draft', 404], 'note author changed' => ['update', 'note author', 403]];
    }

    #[DataProvider('retargets')]
    public function test_bound_note_or_shift_retarget_after_the_identity_read_is_rejected(string $kind): void
    {
        $other = $this->shift->replicate();
        $other->save();
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->commitFixtures();
        $writer = $this->writer();
        $before = $this->state();
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($kind, $other, $otherClient, $writer, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            if ($kind === 'note') {
                $writer->table('client_notes')->where('id', $this->note->id)->update(['shift_id' => $other->id]);
            } else {
                $writer->table('shifts')->where('id', $this->shift->id)->update(['client_id' => $otherClient->id]);
            }
        });
        $this->actingAs($this->actor)->command('update', true)->assertNotFound()->assertSessionMissing('shift_note_result');
        $this->assertTrue($fired);
        $this->assertSame($before['audit_logs'], $this->state()['audit_logs']);
        $this->assertSame($before['timeline_events'], $this->state()['timeline_events']);
        $this->assertSame($this->note->body, $this->note->fresh()->body);
    }

    public static function retargets(): array
    {
        return ['note Shift' => ['note'], 'Shift Client' => ['shift']];
    }

    #[DataProvider('heldSources')]
    public function test_actual_late_nowait_conflict_rolls_back_without_note_or_receipt(string $holder): void
    {
        $roleId = $this->actor->roles()->sole()->id;
        $this->commitFixtures();
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->table($holder === 'role' ? 'roles' : 'sites')->where('id', $holder === 'role' ? $roleId : $this->site->id)->lockForUpdate()->first();
        $before = $this->state();
        try {
            $this->actingAs($this->actor)->command('create', true)->assertUnprocessable()
                ->assertJsonValidationErrors('shift_note')->assertSessionMissing('shift_note_result');
            $this->assertSame($before, $this->state());
        } finally {
            $writer->rollBack();
        }
    }

    public static function heldSources(): array
    {
        return ['actor Role' => ['role'], 'approved Site' => ['site']];
    }

    #[DataProvider('persistFailures')]
    public function test_veto_or_changed_saved_values_cannot_be_certified_as_the_requested_note(string $event, bool $veto): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $this->modelListener($event, function (ClientNote $note) use ($veto) {
            if ($veto) {
                $note->fill(['body' => 'An unsaved filled intent']);

                return false;
            }
            $note->body = 'Unexpected changed saved value';

            return null;
        }, fn () => $this->actingAs($this->actor)->command('create', true)->assertStatus(409)
            ->assertSessionMissing('shift_note_result'));
        $this->assertSame($before, $this->state());
    }

    public static function persistFailures(): array
    {
        return ['vetoed filled intent' => ['saving', true], 'observer changed content' => ['saving', false]];
    }

    public function test_timeline_failure_rolls_back_note_and_audit_without_receipt(): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $this->mock(TimelineEmitter::class)->shouldReceive('project')->once()->andThrow(new RuntimeException('private timeline fixture failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->command('create');
            $this->fail('Expected the actual timeline failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('private timeline fixture failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        $this->assertNull(session('shift_note_result'));
    }

    public function test_repeated_review_preserves_original_reviewer_timestamp_and_reports_actual_no_op(): void
    {
        $this->note->forceFill(['reviewed_at' => now()->subDay(), 'reviewed_by' => $this->worker->id])->saveQuietly();
        $before = $this->note->fresh()->getRawOriginal();
        $this->commitFixtures();
        $this->actingAs($this->actor)->command('review')->assertRedirect()->assertSessionHas('success', 'Note marked as reviewed.');
        $this->assertFalse(session('shift_note_result')['changed']);
        $this->assertSame($this->worker->id, session('shift_note_result')['reviewed_by']);
        $this->assertSame($before, $this->note->fresh()->getRawOriginal());
    }

    public function test_omitted_privacy_does_not_turn_an_existing_private_note_public(): void
    {
        $this->note->forceFill(['is_private' => true])->saveQuietly();
        $this->commitFixtures();
        $this->actingAs($this->actor)->put(route('operations.shift_notes.update', $this->note),
            ['type' => 'note', 'body' => 'Keep current privacy'])->assertRedirect();
        $this->assertTrue($this->note->fresh()->is_private);
        $this->assertTrue(session('shift_note_result')['is_private']);
    }

    public function test_hash_distinguishes_null_empty_unicode_slashes_and_line_terminators(): void
    {
        $note = clone $this->note;
        $note->forceFill(['body' => "Māori / care\u{2028}next", 'flagged_reason' => null]);
        $method = new ReflectionMethod(ShiftNoteController::class, 'valuesHash');
        $null = $method->invoke(app(ShiftNoteController::class), $note);
        $this->assertSame($this->hash($note), $null);
        $note->flagged_reason = '';
        $empty = $method->invoke(app(ShiftNoteController::class), $note);
        $this->assertNotSame($null, $empty);
        $this->assertSame($this->hash($note), $empty);
    }

    #[DataProvider('editableActions')]
    public function test_actual_http_receipt_hash_matches_normalized_unicode_text_null_reason_and_numeric_flags(string $action): void
    {
        $this->commitFixtures();
        $body = "Māori / care\u{2028}next\u{2029}line";
        $input = ['type' => "\u{200B}note\u{00A0}", 'body' => "\u{200B}\u{00A0} ".$body." \u{2060}",
            'is_flagged' => 1, 'flagged_reason' => "\u{200B}\u{00A0} \u{2060}", 'is_private' => 0];
        $this->actingAs($this->actor);
        $action === 'create'
            ? $this->post(route('operations.shift_notes.store'), ['shift_id' => $this->shift->id, ...$input])->assertRedirect()->assertSessionHasNoErrors()
            : $this->put(route('operations.shift_notes.update', $this->note), $input)->assertRedirect()->assertSessionHasNoErrors();
        $receipt = session('shift_note_result');
        $saved = ClientNote::findOrFail($receipt['note_id']);
        $this->assertSame('note', $saved->type);
        $this->assertSame($body, $saved->body);
        $this->assertNull($saved->flagged_reason);
        $this->assertTrue($saved->is_flagged);
        $this->assertFalse($saved->is_private);
        $expectedJson = '{"type":"note","body":"'.$body.'","is_flagged":true,"flagged_reason":null,"is_private":false}';
        $this->assertSame(hash('sha256', $expectedJson), $receipt['values_hash']);
        $this->assertSame($action, $receipt['action']);
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function editableActions(): array
    {
        return ['create' => ['create'], 'update' => ['update']];
    }

    public function test_pdo_only_outer_transaction_cannot_be_certified_as_a_committed_result(): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $controller = app(ShiftNoteController::class);
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $this->assertFalse((new ReflectionMethod(ShiftNoteController::class, 'isPhysicalRoot'))->invoke($controller));
            $response = (new ReflectionMethod(ShiftNoteController::class, 'committedResult'))->invoke($controller,
                redirect()->back(), true, 'review', ['actor_id' => $this->actor->id, 'note' => $this->note, 'changed' => false]);
            $this->assertNull($response->getSession()->get('shift_note_result'));
            $this->assertSame($before, $this->state());
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_requester_filter_suppresses_a_prior_actor_receipt_without_record_changes(): void
    {
        $this->commitFixtures();
        $this->actingAs($this->actor)->command('create')->assertRedirect();
        $this->assertSame($this->actor->id, session('shift_note_result')['actor_id']);
        $before = $this->state();
        $this->actingAs($this->worker)->getJson(route('operations.shift_notes.index'))->assertForbidden();
        $this->actingAs($this->actor)->withSession(['shift_note_result' => ['actor_id' => $this->worker->id, 'action' => 'old']])
            ->get(route('operations.shift_notes.index', ['week' => '2026-10-05']))->assertInertia(fn (Assert $page) => $page->where('flash.shift_note_result', null));
        $this->assertSame($before, $this->state());
    }

    public function test_presentation_and_logger_fault_after_real_commit_cannot_turn_saved_note_into_command_failure(): void
    {
        $this->commitFixtures();
        $controller = new class(app(UserSiteAccessService::class)) extends ShiftNoteController
        {
            protected function valuesHash(ClientNote $note): string
            {
                throw new RuntimeException('private metadata fixture failure');
            }
        };
        $this->app->instance(ShiftNoteController::class, $controller);
        Log::partialMock()->shouldReceive('warning')->andThrow(new RuntimeException('private logging fixture failure'));
        $this->actingAs($this->actor)->command('create')->assertRedirect()->assertSessionHas('success', 'Shift note added.')
            ->assertSessionMissing('shift_note_result');
        $this->assertSame(2, ClientNote::count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function command(string $action, bool $json = false)
    {
        $body = ['type' => 'note', 'body' => 'Saved Māori / wording', 'is_flagged' => false, 'is_private' => true];
        $method = match ($action) {
            'create' => 'post', 'update' => 'put', default => 'patch'
        };
        $url = route(match ($action) {
            'create' => 'operations.shift_notes.store', 'update' => 'operations.shift_notes.update',
            'flag' => 'operations.shift_notes.flag', 'review' => 'operations.shift_notes.review',
        }, $action === 'create' ? [] : $this->note);
        $data = match ($action) {
            'create' => ['shift_id' => $this->shift->id, ...$body], 'update' => $body,
            'flag' => ['flagged_reason' => 'Needs coordinator review'], default => [],
        };

        return $this->{$method.($json ? 'Json' : '')}($url, $data);
    }

    private function hash(ClientNote $note): string
    {
        return hash('sha256', json_encode(['type' => $note->type, 'body' => $note->body,
            'is_flagged' => (bool) $note->is_flagged, 'flagged_reason' => $note->flagged_reason,
            'is_private' => (bool) $note->is_private], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function user(array $keys): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'organization_id' => 1]);
        $role = Role::create(['name' => 'note-command-'.Str::uuid(), 'label' => 'Notes fixture', 'type' => 'custom', 'level' => 40]);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id));
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function state(?Connection $connection = null): array
    {
        $connection ??= DB::connection();

        return collect(['client_notes', 'shifts', 'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function commitFixtures(): void
    {
        $db = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $db->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $db->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($db->getDatabaseName(), getmypid()));
        $this->assertSame($db->getDatabaseName(), $db->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $db->transactionLevel());
        DB::commit();
        $this->committed = true;
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    private function physicalCallbacks(callable $proof): void
    {
        $db = DB::connection();
        $testManager = $this->app['db.transactions'];
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $db->setTransactionManager($manager);
        try {
            $proof();
        } finally {
            try {
                while ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }
            } finally {
                $this->app->instance('db.transactions', $testManager);
                $db->setTransactionManager($testManager);
            }
        }
    }

    private function writer(): Connection
    {
        config(['database.connections.shift_note_evidence_writer' => DB::connection()->getConfig()]);
        DB::purge('shift_note_evidence_writer');
        $writer = DB::connection('shift_note_evidence_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function modelListener(string $event, callable $listener, callable $proof): void
    {
        new ClientNote;
        $original = Model::getEventDispatcher();
        $scoped = clone $original;
        $scoped->listen('eloquent.'.$event.': '.ClientNote::class, $listener);
        Model::setEventDispatcher($scoped);
        try {
            $proof();
        } finally {
            Model::setEventDispatcher($original);
        }
    }
}
