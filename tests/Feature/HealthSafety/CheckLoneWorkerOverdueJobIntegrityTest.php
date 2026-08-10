<?php

declare(strict_types=1);

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\CheckLoneWorkerOverdueJob;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckLoneWorkerOverdueJobIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_a_corrupt_first_session_rolls_back_without_blocking_a_valid_later_session_and_rerun_is_idempotent(): void
    {
        $validSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $corruptWorker = $this->currentSiteWorker($validSite);
        $validWorker = $this->currentSiteWorker($validSite);
        $corrupt = $this->overdueSession($corruptWorker, $foreignSite);
        $valid = $this->overdueSession($validWorker, $validSite);
        $job = new CheckLoneWorkerOverdueJob;
        $createdAlerts = [];
        Event::listen(
            'eloquent.created: '.ControlRoomAlert::class,
            static function (ControlRoomAlert $alert) use (&$createdAlerts): void {
                $createdAlerts[] = $alert->toArray();
            },
        );

        $job->handle(app(LoneWorkerSignalService::class));

        $validAlert = collect($createdAlerts)->first(
            fn (array $alert): bool => (int) data_get(
                $alert,
                'context.normalized_data.lone_worker_session_id',
            ) === (int) $valid->id,
        );
        $this->assertNotNull($validAlert);
        $this->assertSame([
            'source' => 'lone_worker',
            'alert_type' => 'Lone Worker Overdue Check-in',
            'site_id' => $validSite->id,
            'client_id' => null,
            'signal_type_code' => 'lone_worker_overdue_checkin',
            'source_module' => 'lone_worker',
            'signal_type' => 'lone_worker_overdue_checkin',
            'session_id' => $valid->id,
            'worker_id' => $validWorker->id,
            'normalized_site_id' => $validSite->id,
            'normalized_client_id' => null,
        ], [
            'source' => $validAlert['source'],
            'alert_type' => $validAlert['alert_type'],
            'site_id' => $validAlert['site_id'],
            'client_id' => $validAlert['client_id'],
            'signal_type_code' => data_get($validAlert, 'context.signal_type_code'),
            'source_module' => data_get($validAlert, 'context.normalized_data.source_module'),
            'signal_type' => data_get($validAlert, 'context.normalized_data.signal_type'),
            'session_id' => data_get($validAlert, 'context.normalized_data.lone_worker_session_id'),
            'worker_id' => data_get($validAlert, 'context.normalized_data.worker_user_id'),
            'normalized_site_id' => data_get($validAlert, 'context.normalized_data.site_id'),
            'normalized_client_id' => data_get($validAlert, 'context.normalized_data.client_id'),
        ]);
        $this->assertSame('active', $corrupt->fresh()->status);
        $this->assertSame('overdue', $valid->fresh()->status);
        $this->assertSame(0, $this->sessionSignalCount($corrupt));
        $this->assertSame(0, $this->sessionAlertCount($corrupt));
        $this->assertSame(1, $this->sessionSignalCount($valid));
        $this->assertSame(1, $this->sessionAlertCount($valid));

        $job->handle(app(LoneWorkerSignalService::class));

        $this->assertSame('active', $corrupt->fresh()->status);
        $this->assertSame('overdue', $valid->fresh()->status);
        $this->assertSame(1, $this->sessionSignalCount($valid));
        $this->assertSame(1, $this->sessionAlertCount($valid));
    }

    private function currentSiteWorker(Site $site): User
    {
        $worker = User::factory()->create([
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $worker;
    }

    private function overdueSession(User $worker, Site $site): LoneWorkerSession
    {
        return LoneWorkerSession::query()->create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHours(2),
            'expected_end_at' => now()->addHour(),
            'last_check_in_at' => now()->subHours(2),
            'check_in_interval_minutes' => 10,
            'status' => 'active',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ]);
    }

    private function sessionSignalCount(LoneWorkerSession $session): int
    {
        return Signal::query()
            ->where('normalized_data->lone_worker_session_id', $session->id)
            ->count();
    }

    private function sessionAlertCount(LoneWorkerSession $session): int
    {
        return ControlRoomAlert::query()
            ->where('context->normalized_data->lone_worker_session_id', $session->id)
            ->count();
    }
}
