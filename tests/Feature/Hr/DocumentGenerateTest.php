<?php

use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'Document generation Site',
    ]);

    // hr.documents.manage is granted to provider_manager via RbacSeeder.
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
        'content' => 'Dear {{employee.name}}, welcome aboard.',
        'is_active' => true,
    ]);
});

test('a manager can generate a document from a template', function () {
    Storage::fake('private');

    $this->actingAs($this->manager)
        ->post('/hr/documents/generate', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->profile->id,
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('hr_documents', [
        'template_id' => $this->template->id,
        'employee_profile_id' => $this->profile->id,
        'generated_from_template' => true,
    ]);
});

test('the documents index ships active templates for the generate picker', function () {
    $response = $this->actingAs($this->manager)->get('/hr/documents');
    $response->assertOk();

    $templateIds = collect($response->inertiaProps('templates'))->pluck('id')->all();
    expect($templateIds)->toContain($this->template->id);
});

test('a user without hr.documents.manage cannot generate a document', function () {
    Storage::fake('private');

    $this->actingAs($this->worker)
        ->post('/hr/documents/generate', [
            'template_id' => $this->template->id,
            'employee_profile_id' => $this->profile->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('hr_documents', [
        'template_id' => $this->template->id,
    ]);
});
