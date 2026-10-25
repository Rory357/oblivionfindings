<?php

namespace Tests\Feature\Incidents;

use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Incidents\IncidentRegisterReadService;
use App\Services\Tasks\TaskAggregator;
use App\Support\Database\LockConflictRetry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The live 500 on /incidents (26 Oct): page reads take a no-wait shared lock on the viewer's user row, so a save
 * holding that row made the read fail at once (MySQL 3572) and the page showed "500 Server Error". The register,
 * the H&S Overview and Tasks now try the read again a moment later.
 */
final class PageReadLockConflictTest extends IncidentRoutingTestCase
{
    /** @var array<string, int> calls per marker permission */
    public static array $calls = [];

    protected function tearDown(): void
    {
        LockConflictRetry::$pause = null;
        self::$calls = [];
        parent::tearDown();
    }

    private static function busy(): QueryException
    {
        $pdo = new \PDOException('SQLSTATE[HY000]: General error: 3572 Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set.');
        $pdo->errorInfo = ['HY000', 3572, 'Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set.'];

        return new QueryException('mysql', 'select * from `users` where `id` in (?) order by `id` asc for share nowait', [1], $pdo);
    }

    /** The no-wait user lock fails $failures times for reads that ask for $marker, as if a save held the row. */
    private function busyFor(string $marker, int $failures, int $code = 3572): void
    {
        LockConflictRetry::$pause = static fn () => null;
        $this->app->instance(AuthorizationEvidenceLockService::class, new class($marker, $failures, $code) extends AuthorizationEvidenceLockService
        {
            public function __construct(private string $marker, private int $failures, private int $code) {}

            public function lockForUserWithoutWaiting(User|int $user, array $permissionKeys): User
            {
                if (in_array($this->marker, $permissionKeys, true)) {
                    $n = PageReadLockConflictTest::$calls[$this->marker] = (PageReadLockConflictTest::$calls[$this->marker] ?? 0) + 1;
                    if ($n <= $this->failures) {
                        $pdo = new \PDOException('SQLSTATE[HY000]: General error: '.$this->code);
                        $pdo->errorInfo = ['HY000', $this->code, 'Synthetic lock conflict'];
                        throw new QueryException('mysql', 'select * from `users` for share nowait', [], $pdo);
                    }
                }

                return parent::lockForUserWithoutWaiting($user, $permissionKeys);
            }
        });
    }

    private function register(?User $actor = null): array
    {
        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => $actor ?? $this->manager);

        return app(IncidentRegisterReadService::class)->index($request);
    }

    public function test_the_incidents_page_loads_when_a_save_briefly_holds_the_viewers_row(): void
    {
        $this->incident();
        $this->busyFor('incidents.templates.manage', 1);

        $this->actingAs($this->manager)->get('/incidents')->assertOk();
        // The route guard meets the held row, tries again and passes; then the register read runs.
        $this->assertSame(3, self::$calls['incidents.templates.manage']);
    }

    public function test_a_row_that_stays_held_is_tried_three_times_then_refused(): void
    {
        $this->busyFor('incidents.templates.manage', 3);

        try {
            $this->register();
            $this->fail('A held row must still refuse after three tries.');
        } catch (QueryException $error) {
            $this->assertSame(3572, (int) $error->errorInfo[1]);
        }
        $this->assertSame(3, self::$calls['incidents.templates.manage']);
    }

    public function test_a_read_inside_a_callers_transaction_is_left_to_the_caller(): void
    {
        $this->busyFor('incidents.templates.manage', 1);

        $this->expectException(QueryException::class);
        try {
            DB::transaction(fn () => $this->register());
        } finally {
            $this->assertSame(1, self::$calls['incidents.templates.manage']);
        }
    }

    public function test_other_database_errors_are_not_retried(): void
    {
        $this->busyFor('incidents.templates.manage', 1, 1054);

        $this->expectException(QueryException::class);
        try {
            $this->register();
        } finally {
            $this->assertSame(1, self::$calls['incidents.templates.manage']);
        }
    }

    public function test_the_hs_overview_loads_when_a_save_briefly_holds_the_viewers_row(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);
        $this->busyFor('sites.type.head_office.view', 1);

        $this->actingAs($viewer)->get('/health-safety')->assertOk();
        $this->assertGreaterThanOrEqual(2, self::$calls['sites.type.head_office.view']);
    }

    public function test_tasks_load_when_a_save_briefly_holds_the_viewers_row(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);
        $this->busyFor('procedures.view', 1);
        $this->asPageRequest('/tasks');

        app(TaskAggregator::class)->itemsFor($viewer);
        $this->assertGreaterThanOrEqual(2, self::$calls['procedures.view']);
    }

    public function test_a_command_or_console_read_is_not_retried(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);
        $this->busyFor('procedures.view', 1);

        // No routed GET request: a no-wait clash stays a fast refusal (here the Tasks read re-raises it).
        $this->expectException(QueryException::class);
        try {
            app(TaskAggregator::class)->itemsFor($viewer);
        } finally {
            $this->assertSame(1, self::$calls['procedures.view']);
        }
    }

    public function test_a_post_request_read_is_not_retried(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);
        $this->busyFor('procedures.view', 1);
        $this->asPageRequest('/tasks', 'POST');

        $this->expectException(QueryException::class);
        try {
            app(TaskAggregator::class)->itemsFor($viewer);
        } finally {
            $this->assertSame(1, self::$calls['procedures.view']);
        }
    }

    /** Serve the next service call as if inside a routed request. */
    private function asPageRequest(string $uri, string $method = 'GET'): void
    {
        $request = Request::create($uri, $method);
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route($method, $uri, []));
        $this->app->instance('request', $request);
    }

    public function test_the_retry_recognises_only_lock_conflicts(): void
    {
        $this->assertTrue(LockConflictRetry::isLockConflict(self::busy()));
        $this->assertTrue(LockConflictRetry::isLockConflict(new \Illuminate\Database\DeadlockException('deadlock')));
        $this->assertFalse(LockConflictRetry::isLockConflict(new \RuntimeException('other')));
    }
}
