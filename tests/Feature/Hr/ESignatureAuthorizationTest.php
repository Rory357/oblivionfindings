<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->requester = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->signer = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $profile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $this->signer->id,
        'employee_number' => 'SIG-001',
        'work_email' => "sig-signer-{$this->signer->id}@example.test",
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
    ]);

    $this->document = HrDocument::factory()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'title' => 'Employment agreement',
        'category' => 'contract',
        'storage_disk' => 'local',
        'storage_path' => 'hr-documents/agreement.pdf',
        'original_name' => 'agreement.pdf',
        'created_by' => $this->requester->id,
        'uploaded_by' => $this->requester->id,
    ]);
});

test('regular authenticated users cannot request e signatures', function () {
    $this->actingAs($this->requester)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$this->signer->id],
        ])
        ->assertForbidden();
});

test('signature managers can request e signatures', function () {
    $role = Role::query()->create([
        'name' => 'signature_manager',
        'label' => 'Signature Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $this->actingAs($this->requester)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$this->signer->id],
        ])
        ->assertRedirect();

    expect(HrDocumentSignature::query()
        ->where('document_id', $this->document->id)
        ->where('signer_user_id', $this->signer->id)
        ->where('requested_by', $this->requester->id)
        ->exists())->toBeTrue();
});

test('signature request route carries initiator permission middleware', function () {
    $route = collect(Route::getRoutes())
        ->first(fn ($route) => $route->getName() === 'hr.signatures.request');

    expect($route?->middleware())->toContain('permission:hr.signatures.manage|hr.documents.manage');
});
