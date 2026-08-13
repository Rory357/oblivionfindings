<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeguardingNestedAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private SafeguardingConcern $visibleSensitiveConcern;

    private SafeguardingConcern $hiddenConcern;

    /** @var array<string, Model> */
    private array $visibleChildren;

    /** @var array<string, Model> */
    private array $hiddenChildren;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('private');

        $visibleSite = Site::factory()->create(['name' => 'SAFE-NESTED visible Site']);
        $hiddenSite = Site::factory()->create(['name' => 'SAFE-NESTED hidden Site']);

        $this->actor = $this->siteBoundUser($visibleSite, [
            'safeguarding.update',
            'safeguarding.investigate',
            'safeguarding.report.external',
        ]);

        $this->visibleSensitiveConcern = SafeguardingConcern::factory()->create([
            'site_id' => $visibleSite->id,
            'is_sensitive' => true,
            'reported_by_user_id' => User::factory(),
            'assigned_to_user_id' => User::factory(),
            'status' => 'investigating',
        ]);
        $this->hiddenConcern = SafeguardingConcern::factory()->create([
            'site_id' => $hiddenSite->id,
            'is_sensitive' => false,
            'status' => 'investigating',
        ]);

        $this->visibleChildren = $this->childrenFor($this->visibleSensitiveConcern);
        $this->hiddenChildren = $this->childrenFor($this->hiddenConcern);

        Notification::fake();
    }

    #[DataProvider('nestedWriteOperations')]
    public function test_cross_parent_child_ids_are_not_resolved_or_mutated(string $operation): void
    {
        $before = $this->sideEffectSnapshot();

        $response = $this->dispatchWrite(
            $operation,
            $this->visibleSensitiveConcern,
            $this->hiddenChildren,
        );

        $response->assertNotFound();
        $this->assertNoSideEffects($before);
    }

    #[DataProvider('nestedWriteOperations')]
    public function test_correctly_nested_hidden_site_children_are_denied_without_side_effects(string $operation): void
    {
        $before = $this->sideEffectSnapshot();

        $response = $this->dispatchWrite($operation, $this->hiddenConcern, $this->hiddenChildren);

        $response->assertForbidden();
        $this->assertNoSideEffects($before);
    }

    #[DataProvider('nestedWriteOperations')]
    public function test_correctly_nested_sensitive_children_use_the_actual_parent_policy(string $operation): void
    {
        $before = $this->sideEffectSnapshot();

        $response = $this->dispatchWrite(
            $operation,
            $this->visibleSensitiveConcern,
            $this->visibleChildren,
        );

        $response->assertForbidden();
        $this->assertNoSideEffects($before);
    }

    public function test_attachment_download_obeys_parent_nesting_and_hidden_site_boundaries(): void
    {
        $before = $this->sideEffectSnapshot();
        $hiddenAttachment = $this->hiddenChildren['attachment'];

        $this->actingAs($this->actor)
            ->get("/safeguarding/{$this->visibleSensitiveConcern->id}/attachments/{$hiddenAttachment->id}/download")
            ->assertNotFound()
            ->assertHeaderMissing('content-disposition');

        $this->actingAs($this->actor)
            ->get("/safeguarding/{$this->hiddenConcern->id}/attachments/{$hiddenAttachment->id}/download")
            ->assertForbidden()
            ->assertHeaderMissing('content-disposition');

        $this->assertNoSideEffects($before);
    }

    public static function nestedWriteOperations(): array
    {
        return [
            'investigation update' => ['investigation_update'],
            'external report update' => ['external_report_update'],
            'action plan update' => ['action_plan_update'],
            'action plan complete' => ['action_plan_complete'],
            'attachment destroy' => ['attachment_destroy'],
        ];
    }

    /**
     * @param  array<string, Model>  $children
     */
    private function dispatchWrite(string $operation, SafeguardingConcern $concern, array $children)
    {
        $this->actingAs($this->actor);

        return match ($operation) {
            'investigation_update' => $this->put(
                "/safeguarding/{$concern->id}/investigations/{$children['investigation']->id}",
                ['status' => 'completed', 'findings' => 'Must never be persisted.'],
            ),
            'external_report_update' => $this->put(
                "/safeguarding/{$concern->id}/external-reports/{$children['external_report']->id}",
                ['acknowledgement_received' => true, 'authority_feedback' => 'Must never be persisted.'],
            ),
            'action_plan_update' => $this->put(
                "/safeguarding/{$concern->id}/action-plans/{$children['action_plan']->id}",
                ['status' => 'completed', 'completion_notes' => 'Must never be persisted.'],
            ),
            'action_plan_complete' => $this->post(
                "/safeguarding/{$concern->id}/action-plans/{$children['action_plan']->id}/complete",
                ['completion_notes' => 'Must never be persisted.'],
            ),
            'attachment_destroy' => $this->delete(
                "/safeguarding/{$concern->id}/attachments/{$children['attachment']->id}",
            ),
            default => throw new \InvalidArgumentException("Unknown nested write [{$operation}]."),
        };
    }

    /**
     * @return array<string, Model>
     */
    private function childrenFor(SafeguardingConcern $concern): array
    {
        $investigation = SafeguardingInvestigation::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $this->actor->id,
            'started_at' => now()->subDay(),
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ]);
        $externalReport = SafeguardingExternalReport::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'reported_at' => now()->subDay(),
            'reported_by_user_id' => $this->actor->id,
            'report_method' => 'phone',
            'report_summary' => 'Original safeguarding report.',
            'acknowledgement_received' => false,
            'created_by' => $this->actor->id,
        ]);
        $actionPlan = SafeguardingActionPlan::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'action_description' => 'Original protective action.',
            'action_type' => 'protective_measure',
            'assigned_to_user_id' => $this->actor->id,
            'due_date' => now()->addWeek(),
            'status' => 'pending',
            'priority' => 2,
            'created_by' => $this->actor->id,
        ]);

        $path = "safeguarding_attachments/safe-nested-{$concern->id}.txt";
        Storage::disk('private')->put($path, 'SAFE-NESTED evidence');
        $attachment = $concern->attachments()->create([
            'uploaded_by' => $this->actor->id,
            'disk' => 'private',
            'original_name' => "safe-nested-{$concern->id}.txt",
            'path' => $path,
            'mime' => 'text/plain',
            'size' => 20,
            'is_sensitive' => false,
        ]);

        return [
            'investigation' => $investigation,
            'external_report' => $externalReport,
            'action_plan' => $actionPlan,
            'attachment' => $attachment,
        ];
    }

    /** @return array<string, mixed> */
    private function sideEffectSnapshot(): array
    {
        return [
            'concerns' => $this->tableRows('safeguarding_concerns'),
            'investigations' => $this->tableRows('safeguarding_investigations'),
            'external_reports' => $this->tableRows('safeguarding_external_reports'),
            'action_plans' => $this->tableRows('safeguarding_action_plans'),
            'attachments' => $this->tableRows('safeguarding_attachments'),
            'audit_count' => DB::table('audit_logs')->count(),
            'notification_count' => DB::table('notifications')->count(),
        ];
    }

    private function assertNoSideEffects(array $before): void
    {
        $this->assertSame($before, $this->sideEffectSnapshot());
        Notification::assertNothingSent();

        foreach ([$this->visibleChildren, $this->hiddenChildren] as $children) {
            Storage::disk('private')->assertExists($children['attachment']->path);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function tableRows(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param array<int, string> $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }
}
