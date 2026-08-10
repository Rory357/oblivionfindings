<?php

use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\SignatureReminderNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'Documents hub Site',
    ]);

    $this->manager = User::factory()->create([
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->manager->id,
        'employee_number' => 'EMP-MANAGER-'.$this->manager->id,
        'work_email' => $this->manager->email,
        'position_title' => 'Manager',
        'position_role' => 'provider_manager',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $this->template = HrDocumentTemplate::query()->create([
        'name' => 'Welcome Letter',
        'category' => 'letter',
        'content' => 'Dear {{employee_name}}, welcome aboard.',
        'is_active' => true,
    ]);
});

function makeDocument(int $profileId): HrDocument
{
    Storage::disk('private')->put("hr-documents/profiles/{$profileId}/contract.pdf", '%PDF-1.4 fake');
    $createdBy = User::query()->value('id');

    return HrDocument::query()->create([
        'employee_profile_id' => $profileId,
        'title' => 'Employment Agreement',
        'category' => 'contract',
        'folder' => 'Contracts',
        'storage_disk' => 'private',
        'storage_path' => "hr-documents/profiles/{$profileId}/contract.pdf",
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
        'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'pending', 'requested_by' => $this->manager->id, 'requested_at' => now(),
    ]);

    $this->actingAs($this->manager)->post("/hr/signatures/{$pending->id}/nudge")->assertSessionHas('success');
    expect($pending->fresh()->reminder_sent_at)->not->toBeNull();
    $pending->update(['status' => 'cancelled']);

    $declined = HrDocumentSignature::query()->create([
        'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'declined', 'declined_reason' => 'No', 'requested_by' => $this->manager->id, 'requested_at' => now(),
    ]);
    $this->actingAs($this->manager)->post("/hr/signatures/{$declined->id}/resend")->assertSessionHas('success');
    expect($declined->fresh()->status)->toBe('pending');

    $this->actingAs($this->manager)->post("/hr/signatures/document/{$document->id}/cancel")->assertSessionHas('success');
    expect($document->signatures()->where('status', 'pending')->count())->toBe(0);
    expect($document->signatures()->where('status', 'cancelled')->count())->toBe(2)
        ->and($document->signatures()->count())->toBe(2);

    $audit = $this->actingAs($this->manager)->getJson("/hr/documents/{$document->id}/audit")->assertOk();
    expect(collect($audit->json('entries'))->pluck('label'))->toContain('Signature request cancelled');
});

