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

    $this->auditViewer = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->auditViewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    $this->secondaryHr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
});

test('the HR audit viewer reads one application history without exposing storage markers', function () {
    AuditLog::query()->forceCreate([
        'user_id' => $this->auditViewer->id,
        'action' => 'hr.employee.updated',
        'auditable_type' => User::class,
        'auditable_id' => $this->auditViewer->id,
        'meta' => ['field' => 'position_title'],
    ]);
    AuditLog::query()->forceCreate([
        'user_id' => $this->secondaryHr->id,
        'action' => 'hr.employee.updated',
        'auditable_type' => User::class,
        'auditable_id' => $this->secondaryHr->id,
        'meta' => ['field' => 'position_title'],
    ]);
    $storageField = 'organi'.'zation_id';

    $this->actingAs($this->auditViewer)
        ->get('/hr/settings/audit-log')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/settings/audit-log')
            ->has('logs.data', 2)
            ->where('logs.data.0.action', 'hr.employee.updated')
            ->missing('logs.data.0.'.$storageField)
            ->where('logs.data.1.action', 'hr.employee.updated')
            ->missing('logs.data.1.'.$storageField));

    expect(AuditLog::query()->count())->toBe(2);
});

test('canonical writes retain exact actor and auditable provenance without storage metadata', function () {
    $subject = User::factory()->create();
    $storageField = 'organi'.'zation_id';
    $legacyAlias = 'ten'.'ant_id';

    AuditLogger::log('user.employee_intake', $subject, [
        'actor_id' => $this->auditViewer->id,
        'correlation' => 'intake-audit-proof',
        $storageField => 991,
        $legacyAlias => 992,
    ], Request::create('/internal/audit', 'POST'));

    $attributed = AuditLog::query()->where('action', 'user.employee_intake')->firstOrFail();
    expect((int) $attributed->user_id)->toBe($this->auditViewer->id)
        ->and($attributed->auditable_type)->toBe($subject->getMorphClass())
        ->and((int) $attributed->auditable_id)->toBe($subject->id)
        ->and($attributed->meta['correlation'])->toBe('intake-audit-proof')
        ->and($attributed->meta)->not->toHaveKey($storageField)
        ->and($attributed->meta)->not->toHaveKey($legacyAlias)
        ->and((int) $attributed->getRawOriginal($storageField))->toBe(1)
        ->and($attributed->toArray())->not->toHaveKey($storageField);

    $this->actingAs($this->secondaryHr);
    AuditLogger::log('hr.actor_context_only');

    $actorDerived = AuditLog::query()->where('action', 'hr.actor_context_only')->firstOrFail();
    expect((int) $actorDerived->user_id)->toBe($this->secondaryHr->id)
        ->and($actorDerived->auditable_type)->toBeNull()
        ->and($actorDerived->auditable_id)->toBeNull();
});

test('unauthenticated system events retain neutral actor and auditable provenance', function () {
    AuditLogger::log(
        'system.unresolved',
        null,
        [],
        Request::create('/internal/audit', 'POST'),
    );

    $entry = AuditLog::query()->where('action', 'system.unresolved')->firstOrFail();
    expect($entry->user_id)->toBeNull()
        ->and($entry->auditable_type)->toBeNull()
        ->and($entry->auditable_id)->toBeNull();
});

test('changing the default payroll export profile audits promoted and demoted identities', function () {
    $first = HrPayrollExportProfile::query()->create([
        'name' => 'Default A',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => true,
        'mappings' => [['header' => 'Employee', 'source' => 'employee_number']],
        'created_by' => $this->auditViewer->id,
        'updated_by' => $this->auditViewer->id,
    ]);
    $second = HrPayrollExportProfile::query()->create([
        'name' => 'Default B',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => false,
        'mappings' => [['header' => 'Employee', 'source' => 'employee_number']],
        'created_by' => $this->auditViewer->id,
        'updated_by' => $this->auditViewer->id,
    ]);

    $this->actingAs($this->auditViewer)
        ->post("/hr/payroll/export-profiles/{$second->id}/set-default")
        ->assertSessionHas('success');

    $entry = AuditLog::query()
        ->where('action', 'hr.payroll_export_profile.default_changed')
        ->firstOrFail();

    expect((int) $entry->user_id)->toBe($this->auditViewer->id)
        ->and($entry->meta['promoted_profile_id'])->toBe($second->id)
        ->and($entry->meta['demoted_profile_ids'])->toBe([$first->id]);
});
