<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\SignatureOutcomeNotification;
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
        'name' => 'E-signature Site',
    ]);

    // hr.documents.manage (request perm accepts it) is granted to provider_manager.
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

    $this->document = HrDocument::query()->create([
        'employee_profile_id' => $this->profile->id,
        'title' => 'Code of Conduct',
        'category' => 'policy',
        'storage_disk' => 'local',
        'storage_path' => 'hr/documents/code.pdf',
        'original_name' => 'code-of-conduct.pdf',
        'created_by' => $this->manager->id,
    ]);
});

test('a manager can send a document for signature', function () {
    $this->actingAs($this->manager)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$this->worker->id],
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('hr_document_signatures', [
        'document_id' => $this->document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
    ]);
});

test('the documents index ships employees with user_id for the signer picker', function () {
    $response = $this->actingAs($this->manager)->get('/hr/documents');
    $response->assertOk();

    $employees = collect($response->inertiaProps('employees'));
    expect($employees->pluck('user_id')->all())->toContain($this->worker->id);
});

test('a user without signature/document manage cannot send for signature', function () {
    $this->actingAs($this->worker)
        ->post('/hr/signatures/request', [
            'document_id' => $this->document->id,
            'user_ids' => [$this->worker->id],
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('hr_document_signatures', [
        'document_id' => $this->document->id,
    ]);
});

test('the signer can download the document they were asked to sign', function () {
    Storage::fake('local');
    Storage::disk('local')->put('hr/documents/code.pdf', 'PDF BYTES');

    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->get("/hr/signatures/{$signature->id}/document")
        ->assertOk();

    // A different (non-signer) user cannot download it.
    $other = User::factory()->create(['approved_at' => now()]);
    $this->actingAs($other)
        ->get("/hr/signatures/{$signature->id}/document")
        ->assertNotFound();
});

test('signing notifies the accessible current requester exactly once', function () {
    Notification::fake();

    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);
    $otherSigner = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $otherSigner->id,
        'employee_number' => 'EMP-COSIGNER-'.$otherSigner->id,
        'work_email' => $otherSigner->email,
        'position_title' => 'Co-signer',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);
    HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $otherSigner->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/hr/signatures/{$signature->id}/sign", [
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ])
        ->assertSessionHas('success');

    Notification::assertSentToTimes($this->manager, SignatureOutcomeNotification::class, 1);
});

test('declining notifies the accessible current requester once and a repeat transition notifies nobody again', function () {
    Notification::fake();

    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/hr/signatures/{$signature->id}/decline", ['reason' => 'Needs a correction'])
        ->assertSessionHas('success');
    $this->actingAs($this->worker)
        ->post("/hr/signatures/{$signature->id}/decline", ['reason' => 'Again'])
        ->assertSessionHas('error');

    Notification::assertSentToTimes($this->manager, SignatureOutcomeNotification::class, 1);
});

test('a self requested signature outcome does not send a redundant notification', function () {
    Notification::fake();

    $signature = HrDocumentSignature::query()->create([
        'document_id' => $this->document->id,
        'signer_user_id' => $this->worker->id,
        'status' => 'pending',
        'requested_by' => $this->worker->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/hr/signatures/{$signature->id}/decline", ['reason' => 'No longer needed'])
        ->assertSessionHas('success');

    Notification::assertNothingSent();
});
