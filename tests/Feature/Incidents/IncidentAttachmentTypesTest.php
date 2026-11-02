<?php

namespace Tests\Feature\Incidents;

use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Incident attachments take the same file types as a safety review's files (HsEventFile::MIMES): photos, videos, PDFs
 * and Word files. Anything else is refused in plain words and nothing is stored. The 10 MB limit is unchanged.
 */
class IncidentAttachmentTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClientIncident $incident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->incident = ClientIncident::factory()->create(['status' => 'draft', 'reported_by' => $this->admin->id]);
    }

    public function test_photos_videos_pdfs_and_word_files_are_accepted(): void
    {
        foreach ([['photo.jpg', 'image/jpeg'], ['clip.mp4', 'video/mp4'], ['report.pdf', 'application/pdf'],
            ['notes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']] as [$name, $mime]) {
            $this->actingAs($this->admin)
                ->post("/incidents/{$this->incident->id}/attachments", ['file' => UploadedFile::fake()->create($name, 200, $mime)])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, $this->incident->attachments()->count());
    }

    public function test_other_file_types_are_refused_with_a_friendly_message_and_nothing_is_stored(): void
    {
        foreach ([['run.exe', 'application/x-msdownload'], ['page.html', 'text/html'], ['notes.txt', 'text/plain']] as [$name, $mime]) {
            $this->actingAs($this->admin)
                ->post("/incidents/{$this->incident->id}/attachments", ['file' => UploadedFile::fake()->create($name, 10, $mime)])
                ->assertSessionHasErrors(['file' => 'Add photos, videos, PDFs or Word files.']);
        }

        $this->assertSame(0, $this->incident->attachments()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
