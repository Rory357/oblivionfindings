<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\CalmPageOnLockConflict;
use App\Support\Database\LockConflictRetry;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Incidents\IncidentRoutingTestCase;

/**
 * The .com, 1 Nov 21:55 UTC: GET /health-safety/events/148 returned 500 twice. Its reads take FOR SHARE NOWAIT locks and
 * a scheduled job held the rows for ~8 s, longer than the page's quick retries. A page load must never 500 on a lock
 * clash: it retries, then shows a calm "busy" page. This holds a REAL lock from a second connection.
 */
final class CalmPageOnLockConflictTest extends IncidentRoutingTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void
    {
        parent::setUp();
        LockConflictRetry::$pause = static fn () => null;
        CalmPageOnLockConflict::$pause = static fn () => null;
        config(['database.connections.lock_holder' => config('database.connections.'.config('database.default'))]);
    }

    protected function tearDown(): void
    {
        $holder = DB::connection('lock_holder');
        if ($holder->transactionLevel() > 0) { $holder->rollBack(); }
        DB::purge('lock_holder');
        LockConflictRetry::$pause = null;
        CalmPageOnLockConflict::$pause = null;
        parent::tearDown();
    }

    public function test_a_page_whose_rows_stay_locked_shows_the_busy_page_not_a_500_and_loads_once_released(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);

        // A second connection holds the viewer's user row, as a long save or a scheduled job would.
        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('users')->where('id', $viewer->id)->lockForUpdate()->first(['id']);

        $busy = $this->actingAs($viewer)->get('/health-safety');
        $busy->assertStatus(503)->assertHeader('Retry-After', '2');
        $busy->assertInertia(fn ($page) => $page->component('errors/page-busy')->where('path', '/health-safety'));

        $holder->rollBack();

        $this->actingAs($viewer)->get('/health-safety')->assertOk();
    }

    public function test_an_inertia_visit_gets_the_busy_page_too(): void
    {
        $viewer = $this->staff(['hazards.view', 'incidents.viewAny']);
        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('users')->where('id', $viewer->id)->lockForUpdate()->first(['id']);

        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $this->actingAs($viewer)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/health-safety?tab=prevention')
            ->assertStatus(503)
            ->assertJsonPath('component', 'errors/page-busy')
            ->assertJsonPath('props.path', '/health-safety?tab=prevention');
    }

    public function test_a_save_is_never_retried_or_softened(): void
    {
        $response = new \Illuminate\Http\Response('', 500);
        $pdo = new \PDOException('SQLSTATE[HY000]: General error: 3572');
        $pdo->errorInfo = ['HY000', 3572, 'NOWAIT'];
        $response->exception = new \Illuminate\Database\QueryException('mysql', 'select 1 for share nowait', [], $pdo);
        $calls = 0;

        $result = (new CalmPageOnLockConflict)->handle(\Illuminate\Http\Request::create('/health-safety/x', 'POST'), function () use (&$calls, $response) { $calls++; return $response; });

        $this->assertSame(500, $result->getStatusCode());
        $this->assertSame(1, $calls);
    }
}
