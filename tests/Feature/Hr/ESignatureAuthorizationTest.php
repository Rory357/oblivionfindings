<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\ESignatureService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create([
        'name' => 'Signature Allowed Site',
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Signature Hidden Site',
    ]);

    $this->requester = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->signer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $this->requester->id,
        'employee_number' => 'SIG-REQUESTER-'.$this->requester->id,
        'work_email' => "sig-requester-{$this->requester->id}@example.test",
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);

    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->signer->id,
        'employee_number' => 'SIG-001',
        'work_email' => "sig-signer-{$this->signer->id}@example.test",
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);

    $this->document = HrDocument::factory()->create([
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

test('signature managers cannot request documents or signers at inaccessible Sites', function () {
    $role = Role::query()->create([
        'name' => 'site_signature_manager',
        'label' => 'Site Signature Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $foreignSigner = User::factory()->create([
        'approved_at' => now(),
    ]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $foreignSigner->id,
        'employee_number' => 'SIG-FOREIGN-'.$foreignSigner->id,
        'work_email' => "foreign-{$foreignSigner->id}@example.test",
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $foreignDocument = HrDocument::factory()->create([
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
        ->assertSessionHasErrors('user_ids');

    expect(HrDocumentSignature::query()->count())->toBe(0);
});

test('signature managers cannot mutate signature requests at inaccessible Sites', function () {
    $role = Role::query()->create([
        'name' => 'site_signature_actions_manager',
        'label' => 'Site Signature Actions Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $foreignSigner = User::factory()->create(['approved_at' => now()]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $foreignSigner->id,
        'employee_number' => 'SIG-ACTION-'.$foreignSigner->id,
        'work_email' => "foreign-action-{$foreignSigner->id}@example.test",
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $foreignDocument = HrDocument::factory()->create([
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignSigner->id,
        'uploaded_by' => $foreignSigner->id,
    ]);
    $pending = HrDocumentSignature::query()->create([
        'document_id' => $foreignDocument->id,
        'signer_user_id' => $foreignSigner->id,
        'status' => 'pending',
        'requested_by' => $foreignSigner->id,
        'requested_at' => now(),
    ]);
    $declinedDocument = HrDocument::factory()->create([
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignSigner->id,
        'uploaded_by' => $foreignSigner->id,
    ]);
    $declined = HrDocumentSignature::query()->create([
        'document_id' => $declinedDocument->id,
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
        ->and(HrDocumentSignature::query()->where('document_id', $foreignDocument->id)->count())->toBe(1)
        ->and(HrDocumentSignature::query()->where('document_id', $declinedDocument->id)->count())->toBe(1);
});

test('signers cannot view or action a signature for a document at an inaccessible Site', function () {
    $foreignOwner = User::factory()->create(['approved_at' => now()]);
    $foreignProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $foreignOwner->id,
        'employee_number' => 'SIG-SIGNER-'.$foreignOwner->id,
        'work_email' => "foreign-signer-{$foreignOwner->id}@example.test",
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $foreignDocument = HrDocument::factory()->create([
        'employee_profile_id' => $foreignProfile->id,
        'created_by' => $foreignOwner->id,
        'uploaded_by' => $foreignOwner->id,
    ]);
    $signature = HrDocumentSignature::query()->create([
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

test('former staff cannot receive view or action internal signature requests', function () {
    $role = Role::query()->create([
        'name' => 'current_signature_manager',
        'label' => 'Current Signature Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
        Permission::query()->where('key', 'hr.documents.view')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $former = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $formerProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $former->id,
        'employee_number' => 'SIG-FORMER-'.$former->id,
        'work_email' => "former-{$former->id}@example.test",
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => false,
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $former->id,
        'status' => 'pending',
        'requested_by' => $this->requester->id,
        'requested_at' => now(),
    ]);
    $declinedDocument = HrDocument::factory()->create([
        'employee_profile_id' => $formerProfile->id,
        'created_by' => $this->requester->id,
        'uploaded_by' => $this->requester->id,
    ]);
    $declined = HrDocumentSignature::query()->create([
        'document_id' => $declinedDocument->id,
        'signer_user_id' => $former->id,
        'status' => 'declined',
        'declined_reason' => 'Legacy decline',
        'requested_by' => $this->requester->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->requester)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$former->id],
        ])
        ->assertSessionHasErrors(['user_ids']);

    $pending = $this->actingAs($former)
        ->get('/hr/signatures/pending')
        ->assertOk()
        ->inertiaProps('signatures');
    expect($pending)->toBe([]);

    $this->actingAs($former)
        ->get("/hr/signatures/{$signature->id}")
        ->assertNotFound();
    $this->actingAs($former)
        ->post("/hr/signatures/{$signature->id}/sign", ['signature_data' => 'signed'])
        ->assertNotFound();
    $this->actingAs($former)
        ->post("/hr/signatures/{$signature->id}/decline", ['reason' => 'No'])
        ->assertNotFound();

    $managerIndex = $this->actingAs($this->requester)
        ->get('/hr/documents')
        ->assertOk();
    expect($managerIndex->inertiaProps('signatureRequests'))->toBe([])
        ->and($managerIndex->inertiaProps('signatureCompletion.total'))->toBe(0);

    $this->actingAs($this->requester)
        ->post("/hr/signatures/{$signature->id}/nudge")
        ->assertNotFound();
    $this->actingAs($this->requester)
        ->post("/hr/signatures/{$declined->id}/resend")
        ->assertNotFound();
    $this->actingAs($this->requester)
        ->post("/hr/signatures/document/{$this->document->id}/cancel")
        ->assertNotFound();

    expect($signature->fresh()->status)->toBe('pending')
        ->and($signature->fresh()->reminder_sent_at)->toBeNull()
        ->and($declined->fresh()->status)->toBe('declined')
        ->and(HrDocumentSignature::query()->where('document_id', $this->document->id)->count())->toBe(1)
        ->and(HrDocumentSignature::query()->where('document_id', $declinedDocument->id)->count())->toBe(1);
});

test('the signature service rejects inaccessible Site participants before writing', function () {
    $foreignUser = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $foreignUser->id,
        'employee_number' => 'SIG-HIDDEN-'.$foreignUser->id,
        'work_email' => "hidden-{$foreignUser->id}@example.test",
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $service = app(ESignatureService::class);

    expect(fn () => $service->requestSignature(
        $this->document,
        $foreignUser->id,
        $this->requester->id,
    ))->toThrow(LogicException::class, 'not available');

    expect(fn () => $service->bulkRequestSignatures(
        $this->document,
        [$this->signer->id],
        $foreignUser->id,
    ))->toThrow(LogicException::class, 'not available');

    expect(HrDocumentSignature::query()->count())->toBe(0);
});

test('signature views conceal an unavailable legacy requester identity', function () {
    $role = Role::query()->create([
        'name' => 'signature_identity_manager',
        'label' => 'Signature Identity Manager',
        'type' => 'custom',
        'level' => 50,
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.signatures.manage')->firstOrFail()->id,
        Permission::query()->where('key', 'hr.documents.view')->firstOrFail()->id,
    ]);
    $this->requester->roles()->sync([$role->id]);

    $hiddenRequester = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hiddenRequester->id,
        'employee_number' => 'SIG-HIDDEN-REQUESTER-'.$hiddenRequester->id,
        'work_email' => "hidden-requester-{$hiddenRequester->id}@example.test",
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $this->signer->id,
        'status' => 'pending',
        'requested_by' => $hiddenRequester->id,
        'requested_at' => now(),
    ]);

    $managerRequest = collect(
        $this->actingAs($this->requester)
            ->get('/hr/documents')
            ->assertOk()
            ->inertiaProps('signatureRequests'),
    )->firstWhere('document_id', $this->document->id);
    expect($managerRequest)->not->toBeNull()
        ->and($managerRequest['requested_by'])->not->toBe($hiddenRequester->name);

    $signerInbox = collect(
        $this->actingAs($this->signer)
            ->get('/hr/signatures/pending')
            ->assertOk()
            ->inertiaProps('signatures'),
    )->firstWhere('id', $signature->id);
    expect($signerInbox['requested_by'])->toBe('HR team');

    $this->actingAs($this->signer)
        ->get("/hr/signatures/{$signature->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signature.requested_by', 'HR team'));

    $myHrRequest = collect(
        $this->actingAs($this->signer)
            ->get('/hr/my/documents')
            ->assertOk()
            ->inertiaProps('pendingSignatures'),
    )->firstWhere('id', $signature->id);
    expect($myHrRequest['requested_by'])->toBe('HR team');
});
