<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MonitoringCollectorOperatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_site_scoped_operator_issues_one_time_enrolment_without_persisting_plaintext(): void
    {
        [$operator, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
        ]);

        $response = $this->actingAs($operator)->postJson(
            '/security-devices/discovery/collectors/enrolments',
            ['site_id' => $allowedSite->id],
        );

        $response
            ->assertCreated()
            ->assertHeader('Cache-Control')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('enrollment.purpose', 'new_collector');
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
        $token = (string) $response->json('enrollment.token');
        $this->assertStringStartsWith('ofc_enrol_', $token);

        $enrollment = CollectorEnrollment::query()->sole();
        $this->assertSame(hash('sha256', $token), $enrollment->token_hash);
        $this->assertSame($allowedSite->id, $enrollment->site_id);
        $this->assertSame($operator->id, $enrollment->issued_by_user_id);
        $this->assertDatabaseMissing('monitoring_collector_enrollments', ['token_hash' => $token]);

        $audit = AuditLog::query()
            ->where('action', 'monitoring.collector.enrolment.issued')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($operator->id, $audit->user_id);
        $this->assertSame('new_collector', $audit->meta['purpose']);
        $this->assertStringNotContainsString($token, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $page = $this->actingAs($operator)->get('/security-devices/discovery?tab=collectors');
        $page->assertOk()->assertInertia(function ($page) use ($allowedSite): void {
            $management = $page->toArray()['props']['workspace']['collector_management'];

            $this->assertTrue($management['can_manage']);
            $this->assertSame(
                [$allowedSite->id],
                collect($management['sites'])->pluck('id')->all(),
            );
        });
        $page
            ->assertDontSee($token, false)
            ->assertDontSee($hiddenSite->name, false);
    }

    public function test_permission_and_site_boundaries_conceal_collector_lifecycle_objects(): void
    {
        [$operator, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
        ]);
        $hiddenCollector = MonitoringCollector::factory()->create([
            'site_id' => $hiddenSite->id,
            'name' => 'Hidden collector sentinel',
        ]);

        $this->actingAs($operator)
            ->postJson('/security-devices/discovery/collectors/enrolments', [
                'site_id' => $hiddenSite->id,
            ])
            ->assertNotFound();
        $this->actingAs($operator)
            ->postJson("/security-devices/discovery/collectors/{$hiddenCollector->id}/revoke", [
                'reason' => 'Replace inaccessible remote collector safely.',
            ])
            ->assertNotFound();

        $viewer = $this->siteScopedUser($allowedSite, [
            'securityDevices.integrations.view',
        ]);
        $this->actingAs($viewer)
            ->postJson('/security-devices/discovery/collectors/enrolments', [
                'site_id' => $allowedSite->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('monitoring_collector_enrollments', 0);
        $this->assertNull($hiddenCollector->fresh()->revoked_at);
    }

    public function test_revocation_requires_reason_and_re_enrolment_requires_revoked_collector(): void
    {
        [$operator, $site] = $this->siteScopedViewer([
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
        ]);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Remote recovery collector',
            'status' => 'online',
            'revoked_at' => null,
        ]);

        $this->actingAs($operator)
            ->postJson("/security-devices/discovery/collectors/{$collector->id}/re-enrolment")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collector');
        $this->actingAs($operator)
            ->postJson("/security-devices/discovery/collectors/{$collector->id}/revoke", [
                'reason' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $reason = 'Remote host replacement after confirmed storage failure.';
        $this->actingAs($operator)
            ->postJson("/security-devices/discovery/collectors/{$collector->id}/revoke", [
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('collector.state', 'revoked');
        $this->assertNotNull($collector->fresh()->revoked_at);

        $revocationAudit = AuditLog::query()
            ->where('action', 'monitoring.collector.revoked')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($reason, $revocationAudit->meta['reason']);
        $this->assertSame($operator->id, $revocationAudit->user_id);

        $response = $this->actingAs($operator)
            ->postJson("/security-devices/discovery/collectors/{$collector->id}/re-enrolment")
            ->assertCreated()
            ->assertJsonPath('enrollment.purpose', 'collector_re_enrolment');
        $replacementToken = (string) $response->json('enrollment.token');
        $this->assertStringStartsWith('ofc_enrol_', $replacementToken);
        $this->assertDatabaseHas('monitoring_collector_enrollments', [
            'token_hash' => hash('sha256', $replacementToken),
            'site_id' => $site->id,
            'replacement_collector_id' => $collector->id,
        ]);

        $replacementAudit = AuditLog::query()
            ->where('action', 'monitoring.collector.enrolment.issued')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('collector_re_enrolment', $replacementAudit->meta['purpose']);
        $this->assertSame($collector->id, $replacementAudit->meta['replaces_collector_id']);
        $this->assertStringNotContainsString(
            $replacementToken,
            json_encode($replacementAudit->meta, JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $permissions */
    private function siteScopedViewer(array $permissions): array
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed collector Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden collector Site']);

        return [
            $this->siteScopedUser($allowedSite, $permissions),
            $allowedSite,
            $hiddenSite,
        ];
    }

    /** @param list<string> $permissions */
    private function siteScopedUser(Site $site, array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $overrides = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($overrides);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $viewer;
    }
}
