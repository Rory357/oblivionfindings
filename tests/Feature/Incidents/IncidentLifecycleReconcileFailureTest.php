<?php

namespace Tests\Feature\Incidents;

use App\Models\ClientIncident;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Services\Incidents\IncidentAlertLifecycleSignalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The .com logged about 9 errors a minute, all day (since at least 20 Oct): closed incidents whose own data can't
 * produce a Control Room lifecycle signal were tried, and failed, every minute. A failed transition is now logged
 * once and tried again after six hours, or straight away after a new close or reopen.
 */
final class IncidentLifecycleReconcileFailureTest extends IncidentRoutingTestCase
{
    public static int $calls = 0;

    protected function tearDown(): void
    {
        self::$calls = 0;
        parent::tearDown();
    }

    private function failingReconciliation(): void
    {
        $this->app->instance(IncidentAlertLifecycleSignalService::class, new class extends IncidentAlertLifecycleSignalService
        {
            public function __construct() {}

            public function reconcileLatestTransition(ClientIncident $incident): ?IncidentLifecycleSignalOutbox
            {
                IncidentLifecycleReconcileFailureTest::$calls++;
                throw new \DomainException('Immediate action is required for a high or critical incident.');
            }
        });
    }

    private function closed(): ClientIncident
    {
        return $this->incident(['status' => 'closed', 'closed_by' => $this->manager->id, 'closed_at' => now(),
            'closed_outcome' => 'resolved']);
    }

    public function test_a_transition_that_cannot_be_reconciled_is_logged_once_not_every_minute(): void
    {
        $incident = $this->closed();
        $this->failingReconciliation();
        Log::spy();

        foreach (range(1, 5) as $minute) {
            app(SafetySignalDeliveryRecoveryService::class)->recover();
            $this->travel(1)->minutes();
        }

        $this->assertSame(1, self::$calls);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context = []) => $message === 'Incident lifecycle signal reconciliation failed'
            && $context['incident_id'] === $incident->id && $context['error'] === 'Immediate action is required for a high or critical incident.');
    }

    public function test_it_is_tried_again_after_six_hours(): void
    {
        $this->closed();
        $this->failingReconciliation();

        app(SafetySignalDeliveryRecoveryService::class)->recover();
        $this->travel(SafetySignalDeliveryRecoveryService::INCIDENT_RETRY_HOURS)->hours();
        $this->travel(1)->minutes();
        app(SafetySignalDeliveryRecoveryService::class)->recover();

        $this->assertSame(2, self::$calls);
    }

    public function test_a_new_close_or_reopen_is_tried_straight_away(): void
    {
        $incident = $this->closed();
        $this->failingReconciliation();

        app(SafetySignalDeliveryRecoveryService::class)->recover();
        $this->travel(5)->minutes();
        DB::table('client_incidents')->where('id', $incident->id)->update(['closed_at' => now()]);
        app(SafetySignalDeliveryRecoveryService::class)->recover();

        $this->assertSame(2, self::$calls);
    }
}
