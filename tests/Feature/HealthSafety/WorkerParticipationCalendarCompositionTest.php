<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationAccessService;
use App\Services\HealthSafety\WorkerParticipationCalendarReadService;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\Providers\MealPlanObligationProvider;
use App\Services\Sites\Calendar\Providers\WorkerParticipationObligationProvider;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Services\Sites\SiteCalendarService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Native serializer/ICS and real providers; no substituted authorization or SQL results. All UNRUN. */
final class WorkerParticipationCalendarCompositionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Site $site;
    private Site $other;
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private ObservedParticipationMealProvider $meal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-13T12:00:00+13:00')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        foreach (['hazards.view', 'healthSafety.viewAllSites', 'calendar.view', 'sites.viewAny', 'sites.type.house.view'] as $key) { $this->grant($key, true); }
        $this->committee = HsCommittee::create(['name' => 'Protected committee', 'site_id' => $this->site->id, 'status' => 'active', 'established_at' => '2026-01-01', 'meeting_frequency' => 'monthly', 'members' => [$this->actor->id]]);
        $this->meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-10-15 01:30:00', 'status' => 'completed', 'location' => 'Protected room', 'minutes' => 'Private minutes must never serialize']);
        SiteCalendarEvent::create(['site_id' => $this->site->id, 'event_type' => 'general', 'title' => 'Ordinary manual event', 'start_at' => '2026-10-17 01:00:00',
            'created_by_user_id' => $this->actor->id, 'status' => 'approved', 'approval_status' => 'not_required']);
        SiteMealPlanEntry::create(['site_id' => $this->site->id, 'plan_date' => '2026-10-18', 'meal_slot' => 'lunch', 'source_type' => 'ad_hoc', 'ad_hoc_name' => 'Ordinary meal', 'servings' => 2]);
        $this->meal = new ObservedParticipationMealProvider;
    }

    public function test_native_json_contains_admitted_participation_and_other_sources_with_original_viewer(): void
    {
        $result = $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end());
        $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('application/json', $result['content_type']);
        $this->assertSame($this->actor->id, $data['participation_viewer_id']);
        $this->assertSame('available', $data['availability']['participation']);
        $this->assertEqualsCanonicalizing(['event', 'participation', 'meal'], array_unique(array_column($data['events'], 'source')));
        $meeting = collect($data['events'])->firstWhere('id', 'participation-meeting-'.$this->meeting->id);
        $this->assertSame('Protected room', $meeting['room']); $this->assertSame('completed', $meeting['status']);
        $this->assertStringNotContainsString('Private minutes', $result['body']);
        $this->assertSame(1, $this->meal->calls);
    }

    public function test_native_user_ics_build_keeps_original_read_active_and_preserves_other_entries(): void
    {
        $result = $this->reader()->ics($this->actor, [$this->site->id], $this->start(), $this->end());
        $this->assertStringContainsString('BEGIN:VCALENDAR', $result['body']);
        $this->assertStringContainsString('SUMMARY:H&S committee: Protected committee', $result['body']);
        $this->assertStringContainsString('SUMMARY:Ordinary manual event', $result['body']);
        $this->assertStringContainsString('Ordinary meal', $result['body']);
        $this->assertSame('available', $result['participation_source_access']['status']);
    }

    public function test_initial_permission_denial_preserves_other_providers_without_a_second_private_attempt(): void
    {
        $this->grant('hazards.view', false); $state = $this->participationSql();
        try {
            $result = $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end());
            $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('restricted', $data['availability']['participation']); $this->assertNull($data['participation_viewer_id']);
            $this->assertEqualsCanonicalizing(['event', 'meal'], array_unique(array_column($data['events'], 'source')));
            $this->assertSame(0, $state->selects); $this->assertSame(1, $this->meal->calls);
        } finally { $state->armed = false; }
    }

    public function test_initial_no_viewer_json_excludes_participation_and_preserves_manual_source(): void
    {
        $state = $this->participationSql();
        try {
            $result = $this->reader()->json(null, [$this->site->id], $this->start(), $this->end());
            $this->assertSame('restricted', $result['participation_source_access']['status']);
            $this->assertStringContainsString('Ordinary manual event', $result['body']); $this->assertStringNotContainsString('Protected committee', $result['body']);
            $this->assertSame(0, $state->selects);
        } finally { $state->armed = false; }
    }

    public function test_participation_only_initial_denial_does_not_pass_empty_sources_as_all(): void
    {
        $this->grant('hazards.view', false); $state = $this->participationSql();
        try {
            $result = $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), ['sources' => ['participation']]);
            $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $data['events']); $this->assertSame('restricted', $data['availability']['participation']);
            $this->assertSame(0, $state->selects); $this->assertSame(0, $this->meal->calls);
        } finally { $state->armed = false; }
    }

    public function test_explicit_no_participation_filter_keeps_other_data_without_private_admission(): void
    {
        $this->grant('hazards.view', false); $state = $this->participationSql();
        try {
            $result = $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), ['sources' => ['meal']]);
            $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['meal'], array_unique(array_column($data['events'], 'source')));
            $this->assertSame('not_requested', $data['availability']['participation']); $this->assertSame(0, $state->selects);
        } finally { $state->armed = false; }
    }

    public function test_empty_filter_means_all_and_genuine_empty_sites_remain_available(): void
    {
        $result = $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), ['sources' => []]);
        $this->assertStringContainsString('Protected committee', $result['body']);
        $empty = $this->reader()->json($this->actor, [], $this->start(), $this->end());
        $data = json_decode($empty['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $data['events']); $this->assertSame('available', $data['availability']['participation']);
    }

    public function test_explicit_other_family_only_context_does_not_need_private_participation_preparation(): void
    {
        $result = HealthSafetyReadContext::within($this->actor, ['calendar.view'], fn ($context): array =>
            $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), ['sources' => ['meal']], $context));
        $this->assertSame('not_requested', $result['participation_source_access']['status']);
        $this->assertStringContainsString('Ordinary meal', $result['body']);
    }

    public function test_manual_provider_mutation_before_participation_body_cannot_rebaseline_the_supplied_access(): void
    {
        $armed = true; $fired = false; $names = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommittee::retrieved(function (Model $row) use (&$armed, &$fired, &$names): void { if ($armed && $fired && array_key_exists('name', $row->getAttributes())) { $names++; } });
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && ! $fired && preg_match('/^select .* from `site_calendar_events`/is', ltrim($sql))) {
                $fired = true; DB::table('hs_committees')->where('id', $this->committee->id)->update(['site_id' => $this->other->id]);
            }
        });
        try {
            $this->refused(fn () => $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end()), 404);
            $this->assertTrue($fired); $this->assertSame(0, $names); $this->assertSame(1, $this->meal->calls);
        } finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    public function test_after_other_provider_source_mutation_refuses_without_fallback_or_second_provider_invocation(): void
    {
        $this->meal->afterRead = fn () => DB::table('hs_committees')->where('id', $this->committee->id)->update(['updated_by' => $this->actor->id]);
        $this->refused(fn () => $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end()), 404);
        $this->assertSame(1, $this->meal->calls);
    }

    public function test_native_json_serialization_mutation_is_checked_before_any_detached_body_returns(): void
    {
        $probe = new ParticipationEncodingProbe(fn () => DB::table('hs_committees')->where('id', $this->committee->id)->update(['updated_by' => $this->actor->id]));
        $this->meal->encodingProbe = $probe;
        $this->refused(fn () => $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end()), 404);
        $this->assertSame(1, $probe->calls); $this->assertSame(1, $this->meal->calls);
    }

    public function test_native_json_encoding_failure_after_operation_entry_does_not_retry_other_sources(): void
    {
        $this->meal->encodingProbe = new ParticipationEncodingProbe(static function (): void { throw new \RuntimeException('Encoder failure fixture'); });
        $this->expectException(\RuntimeException::class);
        try { $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end()); }
        finally { $this->assertSame(1, $this->meal->calls); }
    }

    public function test_actual_ics_builder_return_mutation_remains_inside_original_collector_and_never_falls_back(): void
    {
        $builder = new ObservedParticipationIcsBuilder;
        $builder->afterBuild = fn () => DB::table('hs_committees')->where('id', $this->committee->id)->update(['updated_by' => $this->actor->id]);
        $this->refused(fn () => $this->reader($builder)->ics($this->actor, [$this->site->id], $this->start(), $this->end()), 404);
        $this->assertSame(1, $builder->calls); $this->assertTrue($builder->nativeBodyBuilt); $this->assertSame(1, $this->meal->calls);
    }

    public function test_current_grant_change_after_other_provider_is_refused_without_fresh_context_rescue(): void
    {
        $this->meal->afterRead = fn () => $this->grant('hazards.view', false);
        $this->refused(fn () => $this->reader()->ics($this->actor, [$this->site->id], $this->start(), $this->end()), 404);
        $this->assertSame(1, $this->meal->calls);
        $this->meal->afterRead = null; $this->grant('hazards.view', true);
        $this->assertSame('available', $this->reader()->ics($this->actor, [$this->site->id], $this->start(), $this->end())['participation_source_access']['status']);
    }

    public function test_original_supplied_context_and_collector_survive_native_builder_without_escape(): void
    {
        $builder = new ObservedParticipationIcsBuilder;
        $result = HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use ($builder): array {
            return HealthSafetyReadMaterialisationProof::within($context, function ($proof) use ($context, $builder): array {
                $builder->afterBuild = function () use ($context, $proof): void { $context->assertActive(); $this->assertSame($proof, HealthSafetyReadMaterialisationProof::current($context)); };
                return $this->reader($builder)->ics($this->actor, [$this->site->id], $this->start(), $this->end(), [], 'Calendar', $context);
            });
        });
        $this->assertSame('available', $result['participation_source_access']['status']);
    }

    public function test_expired_wrong_viewer_or_unprepared_supplied_context_cannot_fallback(): void
    {
        $escaped = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS, function ($context) use (&$escaped): array { $escaped = $context; return []; });
        $this->logic(fn () => $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), [], $escaped));
        $different = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->logic(fn () => HealthSafetyReadContext::within($this->actor, WorkerParticipationAccessService::PERMISSIONS,
            fn ($context): array => $this->reader()->json($different, [$this->site->id], $this->start(), $this->end(), [], $context)));
        $this->logic(fn () => HealthSafetyReadContext::within($this->actor, ['hazards.view'],
            fn ($context): array => $this->reader()->json($this->actor, [$this->site->id], $this->start(), $this->end(), [], $context)));
        $this->assertSame(0, $this->meal->calls);
    }

    private function reader(?IcsFeedBuilder $builder = null): WorkerParticipationCalendarReadService
    {
        return new WorkerParticipationCalendarReadService(app(WorkerParticipationAccessService::class),
            new SiteCalendarAggregator(app(SiteCalendarService::class), [new WorkerParticipationObligationProvider, $this->meal]), $builder ?? app(IcsFeedBuilder::class));
    }
    private function start(): Carbon { return Carbon::parse('2026-10-01T00:00:00Z'); }
    private function end(): Carbon { return Carbon::parse('2026-10-31T23:59:59Z'); }
    private function grant(string $key, bool $allowed): void { $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]); }
    private function participationSql(): object
    {
        $state = (object) ['armed' => true, 'selects' => 0];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void { if ($state->armed && preg_match('/^select .* from `hs_(committees|committee_meetings|representatives|consultations|meeting_attendees)`/is', ltrim($sql))) { $state->selects++; } });
        return $state;
    }
    private function refused(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('The original source must refuse before returning a body.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }
    private function logic(callable $operation): void
    {
        try { $operation(); $this->fail('The original context must not be replaced.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}

/** Observe real meal SQL; instrumentation never supplies a source admission result. */
final class ObservedParticipationMealProvider extends MealPlanObligationProvider
{
    public int $calls = 0;
    public mixed $afterRead = null;
    public ?ParticipationEncodingProbe $encodingProbe = null;
    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        $this->calls++; $items = parent::obligations($siteIds, $start, $end);
        if ($this->afterRead !== null) { ($this->afterRead)(); }
        if ($this->encodingProbe !== null && $items !== []) { $items[0]->owner = ['id' => 1, 'name' => $this->encodingProbe]; }
        return $items;
    }
}

final class ParticipationEncodingProbe implements JsonSerializable
{
    public int $calls = 0;
    public function __construct(private readonly mixed $mutation) {}
    public function jsonSerialize(): mixed { $this->calls++; ($this->mutation)(); return 'Ordinary encoded owner'; }
}

/** Executes the actual IcsFeedBuilder implementation, then fires while its call is still active. */
final class ObservedParticipationIcsBuilder extends IcsFeedBuilder
{
    public int $calls = 0;
    public bool $nativeBodyBuilt = false;
    public mixed $afterBuild = null;
    public function build(array $items, string $calendarName = 'Site Calendar'): string
    {
        $this->calls++; $body = parent::build($items, $calendarName); $this->nativeBodyBuilt = str_contains($body, 'BEGIN:VEVENT');
        if ($this->afterBuild !== null) { ($this->afterBuild)(); }
        return $body;
    }
}
