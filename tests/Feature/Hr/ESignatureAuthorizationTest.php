<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\ESignatureService;
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

test('signature managers cannot request foreign tenant documents or signers', function () {
    $role = Role::query()->create([
        'name' => 'tenant_signature_manager',
        'label' => 'Tenant Signature Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $foreignSigner = User::factory()->create([
        'organization_id' => 2,
        'approved_at' => now(),
    ]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 2,
        'user_id' => $foreignSigner->id,
        'employee_number' => 'SIG-FOREIGN-'.$foreignSigner->id,
        'work_email' => "foreign-{$foreignSigner->id}@example.test",
    ]);
    $foreignDocument = HrDocument::factory()->create([
        'tenant_id' => 2,
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignSigner->id,
        'uploaded_by' => $foreignSigner->id,
    ]);

    $this->actingAs($this->requester)
        ->post('/hr/signatures/request', [
            'document_id' => $foreignDocument->id,
            'user_ids' => [$this->signer->id],
        ])
        ->assertNotFound();

    $this->actingAs($this->requester)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$foreignSigner->id],
        ])
        ->assertSessionHasErrors('user_ids.0');

    expect(HrDocumentSignature::query()->count())->toBe(0);
});

test('signature managers cannot mutate foreign tenant signature requests', function () {
    $role = Role::query()->create([
        'name' => 'tenant_signature_actions_manager',
        'label' => 'Tenant Signature Actions Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $foreignSigner = User::factory()->create(['organization_id' => 2, 'approved_at' => now()]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 2,
        'user_id' => $foreignSigner->id,
        'employee_number' => 'SIG-ACTION-'.$foreignSigner->id,
        'work_email' => "foreign-action-{$foreignSigner->id}@example.test",
    ]);
    $foreignDocument = HrDocument::factory()->create([
        'tenant_id' => 2,
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignSigner->id,
        'uploaded_by' => $foreignSigner->id,
    ]);
    $pending = HrDocumentSignature::query()->create([
        'tenant_id' => 2,
        'document_id' => $foreignDocument->id,
        'signer_user_id' => $foreignSigner->id,
        'status' => 'pending',
        'requested_by' => $foreignSigner->id,
        'requested_at' => now(),
    ]);
    $declined = HrDocumentSignature::query()->create([
        'tenant_id' => 2,
        'document_id' => $foreignDocument->id,
        'signer_user_id' => $foreignSigner->id,
        'status' => 'declined',
        'declined_reason' => 'Not agreed',
        'requested_by' => $foreignSigner->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->requester)
        ->post("/hr/signatures/{$pending->id}/nudge")
        ->assertNotFound();
    $this->actingAs($this->requester)
        ->post("/hr/signatures/{$declined->id}/resend")
        ->assertNotFound();
    $this->actingAs($this->requester)
        ->post("/hr/signatures/document/{$foreignDocument->id}/cancel")
        ->assertNotFound();

    expect($pending->fresh()->reminder_sent_at)->toBeNull()
        ->and($declined->fresh()->status)->toBe('declined')
        ->and(HrDocumentSignature::query()->where('document_id', $foreignDocument->id)->count())->toBe(2);
});

test('signers cannot view or action a signature assigned from a foreign tenant', function () {
    $foreignOwner = User::factory()->create(['organization_id' => 2, 'approved_at' => now()]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 2,
        'user_id' => $foreignOwner->id,
        'employee_number' => 'SIG-SIGNER-'.$foreignOwner->id,
        'work_email' => "foreign-signer-{$foreignOwner->id}@example.test",
    ]);
    $foreignDocument = HrDocument::factory()->create([
        'tenant_id' => 2,
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignOwner->id,
        'uploaded_by' => $foreignOwner->id,
    ]);
    $signature = HrDocumentSignature::query()->create([
        'tenant_id' => 2,
        'document_id' => $foreignDocument->id,
        'signer_user_id' => $this->signer->id,
        'status' => 'pending',
        'requested_by' => $foreignOwner->id,
        'requested_at' => now(),
    ]);

    $pending = $this->actingAs($this->signer)
        ->get('/hr/signatures/pending')
        ->assertOk()
        ->inertiaProps('signatures');
    expect(collect($pending)->pluck('id'))->not->toContain($signature->id);

    $this->actingAs($this->signer)->get("/hr/signatures/{$signature->id}")->assertNotFound();
    $this->actingAs($this->signer)
        ->post("/hr/signatures/{$signature->id}/sign", ['signature_data' => 'signed'])
        ->assertNotFound();
    $this->actingAs($this->signer)
        ->post("/hr/signatures/{$signature->id}/decline", ['reason' => 'No'])
        ->assertNotFound();

    expect($signature->fresh()->status)->toBe('pending');
});

test('the signature service rejects cross tenant request participants before writing', function () {
    $foreignUser = User::factory()->create(['organization_id' => 2, 'approved_at' => now()]);
    $service = app(ESignatureService::class);

    expect(fn () => $service->requestSignature(
        $this->document,
        $foreignUser->id,
        $this->requester->id,
    ))->toThrow(LogicException::class, 'same organisation');

    expect(fn () => $service->bulkRequestSignatures(
        $this->document,
        [$this->signer->id],
        $foreignUser->id,
    ))->toThrow(LogicException::class, 'same organisation');

    expect(HrDocumentSignature::query()->count())->toBe(0);
});
