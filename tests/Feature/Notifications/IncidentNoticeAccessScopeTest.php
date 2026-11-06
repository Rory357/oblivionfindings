<?php

namespace Tests\Feature\Notifications;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Readiness audit B2 (6 Nov 2026): incident notices name the client and severity, so they reach only people who can
 * open that incident — its Site, and the HR-confidential rule — never every manager and coordinator in the organisation.
 */
final class IncidentNoticeAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $here;

    private Site $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->here = Site::factory()->create(['is_active' => true]);
        $this->elsewhere = Site::factory()->create(['is_active' => true]);
    }

    public function test_the_unreviewed_reminder_reaches_only_people_who_can_open_the_incident(): void
    {
        $local = $this->staff('provider_manager', $this->here);
        $remote = $this->staff('provider_manager', $this->elsewhere);
        $remoteCoordinator = $this->staff('coordinator', $this->elsewhere);
        $finance = $this->staff('finance', $this->here);
        $incident = $this->incident(['created_at' => now()->subHours(6)]);

        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);

        $got = $this->recipients('incidents.high_unreviewed_reminder', $incident);
        $this->assertContains($local->id, $got);
        $this->assertNotContains($remote->id, $got);
        $this->assertNotContains($remoteCoordinator->id, $got);
        $this->assertNotContains($finance->id, $got, 'Finance is not in the reminder\'s groups any more.');
    }

    public function test_submitted_and_high_severity_notices_follow_the_incident_read(): void
    {
        $local = $this->staff('provider_manager', $this->here);
        $remote = $this->staff('provider_manager', $this->elsewhere);
        $coordinator = $this->staff('coordinator', $this->here);
        $remoteCoordinator = $this->staff('coordinator', $this->elsewhere);
        $incident = $this->incident();

        foreach (['incidents.submitted', 'incidents.high_severity_alert'] as $key) {
            app(NotificationService::class)->notifyCrud(null, 'submitted', 'incident', $incident, $incident->client, [
                'event_key' => $key, 'title' => 'Incident submitted', 'include_entity_user' => false,
            ]);
            $got = $this->recipients($key, $incident);
            $this->assertNotContains($remote->id, $got, $key);
            $this->assertNotContains($remoteCoordinator->id, $got, $key);
        }
        $this->assertContains($coordinator->id, $this->recipients('incidents.submitted', $incident));
        $this->assertContains($local->id, $this->recipients('incidents.high_severity_alert', $incident));
    }

    public function test_an_hr_confidential_incident_is_announced_only_to_people_who_can_read_hr_cases(): void
    {
        $local = $this->staff('coordinator', $this->here);
        $hr = $this->staff('provider_manager', $this->here);
        $incident = $this->incident(['is_hr_confidential' => true, 'created_at' => now()->subHours(6)]);
        $this->assertFalse($local->canDo('hr.cases.view'));
        $this->assertTrue($hr->canDo('hr.cases.view'));

        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);

        $got = $this->recipients('incidents.high_unreviewed_reminder', $incident);
        $this->assertNotContains($local->id, $got);
        $this->assertContains($hr->id, $got);
    }

    /** @return list<int> */
    private function recipients(string $key, ClientIncident $incident): array
    {
        return DB::table('notifications')->where('data->event_key', $key)->where('data->entity_id', $incident->id)
            ->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function incident(array $extra = []): ClientIncident
    {
        $client = Client::factory()->create(['site_id' => $this->here->id]);

        return ClientIncident::factory()->atSite($this->here)->create([
            'client_id' => $client->id, 'status' => 'submitted', 'severity' => 'high', 'reviewed_at' => null, ...$extra,
        ])->load('client');
    }

    private function staff(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'position_role' => $role, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }
}
