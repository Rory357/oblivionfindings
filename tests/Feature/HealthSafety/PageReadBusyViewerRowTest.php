<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Support\Database\LockConflictRetry;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\CommittedDatabaseTestCase;

/**
 * The live 500s on the .com (30 Oct 04:31 UTC, admin): H&S page reads lock the viewer's user row FOR SHARE NOWAIT,
 * and Chat's availability save held that row FOR UPDATE for a moment, so /health-safety/injuries, worker
 * participation and /hazards/{id} failed with MySQL 3572. A second connection now really holds the row while each
 * page loads; the page must meet the lock, wait, run again from the start and load (never a 500).
 */
final class PageReadBusyViewerRowTest extends CommittedDatabaseTestCase
{
    private ?PDO $holder = null;

    private int $met = 0;

    protected function tearDown(): void
    {
        if ($this->holder?->inTransaction()) {
            $this->holder->rollBack();
        }
        $this->holder = null;
        LockConflictRetry::$pause = null;
        parent::tearDown();
    }

    public function test_h_and_s_incident_and_task_pages_load_while_a_save_holds_the_viewers_user_row(): void
    {
        // Its own roles: committed tests restore every table afterwards, so nothing leaks to or from other tests.
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $admin->roles()->attach(Role::where('name', 'admin')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $admin->id, 'position_role' => 'admin', 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $hazard = SiteHazard::query()->create(['site_id' => $site->id, 'reported_by_user_id' => $admin->id, 'hazard_type' => 'slip_trip_fall',
            'severity' => 'low', 'likelihood' => 'rare', 'risk_rating' => 'low', 'description' => 'Wet floor by the back door', 'status' => 'open',
            'due_date' => now()->addWeek()]);

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

        $pages = [
            '/health-safety',
            '/health-safety/injuries',
            '/health-safety/worker-participation?tab=representatives',
            '/health-safety/risk-assessments',
            '/health-safety/first-aid',
            '/hazards/'.$hazard->id,
            '/incidents',
            '/tasks',
        ];
        foreach ($pages as $page) {
            $this->met = 0;
            $this->holder->beginTransaction();
            $this->holder->exec('SET SESSION innodb_lock_wait_timeout = 2');
            $held = $this->holder->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT');
            $held->execute([$admin->id]);
            $this->assertSame($admin->id, (int) $held->fetchColumn());

            $response = $this->actingAs($admin)->get($page);

            $this->assertLessThan(500, $response->getStatusCode(), "$page answered {$response->getStatusCode()} while the viewer's row was busy.");
            $response->assertOk();
            $this->assertGreaterThan(0, $this->met, "$page never met the held row, so this proves nothing about it.");
            $this->assertFalse($this->holder->inTransaction());
        }
    }
}
