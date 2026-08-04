<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Storage::fake('private');

    $this->allowedSite = Site::factory()->create([
        'name' => 'Document Allowed Site',
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Document Hidden Site',
    ]);

    $this->manager = User::factory()->create([
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->firstOrFail()->id,
    ]);
    documentAccessProfile($this->manager, $this->allowedSite, 'DOC-MANAGER');

    $this->allowedStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->allowedProfile = documentAccessProfile($this->allowedStaff, $this->allowedSite, 'DOC-ALLOWED');

    $this->hiddenStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenProfile = documentAccessProfile($this->hiddenStaff, $this->hiddenSite, 'DOC-HIDDEN');

    $this->allowedDocument = documentAccessDocument($this->allowedProfile, $this->manager, 'Allowed employment agreement');
    $this->hiddenDocument = documentAccessDocument($this->hiddenProfile, $this->manager, 'Hidden employment agreement');

    $this->template = HrDocumentTemplate::query()->create([
        'name' => 'Application welcome letter',
        'category' => 'letter',
        'content' => 'Welcome {{employee_name}}',
        'is_active' => true,
    ]);
});

test('document hub rows counts signature metadata and employee pickers use canonical Site access', function () {
    HrDocumentSignature::query()->create([
        'document_id' => $this->hiddenDocument->id,
        'signer_user_id' => $this->hiddenStaff->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/documents')->assertOk();

    expect(collect($response->inertiaProps('documents'))->pluck('id')->all())
        ->toContain($this->allowedDocument->id)
        ->not->toContain($this->hiddenDocument->id)
        ->and(collect($response->inertiaProps('signatureRequests'))->pluck('document_id')->all())
        ->not->toContain($this->hiddenDocument->id)
        ->and(collect($response->inertiaProps('employees'))->pluck('id')->all())
        ->toContain($this->allowedProfile->id)
        ->not->toContain($this->hiddenProfile->id)
        ->and($response->inertiaProps('stats.on_file'))->toBe(1)
        ->and($response->inertiaProps('stats.awaiting'))->toBe(0)
        ->and(collect($response->inertiaProps('templates'))->pluck('id')->all())
        ->toContain($this->template->id);
});

test('document direct URLs and profile routes conceal records at inaccessible Sites', function () {
    $signedPath = 'hr-documents/profiles/'.$this->hiddenProfile->id.'/signed.pdf';
    Storage::disk('private')->put($signedPath, '%PDF signed');
    $this->hiddenDocument->update(['signed_document_path' => $signedPath]);

    $this->actingAs($this->manager)
        ->get("/hr/documents/{$this->hiddenDocument->id}/download")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/documents/{$this->hiddenDocument->id}/signed")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->getJson("/hr/documents/{$this->hiddenDocument->id}/audit")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->put("/hr/documents/{$this->hiddenDocument->id}", ['title' => 'Forged title'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->delete("/hr/documents/{$this->hiddenDocument->id}")
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->get("/hr/people/{$this->hiddenProfile->id}/documents")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/people/{$this->allowedProfile->id}/documents/{$this->hiddenDocument->id}/download")
        ->assertNotFound();

    expect($this->hiddenDocument->fresh()->title)->toBe('Hidden employment agreement')
        ->and($this->hiddenDocument->fresh()->folder)->not->toBe('Archive');
});

test('document bulk operations reject a mixed visible and hidden identifier set atomically', function () {
    $ids = [$this->allowedDocument->id, $this->hiddenDocument->id];

    $this->actingAs($this->manager)
        ->post('/hr/documents/move', ['ids' => $ids, 'folder' => 'Forged folder'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/documents/bulk-delete', ['ids' => $ids])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get('/hr/documents/bulk-download?'.http_build_query(['ids' => $ids]))
        ->assertNotFound();

    expect($this->allowedDocument->fresh()->folder)->toBe('Contracts')
        ->and($this->hiddenDocument->fresh()->folder)->toBe('Contracts');
});

test('new uploads generated documents and previews reject inaccessible employee profiles', function () {
    $this->actingAs($this->manager)
        ->post('/hr/documents', [
            'employee_profile_id' => $this->hiddenProfile->id,
            'title' => 'Hidden upload',
            'category' => 'contract',
            'file' => UploadedFile::fake()->create('hidden.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors(['employee_profile_id']);

    $this->actingAs($this->manager)
        ->post('/hr/documents/generate', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->hiddenProfile->id,
        ])
        ->assertSessionHasErrors(['employee_profile_id']);

    $this->actingAs($this->manager)
        ->postJson('/hr/documents/preview', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->hiddenProfile->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['employee_profile_id']);

    expect(HrDocument::query()->where('title', 'Hidden upload')->exists())->toBeFalse();
});

test('allowed historical documents remain readable while former staff are excluded from new document pickers', function () {
    $this->allowedStaff->update(['approved_at' => null]);
    $this->allowedProfile->update([
        'is_active' => false,
        'end_date' => now()->subDay()->toDateString(),
    ]);

    $index = $this->actingAs($this->manager)->get('/hr/documents')->assertOk();

    expect(collect($index->inertiaProps('documents'))->pluck('id')->all())
        ->toContain($this->allowedDocument->id)
        ->and(collect($index->inertiaProps('employees'))->pluck('id')->all())
        ->not->toContain($this->allowedProfile->id);

    $this->actingAs($this->manager)
        ->get("/hr/documents/{$this->allowedDocument->id}/download")
        ->assertOk();
});

function documentAccessProfile(User $user, Site $site, string $number): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => $number,
        'work_email' => $user->email,
        'position_title' => 'Staff member',
        'position_role' => $user->role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
    ]);
}

function documentAccessDocument(HrEmployeeProfile $profile, User $creator, string $title): HrDocument
{
    $path = 'hr-documents/profiles/'.$profile->id.'/'.str($title)->slug().'.pdf';
    Storage::disk('private')->put($path, '%PDF test');

    return HrDocument::query()->create([
        'employee_profile_id' => $profile->id,
        'title' => $title,
        'category' => 'contract',
        'folder' => 'Contracts',
        'storage_disk' => 'private',
        'storage_path' => $path,
        'original_name' => basename($path),
        'mime_type' => 'application/pdf',
        'size_bytes' => 9,
        'created_by' => $creator->id,
        'uploaded_by' => $creator->id,
    ]);
}
