<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\Medication\MedicationSignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Seeded signal rules route to role GROUPS ('managers_core', 'coordinators'),
 * not role names. Recipients must be the real role holders who can open the
 * alert at its Site, and controlled-drug alerts need controlled-medicine
 * access.
 */
class ControlRoomSignalRecipientRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // The schema dump records this migration as run without its rows, so
        // replay it to route through the exact seeded rule values.
        (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();

        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->otherSite = Site::factory()->create(['name' => 'Rimu House']);
    }

    public function test_controlled_drug_alert_reaches_only_controlled_readers_who_can_open_it(): void
    {
        $rule = $this->rule(MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY);
        $this->assertSame(['managers_core', 'coordinators'], $rule->notify_roles);
        $this->assertFalse(Role::query()->whereIn('name', $rule->notify_roles)->exists());

        $manager = $this->userWithRole('provider_manager');
        $coordinator = $this->userWithRole('coordinator', $this->site);
        $withoutControlledAccess = $this->userWithRole('coordinator', $this->site);
        $this->setPermission($withoutControlledAccess, 'medications.controlled.view', false);
        $this->userWithRole('coordinator', $this->otherSite);
        $this->userWithRole('support_worker', $this->site);

        $alert = $this->medicationAlert(MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY, controlled: true);

        $this->assertStagedRecipients([$manager, $coordinator], $alert, $rule);
    }

    public function test_ordinary_medication_alert_recipients_are_scoped_to_the_alerts_site(): void
    {
        $rule = $this->rule(MedicationSignalService::TYPE_OVERDUE);
        $this->assertSame(['managers_core'], $rule->notify_roles);

        $manager = $this->userWithRole('provider_manager');
        $coordinator = $this->userWithRole('coordinator', $this->site);
        $otherSiteCoordinator = $this->userWithRole('coordinator', $this->otherSite);
        $queue = TriageQueue::query()->create([
            'name' => 'Medication response',
            'code' => 'medication-response',
            'tier' => 1,
            'is_active' => true,
            'assigned_roles' => ['coordinators'],
            'assigned_users' => [$otherSiteCoordinator->id],
        ]);

        $alert = $this->medicationAlert(MedicationSignalService::TYPE_OVERDUE, controlled: false);

        $this->assertStagedRecipients([$manager, $coordinator], $alert, $rule, $queue);
    }

    public function test_role_routing_for_other_alerts_is_site_scoped_and_siteless_alerts_reach_org_wide_readers(): void
    {
        $rule = SignalRule::query()->create([
            'name' => 'Integration: SOS Triggered',
            'signal_type_code' => 'integration_sos_triggered',
            'priority' => 10,
            'output_severity' => 'critical',
            'is_active' => true,
            'conditions' => [],
            'notify_roles' => ['managers_core', 'coordinators'],
        ]);
        $manager = $this->userWithRole('provider_manager');
        $coordinator = $this->userWithRole('coordinator', $this->site);
        $this->userWithRole('coordinator', $this->otherSite);

        $sited = $this->ordinaryAlert(['site_id' => $this->site->id]);
        $siteless = $this->ordinaryAlert(['site_id' => null]);

        $this->assertStagedRecipients([$manager, $coordinator], $sited, $rule);
        $this->assertStagedRecipients([$manager], $siteless, $rule);
    }

    public function test_sla_breach_roles_resolve_literal_role_names_at_the_alerts_site(): void
    {
        Notification::fake();
        $coordinator = $this->userWithRole('coordinator', $this->site);
        $otherSiteCoordinator = $this->userWithRole('coordinator', $this->otherSite);
        $sla = SlaDefinition::query()->create([
            'name' => 'Critical response SLA',
            'code' => 'critical-response-sla',
            'severities' => ['critical'],
            'acknowledge_target_minutes' => 5,
            'resolution_target_minutes' => 60,
            'breach_notify_roles' => ['coordinator'],
            'is_active' => true,
        ]);

        app(ControlRoomNotificationService::class)->notifySlaBreachEscalation(
            $this->ordinaryAlert(['site_id' => $this->site->id]),
            $sla,
            ['acknowledge'],
        );

        Notification::assertSentTo($coordinator, ControlRoomAlertNotification::class);
        Notification::assertNotSentTo($otherSiteCoordinator, ControlRoomAlertNotification::class);
    }

    private function rule(string $signalTypeCode): SignalRule
    {
        return SignalRule::query()->where('signal_type_code', $signalTypeCode)->sole();
    }

    /** @param list<User> $expected */
    private function assertStagedRecipients(
        array $expected,
        ControlRoomAlert $alert,
        ?SignalRule $rule,
        ?TriageQueue $queue = null,
    ): void {
        $staged = app(ControlRoomNotificationService::class)
            ->stageAlertNotifications($alert, $rule, $queue);

        $this->assertEqualsCanonicalizing(
            collect($expected)->map(fn (User $user): int => (int) $user->id)->all(),
            $staged->pluck('target_user_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    private function medicationAlert(string $signalType, bool $controlled): ControlRoomAlert
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        // Shape of an alert raised by MedicationSignalService: no alert Site,
        // so the Site comes from the client.
        return ControlRoomAlert::factory()->open()->create([
            'source' => 'medication',
            'alert_type' => $signalType,
            'severity' => 'high',
            'triggered_at' => now()->subMinute(),
            'site_id' => null,
            'client_id' => $client->id,
            'context' => [
                'signal_type_code' => $signalType,
                'normalized_data' => [
                    'source_module' => 'medication',
                    'signal_type' => $signalType,
                    'client_id' => $client->id,
                    'controlled_drug' => $controlled,
                ],
            ],
        ]);
    }

    private function ordinaryAlert(array $attributes): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->open()->create(array_merge([
            'source' => 'external',
            'alert_type' => 'SOS Button',
            'severity' => 'critical',
            'triggered_at' => now()->subMinute(),
            'client_id' => null,
            'context' => [],
        ], $attributes));
    }

    private function userWithRole(string $roleName, ?Site $site = null): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        if ($site !== null) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ]);
        }

        return $user;
    }

    private function setPermission(User $user, string $permissionKey, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => $allowed],
        ]);
    }
}
