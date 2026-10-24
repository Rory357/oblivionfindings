<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\RestraintKpiService;
use App\Services\Respite\RespiteStayScope;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual canonical source/resolver queries. Whole native suite remains UNRUN. */
final class RestraintStaySupportingSourceReadTest extends RestraintEventCommandTestCase
{
    public function test_healthy_metrics_never_hydrate_shared_supporting_private_bodies(): void
    {
        $this->graph();
        $this->withoutSupportingBodies(fn () => $this->assertSame(1, $this->summary()['events_in_period']));
    }

    public function test_metadata_mode_retains_every_canonical_resolution_field_and_loaded_relation(): void
    {
        $graph = $this->graph();
        $result = $this->within(function ($context) use ($graph): array {
            $access = app(RestraintAccessService::class);
            return $access->withReadContext($context, function ($current, $reads) use ($context, $graph): array {
                $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                $stay = app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata($this->requestFor($current), $graph['stay']->id, $reads, $frame);
                $booking = $stay->getRelation('booking');
                return ['stay' => $stay->getAttributes(), 'client' => $stay->getRelation('client')->getAttributes(),
                    'booking' => $booking->getAttributes(), 'request' => $booking->getRelation('request')->getAttributes(),
                    'site' => app(RespiteStayScope::class)->siteId($stay)];
            });
        });
        $this->assertEqualsCanonicalizing(['id', 'client_id', 'booking_id', 'deleted_at'], array_keys($result['stay']));
        $this->assertEqualsCanonicalizing(['id', 'site_id', 'deleted_at'], array_keys($result['client']));
        $this->assertEqualsCanonicalizing(['id', 'client_id', 'location_id', 'booking_request_id', 'deleted_at'], array_keys($result['booking']));
        $this->assertEqualsCanonicalizing(['id', 'client_id', 'deleted_at'], array_keys($result['request']));
        $this->assertSame($this->site->id, $result['site']);
    }

