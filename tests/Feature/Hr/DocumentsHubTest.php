<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->template = HrDocumentTemplate::query()->create([
        'tenant_id' => 1,
        'name' => 'Welcome Letter',
        'category' => 'letter',
        'content' => 'Dear {{employee_name}}, welcome aboard.',
        'is_active' => true,
    ]);
});

function makeDocument(int $profileId): HrDocument
{
    Storage::disk('private')->put("hr-documents/1/{$profileId}/contract.pdf", '%PDF-1.4 fake');
    $createdBy = User::query()->value('id');

    return HrDocument::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profileId,
        'title' => 'Employment Agreement',
        'category' => 'contract',
        'folder' => 'Contracts',
        'storage_disk' => 'private',
        'storage_path' => "hr-documents/1/{$profileId}/contract.pdf",
        'original_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'created_by' => $createdBy,
        'uploaded_by' => $createdBy,
    ]);
}

test('the documents hub ships every tab payload', function () {
    makeDocument($this->profile->id);

    $response = $this->actingAs($this->manager)->get('/hr/documents');
    $response->assertOk();

    expect($response->inertiaProps('documents'))->toHaveCount(1);
    expect($response->inertiaProps('stats.on_file'))->toBe(1);
    expect($response->inertiaProps('templates'))->not->toBeEmpty();
    expect($response->inertiaProps('can.manage'))->toBeTrue();
    // structural keys present
    expect($response->inertiaProps('signatureRequests'))->toBeArray();
    expect($response->inertiaProps('policies'))->toBeArray();
    expect($response->inertiaProps('signatureCompletion'))->toHaveKey('total');
});

test('generating a document produces a PDF', function () {
    Storage::fake('private');

    $this->actingAs($this->manager)
        ->post('/hr/documents/generate', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->profile->id,
        ])
        ->assertSessionHas('success');

    $doc = HrDocument::query()->where('generated_from_template', true)->first();
    expect($doc)->not->toBeNull();
    expect($doc->mime_type)->toBe('application/pdf');
    expect(Storage::disk('private')->exists($doc->storage_path))->toBeTrue();
});

test('approval-required templates cannot be generated', function () {
    $this->template->update(['approval_required' => true]);

    $this->actingAs($this->manager)
        ->post('/hr/documents/generate', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->profile->id,
        ])
        ->assertSessionHas('error');

    expect(HrDocument::query()->where('generated_from_template', true)->count())->toBe(0);
});

test('completing all signatures finalises a signed PDF', function () {
    $document = makeDocument($this->profile->id);

    $signature = HrDocumentSignature::query()->create([
        'tenant_id' => 1,
        'document_id' => $document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/hr/signatures/{$signature->id}/sign", [
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ])
        ->assertSessionHas('success');

    $document->refresh();
    expect($document->signed_by_employee)->toBeTrue();
    expect($document->signed_document_path)->not->toBeNull();
    expect(Storage::disk('private')->exists($document->signed_document_path))->toBeTrue();
});

test('a manager can nudge, resend and cancel signature requests', function () {
    $document = makeDocument($this->profile->id);

    $pending = HrDocumentSignature::query()->create([
        'tenant_id' => 1, 'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'pending', 'requested_by' => $this->manager->id, 'requested_at' => now(),
    ]);

    $this->actingAs($this->manager)->post("/hr/signatures/{$pending->id}/nudge")->assertSessionHas('success');
    expect($pending->fresh()->reminder_sent_at)->not->toBeNull();

    $declined = HrDocumentSignature::query()->create([
        'tenant_id' => 1, 'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'declined', 'declined_reason' => 'No', 'requested_by' => $this->manager->id, 'requested_at' => now(),
    ]);
    $this->actingAs($this->manager)->post("/hr/signatures/{$declined->id}/resend")->assertSessionHas('success');
    expect($declined->fresh()->status)->toBe('pending');

    $this->actingAs($this->manager)->post("/hr/signatures/document/{$document->id}/cancel")->assertSessionHas('success');
    expect($document->signatures()->where('status', 'pending')->count())->toBe(0);
});

test('restricted documents require manage to download', function () {
    $document = makeDocument($this->profile->id);
    $document->update(['is_restricted' => true]);

    // worker has neither view nor manage → forbidden
    $this->actingAs($this->worker)
        ->get("/hr/documents/{$document->id}/download")
        ->assertForbidden();

    // manager (has manage) → allowed
    $this->actingAs($this->manager)
        ->get("/hr/documents/{$document->id}/download")
        ->assertOk();
});

test('bulk operations move and delete documents', function () {
    $a = makeDocument($this->profile->id);
    $b = makeDocument($this->profile->id);

    $this->actingAs($this->manager)
        ->post('/hr/documents/move', ['ids' => [$a->id, $b->id], 'folder' => 'Archive'])
        ->assertSessionHas('success');
    expect($a->fresh()->folder)->toBe('Archive');

    $this->actingAs($this->manager)
        ->post('/hr/documents/bulk-delete', ['ids' => [$a->id, $b->id]])
        ->assertSessionHas('success');
    expect(HrDocument::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(0);
});