test('signed requests cannot be reopened and non-pending requests cannot be nudged', function () {
    $document = makeDocument($this->profile->id);
    $signed = HrDocumentSignature::query()->create([
        'document_id' => $document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'signed',
        'signature_data' => 'immutable-signature',
        'signed_at' => now()->subDay(),
        'requested_by' => $this->manager->id,
        'requested_at' => now()->subDays(2),
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/signatures/{$signed->id}/resend")
        ->assertSessionHas('error');
    $this->actingAs($this->manager)
        ->post("/hr/signatures/{$signed->id}/nudge")
        ->assertSessionHas('error');

    expect($signed->fresh()->status)->toBe('signed')
        ->and($signed->fresh()->signature_data)->toBe('immutable-signature')
        ->and($signed->fresh()->reminder_sent_at)->toBeNull();
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

test('restricted document metadata and files are concealed from view-only users', function () {
    Storage::fake('private');

    $document = makeDocument($this->profile->id);
    $signedPath = "hr-documents/1/{$this->profile->id}/contract-signed.pdf";
    Storage::disk('private')->put($signedPath, '%PDF-1.4 signed');
    $document->update([
        'title' => 'Restricted clinical contract',
        'is_restricted' => true,
        'signed_document_path' => $signedPath,
    ]);

    $auditorRole = Role::query()->where('name', 'auditor')->firstOrFail();
    $auditorRole->permissions()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.documents.view')->firstOrFail()->id,
    ]);
    $viewer = User::factory()->create([
        'role' => 'auditor',
        'approved_at' => now(),
    ]);
    $viewer->roles()->syncWithoutDetaching([$auditorRole->id]);
    HrEmployeeProfile::query()->create([
        'user_id' => $viewer->id,
        'employee_number' => 'EMP-AUDITOR-'.$viewer->id,
        'work_email' => $viewer->email,
        'position_title' => 'Auditor',
        'position_role' => 'auditor',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $index = $this->actingAs($viewer)->get('/hr/documents')->assertOk();
    expect(collect($index->inertiaProps('documents'))->pluck('id'))->not->toContain($document->id);
    expect(collect($index->inertiaProps('recent'))->pluck('id'))->not->toContain($document->id);

    $profileDocuments = $this->actingAs($viewer)
        ->get(route('hr.people.documents', $this->profile))
        ->assertOk();
    expect(collect($profileDocuments->inertiaProps('documents'))->pluck('id'))->not->toContain($document->id);

    $viewerExport = $this->actingAs($viewer)
        ->get('/hr/documents/export')
        ->assertOk();
    expect($viewerExport->streamedContent())->not->toContain('Restricted clinical contract');

    $this->actingAs($viewer)->get("/hr/documents/{$document->id}/download")->assertNotFound();
    $this->actingAs($viewer)->get("/hr/documents/{$document->id}/signed")->assertNotFound();
    $this->actingAs($viewer)->getJson("/hr/documents/{$document->id}/audit")->assertNotFound();

    $this->actingAs($this->manager)->get("/hr/documents/{$document->id}/download")->assertOk();
    $this->actingAs($this->manager)->get("/hr/documents/{$document->id}/signed")->assertOk();
    $this->actingAs($this->manager)->getJson("/hr/documents/{$document->id}/audit")->assertOk();
    $managerExport = $this->actingAs($this->manager)
        ->get('/hr/documents/export')
        ->assertOk();
    expect($managerExport->streamedContent())->toContain('Restricted clinical contract');
});

test('view-only document access does not expose signature identities progress or audit data', function () {
    $document = makeDocument($this->profile->id);
    HrDocumentSignature::query()->create([
        'document_id' => $document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $viewerRole = Role::query()->create([
        'name' => 'documents_view_only',
        'label' => 'Documents View Only',
        'type' => 'custom',
        'level' => 10,
    ]);
    $viewerRole->permissions()->sync([
        Permission::query()->where('key', 'hr.documents.view')->firstOrFail()->id,
    ]);
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $viewer->roles()->sync([$viewerRole->id]);
    HrEmployeeProfile::query()->create([
        'user_id' => $viewer->id,
        'employee_number' => 'EMP-DOC-VIEWER-'.$viewer->id,
        'work_email' => $viewer->email,
        'position_title' => 'Document Viewer',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $response = $this->actingAs($viewer)->get('/hr/documents')->assertOk();
    $visibleDocument = collect($response->inertiaProps('documents'))->firstWhere('id', $document->id);

    expect($visibleDocument)->not->toBeNull()
        ->and($visibleDocument['signature'])->toBeNull()
        ->and($response->inertiaProps('signatureRequests'))->toBe([])
        ->and($response->inertiaProps('signatureCompletion'))->toBe([
            'signed' => 0,
            'total' => 0,
            'requests' => 0,
        ])
        ->and($response->inertiaProps('can.signatures_manage'))->toBeFalse();

    $this->actingAs($viewer)
        ->getJson("/hr/documents/{$document->id}/audit")
        ->assertForbidden();
});

test('the audit endpoint merges document changes with signature events', function () {
    $document = makeDocument($this->profile->id);
    $document->update(['title' => 'Employment Agreement (v2)']); // audit: update

    HrDocumentSignature::query()->create([
        'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'signed', 'signed_at' => now(), 'ip_address' => '10.0.0.1',
        'requested_by' => $this->manager->id, 'requested_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($this->manager)->getJson("/hr/documents/{$document->id}/audit");
    $response->assertOk();

    $labels = collect($response->json('entries'))->pluck('label')->implode(' | ');
    expect($labels)->toContain('Sent for signature');
    expect($labels)->toContain('Signed');
});

test('the signature-due reminder sweep notifies and stamps once', function () {
    $document = makeDocument($this->profile->id);

    $sig = HrDocumentSignature::query()->create([
        'document_id' => $document->id, 'signer_user_id' => $this->worker->id,
        'status' => 'pending', 'requested_by' => $this->manager->id, 'requested_at' => now(),
        'due_at' => now()->addDay()->toDateString(), // inside the 2-day window
    ]);

    Notification::fake();
    (new SendExpiryRemindersJob)->handle();

    Notification::assertSentTo(
        $this->worker,
        SignatureReminderNotification::class,
    );
    expect($sig->fresh()->reminder_sent_at)->not->toBeNull();
});

test('bulk operations move and archive documents', function () {
    $a = makeDocument($this->profile->id);
    $b = makeDocument($this->profile->id);

    $this->actingAs($this->manager)
        ->post('/hr/documents/move', ['ids' => [$a->id, $b->id], 'folder' => 'Archive'])
        ->assertSessionHas('success');
    expect($a->fresh()->folder)->toBe('Archive');

    $this->actingAs($this->manager)
        ->post('/hr/documents/bulk-delete', ['ids' => [$a->id, $b->id]])
        ->assertSessionHas('success');
    expect(HrDocument::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(2);
    expect($a->fresh()->folder)->toBe('Archive');
    expect($b->fresh()->folder)->toBe('Archive');
});