    public function test_ordinary_health_safety_and_client_resolvers_keep_full_private_fields_and_relations(): void
    {
        $graph = $this->graph();
        foreach ([false, true] as $clientPurpose) {
            $result = $this->within(fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
                function ($current, $reads) use ($graph, $clientPurpose): array {
                    $scope = app(RespiteStayScope::class);
                    $stay = $clientPurpose ? $scope->resolveAuthorizedStay($this->requestFor($current), $graph['stay']->id, false, $reads)
                        : $scope->resolveAuthorizedHealthSafetyStay($this->requestFor($current), $graph['stay']->id, false, $reads);
                    return [$stay->discharge_summary, $stay->booking->funding_reference, $stay->booking->request->preference_notes,
                        $stay->client->first_name, $scope->siteId($stay)];
                }));
            $this->assertSame(['Private stay body', 'Private booking body', 'Private request body', $this->client->first_name, $this->site->id], $result);
        }
    }

    public function test_original_null_request_and_null_location_keep_real_client_site_fallback(): void
    {
        $graph = $this->graph(false);
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['location_id' => null]);
        $this->withoutSupportingBodies(fn () => $this->assertSame(1, $this->summary()['events_in_period']));
    }

    public function test_historical_soft_deleted_request_remains_canonically_readable_without_its_body(): void
    {
        $graph = $this->graph();
        DB::table('respite_booking_requests')->where('id', $graph['request']->id)->update(['deleted_at' => now()]);
        $this->withoutSupportingBodies(fn () => $this->assertSame(1, $this->summary()['events_in_period']));
    }

    public static function sharedSelects(): array
    {
        return array_map(fn ($point) => [$point], ['stay_candidate', 'client', 'booking_candidate', 'request', 'booking_final', 'stay_final']);
    }

    #[DataProvider('sharedSelects')]
    public function test_original_root_mutation_before_each_actual_shared_support_select_denies_hydration(string $point): void
    {
        $graph = $this->graph();
        $foreign = Client::factory()->create(['site_id' => $this->site->id]);
        $this->atSharedSelect($point, fn () => DB::table('restraint_events')->where('id', $graph['event']->id)->update(['client_id' => $foreign->id]),
            fn () => $this->assertNull($this->summary()['events_in_period']));
        DB::table('restraint_events')->where('id', $graph['event']->id)->update(['client_id' => $this->client->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public static function currentEvidenceChanges(): array
    {
        return array_map(fn ($change) => [$change], ['permission', 'profile', 'assignment', 'site']);
    }

    #[DataProvider('currentEvidenceChanges')]
    public function test_original_current_evidence_withdrawn_at_shared_client_query_denies_then_fresh_restore_works(string $change): void
    {
        $this->graph();
        $this->grant('clients.viewAny', false); $this->grant('clients.viewAssigned', true);
        $this->client->supportWorkers()->attach($this->actor->id);
        $this->atSharedSelect('client', function () use ($change): void {
            match ($change) {
                'permission' => $this->grant('restraints.view', false),
                'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]),
                'assignment' => DB::table('client_user')->where('client_id', $this->client->id)->where('user_id', $this->actor->id)->delete(),
                'site' => DB::table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
            };
        }, fn () => $this->assertNull($this->summary()['events_in_period']));
        $this->grant('restraints.view', true);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => true]);
        DB::table('sites')->where('id', $this->site->id)->update(['is_active' => true]);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->actor->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_prior_booking_request_pointer_is_required_at_shared_request_select(): void
    {
        $graph = $this->graph();
        $this->atSharedSelect('request', fn () => DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => null]),
            fn () => $this->assertNull($this->summary()['events_in_period']));
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => $graph['request']->id]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_original_null_request_cannot_be_rebased_to_new_request_before_shared_final_booking(): void
    {
        $graph = $this->graph(false);
        $request = RespiteBookingRequest::create(['client_id' => $this->client->id, 'requested_start' => now()->subDay(), 'requested_end' => now()->addDay(), 'status' => 'approved']);
        $this->atSharedSelect('booking_final', fn () => DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => $request->id]),
            fn () => $this->assertNull($this->summary()['events_in_period']));
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['booking_request_id' => null]);
        $this->assertSame(1, $this->summary()['events_in_period']);
    }

    public function test_malformed_supporting_client_tuple_withholds_completeness_and_preserves_healthy_neighbour_after_repair(): void
    {
        $graph = $this->graph(); $this->event();
        $foreign = Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['client_id' => $foreign->id]);
        $this->withoutSupportingBodies(fn () => $this->assertNull($this->summary()['events_in_period']));
        DB::table('respite_bookings')->where('id', $graph['booking']->id)->update(['client_id' => $this->client->id]);
        $this->assertSame(2, $this->summary()['events_in_period']);
    }

    public function test_no_stay_and_empty_population_keep_legitimate_operands_without_supporting_body_queries(): void
    {
        $this->withoutSupportingBodies(fn () => $this->assertSame(0, $this->summary()['events_in_period']));
        $this->event();
        $this->withoutSupportingBodies(fn () => $this->assertSame(1, $this->summary()['events_in_period']));
    }

    public function test_expired_original_source_cannot_be_embedded_into_new_viewer_metadata_resolution(): void
    {
        $graph = $this->graph(); $escaped = null;
        $this->within(function ($context) use ($graph, &$escaped): array {
            return app(RestraintAccessService::class)->withReadContext($context, function ($current, $reads) use ($context, $graph, &$escaped): array {
                $escaped = $this->actualFrame($graph['event'], $context, $current, $reads);
                return [];
            });
        });
        $other = $this->staff(['restraints.view', 'clients.viewAny']);
        $this->logicRefusal(fn () => HealthSafetyReadContext::within($other, RestraintAccessService::PERMISSIONS, fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
                function ($current, $reads) use ($graph, $escaped): array {
                    $stay = app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata($this->requestFor($current), $graph['stay']->id, $reads, $escaped);
                    return ['id' => $stay->id];
                })));
    }

    public function test_original_frame_cannot_attach_under_another_still_live_actor_context(): void
    {
        $graph = $this->graph(); $other = $this->staff(['restraints.view', 'clients.viewAny']);
        $this->withoutSupportingBodies(fn () => $this->within(fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
            function ($current, $reads) use ($context, $graph, $other): array {
                $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                $this->logicRefusal(fn () => HealthSafetyReadContext::within($other, RestraintAccessService::PERMISSIONS,
                    fn ($otherContext) => app(RestraintAccessService::class)->withReadContext($otherContext,
                        fn ($receiver, $receiverReads) => app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata(
                            $this->requestFor($receiver), $graph['stay']->id, $receiverReads, $frame))));
                return [];
            })));
    }

    public function test_same_actor_fresh_context_cannot_consume_an_original_still_live_frame(): void
    {
        $graph = $this->graph();
        $this->within(fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
            function ($current, $reads) use ($context, $graph): array {
                $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                $this->logicRefusal(fn () => HealthSafetyReadContext::within($this->actor, RestraintAccessService::PERMISSIONS,
                    fn ($fresh) => app(RestraintAccessService::class)->withReadContext($fresh,
                        fn ($receiver, $receiverReads) => app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata(
                            $this->requestFor($receiver), $graph['stay']->id, $receiverReads, $frame))));
                return [];
            }));
    }

    public function test_same_prepared_actor_with_another_live_reads_handle_cannot_use_frame(): void
    {
        $graph = $this->graph();
        $this->within(fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
            function ($current, $reads) use ($context, $graph): array {
                $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                $this->logicRefusal(fn () => CurrentAuthorizationReads::within(fn ($otherReads) =>
                    app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata($this->requestFor($current), $graph['stay']->id, $otherReads, $frame)));
                return [];
            }));
    }

    public function test_same_id_cloned_actor_is_not_the_original_prepared_receiver(): void
    {
        $graph = $this->graph();
        $this->within(fn ($context) => app(RestraintAccessService::class)->withReadContext($context,
            function ($current, $reads) use ($context, $graph): array {
                $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                $this->logicRefusal(fn () => app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata(
                    $this->requestFor(clone $current), $graph['stay']->id, $reads, $frame));
                return [];
            }));
    }

    public function test_direct_expired_frame_refuses_before_actual_support_query(): void
    {
        $graph = $this->graph(); $frame = $receiver = $receiverReads = null;
        // A closure (not an arrow fn) so the escaped frame reaches this scope by reference.
        $this->within(function ($context) use ($graph, &$frame, &$receiver, &$receiverReads) {
            return app(RestraintAccessService::class)->withReadContext($context,
                function ($current, $reads) use ($context, $graph, &$frame, &$receiver, &$receiverReads): array {
                    $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                    $receiver = $current; $receiverReads = $reads;
                    return [];
                });
        });
        $this->assertInstanceOf(Closure::class, $frame);
        $this->logicRefusal(fn () => $frame(RespiteStay::query()->whereKey($graph['stay']->id), $receiver, $receiverReads)->get());
    }

    public function test_old_frame_inside_fresh_same_actor_context_refuses_before_support_sql(): void
    {
        $graph = $this->graph(); $frame = null;
        // A closure (not an arrow fn) so the escaped frame reaches this scope by reference.
        $this->within(function ($context) use ($graph, &$frame) {
            return app(RestraintAccessService::class)->withReadContext($context,
                function ($current, $reads) use ($context, $graph, &$frame): array {
                    $frame = $this->actualFrame($graph['event'], $context, $current, $reads);
                    return [];
                });
        });
        $this->assertInstanceOf(Closure::class, $frame);
        $this->logicRefusal(fn () => $this->within(fn ($fresh) => app(RestraintAccessService::class)->withReadContext($fresh,
            fn ($current, $reads) => app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStayMetadata(
                $this->requestFor($current), $graph['stay']->id, $reads, $frame))));
    }

    private function actualFrame(RestraintEvent $event, HealthSafetyReadContext $context, $current, CurrentAuthorizationReads $reads): Closure
    {
        $access = app(RestraintAccessService::class);
        // Exercise the actual private applicator with a real canonically proven
        // parent. Reflection exposes no production factory or alternate grant.
        $parent = $access->protectedEventSourceQuery($event, $context);
        return (new \ReflectionMethod($access, 'supportingSourceFrame'))->invoke($access, $context, $current, $reads, $parent);
    }

    private function logicRefusal(callable $operation): void
    {
        $armed = true; $queries = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$queries): void {
            if ($armed && preg_match('/^select\b.*? from `(respite_stays|respite_bookings|respite_booking_requests|clients)`/i', ltrim($sql))) { $queries[] = $sql; }
        });
        try {
            try { $operation(); $this->fail('Original live supporting source receiver must be retained.'); }
            catch (LogicException) {}
            $this->assertSame([], $queries, 'Receiver refusal must occur before actual supporting SQL, not only final model-return validation.');
        } finally { $armed = false; }
    }

    private function graph(bool $withRequest = true): array
    {
        $request = $withRequest ? RespiteBookingRequest::create(['client_id' => $this->client->id, 'requested_start' => now()->subDay(),
            'requested_end' => now()->addDay(), 'status' => 'approved', 'preference_notes' => 'Private request body']) : null;
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id,
            'booking_request_id' => $request?->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'funding_reference' => 'Private booking body']);
        $stay = RespiteStay::create(['client_id' => $this->client->id, 'booking_id' => $booking->id, 'status' => 'active',
            'actual_start' => now()->subDay(), 'discharge_summary' => 'Private stay body', 'created_by' => $this->actor->id]);
        $this->grant('respite.stays.manage', true);
        $event = RestraintEvent::findOrFail($this->service()->createForStay($this->actor, $stay, $this->capture())['event_id']);
        return compact('request', 'booking', 'stay', 'event');
    }

    private function summary(): array
    {
        return app(RestraintKpiService::class)->summary(null, now()->subDay(), now(), $this->actor);
    }

    private function within(callable $consume): mixed
    {
        return HealthSafetyReadContext::within($this->actor, RestraintAccessService::PERMISSIONS, $consume);
    }

    private function requestFor($current): Request
    {
        $request = Request::create('/'); $request->setUserResolver(fn () => $current); return $request;
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function withoutSupportingBodies(callable $read): void
    {
        $armed = true; $bodies = [];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        foreach ([RespiteStay::class => 'discharge_summary', RespiteBooking::class => 'funding_reference',
            RespiteBookingRequest::class => 'preference_notes', Client::class => 'first_name'] as $class => $field) {
            $class::retrieved(function (Model $row) use ($field, &$armed, &$bodies): void {
                if ($armed && array_key_exists($field, $row->getAttributes())) { $bodies[] = $row->getTable().'.'.$field; }
            });
        }
        try { $read(); $this->assertSame([], $bodies, 'Actual supporting private attributes must never hydrate in metadata-only metrics.'); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    private function atSharedSelect(string $point, callable $mutate, callable $read): void
    {
        $armed = true; $fired = false; $seen = 0; $laterMetadata = [];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        foreach ([RespiteStay::class, RespiteBooking::class, RespiteBookingRequest::class, Client::class] as $class) {
            $class::retrieved(function (Model $row) use (&$armed, &$fired, &$laterMetadata): void {
                if ($armed && $fired) { $laterMetadata[] = $row->getTable(); }
            });
        }
        DB::connection()->beforeExecuting(function (string $sql) use ($point, $mutate, &$armed, &$fired, &$seen): void {
            if (! $armed || $fired) { return; }
            $sql = strtolower(ltrim($sql));
            $matches = match ($point) {
                'stay_candidate', 'stay_final' => str_starts_with($sql, 'select `id`, `client_id`, `booking_id`, `deleted_at` from `respite_stays`') && str_contains($sql, 'exists (select * from `clients`'),
                'client' => str_starts_with($sql, 'select `id`, `site_id`, `deleted_at` from `clients`'),
                'booking_candidate', 'booking_final' => str_starts_with($sql, 'select `id`, `client_id`, `location_id`, `booking_request_id`, `deleted_at` from `respite_bookings`') && str_contains($sql, 'where `respite_bookings`.`id` ='),
                'request' => str_starts_with($sql, 'select `id`, `client_id`, `deleted_at` from `respite_booking_requests`') && str_contains($sql, 'where `respite_booking_requests`.`id` ='),
            };
            if ($matches && ++$seen === (str_ends_with($point, '_final') ? 2 : 1)) { $fired = true; $mutate(); }
        });
        try {
            $this->withoutSupportingBodies($read);
            $this->assertTrue($fired, 'Intercept the actual named shared metadata SELECT, not a readiness mock.');
            $this->assertSame([], $laterMetadata, 'The withdrawn original predicate must exclude even the actual supporting metadata row.');
        } finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }
}
