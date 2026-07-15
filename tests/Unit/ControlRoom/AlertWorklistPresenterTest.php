<?php

namespace Tests\Unit\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorklistQuery;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertWorklistPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_the_shared_query_is_scoped_actionable_unsnoozed_and_deterministic(): void
    {
        $visibleSite = Site::factory()->create(['tenant_id' => 1]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 2]);
        $viewer = $this->siteBoundUser($visibleSite, ['controlRoom.alerts.view']);

        $high = ControlRoomAlert::factory()->create([
            'reference_number' => 'CR-2026-0101',
            'site_id' => $visibleSite->id,
            'severity' => 'high',
            'triggered_at' => now()->subMinutes(10),
        ]);
        $confirmed = ControlRoomAlert::factory()->create([
            'reference_number' => 'CR-2026-0102',
            'site_id' => $visibleSite->id,
            'status' => ControlRoomAlert::STATUS_CONFIRMED,
            'severity' => 'medium',
            'triggered_at' => now()->subMinutes(30),
        ]);
        ControlRoomAlert::factory()->create([
            'site_id' => $visibleSite->id,
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'severity' => 'critical',
        ]);
        ControlRoomAlert::factory()->create([
            'site_id' => $visibleSite->id,
            'severity' => 'critical',
            'snoozed_until' => now()->addHour(),
        ]);
        ControlRoomAlert::factory()->create([
            'site_id' => $hiddenSite->id,
            'severity' => 'critical',
        ]);

        $rows = app(AlertWorklistQuery::class)->forUser($viewer)->get();

        $this->assertSame([$high->id, $confirmed->id], $rows->pluck('id')->all());
        $this->assertTrue($rows->first()->relationLoaded('site'));
        $this->assertTrue($rows->first()->relationLoaded('sla'));
        $this->assertTrue($rows->first()->relationLoaded('clientIncident'));
        $this->assertTrue($rows->first()->relationLoaded('hsEvent'));
    }

    public function test_presenter_uses_official_references_and_explains_priority_in_plain_language(): void
    {
        $site = Site::factory()->create(['name' => 'Kōwhai House']);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'organization_id' => 1,
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
        ]);
        $viewer = $this->siteBoundUser($site, ['controlRoom.alerts.view']);
        $alert = ControlRoomAlert::factory()->create([
            'reference_number' => 'CR-2026-4321',
            'site_id' => $site->id,
            'client_id' => $client->id,
            'alert_type' => 'missed_welfare_check',
            'severity' => 'low',
            'priority' => 'high',
            'context' => ['title' => 'Welfare check overdue'],
        ]);

        $row = app(AlertWorklistPresenter::class)->present(
            app(AlertWorklistQuery::class)->forUser($viewer)->whereKey($alert->id)->firstOrFail(),
            $viewer,
        );

        $this->assertSame('CR-2026-4321', $row['reference_number']);
        $this->assertSame('Kōwhai House', $row['site']['name']);
        $this->assertSame('Aroha Ngata', $row['person']['name']);
        $this->assertSame('High priority', $row['priority']['reason']);
        $this->assertSame('Welfare check overdue', $row['summary']);
        $this->assertSame('/control-room/alerts/'.$alert->id, $row['href']);
        $this->assertStringNotContainsString('CR-'.$alert->id, json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'organization_id' => $site->tenant_id]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
