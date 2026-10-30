<?php

namespace Tests\Feature\Performance;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\RetryPageReadOnLockConflict;
use App\Models\Client;
use App\Models\Role;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Support\Database\LockConflictRetry;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use Tests\Support\CommittedDatabaseTestCase;

/**
 * Many pages lock the viewer's user row FOR SHARE NOWAIT while they prove access. A save that holds that row for a
 * moment (Chat's availability setting holds it FOR UPDATE) made such a page fail at once with MySQL 3572 and show
 * "500 Server Error" (the admin got three on the .com on 30 Oct). Here a second connection really holds the row
 * while each page loads: the page must load, and a page that meets the lock waits and runs again from the start.
 * Only page loads (GET, HEAD) that have written nothing are retried; saves still refuse at once. Real seeded admin.
 */
final class BusyViewerRowPagesTest extends CommittedDatabaseTestCase
{
    private ?PDO $holder = null;

    private int $met = 0;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['features.rostering.auto_schedule' => true]);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $this->admin->id, 'position_role' => 'admin', 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        $config = config('database.connections.'.DB::getDefaultConnection());
        $this->holder = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], DB::connection()->getDatabaseName()),
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // The first pause between attempts is when the "save" finishes and lets the row go.
        LockConflictRetry::$pause = function (): void {
            $this->met++;
            if ($this->holder->inTransaction()) {
                $this->holder->rollBack();
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->holder?->inTransaction()) {
            $this->holder->rollBack();
        }
        $this->holder = null;
        LockConflictRetry::$pause = null;
        parent::tearDown();
    }

    /** Loads $page while a second connection holds the admin's user row FOR UPDATE; returns [status, met]. */
    private function loadWhileBusy(string $page): array
    {
        $this->met = 0;
        $this->holder->beginTransaction();
        $this->holder->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $held = $this->holder->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT');
        $held->execute([$this->admin->id]);
        $this->assertSame($this->admin->id, (int) $held->fetchColumn());

        $status = $this->actingAs($this->admin)->get($page)->getStatusCode();
        if ($this->holder->inTransaction()) {
            $this->holder->rollBack();
        }

        return [$status, $this->met];
    }

    public function test_listed_pages_load_while_a_save_holds_the_viewers_user_row(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $shift = Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id]);
        $run = RosterSuggestionRun::factory()->create(['site_id' => $this->site->id, 'requested_by' => $this->admin->id]);

        // Page => whether its own read locks the viewer's row (so it must meet the lock and run again).
        $pages = [
            '/my-day' => false,
            '/operations/shifts' => false,
            "/operations/shifts/{$shift->id}" => true,
            '/operations/job-board' => false,
            '/operations/job-board/postable-shifts' => true,
            '/operations/rostering' => false,
            "/operations/rostering/suggestions/{$run->id}" => true,
            '/operations/referrals' => false,
            '/safeguarding' => false,
            '/emar/downtime' => false,
            "/emar/reports/export-mar?purpose=audit&site_id={$this->site->id}" => true,
        ];
        $results = [];
        foreach ($pages as $page => $locks) {
            [$status, $met] = $this->loadWhileBusy($page);
            $results[$page] = ['status' => $status, 'met' => $met, 'locks' => $locks];
        }

        $report = collect($results)->map(fn (array $row, string $page) => sprintf('%-70s %d met=%d', $page, $row['status'], $row['met']))->implode("\n");
        foreach ($results as $page => $row) {
            $this->assertSame(200, $row['status'], "$page answered {$row['status']} while the viewer's row was busy.\n{$report}");
            if ($row['locks']) {
                $this->assertGreaterThan(0, $row['met'], "$page never met the held row, so this proves nothing about it.\n{$report}");
            }
        }
    }

    public function test_without_the_retry_the_export_fails_while_the_row_is_busy(): void
    {
        $this->withoutMiddleware(RetryPageReadOnLockConflict::class);

        [$status, $met] = $this->loadWhileBusy("/emar/reports/export-mar?purpose=audit&site_id={$this->site->id}");

        $this->assertSame(500, $status);
        $this->assertSame(0, $met);
    }

    public function test_a_page_load_that_has_written_is_not_run_again(): void
    {
        DB::statement('CREATE TEMPORARY TABLE busy_row_probe_writes (id INT AUTO_INCREMENT PRIMARY KEY)');
        $admin = $this->admin;
        Route::middleware(['web', 'auth', RetryPageReadOnLockConflict::class])->get('/__busy-row-probe', function () use ($admin) {
            DB::table('busy_row_probe_writes')->insert([]);
            DB::transaction(fn () => app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($admin, ['*']));

            return 'loaded';
        });

        [$status, $met] = $this->loadWhileBusy('/__busy-row-probe');

        $this->assertSame(500, $status);
        $this->assertSame(0, $met, 'A page load that wrote must not be retried.');
        $this->assertSame(1, DB::table('busy_row_probe_writes')->count());
    }
}
