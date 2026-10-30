<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Http\Controllers\Operations\RosterSuggestionController;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Roles, canonical ownership and approved Sites are the application boundary. */
class WorkforceSuggestionReadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $actor;

    private User $candidate;

    private Client $client;

    private Shift $shift;

    private RosterSuggestionRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.rostering.auto_schedule' => true, 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-06T00:00:00Z'));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->worker('Suggestion manager');
        $this->candidate = $this->worker('Suggested worker');
        $this->grant($this->actor, ['rostering.autoSchedule']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        $this->shift = $this->duty();
        $this->run = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->actor->id,
            'week_start' => '2026-10-12', 'week_end' => '2026-10-19', 'totals' => ['open_shifts' => 7, 'suggestion_count' => 9]]);
    }

    public function test_estimate_and_actual_generation_exclude_contradictory_and_deleted_clients_but_preserve_valid_duties(): void
    {
        $foreign = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreign->id]);
        $this->duty(['client_id' => $foreignClient->id]);
        $deleted = Client::factory()->create(['site_id' => $this->site->id]);
        $this->duty(['client_id' => $deleted->id]);
        $deleted->delete();
        $second = $this->duty();
        $service = app(RosterSuggestionService::class);
        $start = Carbon::parse('2026-10-12', 'Pacific/Auckland');
        $this->assertSame(4, $service->estimateEvaluationCount($this->actor, $start, $start->copy()->addWeek(), $this->site->id));

        $run = $service->generate($this->actor, '2026-10-12', $this->site->id);

        $this->assertSame(2, $run->totals['open_shifts']);
        $this->assertSame(RosterSuggestionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([$this->shift->id, $second->id], $run->suggestions->pluck('shift_id')->unique()->sort()->values()->all());
    }

    public function test_pending_generation_rechecks_the_current_client_site_instead_of_its_earlier_estimate(): void
    {
        $service = app(RosterSuggestionService::class);
        $run = $service->generateOrQueue($this->actor, '2026-10-12', $this->site->id, queueThreshold: 0);
        $this->assertSame(RosterSuggestionRun::STATUS_PENDING, $run->status);
        $this->assertSame(2, $run->parameters['estimated_evaluations']);
        $this->client->update(['site_id' => Site::factory()->create()->id]);

        $completed = $service->completePendingRun($run);

        $this->assertSame(RosterSuggestionRun::STATUS_COMPLETED, $completed->status);
        $this->assertSame(0, $completed->totals['open_shifts']);
        $this->assertSame(0, $completed->totals['suggestion_count']);
        $this->assertCount(0, $completed->suggestions);
    }

    #[DataProvider('hiddenSources')]
    public function test_show_withholds_the_whole_invalid_current_row_and_keeps_recorded_history(string $drift): void
    {
        $hidden = $this->suggestion();
        $valid = $this->suggestion($this->duty());
        $beforeRun = $this->run->fresh()->getRawOriginal();
        $beforeRow = $hidden->fresh()->getRawOriginal();
        $foreign = Site::factory()->create();
        match ($drift) {
            'client' => $this->client->update(['site_id' => $foreign->id]),
            'deleted client' => $this->client->delete(),
            'shift site' => DB::table('shifts')->where('id', $this->shift->id)->update(['site_id' => $foreign->id]),
            'stay marker' => DB::table('shifts')->where('id', $this->shift->id)->update(['respite_booking_id' => RespiteBooking::withoutEvents(fn () => RespiteBooking::factory()->create([
                'client_id' => $this->client->id, 'start_at' => $this->shift->starts_at, 'end_at' => $this->shift->ends_at,
            ]))->id]),
        };
        // Restore the unaffected row to its own valid Client after the source drift.
        $validClient = Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('shifts')->where('id', $valid->shift_id)->update(['client_id' => $validClient->id]);

        $response = $this->actingAs($this->actor)->get($this->showUrl())->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('suggestions', 1)->where('suggestions.0.id', $valid->id)
            ->where('suggestion_visibility', ['basis' => 'current_canonical_run_site', 'recorded_count' => 2, 'visible_count' => 1, 'withheld_count' => 1])
            ->where('run.totals', ['open_shifts' => 7, 'suggestion_count' => 9]));
        $this->assertStringNotContainsString('private-snapshot-'.$hidden->id, $response->getContent());
        $this->assertStringNotContainsString('private-reasons-'.$hidden->id, $response->getContent());
        $this->assertSame($beforeRow, $hidden->fresh()->getRawOriginal());
        $this->assertSame($beforeRun, $this->run->fresh()->getRawOriginal());
    }

    public static function hiddenSources(): array
    {
        return ['client moved' => ['client'], 'client deleted' => ['deleted client'], 'contradictory stored Site' => ['shift site'], 'stay aggregate' => ['stay marker']];
    }

    public function test_post_load_client_identity_and_site_are_rechecked_before_any_suggestion_metadata_is_emitted(): void
    {
        $row = $this->suggestion();
        $foreign = Site::factory()->create();
        $changed = false;
        DB::listen(function ($query) use (&$changed, $foreign): void {
            if (! $changed && str_contains($query->sql, 'from `shifts`') && str_contains($query->sql, '`shifts`.`id` in')) {
                $changed = true;
                DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $foreign->id]);
            }
        });

        $response = $this->actingAs($this->actor)->get($this->showUrl())->assertOk();

        $this->assertTrue($changed);
        $response->assertInertia(fn (Assert $page) => $page->has('suggestions', 0)->where('suggestion_visibility.withheld_count', 1));
        $this->assertStringNotContainsString('private-snapshot-'.$row->id, $response->getContent());
    }

    public function test_valid_client_site_fallback_remains_visible_without_claiming_it_is_write_ready(): void
    {
        DB::table('shifts')->where('id', $this->shift->id)->update(['site_id' => null]);
        $row = $this->suggestion();

        $this->actingAs($this->actor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1)->where('suggestions.0.id', $row->id)->where('suggestions.0.shift.site', $this->site->name)
            ->where('suggestions.0.can.accept', true)->where('suggestions.0.can.dismiss', true)->where('suggestions.0.can.apply', false)
            ->where('suggestions.0.urls.apply', null));
    }

    public function test_defensive_site_only_loaded_source_preserves_scope_without_invalid_persisted_null_client_fixture(): void
    {
        // The native schema requires a Client. Exercise the retained nullable
        // model contract without changing that storage or writer invariant.
        $before = $this->shift->fresh()->getRawOriginal();
        $source = clone $this->shift;
        $source->forceFill(['client_id' => null])->setRelation('client', null)->setRelation('site', $this->site);
        $method = new \ReflectionMethod(RosterSuggestionController::class, 'hasCurrentSourceForRun');
        $this->assertTrue($method->invoke(app(RosterSuggestionController::class), $source, $this->run));
        $source->setRelation('site', Site::factory()->create());
        $this->assertFalse($method->invoke(app(RosterSuggestionController::class), $source, $this->run));
        $this->assertSame($before, $this->shift->fresh()->getRawOriginal());
    }

    #[DataProvider('contexts')]
    public function test_context_labels_respect_current_owning_site_without_creating_an_active_context_policy(string $scope): void
    {
        $siteId = match ($scope) {
            'global' => null, 'same inactive' => $this->site->id, default => Site::factory()->create()->id
        };
        $context = ServiceContext::factory()->create(['site_id' => $siteId, 'is_active' => $scope !== 'same inactive', 'name' => 'Context label']);
        DB::table('shifts')->where('id', $this->shift->id)->update(['service_context_id' => $context->id]);
        $this->suggestion();

        $this->actingAs($this->actor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1)->where('suggestions.0.shift.service_context', $scope === 'foreign' ? null : 'Context label'));
    }

    public static function contexts(): array
    {
        return ['global' => ['global'], 'same inactive history' => ['same inactive'], 'foreign label' => ['foreign']];
    }

    public function test_cancelled_and_applied_authorised_history_is_not_filtered_as_an_open_generation_pool(): void
    {
        $this->shift->update(['status' => 'cancelled']);
        $row = $this->suggestion(attributes: ['status' => RosterSuggestion::STATUS_APPLIED]);
        config(['app.worker_timezone' => 'America/New_York']);

        $this->actingAs($this->actor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('worker_timezone', 'America/New_York')->has('suggestions', 1)->where('suggestions.0.id', $row->id)
            ->where('suggestions.0.status', 'applied')->where('suggestions.0.shift.status', 'cancelled')
            ->where('suggestions.0.can.accept', true)->where('suggestions.0.can.dismiss', true)->where('suggestions.0.can.apply', false));
    }

    public function test_expired_run_retains_dismiss_entry_but_suppresses_accept_and_assignment_entries(): void
    {
        $row = $this->suggestion();
        $this->run->update(['expires_at' => now()->subHour()]);

        $this->actingAs($this->actor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('suggestions.0.can', ['accept' => false, 'dismiss' => true, 'apply' => false])
            ->where('suggestions.0.urls.accept', null)->where('suggestions.0.urls.apply', null)
            ->where('suggestions.0.urls.dismiss', route('operations.rostering.suggestions.dismiss', $row))
            ->where('run.can.apply_accepted', true));
    }

    public function test_run_permission_and_explicit_wide_site_scope_are_preserved_without_rescuing_a_bad_client_tuple(): void
    {
        $this->suggestion();
        $foreign = Site::factory()->create();
        $this->actor->hrEmployeeProfile->update(['primary_site_id' => $foreign->id]);
        $this->actingAs($this->actor)->get($this->showUrl())->assertForbidden();
        $this->grant($this->actor, ['shifts.manageAny']);
        // A new HTTP request reloads its actor; the prior denied request loaded
        // the fixture object's old Role/permission collection.
        $currentActor = $this->actor->fresh();
        $this->assertTrue($currentActor->canDo('shifts.manageAny'));
        $this->actingAs($currentActor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->has('suggestions', 1));
        $this->client->update(['site_id' => $foreign->id]);
        $this->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
    }

    public function test_feature_flag_and_exact_grant_denials_are_checked_with_fresh_request_dependencies(): void
    {
        // Production boots the route/controller feature dependency afresh;
        // this fixture changes configuration before its first Show request.
        config(['features.rostering.auto_schedule' => false]);
        $this->actingAs($this->actor)->get($this->showUrl())->assertNotFound();
        $denied = $this->worker('No scheduling authority');
        $this->actingAs($denied)->get($this->showUrl())->assertForbidden();
    }

    public function test_invalid_sources_never_load_or_publish_the_hidden_candidate(): void
    {
        $hiddenWorker = $this->worker('Withheld-worker-sentinel');
        $hiddenWorker->forceFill(['email' => 'withheld-worker-sentinel@example.test'])->save();
        $hidden = $this->suggestion(attributes: ['candidate_user_id' => $hiddenWorker->id]);
        $foreign = Site::factory()->create();
        DB::table('clients')->where('id', $this->client->id)->update(['site_id' => $foreign->id]);
        $visibleClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        $visible = $this->suggestion($this->duty(['client_id' => $visibleClient->id]));
        $before = DB::table('roster_suggestions')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $hiddenLoaded = false;
        User::retrieved(function (User $user) use ($hiddenWorker, &$hiddenLoaded): void {
            if ((int) $user->id === (int) $hiddenWorker->id) {
                $hiddenLoaded = true;
            }
        });

        $response = $this->actingAs($this->actor)->get($this->showUrl())->assertOk();

        $this->assertFalse($hiddenLoaded, 'Withheld suggestions must be filtered before candidate hydration.');
        $response->assertInertia(fn (Assert $page) => $page->has('suggestions', 1)
            ->where('suggestions.0.id', $visible->id)
            ->where('suggestion_visibility', ['basis' => 'current_canonical_run_site', 'recorded_count' => 2, 'visible_count' => 1, 'withheld_count' => 1]));
        $this->assertStringNotContainsString($hiddenWorker->name, $response->getContent());
        $this->assertStringNotContainsString($hiddenWorker->email, $response->getContent());
        $this->assertStringNotContainsString('private-reasons-'.$hidden->id, $response->getContent());
        $this->assertStringNotContainsString('private-snapshot-'.$hidden->id, $response->getContent());
        $this->assertSame($before, DB::table('roster_suggestions')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    #[DataProvider('candidatePrerequisites')]
    public function test_generation_preserves_the_newer_current_site_pool_and_full_duty_employment_range(string $change, int $estimated): void
    {
        $profile = $this->candidate->hrEmployeeProfile;
        match ($change) {
            'inactive' => $profile->update(['is_active' => false]),
            'other Site' => $profile->update(['primary_site_id' => Site::factory()->create()->id]),
            'ends before duty' => $profile->update(['end_date' => '2026-10-13']),
        };
        $service = app(RosterSuggestionService::class);
        $start = Carbon::parse('2026-10-12', 'Pacific/Auckland');
        $this->assertSame($estimated, $service->estimateEvaluationCount($this->actor, $start, $start->copy()->addWeek(), $this->site->id));

        $run = $service->generate($this->actor, '2026-10-12', $this->site->id);

        $this->assertSame(RosterSuggestionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->totals['open_shifts']);
        $this->assertSame([(int) $this->actor->id], $run->suggestions->pluck('candidate_user_id')->unique()->sort()->values()->all());
        $this->assertSame([(int) $this->shift->id], $run->suggestions->pluck('shift_id')->unique()->values()->all());
    }

    public static function candidatePrerequisites(): array
    {
        return ['inactive current profile' => ['inactive', 1], 'different approved Site' => ['other Site', 1],
            'current employee ending before the full duty' => ['ends before duty', 2]];
    }

    public function test_show_redacts_raw_generation_failure_details_without_rewriting_diagnostics(): void
    {
        $diagnostic = 'Foreign-client-sentinel SQLSTATE[23000] secret-connection-sentinel';
        $this->run->forceFill(['status' => RosterSuggestionRun::STATUS_FAILED, 'failure_message' => $diagnostic])->save();
        $before = $this->run->fresh()->getRawOriginal();

        $response = $this->actingAs($this->actor)->get($this->showUrl())->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('run.failure_message', 'Suggestions could not be generated. Reload or generate a new run.')
            ->where('run.status', RosterSuggestionRun::STATUS_FAILED)
            ->where('run.totals', ['open_shifts' => 7, 'suggestion_count' => 9]));
        foreach (['Foreign-client-sentinel', 'SQLSTATE', 'secret-connection-sentinel'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $response->getContent());
        }
        $this->assertSame($before, $this->run->fresh()->getRawOriginal());
        $this->assertSame($diagnostic, $this->run->fresh()->failure_message);
    }

    #[DataProvider('workerZoneFallbacks')]
    public function test_show_retains_the_configured_worker_zone_and_application_fallback(?string $workerZone, string $applicationZone, string $expected): void
    {
        config(['app.worker_timezone' => $workerZone, 'app.timezone' => $applicationZone]);
        $this->suggestion();

        $this->actingAs($this->actor)->get($this->showUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('worker_timezone', $expected)->has('suggestions', 1));
    }

    public static function workerZoneFallbacks(): array
    {
        return ['configured worker zone' => ['America/New_York', 'UTC', 'America/New_York'],
            'null worker zone' => [null, 'America/New_York', 'America/New_York'],
            'empty worker zone with valid UTC application zone' => ['', 'UTC', 'UTC']];
    }

    private function duty(array $attributes = []): Shift
    {
        return Shift::factory()->unassigned()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => null, 'created_by' => $this->actor->id, 'status' => 'scheduled', 'coverage_roles' => [],
            'starts_at' => Carbon::parse('2026-10-14 09:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-14 10:00', 'Pacific/Auckland')->utc(), ...$attributes])->fresh();
    }

    private function suggestion(?Shift $shift = null, array $attributes = []): RosterSuggestion
    {
        $row = RosterSuggestion::factory()->create(['roster_suggestion_run_id' => $this->run->id,
            'shift_id' => ($shift ?? $this->shift)->id, 'candidate_user_id' => $this->candidate->id, ...$attributes]);
        $row->update(['reasons' => ['sentinel' => 'private-reasons-'.$row->id], 'eligibility_snapshot' => ['sentinel' => 'private-snapshot-'.$row->id]]);

        return $row;
    }

    private function worker(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);
        StaffAvailability::create(['user_id' => $user->id, 'day_of_week' => 3, 'starts_at' => '08:00', 'ends_at' => '17:00', 'ends_next_day' => false]);

        return $user;
    }

    private function grant(User $user, array $keys): void
    {
        $role = Role::create(['name' => 'suggestion-read-'.Str::uuid(), 'label' => 'Suggestion read test', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Workforce', 'module' => 'operations'])->id));
        $user->roles()->attach($role);
    }

    private function showUrl(): string
    {
        return route('operations.rostering.suggestions.show', $this->run);
    }
}
