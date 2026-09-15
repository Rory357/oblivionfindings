<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\StrategicPlan;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Documents keep the uploaded file name and a plain format, board policies
 * are not a document type, and Records only shows each section to people who
 * can already see it.
 */
class GovernanceDocumentsAndRecordsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_a_document_keeps_its_uploaded_name_and_downloads_under_it(): void
    {
        Storage::fake('local');
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/governance/documents', [
                'title' => 'Trust deed',
                'category' => 'policy',
                'file' => UploadedFile::fake()->create('Trust deed 2025.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['category' => 'Choose what kind of document this is from the list. Board policies go in Policies.']);

        $this->actingAs($admin)
            ->post('/governance/documents', [
                'title' => 'Trust deed',
                'category' => 'constitution',
                'file' => UploadedFile::fake()->create('Trust deed 2025.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $document = GovernanceDocument::query()->sole();
        $this->assertSame('Trust deed 2025.pdf', $document->original_name);
        $this->assertNotSame('Trust deed 2025.pdf', basename($document->file_path));

        $this->actingAs($admin)
            ->get('/governance/documents')
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.file_name', 'Trust deed 2025.pdf')
                ->where('documents.data.0.format_label', 'PDF')
                ->where('documents.data.0.category_label', 'Governing document')
                ->where('categories', fn ($categories) => ! collect($categories)->contains('value', 'policy')));

        $this->actingAs($admin)
            ->get("/governance/documents/{$document->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.file_name', 'Trust deed 2025.pdf')
                ->where('document.format_label', 'PDF')
                ->missing('document.mime_type'));

        $download = $this->actingAs($admin)->get("/governance/documents/{$document->id}/download");
        $download->assertOk();
        $this->assertStringContainsString('Trust deed 2025.pdf', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_the_updated_in_the_last_30_days_block_opens_a_matching_list(): void
    {
        $admin = $this->createAdminUser();
        $make = fn (string $title) => GovernanceDocument::create([
            'title' => $title,
            'document_type' => 'template',
            'file_path' => "governance/documents/template/{$title}.docx",
            'original_name' => "{$title}.docx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'uploaded_by' => $admin->id,
            'version_number' => 1,
            'is_current' => true,
        ]);
        $make('Recent');
        $old = $make('Old');
        GovernanceDocument::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(45)]);

        $this->actingAs($admin)
            ->get('/governance/documents?updated=30d')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.updated_last_30_days', 1)
                ->where('filters.updated', '30d')
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Recent')
                ->where('documents.data.0.format_label', 'Word document'));
    }

    public function test_the_policy_document_type_moves_to_other_and_back(): void
    {
        $admin = $this->createAdminUser();
        $document = GovernanceDocument::create([
            'title' => 'Old board policy pack',
            'document_type' => 'policy',
            'file_path' => 'governance/documents/policy/pack.pdf',
            'uploaded_by' => $admin->id,
            'version_number' => 1,
            'is_current' => true,
        ]);

        $migration = require database_path('migrations/2026_09_15_620400_move_board_policy_documents_to_other_type.php');

        $migration->up();
        $this->assertSame('other', $document->fresh()->document_type);

        $migration->down();
        $this->assertSame('policy', $document->fresh()->document_type);
    }

    public function test_records_leaves_out_cancelled_meetings_and_shows_budgets_and_plans_only_to_those_who_can_see_them(): void
    {
        $admin = $this->createAdminUser();
        $outsider = $this->createUserWithRole('support_worker');

        $this->createMeeting($admin, ['title' => 'Held meeting', 'scheduled_at' => now()->subWeek(), 'status' => 'completed']);
        $this->createMeeting($admin, ['title' => 'Cancelled meeting', 'scheduled_at' => now()->subWeek(), 'status' => 'cancelled']);

        $decided = now()->subDays(3)->startOfMinute();
        $resolution = $this->createResolution($admin, [
            'title' => 'Approve the budget',
            'status' => 'closed',
            'outcome' => 'carried',
            'closed_at' => $decided,
        ]);
        $resolution->forceFill(['created_at' => now()->subMonths(2)])->save();

        Budget::create([
            'fiscal_year' => '2026',
            'title' => 'Budget 2025/26',
            'total_budget' => 250000,
            'status' => 'approved',
            'approved_by_board_at' => now()->subMonth(),
            'created_by' => $admin->id,
        ]);
        Budget::create([
            'fiscal_year' => '2027',
            'title' => 'Draft budget 2026/27',
            'total_budget' => 260000,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        StrategicPlan::create([
            'title' => 'Strategic plan 2026–2029',
            'planning_horizon' => '3_year',
            'period_start' => '2026-07-01',
            'period_end' => '2029-06-30',
            'vision_statement' => 'Vision',
            'mission_statement' => 'Mission',
            'values' => [],
            'status' => 'approved',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/governance/records')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Records/Index')
                ->where('meetings.total', 1)
                ->where('meetings.data.0.title', 'Held meeting')
                ->where('resolutions.data.0.title', 'Approve the budget')
                ->where('resolutions.data.0.decided_at', $decided->toIso8601String())
                ->where('capabilities.budgets', true)
                ->where('capabilities.plans', true)
                ->where('budgets.total', 1)
                ->where('budgets.data.0.title', 'Budget 2025/26')
                ->where('plans.total', 1)
                ->where('plans.data.0.title', 'Strategic plan 2026–2029')
                ->where('categories', fn ($categories) => ! collect($categories)->contains('value', 'policy')));

        $this->actingAs($admin)
            ->get('/governance/records?tab=budgets&search=2025')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', '2025')
                ->where('budgets.total', 1));

        // No access is widened: people outside Governance can't open Records…
        $this->actingAs($outsider)->get('/governance/records')->assertForbidden();

        // …and a member who can't see budgets or plans is never sent them.
        Role::query()->where('name', 'board_observer')->firstOrFail()->permissions()->detach(
            Permission::query()->whereIn('key', ['governance.budgets.view', 'governance.strategy.view'])->pluck('id')
        );
        $observer = $this->createUserWithRole('board_observer');

        $this->actingAs($observer)
            ->get('/governance/records')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.meetings', true)
                ->where('capabilities.budgets', false)
                ->where('capabilities.plans', false)
                ->where('budgets', null)
                ->where('plans', null));
    }
}
