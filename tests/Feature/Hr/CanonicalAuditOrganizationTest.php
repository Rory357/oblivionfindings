<?php

use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->orgOneHr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->orgOneHr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    $this->orgTwoHr = User::factory()->create([
        'organization_id' => 2,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
});

test('the HR audit viewer reads only canonical events for the current organization', function () {
    AuditLog::query()->forceCreate([
        'organization_id' => 1,
        'user_id' => $this->orgOneHr->id,
        'action' => 'hr.employee.updated',
        'auditable_type' => User::class,
        'auditable_id' => $this->orgOneHr->id,
        'meta' => ['field' => 'position_title'],
    ]);
    AuditLog::query()->forceCreate([
        'organization_id' => 2,
        'user_id' => $this->orgTwoHr->id,
        'action' => 'hr.employee.updated',
        'auditable_type' => User::class,
        'auditable_id' => $this->orgTwoHr->id,
        'meta' => ['field' => 'position_title'],
    ]);

    $this->actingAs($this->orgOneHr)
        ->get('/hr/settings/audit-log')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/settings/audit-log')
            ->has('logs.data', 1)
            ->where('logs.data.0.organization_id', 1)
            ->where('logs.data.0.action', 'hr.employee.updated'));

    expect(AuditLog::query()->forOrganization(2)->count())->toBe(1);
});

test('canonical writes resolve organization explicitly then from auditable and actor context', function () {
    $globalUser = User::factory()->create(['organization_id' => null]);

    AuditLogger::log('user.employee_intake', $globalUser, [
        'organization_id' => 1,
        'actor_id' => $this->orgOneHr->id,
    ], Request::create('/internal/audit', 'POST'));

    $explicit = AuditLog::query()->where('action', 'user.employee_intake')->firstOrFail();
    expect((int) $explicit->organization_id)->toBe(1)
        ->and($explicit->meta)->not->toHaveKey('organization_id');

    $this->actingAs($this->orgTwoHr);
    AuditLogger::log('hr.actor_context_only');

    $actorDerived = AuditLog::query()->where('action', 'hr.actor_context_only')->firstOrFail();
    expect((int) $actorDerived->organization_id)->toBe(2)
        ->and((int) $actorDerived->user_id)->toBe($this->orgTwoHr->id);
});

test('unresolvable system events remain organization neutral', function () {
    AuditLogger::log(
        'system.unresolved',
        null,
        [],
        Request::create('/internal/audit', 'POST'),
    );

    $entry = AuditLog::query()->where('action', 'system.unresolved')->firstOrFail();
    expect($entry->organization_id)->toBeNull()
        ->and($entry->user_id)->toBeNull();
});

test('changing the default payroll export profile audits promoted and demoted identities', function () {
    $first = HrPayrollExportProfile::query()->create([
        'tenant_id' => 1,
        'name' => 'Default A',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => true,
        'mappings' => [['header' => 'Employee', 'source' => 'employee_number']],
        'created_by' => $this->orgOneHr->id,
        'updated_by' => $this->orgOneHr->id,
    ]);
    $second = HrPayrollExportProfile::query()->create([
        'tenant_id' => 1,
        'name' => 'Default B',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => false,
        'mappings' => [['header' => 'Employee', 'source' => 'employee_number']],
        'created_by' => $this->orgOneHr->id,
        'updated_by' => $this->orgOneHr->id,
    ]);

    $this->actingAs($this->orgOneHr)
        ->post("/hr/payroll/export-profiles/{$second->id}/set-default")
        ->assertSessionHas('success');

    $entry = AuditLog::query()
        ->where('action', 'hr.payroll_export_profile.default_changed')
        ->firstOrFail();

    expect((int) $entry->organization_id)->toBe(1)
        ->and((int) $entry->user_id)->toBe($this->orgOneHr->id)
        ->and($entry->meta['promoted_profile_id'])->toBe($second->id)
        ->and($entry->meta['demoted_profile_ids'])->toBe([$first->id]);
});
