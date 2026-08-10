<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Storage::fake('private');

    $this->documentIdentitySite = Site::factory()->create([
        'name' => 'Document Identity Site',
    ]);
    $this->documentIdentityManager = documentIdentityStaff(
        'Document Identity Manager',
        $this->documentIdentitySite,
    );
    $this->documentIdentitySigner = documentIdentityStaff(
        'Document Identity Signer',
        $this->documentIdentitySite,
    );

    $role = Role::query()->create([
        'name' => 'document_identity_manager',
        'label' => 'Document identity manager',
        'type' => 'custom',
        'level' => 60,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key', [
        'hr.documents.view',
        'hr.documents.manage',
        'hr.signatures.manage',
    ])->pluck('id')->all());
    $this->documentIdentityManager->roles()->sync([$role->id]);

    $profile = $this->documentIdentitySigner->hrEmployeeProfile()->firstOrFail();
    $this->documentIdentityDocument = HrDocument::factory()->create([
        'employee_profile_id' => $profile->id,
        'title' => 'Application identity agreement',
        'category' => 'contract',
        'storage_disk' => 'private',
        'storage_path' => 'hr-documents/application-identity.pdf',
        'original_name' => 'application-identity.pdf',
        'created_by' => $this->documentIdentityManager->id,
        'uploaded_by' => $this->documentIdentityManager->id,
    ]);
    Storage::disk('private')->put($this->documentIdentityDocument->storage_path, 'PDF BYTES');
});

function documentIdentityStaff(string $name, Site $site): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'DOC-IDENTITY-'.$user->id,
        'work_email' => "doc-identity-{$user->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => today()->subYear(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

test('document template names are normalized and unique across the application', function (): void {
    $payload = [
        'name' => '  Employment Agreement  ',
        'category' => 'contract',
        'content' => 'Welcome {{employee_name}}',
        'merge_fields' => ['employee_name'],
        'approval_required' => false,
    ];

    $this->actingAs($this->documentIdentityManager)
        ->post('/hr/documents/templates', $payload)
        ->assertSessionHas('success');

    expect(HrDocumentTemplate::query()->sole()->name)->toBe('Employment Agreement');

    $this->actingAs($this->documentIdentityManager)
        ->post('/hr/documents/templates', [
            ...$payload,
            'name' => ' employment agreement ',
        ])
        ->assertSessionHasErrors('name');

    expect(HrDocumentTemplate::query()->count())->toBe(1);

    $otherTemplate = HrDocumentTemplate::query()->create([
        'name' => 'Offer Letter',
        'category' => 'offer',
        'content' => 'Offer for {{employee_name}}',
        'is_active' => true,
    ]);
    $this->actingAs($this->documentIdentityManager)
        ->put("/hr/documents/templates/{$otherTemplate->id}", [
            'name' => 'EMPLOYMENT AGREEMENT',
        ])
        ->assertSessionHasErrors('name');

    expect($otherTemplate->fresh()->name)->toBe('Offer Letter');
});

test('signature requests reject an existing active signer atomically and allow a new cycle after cancellation', function (): void {
    $this->actingAs($this->documentIdentityManager)
        ->post('/hr/signatures/request', [
            'document_id' => $this->documentIdentityDocument->id,
            'user_ids' => [$this->documentIdentitySigner->id],
            'order' => 'parallel',
        ])
        ->assertSessionHas('success');

    $otherSigner = documentIdentityStaff('Document Identity Other Signer', $this->documentIdentitySite);
    $this->actingAs($this->documentIdentityManager)
        ->post('/hr/signatures/request', [
            'document_id' => $this->documentIdentityDocument->id,
            'user_ids' => [$this->documentIdentitySigner->id, $otherSigner->id],
            'order' => 'parallel',
        ])
        ->assertSessionHasErrors('user_ids');

    expect($this->documentIdentityDocument->signatures()->count())->toBe(1)
        ->and($this->documentIdentityDocument->signatures()
            ->where('signer_user_id', $otherSigner->id)->exists())->toBeFalse();

    $this->documentIdentityDocument->signatures()->sole()->update(['status' => 'cancelled']);

    $this->actingAs($this->documentIdentityManager)
        ->post('/hr/signatures/request', [
            'document_id' => $this->documentIdentityDocument->id,
            'user_ids' => [$this->documentIdentitySigner->id],
            'order' => 'parallel',
        ])
        ->assertSessionHas('success');

    expect($this->documentIdentityDocument->signatures()->count())->toBe(2)
        ->and($this->documentIdentityDocument->signatures()
            ->where('status', 'pending')->count())->toBe(1);
});

test('signature capture rejects oversized and malformed image payloads before mutation', function (): void {
    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->documentIdentityDocument->id,
        'signer_user_id' => $this->documentIdentitySigner->id,
        'status' => 'pending',
        'requested_by' => $this->documentIdentityManager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->documentIdentitySigner)
        ->post("/hr/signatures/{$signature->id}/sign", [
            'signature_data' => str_repeat('A', 500_001),
        ])
        ->assertSessionHasErrors('signature_data');

    $this->actingAs($this->documentIdentitySigner)
        ->post("/hr/signatures/{$signature->id}/sign", [
            'signature_data' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'),
        ])
        ->assertSessionHas('error');

    expect($signature->fresh()->status)->toBe('pending')
        ->and($signature->fresh()->signature_data)->toBeNull();

    $otherSigner = documentIdentityStaff('Document Identity Co-signer', $this->documentIdentitySite);
    HrDocumentSignature::query()->create([
        'document_id' => $this->documentIdentityDocument->id,
        'signer_user_id' => $otherSigner->id,
        'status' => 'pending',
        'requested_by' => $this->documentIdentityManager->id,
        'requested_at' => now(),
    ]);
    $this->actingAs($this->documentIdentitySigner)
        ->withHeader('User-Agent', str_repeat('U', 1000))
        ->post("/hr/signatures/{$signature->id}/sign", [
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ])
        ->assertSessionHas('success');

    expect($signature->fresh()->status)->toBe('signed')
        ->and(strlen((string) $signature->fresh()->user_agent))->toBe(255);
});
