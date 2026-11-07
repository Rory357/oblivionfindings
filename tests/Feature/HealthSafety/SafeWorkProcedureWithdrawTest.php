<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Withdraw draft (decided 6 Nov 2026): a never-published procedure draft made by mistake leaves the register with a
 * required reason. Same permission as editing the draft; the row, its versions and its history stay (never deleted).
 */
class SafeWorkProcedureWithdrawTest extends TestCase
{
    use RefreshDatabase;

    private User $writer;

    private Site $site;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->writer = $this->person($this->site, ['procedures.create', 'procedures.manage', 'procedures.approve']);
        $this->actingAs($this->writer);
    }

    private function person(Site $site, array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach (['procedures.view', ...$keys] as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function service(): SafeWorkProcedureService
    {
        return app(SafeWorkProcedureService::class);
    }

    private function draft(array $data = [], ?User $author = null): SafeWorkProcedure
    {
        return $this->service()->create($author ?? $this->writer, ['title' => 'Draft made by mistake', 'reference_number' => 'SWP-WD-'.(++$this->sequence),
            'category' => 'manual_handling', 'steps' => [['description' => 'Use the agreed handling technique.', 'safety_notes' => null]],
            'applicable_roles' => ['support_worker'], 'applicable_sites' => [$this->site->id], 'related_training' => [],
            'owner_id' => $this->writer->id, 'review_date' => null, 'review_frequency_months' => null, ...$data]);
    }

    private function guards(SafeWorkProcedure $record): array
    {
        $fresh = $record->fresh();

        return ['expected_version' => $fresh->current_version, 'expected_lock_version' => $fresh->lock_version];
    }

    private function withdraw(SafeWorkProcedure $record, string $reason = 'Created twice by mistake; SWP-WD-1 is the real one.'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/health-safety/procedures/'.$record->id.'/withdraw', [...$this->guards($record), 'reason' => $reason]);
    }

    private function registerIds(?string $query = ''): array
    {
        return collect($this->get('/health-safety/procedures'.$query)->assertOk()->viewData('page')['props']['procedures']['data'])->pluck('id')->all();
    }

    public function test_withdrawn_draft_leaves_every_register_view_but_is_kept_with_its_reason_and_history(): void
    {
        $record = $this->draft();
        $kept = $this->draft(['title' => 'Real draft']);
        $versions = SafeWorkProcedureVersion::where('safe_work_procedure_id', $record->id)->count();

        $this->withdraw($record)->assertSessionHas('success');

        $record = SafeWorkProcedure::withTrashed()->findOrFail($record->id);
        $this->assertSame('withdrawn', $record->status);
        $this->assertNull($record->deleted_at);
        $this->assertSame($versions, SafeWorkProcedureVersion::where('safe_work_procedure_id', $record->id)->count());
        $audit = AuditLog::where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->where('action', 'procedure.withdrawn')->sole();
        $this->assertSame('Created twice by mistake; SWP-WD-1 is the real one.', $audit->meta['note']);
        $this->assertSame(['draft', 'withdrawn'], [$audit->meta['from'], $audit->meta['to']]);

        foreach (['', '?tab=draft', '?tab=all', '?status=draft', '?q=mistake'] as $query) {
            $this->assertNotContains($record->id, $this->registerIds($query), $query);
        }
        $this->assertContains($kept->id, $this->registerIds('?tab=draft'));
        $this->get('/health-safety/procedures')->assertInertia(fn (Assert $page) => $page->where('tabCounts.draft', 1)->where('tabCounts.all', 1));
        $csv = $this->get('/health-safety/procedures/export')->streamedContent();
        $this->assertStringNotContainsString($record->reference_number, $csv);
        $this->assertStringContainsString($kept->reference_number, $csv);

        // Still readable on its own page (people who may edit drafts), read-only, with the reason and the history entry.
        $this->get('/health-safety/procedures/'.$record->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.status', 'withdrawn')
            ->where('detail.withdrawal.reason', 'Created twice by mistake; SWP-WD-1 is the real one.')
            ->where('detail.withdrawal.by.id', $this->writer->id)
            ->where('detail.can.withdraw', false)->where('detail.can.edit', false)->where('detail.can.submit_review', false)
            ->where('detail.can.archive', false)->where('detail.form', null));
        $history = SafeWorkProcedurePresenter::detail($record, $this->writer)['history'];
        $this->assertSame('Draft withdrawn', collect($history)->firstWhere('action', 'procedure.withdrawn')['title']);
        $this->assertSame('Created twice by mistake; SWP-WD-1 is the real one.', collect($history)->firstWhere('action', 'procedure.withdrawn')['note']);
    }

    public function test_a_reason_is_required(): void
    {
        $record = $this->draft();
        foreach (['', '   '] as $reason) {
            $this->withdraw($record, $reason)->assertSessionHasErrors('reason');
        }
        $this->post('/health-safety/procedures/'.$record->id.'/withdraw', $this->guards($record))->assertSessionHasErrors('reason');
        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_only_a_never_published_draft_can_be_withdrawn(): void
    {
        $review = $this->draft();
        $this->service()->lifecycle($review, $this->writer, 'submit-for-review', $this->guards($review));
        $this->withdraw($review)->assertSessionHasErrors('status');
        $this->assertSame('under_review', $review->fresh()->status);

        // Published once, then sent back to draft: the draft is a revision of a published procedure, so it stays.
        $published = $this->draft();
        $this->service()->lifecycle($published, $this->writer, 'submit-for-review', $this->guards($published));
        $this->service()->lifecycle($published, $this->writer, 'approve', [...$this->guards($published), 'approval_note' => 'Approved.']);
        $this->service()->lifecycle($published, $this->writer, 'request-changes', [...$this->guards($published), 'note' => 'Needs a new step.']);
        $this->assertSame('draft', $published->fresh()->status);
        $this->withdraw($published)->assertSessionHasErrors('status');
        $this->assertFalse(SafeWorkProcedurePresenter::detail($published->fresh(), $this->writer, null, null, true)['can']['withdraw']);

        // Archived after publication and restored as a draft: it had an approved copy, so it can't be withdrawn either.
        $this->service()->lifecycle($published, $this->writer, 'archive', $this->guards($published));
        $this->service()->lifecycle($published, $this->writer, 'restore', $this->guards($published));
        $this->assertSame('draft', $published->fresh()->status);
        $this->withdraw($published)->assertSessionHasErrors('status');
        $this->assertSame('draft', $published->fresh()->status);

        $this->assertTrue(SafeWorkProcedurePresenter::detail($this->draft(), $this->writer)['can']['withdraw']);
    }

    public function test_it_needs_the_same_permission_and_site_as_editing_the_draft(): void
    {
        // An author who may create drafts but not manage procedures can't edit them, so can't withdraw them either.
        $author = $this->person($this->site, ['procedures.create']);
        $mine = $this->draft([], $author);
        $this->actingAs($author);
        $this->withdraw($mine)->assertForbidden();
        $this->put('/health-safety/procedures/'.$mine->id, [])->assertForbidden();
        $this->assertFalse(SafeWorkProcedurePresenter::detail($mine, $author)['can']['withdraw']);

        // A procedure manager at another Site can't reach this Site's draft.
        $elsewhere = $this->person(Site::factory()->create(['type' => 'house']), ['procedures.manage']);
        $this->actingAs($elsewhere);
        $this->withdraw($mine)->assertForbidden();

        // A reader with view only.
        $this->actingAs($this->person($this->site));
        $this->withdraw($mine)->assertForbidden();

        $this->assertSame('draft', $mine->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'procedure.withdrawn')->count());
    }

    public function test_a_withdrawn_draft_takes_no_further_change(): void
    {
        $record = $this->draft();
        $this->withdraw($record)->assertSessionHas('success');
        $record->refresh();
        foreach (['submit-for-review', 'archive', 'restore', 'withdraw'] as $command) {
            try {
                $this->service()->lifecycle($record, $this->writer, $command, [...$this->guards($record), 'reason' => 'Again']);
                $this->fail($command.' should be refused on a withdrawn draft.');
            } catch (ValidationException $refused) {
                $this->assertArrayHasKey('status', $refused->errors(), $command);
            }
        }
        $this->expectException(ValidationException::class);
        $this->service()->update($record, $this->writer, [...$record->only(SafeWorkProcedureService::CONTENT_FIELDS), 'change_summary' => 'Edit', ...$this->guards($record)]);
    }

    public function test_a_stale_page_cannot_withdraw_a_changed_draft(): void
    {
        $record = $this->draft();
        $stale = $this->guards($record);
        $this->service()->update($record, $this->writer, [...$record->fresh()->only(SafeWorkProcedureService::CONTENT_FIELDS), 'title' => 'Edited meanwhile', 'change_summary' => 'Edit', ...$this->guards($record)]);
        $this->post('/health-safety/procedures/'.$record->id.'/withdraw', [...$stale, 'reason' => 'Mistake'])->assertSessionHasErrors('expected_version');
        $this->assertSame('draft', $record->fresh()->status);
    }
}
