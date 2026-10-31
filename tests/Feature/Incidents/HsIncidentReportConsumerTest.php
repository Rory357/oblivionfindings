<?php

namespace Tests\Feature\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsGovernanceService;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Actual report/count/export consumers. Native suite UNRUN while the shared runtime slot is held. */
final class HsIncidentReportConsumerTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([$this->manager, $this->lead] as $viewer) {
            $this->allow($viewer, 'hazards.view');
            $this->allow($viewer, 'hazards.manage');
        }
    }

    public function test_all_six_actual_consumers_keep_readable_neighbours_and_exclude_involved_source_bodies_counts_and_root_causes(): void
    {
        $blocked = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $this->bundle($blocked, 'restricted-source');
        $healthy = $this->incident(['client_id' => $this->client->id, 'description' => 'Readable source description']);
        $this->bundle($healthy, 'readable-source');
        // Standalone H&S event: hs_events.source_type/source_id are NOT NULL, so it keeps the factory's own
        // non-incident source (HsEvent) rather than nulls; no protected incident source is attached.
        $ordinary = HsEvent::factory()->createQuietly([
            'occurred_at' => now(), 'reported_at' => now(), 'site_id' => $this->site->id, 'client_id' => $this->client->id, 'staff_id' => $this->manager->id,
            'event_category' => HsEvent::CATEGORY_HAZARD, 'status' => HsEvent::STATUS_OPEN,
            'worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'created_by' => $this->manager->id, 'idempotency_key' => (string) Str::uuid()]);
        $this->children($ordinary, 'ordinary-source');

        // Both readers have the same Site and H&S authority; only source involvement differs.
        $manager = $this->reports($this->manager);
        $involved = $this->reports($this->lead);
        $this->assertSame(3, $manager['dashboard']['events']['open_events']);
        $this->assertSame(2, $involved['dashboard']['events']['open_events']);
        $this->assertSame(2, $involved['module']['open_events']);
        $this->assertSame(2, $involved['governance']['event_summary']['open_total']);
        $this->assertSame(2, $involved['worksafe']['item_count']);
        $this->assertSame(2, $involved['investigations']['item_count']);
        $this->assertSame(4, $involved['actions']['item_count']);
        $this->assertSame(2, $involved['risks']['item_count']);
        $this->assertSame(2, $manager['incidents']);
        $this->assertSame(1, $involved['incidents']);
        $this->assertCount(1, $involved['incident_export']['rows']);
        $this->assertSame($healthy->id, $involved['incident_export']['rows'][0][0]);
        $this->assertCount(2, $involved['root_cause_export']['rows']);
        $this->assertSame(1, $involved['analytics']['period_summary']['incidents']);
        $this->assertNull($involved['analytics']['site_comparison'][0]['compliance_score']);
        $encoded = json_encode($involved, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('restricted-source', $encoded);
        $this->assertStringContainsString('readable-source', $encoded);
        $this->assertStringContainsString('ordinary-source', $encoded);
        $this->assertSame('critical', $involved['governance']['overall_status']);
        $this->assertSame(100, $involved['governance']['corrective_action_summary']['effectiveness_rate']);
        $this->assertSame('readable_subset', $involved['dashboard']['source_scope']);
        $this->assertSame('unknown', $involved['governance']['source_completeness']);
    }

    public function test_no_readable_source_evidence_is_unknown_and_does_not_fabricate_good_or_full_effectiveness(): void
    {
        $root = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $this->bundle($root, 'restricted-only');
        $summary = app(HsGovernanceService::class)->getBoardSummary(viewer: $this->lead, siteId: $this->site->id);
        $this->assertSame(0, $summary['event_summary']['open_total']);
        $this->assertSame('unknown', $summary['overall_status']);
        $this->assertNull($summary['corrective_action_summary']['effectiveness_rate']);
        $export = app(HsComplianceExportService::class)->correctiveActionTraceability(viewer: $this->lead, siteId: $this->site->id);
        $this->assertSame(0, $export['item_count']);
        $this->assertNull($export['summary']['effectiveness_rate']);
        $this->assertSame('unknown', $export['source_completeness']);
    }

    public function test_actual_final_count_sql_rejects_permission_withdrawal_and_a_fresh_read_recovers(): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id]), 'count-source');
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `hs_events`')) {
                return;
            }
            $state->armed = false;
            $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false);
            $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $result = app(HsDashboardService::class)->getEventKpis(siteId: $this->site->id, viewer: $this->manager);
            $this->assertTrue($state->fired, 'Intercept the actual count after original admission, not metadata discovery.');
            $this->assertSame(0, $result['open_events']);
        } finally {
            $state->armed = false;
            $this->allow($this->manager, 'incidents.viewAny');
        }
        $this->assertSame(1, app(HsDashboardService::class)->getEventKpis(siteId: $this->site->id, viewer: $this->manager)['open_events']);
    }

    public function test_actual_eager_incident_body_retains_original_parent_and_never_hydrates_reassociated_text(): void
    {
        $root = $this->incident(['client_id' => $this->client->id, 'title' => 'original-private-title']);
        $this->bundle($root, 'body-source');
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $state = (object) ['armed' => true, 'fired' => false, 'hydrated' => 0];
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        ClientIncident::retrieved(function (ClientIncident $row) use ($state): void {
            if ($state->fired && array_key_exists('title', $row->getAttributes())) {
                $state->hydrated++;
            }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($root, $other, $state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from `client_incidents`') || ! str_contains($sql, '`title`')) {
                return;
            }
            $state->armed = false;
            $state->fired = true;
            DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id, 'title' => 'late-reassociated-private-title']);
        });
        try {
            try {
                app(HsDashboardService::class)->notifiableEvents($this->site->id, viewer: $this->manager);
                $this->fail('Reassociated source must withhold the whole already materialised report.');
            } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
            $this->assertTrue($state->fired);
            $this->assertSame(0, $state->hydrated);
        } finally {
            $state->armed = false;
            Model::setEventDispatcher($dispatcher);
        }
    }

    public function test_actual_incident_export_client_name_select_retains_original_source_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'OriginalPrivateClientName']);
        $root = $this->incident(['client_id' => $client->id]);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $state = (object) ['armed' => true, 'fired' => false, 'hydrated' => 0];
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        Client::retrieved(function (Client $row) use ($state): void {
            if ($state->fired && array_key_exists('first_name', $row->getAttributes())) {
                $state->hydrated++;
            }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($root, $other, $state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from `clients`') || ! str_contains($sql, '`first_name`')) {
                return;
            }
            $state->armed = false;
            $state->fired = true;
            DB::table('client_incidents')->where('id', $root->id)->update(['client_id' => $other->id]);
        });
        try {
            try {
                app(HsAnalyticsService::class)->exportRows('incidents', $this->site->id, now()->subDays(7), now()->addDay(), viewer: $this->manager);
                $this->fail('Late source withdrawal must withhold already materialised incident export rows.');
            } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
            $this->assertTrue($state->fired);
            $this->assertSame(0, $state->hydrated);
        } finally {
            $state->armed = false;
            Model::setEventDispatcher($dispatcher);
        }
    }

    public function test_outer_response_barrier_revalidates_an_earlier_nested_scalar_after_later_withdrawal(): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id]), 'original-positive-count');
        try {
            HealthSafetyReadContext::within($this->manager, $this->permissions(), function ($context): array {
                return HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                    $count = app(HsKpiService::class)->incidentsInPeriod(now()->subDays(7), now()->addDay(), $this->site->id, $this->manager, $context);
                    $this->assertSame(1, $count);
                    $this->allow($this->manager, 'incidents.viewAny', false);
                    $this->allow($this->manager, 'incidents.viewAssigned', false);
                    return ['previous_positive_incidents' => $count];
                });
            });
            $this->fail('Earlier positive operands may not escape the outer original response barrier.');
        } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
    }

    public function test_actual_later_count_withdrawal_withholds_previously_materialised_positive_metrics(): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id]), 'positive-before-withdrawal');
        $state = (object) ['armed' => true, 'counts' => 0, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select count(*) as aggregate from `hs_events`')) { return; }
            if (++$state->counts !== 2) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false);
            $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            try {
                app(HsDashboardService::class)->getEventKpis(siteId: $this->site->id, viewer: $this->manager);
                $this->fail('An earlier positive count must be revalidated before returning the metric bundle.');
            } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
    }

    public function test_actual_late_eager_authority_withdrawal_withholds_private_investigation_body_and_summary(): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id]), 'private-investigation-findings');
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! preg_match('/^select .* from `users` /s', $sql) || ! str_contains($sql, '`name`')) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false);
            $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            try {
                app(HsComplianceExportService::class)->investigationOutcomes(viewer: $this->manager, siteId: $this->site->id);
                $this->fail('Private findings and completed summary counts must be withheld together.');
            } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
    }

    public function test_late_investigation_body_change_is_withheld_without_rebasing_still_valid_source_authority(): void
    {
        $event = $this->bundle($this->incident(['client_id' => $this->client->id]), 'original-private-findings');
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $event): void {
            // Fire at the eager load of the investigator names, which runs after the findings were read. (A looser
            // "any users query naming `name`" used to fire on the opening authority check, before any findings were
            // read, so the export just emitted the edited text and the late race was never exercised.)
            if (! $state->armed || ! str_starts_with($sql, 'select `id`, `name` from `users` where `users`.`id` in')) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('hs_investigations')->where('hs_event_id', $event->id)->where('status', HsInvestigation::STATUS_COMPLETED)
                ->update(['findings_summary' => 'changed-after-original-private-body']);
        });
        try {
            try {
                app(HsComplianceExportService::class)->investigationOutcomes(viewer: $this->manager, siteId: $this->site->id);
                $this->fail('Exact emitted findings must remain bound even when source permissions stay valid.');
            } catch (HttpException $failure) { $this->assertSame(404, $failure->getStatusCode()); }
            $this->assertTrue($state->fired);
            // The edit was made on the export's own connection here, so the 404 rolled it back with the export. In use
            // it comes from another, already-committed request; put it back and the next export shows it.
            DB::table('hs_investigations')->where('hs_event_id', $event->id)->where('status', HsInvestigation::STATUS_COMPLETED)
                ->update(['findings_summary' => 'changed-after-original-private-body']);
            $fresh = app(HsComplianceExportService::class)->investigationOutcomes(viewer: $this->manager, siteId: $this->site->id);
            $this->assertStringContainsString('changed-after-original-private-body', json_encode($fresh, JSON_THROW_ON_ERROR));
        } finally { $state->armed = false; }
    }

    #[DataProvider('eagerDateChanges')]
    public function test_actual_eager_before_query_rechecks_original_timezone_and_day_after_subquery_compilation(string $case, string $model, string $field, string $change): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id, 'title' => 'Original private source title']), 'original-eager-source');
        $worker = config('app.worker_timezone'); $appTimezone = config('app.timezone'); $instant = now()->copy();
        $global = $model::getAllGlobalScopes(); $dispatcher = Model::getEventDispatcher();
        $state = (object) ['armed' => true, 'fired' => false, 'hydrated' => 0];
        Model::setEventDispatcher(clone $dispatcher);
        $model::retrieved(function (Model $row) use ($state, $field): void {
            if ($state->fired && array_key_exists($field, $row->getAttributes())) {
                $state->hydrated++;
            }
        });
        $model::addGlobalScope('hs_eager_date_test', function ($builder) use ($state, $field, $change): void {
            $builder->getQuery()->beforeQuery(function (QueryBuilder $compiled) use ($state, $field, $change): void {
                if (! $state->armed || ! $this->isActualEagerBody($compiled, $field)) {
                    return;
                }
                $state->armed = false; $state->fired = true;
                if ($change === 'timezone') {
                    config(['app.worker_timezone' => 'UTC', 'app.timezone' => 'UTC']);
                } else {
                    $this->travelTo(now()->addDay()->utc());
                }
            });
        });
        try {
            config(['app.worker_timezone' => null, 'app.timezone' => 'Pacific/Auckland']);
            try {
                $this->readEagerCase($case);
                $this->fail('The actual eager SELECT must reconsume the original source date guard after whereIn compiled its parent early.');
            } catch (LogicException $error) {
                $this->assertTrue($state->fired, 'Intercept the actual eager body compiler, not source metadata discovery.');
                $this->assertStringContainsString('date evidence', $error->getMessage());
                $this->assertSame(0, $state->hydrated, 'No private eager name or source body may hydrate after original date evidence changes.');
            }
        } finally {
            $state->armed = false;
            $model::setAllGlobalScopes($global); Model::setEventDispatcher($dispatcher);
            config(['app.worker_timezone' => $worker, 'app.timezone' => $appTimezone]); $this->travelTo($instant->utc());
        }
        $this->assertNotEmpty($this->readEagerCase($case), 'A new complete read after the original date is restored remains legitimate.');
    }

    #[DataProvider('eagerCases')]
    public function test_captured_actual_eager_builder_cannot_reuse_original_authority_in_a_new_viewer_context(string $case, string $model, string $field): void
    {
        $this->bundle($this->incident(['client_id' => $this->client->id, 'title' => 'Original private source title']), 'expired-eager-source');
        $escaped = null; $global = $model::getAllGlobalScopes();
        $state = (object) ['armed' => true, 'captured' => false, 'expired' => false, 'body_selects' => 0];
        $model::addGlobalScope('hs_eager_lifetime_test', function ($builder) use (&$escaped, $state, $field): void {
            $builder->getQuery()->beforeQuery(function (QueryBuilder $compiled) use (&$escaped, $state, $field): void {
                if (! $state->armed || ! $this->isActualEagerBody($compiled, $field)) {
                    return;
                }
                $state->armed = false; $state->captured = true; $escaped = clone $compiled;
            });
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $model, $field): void {
            if ($state->expired && str_starts_with($sql, 'select ') && str_contains($sql, 'from `'.(new $model)->getTable().'`') && str_contains($sql, '`'.$field.'`')) {
                $state->body_selects++;
            }
        });
        try {
            $this->assertNotEmpty($this->readEagerCase($case));
            $this->assertTrue($state->captured);
            $this->assertInstanceOf(QueryBuilder::class, $escaped);
            try {
                // Count from inside the fresh read: opening it reads the viewer's own users row, which is not the
                // escaped query (that matters for the user-name data sets).
                HealthSafetyReadContext::within($this->lead, $this->permissions(), function () use ($state, $escaped) {
                    $state->expired = true;
                    return $escaped->get()->all();
                });
                $this->fail('A fresh reader context must not revive the original expired eager source guard.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
                $this->assertSame(0, $state->body_selects, 'The expired eager body SELECT must be refused before SQL reaches the connection.');
            }
        } finally {
            $state->armed = false; $state->expired = false; $model::setAllGlobalScopes($global);
        }
    }

    public static function eagerCases(): array
    {
        return [
            'analytics incident client' => ['analytics_client', Client::class, 'first_name'],
            'compliance incident client' => ['compliance_client', Client::class, 'first_name'],
            'compliance nested HSE Site' => ['compliance_site', Site::class, 'name'],
            'dashboard copied source' => ['dashboard_source', ClientIncident::class, 'title'],
            'dashboard HSE parent' => ['dashboard_event', HsEvent::class, 'reference_number'],
            'compliance action owner' => ['compliance_owner', User::class, 'name'],
        ];
    }

    public static function eagerDateChanges(): array
    {
        $cases = [];
        foreach (self::eagerCases() as $name => $arguments) {
            foreach (['timezone', 'day'] as $change) {
                $cases[$name.' '.$change] = [...$arguments, $change];
            }
        }
        return $cases;
    }

    private function isActualEagerBody(QueryBuilder $query, string $field): bool
    {
        $body = false;
        foreach ($query->columns ?? [] as $column) {
            if (is_string($column) && preg_match('/(?:^|\.)'.preg_quote($field, '/').'$/', str_replace('`', '', $column))) {
                $body = true;
            }
        }
        if (! $body) {
            return false;
        }
        foreach ($query->wheres ?? [] as $where) {
            if (($where['type'] ?? null) === 'In' && ($where['values'][0] ?? null) instanceof Expression) {
                return true;
            }
            if (($where['type'] ?? null) === 'Exists' && ($where['query'] ?? null) instanceof QueryBuilder) {
                return true;
            }
        }
        return false;
    }

    private function readEagerCase(string $case): array
    {
        return match ($case) {
            'analytics_client' => app(HsAnalyticsService::class)->exportRows('incidents', $this->site->id, now()->subDays(7), now()->addDay(), viewer: $this->manager),
            'compliance_client' => app(HsComplianceExportService::class)->worksafeRegister(viewer: $this->manager, siteId: $this->site->id),
            'compliance_site' => app(HsComplianceExportService::class)->investigationOutcomes(viewer: $this->manager, siteId: $this->site->id),
            'dashboard_source' => app(HsDashboardService::class)->notifiableEvents($this->site->id, viewer: $this->manager),
            'dashboard_event' => app(HsDashboardService::class)->openInvestigations($this->site->id, viewer: $this->manager),
            'compliance_owner' => app(HsComplianceExportService::class)->correctiveActionTraceability(viewer: $this->manager, siteId: $this->site->id),
        };
    }

    #[DataProvider('consumerNames')]
    public function test_each_actual_consumer_rejects_an_expired_original_context(string $consumer): void
    {
        $expired = null;
        HealthSafetyReadContext::within($this->manager, $this->permissions(), function ($context) use (&$expired): void {
            $expired = $context;
        });
        $this->expectException(LogicException::class);
        $this->consume($consumer, $this->manager, $expired);
    }

    public static function consumerNames(): array
    {
        return array_map(fn (string $name): array => [$name], ['dashboard', 'module', 'governance', 'export', 'kpi', 'analytics']);
    }

    private function consume(string $consumer, User $viewer, HealthSafetyReadContext $context): mixed
    {
        $from = now()->subDays(7); $to = now()->addDay();
        return match ($consumer) {
            'dashboard' => app(HsDashboardService::class)->getEventKpis($from, $this->site->id, $viewer, $context),
            'module' => app(HsModuleSummaryService::class)->forSite($this->site->id, $viewer, $context),
            'governance' => app(HsGovernanceService::class)->getEventSummary($from, $to, $viewer, $this->site->id, $context),
            'export' => app(HsComplianceExportService::class)->worksafeRegister($from, $to, $viewer, $this->site->id, $context),
            'kpi' => app(HsKpiService::class)->incidentsInPeriod($from, $to, $this->site->id, $viewer, $context),
            'analytics' => app(HsAnalyticsService::class)->exportRows('root_cause', $this->site->id, $from, $to, [], $viewer, $context),
        };
    }

    private function permissions(): array
    {
        return array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS]));
    }

    private function reports(User $viewer): array
    {
        return HealthSafetyReadContext::within($viewer, $this->permissions(), function (HealthSafetyReadContext $context) use ($viewer): array {
            $from = now()->subDays(7); $to = now()->addDay();
            $export = app(HsComplianceExportService::class);
            return [
                'dashboard' => app(HsDashboardService::class)->getDashboardSummary($from, $this->site->id, $viewer, $context),
                'module' => app(HsModuleSummaryService::class)->forSite($this->site->id, $viewer, $context),
                'governance' => app(HsGovernanceService::class)->getBoardSummary($from, $to, $viewer, $this->site->id, $context),
                'worksafe' => $export->worksafeRegister($from, $to, $viewer, $this->site->id, $context),
                'investigations' => $export->investigationOutcomes($from, $to, $viewer, $this->site->id, $context),
                'actions' => $export->correctiveActionTraceability(null, $from, $to, $viewer, $this->site->id, $context),
                'risks' => $export->riskAssessmentRegister($viewer, $this->site->id, [], $context),
                'incidents' => app(HsKpiService::class)->incidentsInPeriod($from, $to, $this->site->id, $viewer, $context),
                'analytics' => app(HsAnalyticsService::class)->build($this->site->id, $from, $to, 'manager', $viewer, $context),
                'incident_export' => app(HsAnalyticsService::class)->exportRows('incidents', $this->site->id, $from, $to, [], $viewer, $context),
                'root_cause_export' => app(HsAnalyticsService::class)->exportRows('root_cause', $this->site->id, $from, $to, [], $viewer, $context),
            ];
        });
    }

    private function bundle(ClientIncident $root, string $marker): HsEvent
    {
        $category = $root->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        $event = HsEvent::factory()->createQuietly(['source_type' => ClientIncident::class, 'source_id' => $root->id,
            'event_category' => $category, 'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $root->id, $category),
            // The factory's Faker dates use the real clock, which can land after the frozen test "now".
            'occurred_at' => now(), 'reported_at' => now(),
            'site_id' => $root->site_id, 'client_id' => $root->client_id, 'control_room_alert_id' => null,
            'status' => HsEvent::STATUS_OPEN, 'worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'closure_summary' => $marker, 'created_by' => $this->manager->id]);
        DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $event->id]);
        $this->children($event, $marker);
        return $event;
    }

    private function children(HsEvent $event, string $marker): void
    {
        HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'title' => $marker, 'created_by' => $this->manager->id, 'due_date' => now()->subDay()]);
        HsCorrectiveAction::factory()->verified()->createQuietly(['hs_event_id' => $event->id, 'title' => $marker.'-verified', 'created_by' => $this->manager->id]);
        HsInvestigation::factory()->inProgress()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->manager->id]);
        HsInvestigation::factory()->completed()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'findings_summary' => $marker, 'root_causes' => [['category' => $marker]]]);
        HsRiskAssessment::factory()->active()->forSite($this->site->id)->createQuietly(['hs_event_id' => $event->id,
            'title' => $marker, 'created_by' => $this->manager->id, 'assessed_by_user_id' => $this->manager->id]);
    }
}
