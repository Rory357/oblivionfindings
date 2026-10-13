<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\RosterTemplateCommand;
use App\Services\Operations\RosterTemplateIntent;
use App\Services\Operations\RosterTemplateReceipt;
use App\Services\Operations\RosterTemplateSource;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** One organisation: current exact grants, approved Sites and whole canonical patterns. */
class WorkforceTemplateLibraryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    private User $actor;

    private User $worker;

    private RosterTemplate $template;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08T06:10:11.123456Z'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
        $this->context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true]);
        $this->actor = $this->person(['roster_templates.viewAny', 'roster_templates.create', 'roster_templates.update', 'roster_templates.delete']);
        $this->worker = $this->person();
        $this->template = $this->pattern();
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('template_library_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public function test_actual_create_update_copy_delete_preserves_complete_patterns_and_confirms_only_saved_results(): void
    {
        $values = $this->values();
        $values['name'] = 'New synthetic pattern';
        $values['template_shifts'][] = $this->rowValues(['day_of_week' => 6, 'start_time' => '22:17', 'end_time' => '07:43',
            'user_id' => null, 'service_context_id' => null, 'shift_type' => 'sleepover', 'is_sleepover' => true,
            'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => 0, 'required_skills' => [], 'location' => null, 'notes' => null]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($values): void {
            $before = $this->state();
            $queue = $this->queueState();
            $body = $this->body('create', $values);
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors()
                ->assertRedirect(route('operations.rostering.templates.index', ['week' => '2026-10-12']));
            $receipt = $this->assertReceipt('create', $body, 'saved', true);
            $created = RosterTemplate::findOrFail($receipt['template_id']);
            $this->assertPattern($values, $created);
            $this->assertSame($this->actor->id, (int) $created->created_by);
            $this->assertSame($before['roster_templates'], $this->raw('roster_templates', exceptIds: [$created->id]));
            $this->assertSame($before['roster_template_shifts'], $this->raw('roster_template_shifts', exceptTemplateIds: [$created->id]));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());

            $values['name'] = 'Updated synthetic pattern';
            $values['description'] = null;
            $values['template_type'] = 'monthly';
            $values['is_active'] = false;
            $values['template_shifts'][0]['notes'] = null;
            $values['template_shifts'][0]['is_lone_worker'] = false;
            $body = $this->body('update', $values, $created);
            $this->send('update', $body, $created)->assertStatus(303)->assertSessionHasNoErrors();
            $this->assertReceipt('update', $body, 'saved', true, $created);
            $this->assertPattern($values, $created->fresh());
            $this->assertSame($this->actor->id, (int) $created->fresh()->created_by);

            $source = $this->rawPattern($created);
            $body = $this->body('duplicate', [], $created);
            $this->send('duplicate', $body, $created)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('duplicate', $body, 'copied', true, $created);
            $copy = RosterTemplate::findOrFail($receipt['copy_id']);
            $copyValues = [...$values, 'name' => 'Updated synthetic pattern (copy)'];
            $this->assertPattern($copyValues, $copy);
            $this->assertSame($source, $this->rawPattern($created));

            $children = $this->raw('roster_template_shifts');
            $body = $this->body('delete', [], $copy);
            $this->send('delete', $body, $copy)->assertStatus(303)->assertSessionHasNoErrors();
            $this->assertReceipt('delete', $body, 'deleted', true, $copy);
            $this->assertNull(RosterTemplate::find($copy->id));
            $this->assertNotNull(RosterTemplate::withTrashed()->findOrFail($copy->id)->deleted_at);
            $this->assertSame($children, $this->raw('roster_template_shifts'));
            $this->assertSame($source, $this->rawPattern($created));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_unicode_trim_falsey_rows_and_forced_flags_have_the_exact_persisted_hash(): void
    {
        $values = $this->values();
        $values['name'] = "\u{0085}\u{00a0}Synthetic Unicode\u{2003}";
        $values['description'] = '0';
        $values['template_shifts'][0] = $this->rowValues(['shift_type' => 'on_call', 'is_on_call' => false,
            'expected_break_minutes' => null, 'required_skills' => [' Skill ', 'Skill', '0'], 'location' => '0', 'notes' => "\u{00a0}"]);
        $expected = [...$values, 'name' => 'Synthetic Unicode'];
        $expected['template_shifts'][0] = $this->rowValues(['shift_type' => 'on_call', 'is_on_call' => true,
            'expected_break_minutes' => null, 'required_skills' => ['Skill', 'Skill'], 'location' => null, 'notes' => null]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($values, $expected): void {
            $before = $this->state();
            $queue = $this->queueState();
            $body = $this->body('create', $values);
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('create', $body, 'saved', true, normalized: $expected);
            $this->assertPattern($expected, RosterTemplate::findOrFail($receipt['template_id']));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_omitted_create_defaults_and_null_update_defaults_preserve_header_and_row_distinctions(): void
    {
        $values = ['name' => 'Minimal synthetic', 'template_shifts' => [['client_id' => $this->client->id,
            'day_of_week' => 1, 'start_time' => '09:17', 'end_time' => '11:43']]];
        $expected = ['name' => 'Minimal synthetic', 'description' => null, 'template_type' => 'weekly', 'is_active' => true,
            'template_shifts' => [$this->rowValues(['user_id' => null, 'service_context_id' => null, 'shift_type' => 'standard',
                'is_sleepover' => false, 'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => null,
                'required_skills' => [], 'location' => null, 'notes' => null])]];
        $this->commitFixtures();
        $this->withProductionManager(function () use ($values, $expected): void {
            $body = $this->body('create', $values);
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('create', $body, 'saved', true, normalized: $expected);
            $created = RosterTemplate::findOrFail($receipt['template_id']);
            $this->assertPattern($expected, $created);
            $created->update(['template_type' => 'monthly', 'is_active' => false]);
            $expected['template_type'] = 'monthly';
            $expected['is_active'] = false;
            $values['template_type'] = null;
            $values['is_active'] = null;
            $before = $this->state();
            $queue = $this->queueState();
            $body = $this->body('update', $values, $created);
            $this->send('update', $body, $created)->assertStatus(303)->assertSessionHasNoErrors();
            $this->assertReceipt('update', $body, 'unchanged', false, $created, $expected);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function copyNames(): array
    {
        return ['maximum ASCII' => [str_repeat('A', 255), str_repeat('A', 248).' (copy)'],
            'maximum Unicode' => [str_repeat('界', 255), str_repeat('界', 248).' (copy)'],
            'existing suffix' => ['Synthetic (copy 19)', 'Synthetic (copy)']];
    }

    #[DataProvider('copyNames')]
    public function test_duplicate_keeps_complete_pattern_and_native_safe_unicode_name(string $name, string $expected): void
    {
        $this->template->update(['name' => $name, 'is_active' => false]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($expected): void {
            $before = $this->state();
            $source = $this->rawPattern($this->template);
            $queue = $this->queueState();
            $body = $this->body('duplicate');
            $this->send('duplicate', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('duplicate', $body, 'copied', true);
            $copy = RosterTemplate::findOrFail($receipt['copy_id']);
            $this->assertSame($expected, $copy->name);
            $this->assertLessThanOrEqual(255, mb_strlen($copy->name));
            $this->assertSame($this->patternValues($this->template), [...$this->patternValues($copy), 'name' => $this->template->name]);
            $this->assertSame($source, $this->rawPattern($this->template));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_multi_site_worker_global_context_and_inactive_client_preserve_installed_policy(): void
    {
        $second = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor->hrEmployeeProfile->update(['secondary_site_ids' => [$second->id]]);
        $this->worker->hrEmployeeProfile->update(['primary_site_id' => $second->id]);
        $global = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true]);
        $this->client->update(['status' => 'inactive']);
        $values = $this->values();
        $values['template_shifts'][0]['service_context_id'] = $global->id;
        $this->commitFixtures();
        $this->withProductionManager(function () use ($values): void {
            $before = $this->state();
            $queue = $this->queueState();
            $body = $this->body('create', $values);
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('create', $body, 'saved', true);
            $this->assertPattern($values, RosterTemplate::findOrFail($receipt['template_id']));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function nestedActions(): array
    {
        $rows = [];
        foreach (['create', 'update', 'duplicate', 'delete'] as $action) {
            foreach ([true, false] as $commit) {
                $rows[$action.' '.($commit ? 'commit' : 'rollback')] = [$action, $commit];
            }
        }

        return $rows;
    }

    #[DataProvider('nestedActions')]
    public function test_nested_commands_never_confirm_before_true_root_and_rollback_preserves_aggregate(string $action, bool $commit): void
    {
        $values = $this->values();
        $values['name'] = 'Nested changed pattern';
        $body = $this->body($action, $values);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $body, $commit): void {
            $before = $this->state();
            $queue = $this->queueState();
            DB::beginTransaction();
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->send($action, $body)->assertStatus(303)->assertSessionHasNoErrors();
            $this->assertNull(session('roster_template_result'));
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queueState());
            if ($commit) {
                DB::commit();
                $this->assertNotSame($before['roster_templates'], $this->raw('roster_templates'));
                $this->assertSuffixUnchanged($before);
                $this->assertNull(session('roster_template_result'));
            } else {
                DB::rollBack();
                $this->assertSame($before, $this->state());
            }
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_noop_preserves_every_physical_row_and_reports_prior_revision_without_replacement(): void
    {
        $this->template->templateShifts()->firstOrFail()->update(['day_of_week' => 4]);
        $this->template->templateShifts()->createMany([$this->rowValues(['day_of_week' => 0]), $this->rowValues(['day_of_week' => 0])]);
        $this->assertSame([4, 0, 0], $this->template->templateShifts()->orderBy('id')->pluck('day_of_week')->all());
        $response = $this->get(route('operations.rostering.templates.index'))->assertOk();
        $display = collect($response->inertiaProps('rosterTemplates'))->firstWhere('id', $this->template->id);
        $this->assertIsArray($display);
        $this->assertSame([0, 0, 4], array_column($display['template_shifts'], 'day_of_week'));
        $fields = array_flip(array_keys($this->rowValues()));
        $rows = array_map(fn (array $row) => array_intersect_key($row, $fields), $display['template_shifts']);
        $this->assertSame($rows[0], $rows[1]);
        $values = array_intersect_key($display, array_flip(['name', 'description', 'template_type', 'is_active']));
        $body = $this->body('update', [...$values, 'template_shifts' => $rows]);
        $body['expected_source'] = ['template_id' => $display['id'], 'source_revision' => $display['source_revision']];
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->send('update', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('update', $body, 'unchanged', false);
            $this->assertSame($body['expected_source']['source_revision'], $receipt['result_revision']);
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function existingActions(): array
    {
        return ['update' => ['update'], 'duplicate' => ['duplicate'], 'delete' => ['delete']];
    }

    #[DataProvider('existingActions')]
    public function test_existing_foreign_row_cannot_be_laundered_by_allowed_new_rows_or_direct_copy_delete(string $action): void
    {
        $outside = Site::factory()->create();
        $foreign = Client::factory()->create(['site_id' => $outside->id]);
        $this->template->templateShifts()->firstOrFail()->update(['client_id' => $foreign->id, 'service_context_id' => null]);
        $body = $this->body($action, $this->values());
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $body): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->send($action, $body)->assertForbidden();
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function revisionChanges(): array
    {
        return ['header' => ['header'], 'row retiming' => ['retiming'], 'same-value replacement identity' => ['identity']];
    }

    #[DataProvider('revisionChanges')]
    public function test_displayed_whole_pattern_revision_rejects_committed_changed_source_before_update(string $kind): void
    {
        $body = $this->body('update', $this->values());
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body, $kind): void {
            DB::beginTransaction();
            $old = $this->rawPattern($this->template);
            $writer = $this->writer();
            $writer->transaction(function () use ($writer, $kind): void {
                if ($kind === 'header') {
                    $writer->table('roster_templates')->where('id', $this->template->id)->update(['description' => 'Independently changed']);
                } elseif ($kind === 'retiming') {
                    $writer->table('roster_template_shifts')->where('roster_template_id', $this->template->id)->update(['end_time' => '11:47:00']);
                } else {
                    $row = (array) $writer->table('roster_template_shifts')->where('roster_template_id', $this->template->id)->first();
                    unset($row['id']);
                    $writer->table('roster_template_shifts')->where('roster_template_id', $this->template->id)->delete();
                    $writer->table('roster_template_shifts')->insert($row);
                }
            });
            $this->assertSame($old, $this->rawPattern($this->template));
            $this->assertNotSame($old, $this->rawPattern($this->template, $writer));
            $current = $this->state($writer);
            $queue = $this->queueState();
            $this->send('update', $body)->assertStatus(409);
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
            DB::rollBack();
            $this->assertSame($current, $this->state());
        });
    }

    public static function authorityChanges(): array
    {
        return ['action override deny' => ['permission'], 'approval revoked' => ['approval'], 'external actor' => ['external'],
            'current actor profile ended' => ['actor_profile'], 'Site archived' => ['site'], 'Client moved outside' => ['client'],
            'worker approval revoked' => ['worker'], 'worker profile ended' => ['worker_profile'], 'Context inactive' => ['context']];
    }

    #[DataProvider('authorityChanges')]
    public function test_independent_committed_authority_and_references_override_primed_rr_and_same_actor_caches(string $kind): void
    {
        $permission = Permission::where('key', 'roster_templates.update')->firstOrFail();
        $this->actor->roles()->firstOrFail()->permissions()->attach($permission);
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($kind, $permission, $outside): void {
            DB::beginTransaction();
            $this->actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            $this->assertTrue($this->actor->canDo('roster_templates.update'));
            $this->assertContains($this->site->id, app(UserSiteAccessService::class)->accessibleSiteIds($this->actor, ['shifts.manageAny']));
            $old = $this->rawPattern($this->template);
            $oldEvidence = $this->authorityState();
            $writer = $this->writer();
            $writer->transaction(function () use ($kind, $permission, $outside, $writer): void {
                match ($kind) {
                    'permission' => $writer->table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]),
                    'approval' => $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                    'external' => $writer->table('users')->where('id', $this->actor->id)->update(['external_clinical_account' => true]),
                    'actor_profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['end_date' => '2026-10-07']),
                    'site' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
                    'client' => $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $outside->id]),
                    'worker' => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
                    'worker_profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['end_date' => '2026-10-07']),
                    'context' => $writer->table('service_contexts')->where('id', $this->context->id)->update(['is_active' => false]),
                };
            });
            $this->assertSame($oldEvidence, $this->authorityState());
            $currentEvidence = $this->authorityState($writer);
            $this->assertNotSame($oldEvidence, $currentEvidence);
            $this->assertTrue($this->actor->canDo('roster_templates.update'));
            $this->assertSame($old, $this->rawPattern($this->template));
            $before = $this->state($writer);
            $queue = $this->queueState();
            try {
                app(RosterTemplateCommand::class)->execute($this->actor, 'update', $this->values(['name' => 'Must not save']), $this->template->id);
                $this->fail('Current authority must deny the actual command after independent committed evidence changed.');
            } catch (ValidationException $exception) {
                $this->assertSame('context', $kind);
                $this->assertArrayHasKey('template_shifts', $exception->errors());
            } catch (HttpException $exception) {
                $this->assertNotSame('context', $kind);
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $this->state($writer));
            $this->assertSame($currentEvidence, $this->authorityState($writer));
            $this->assertSame($queue, $this->queueState());
            DB::rollBack();
            $this->assertSame($before, $this->state());
            $this->assertSame($currentEvidence, $this->authorityState());
        });
    }

    public static function compatibilityActions(): array
    {
        return ['create' => ['create', 'rostering.create'], 'update' => ['update', 'rostering.edit'],
            'duplicate' => ['duplicate', 'rostering.create'], 'delete' => ['delete', 'rostering.delete']];
    }

    #[DataProvider('compatibilityActions')]
    public function test_compatibility_command_grant_remains_exact_without_broadening_canonical_route(string $action, string $key): void
    {
        $actor = $this->person([$key, 'roster_templates.viewAny']);
        $this->actingAs($actor);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $actor): void {
            $before = $this->state();
            $queue = $this->queueState();
            $result = app(RosterTemplateCommand::class)->execute($actor, $action, $this->values(['name' => 'Compatibility saved']),
                $action === 'create' ? null : $this->template->id);
            $this->assertSame($actor->id, $result->actorId);
            $this->assertTrue($result->changed);
            $this->assertSame(match ($action) {
                'delete' => 'deleted', 'duplicate' => 'copied', default => 'saved'
            }, $result->outcome);
            $this->assertSuffixUnchanged($before);
            $after = $this->state();
            $body = $this->body('create', $this->values());
            $this->send($action, $body)->assertForbidden();
            $this->assertSame($after, $this->state());
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_only_manage_any_bypasses_site_scope_reports_cannot_and_workers_still_must_be_current(): void
    {
        $outside = Site::factory()->create();
        $foreign = Client::factory()->create(['site_id' => $outside->id]);
        $values = $this->values();
        $values['template_shifts'][0]['client_id'] = $foreign->id;
        $values['template_shifts'][0]['service_context_id'] = null;
        $values['template_shifts'][0]['user_id'] = null;
        $reporter = $this->person(['roster_templates.create', 'roster_templates.viewAny', 'reports.viewAny']);
        $manager = $this->person(['roster_templates.create', 'roster_templates.viewAny', 'shifts.manageAny'], profile: false);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($values, $reporter, $manager): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->actingAs($reporter);
            $this->send('create', $this->body('create', $values))->assertForbidden();
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->actingAs($manager);
            $body = $this->body('create', $values);
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = session('roster_template_result');
            $this->assertSame($manager->id, $receipt['actor_id']);
            $this->assertPattern($values, RosterTemplate::findOrFail($receipt['template_id']));
            $this->assertSame($queue, $this->queueState());
            $values['template_shifts'][0]['user_id'] = $reporter->id;
            $reporter->hrEmployeeProfile->update(['end_date' => '2026-10-07']);
            $before = $this->state();
            $queue = $this->queueState();
            $this->send('create', $this->body('create', $values))->assertForbidden();
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function persistenceFaults(): array
    {
        return ['create header veto' => ['create', 'header_veto'], 'update header alteration' => ['update', 'header_alter'],
            'duplicate late row veto' => ['duplicate', 'late_row'], 'create row alteration' => ['create', 'row_alter'],
            'update late row veto' => ['update', 'late_row'], 'update old child delete veto' => ['update', 'child_delete'],
            'parent delete veto' => ['delete', 'parent_delete'], 'delete readback alteration' => ['delete', 'delete_alter']];
    }

    #[DataProvider('persistenceFaults')]
    public function test_actual_observer_veto_or_changed_readback_rolls_back_the_entire_aggregate(string $action, string $fault): void
    {
        $this->template->templateShifts()->create($this->rowValues(['day_of_week' => 3]));
        $values = $this->values(['name' => 'Fault must not save']);
        $values['template_shifts'][] = $this->rowValues(['day_of_week' => 4]);
        $source = $this->source();
        $this->commitFixtures();
        $this->withProductionManager(function () use ($action, $fault, $values, $source): void {
            $before = $this->state();
            $queue = $this->queueState();
            $this->withObserver(function () use ($action, $fault, $values, $source): void {
                $rows = 0;
                if ($fault === 'header_veto') {
                    RosterTemplate::saving(fn ($row) => $row->exists ? null : false);
                } elseif ($fault === 'header_alter') {
                    RosterTemplate::saving(function ($row): void {
                        $row->name = 'Altered by observer';
                    });
                } elseif ($fault === 'late_row') {
                    RosterTemplateShift::saving(function () use (&$rows) {
                        return ++$rows === 2 ? false : null;
                    });
                } elseif ($fault === 'row_alter') {
                    RosterTemplateShift::saving(function ($row): void {
                        $row->is_lone_worker = ! $row->is_lone_worker;
                    });
                } elseif ($fault === 'child_delete') {
                    RosterTemplateShift::deleting(fn () => false);
                } elseif ($fault === 'parent_delete') {
                    RosterTemplate::deleting(fn () => false);
                } else {
                    RosterTemplate::deleting(function ($row): void {
                        DB::table('roster_templates')->where('id', $row->id)->update(['description' => 'Altered deletion source']);
                    });
                }
                try {
                    app(RosterTemplateCommand::class)->execute($this->actor, $action, $values,
                        $action === 'create' ? null : $this->template->id, $action === 'create' ? null : $source, (string) Str::uuid());
                    $this->fail('The actual persistence veto or altered saved fields must fail.');
                } catch (HttpException $exception) {
                    $this->assertSame(409, $exception->getStatusCode());
                }
            });
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
            $this->assertNull(session('roster_template_result'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_duplicate_preserves_legacy_full_clock_precision_nullable_skills_and_falsey_stored_text(): void
    {
        DB::table('roster_template_shifts')->where('roster_template_id', $this->template->id)->update([
            'start_time' => '09:17:29', 'end_time' => '11:43:31', 'required_skills' => null, 'location' => '0', 'notes' => '',
            'expected_break_minutes' => null, 'user_id' => null, 'service_context_id' => null]);
        $this->commitFixtures();
        $this->withProductionManager(function (): void {
            $before = $this->state();
            $queue = $this->queueState();
            $original = $this->rawPattern($this->template);
            $body = $this->body('duplicate');
            $this->send('duplicate', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('duplicate', $body, 'copied', true);
            $copy = RosterTemplate::findOrFail($receipt['copy_id']);
            $copyRows = $this->rawPattern($copy)['rows'];
            $fields = array_keys($this->rowValues());
            $this->assertSame(array_intersect_key($original['rows'][0], array_flip($fields)), array_intersect_key($copyRows[0], array_flip($fields)));
            $this->assertSame($original, $this->rawPattern($this->template));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_raw_pdo_transaction_never_certifies_an_existing_actual_result_and_clears_stale_flash(): void
    {
        $this->commitFixtures();
        $this->withProductionManager(function (): void {
            $actual = app(RosterTemplateCommand::class)->execute($this->actor, 'create', $this->values(), requestId: (string) Str::uuid());
            $before = $this->state();
            $queue = $this->queueState();
            $request = Request::create('/synthetic-template-result', 'POST');
            $request->setLaravelSession(app('session.store'));
            $request->setUserResolver(fn () => $this->actor);
            $receipts = app(RosterTemplateReceipt::class);
            $this->assertIsArray($receipts->committed($receipts->begin($request), $actual));
            $request->session()->put('roster_template_result', ['actor_id' => $this->actor->id, 'outcome' => 'prior']);
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                $this->assertFalse($receipts->begin($request));
                $this->assertNull(session('roster_template_result'));
                $this->assertNull($receipts->committed(true, $actual));
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queueState());
            } finally {
                $pdo->rollBack();
            }
            $this->assertFalse($pdo->inTransaction());
        });
    }

    private function authorityState(?Connection $connection = null): array
    {
        $connection ??= DB::connection();

        return ['actor' => (array) $connection->table('users')->where('id', $this->actor->id)->first(),
            'worker' => (array) $connection->table('users')->where('id', $this->worker->id)->first(),
            'profiles' => $connection->table('hr_employee_profiles')->whereIn('user_id', [$this->actor->id, $this->worker->id])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'Site' => (array) $connection->table('sites')->where('id', $this->site->id)->first(),
            'Client' => (array) $connection->table('clients')->where('id', $this->client->id)->first(),
            'Context' => (array) $connection->table('service_contexts')->where('id', $this->context->id)->first(),
            'overrides' => $connection->table('permission_user')->where('user_id', $this->actor->id)->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function test_php_normalization_vector_matches_independent_ordered_unicode_and_zero_hash(): void
    {
        $input = ['name' => "\u{200d} Pattern \u{034f}", 'description' => '0', 'template_type' => 'weekly', 'is_active' => true,
            'template_shifts' => [['client_id' => 1, 'user_id' => null, 'service_context_id' => null, 'day_of_week' => 1,
                'start_time' => '09:17', 'end_time' => '11:43', 'shift_type' => 'sleepover', 'is_sleepover' => false,
                'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => 0,
                'required_skills' => [' Hoist ', '0', '', 'Hoist'], 'location' => '0', 'notes' => ' handover ']]];
        $expected = ['name' => 'Pattern', 'description' => '0', 'template_type' => 'weekly', 'is_active' => true,
            'template_shifts' => [['client_id' => 1, 'user_id' => null, 'service_context_id' => null, 'day_of_week' => 1,
                'start_time' => '09:17', 'end_time' => '11:43', 'shift_type' => 'sleepover', 'is_sleepover' => true,
                'is_on_call' => false, 'is_lone_worker' => false, 'expected_break_minutes' => 0,
                'required_skills' => ['Hoist', 'Hoist'], 'location' => null, 'notes' => 'handover']]];
        $before = $this->state();
        $this->assertSame($expected, RosterTemplateIntent::normalize($input));
        $this->assertSame('9a87af7d4027ec3bd1d92aced72c3898fabcf2d33700ca795404effb592e4b40', RosterTemplateIntent::hash('update', 1,
            ['template_id' => 1, 'source_revision' => str_repeat('a', 64)], $expected));
        $this->assertSame($before, $this->state());
    }

    public function test_scoped_read_withholds_entire_invalid_patterns_and_private_labels_before_exposing_revision(): void
    {
        $outside = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $outside->id, 'first_name' => 'Withheld private resident']);
        $foreign = $this->pattern(['name' => 'Withheld private template', 'description' => 'Withheld description',
            'template_shifts' => [$this->rowValues(), $this->rowValues(['client_id' => $foreignClient->id, 'service_context_id' => null, 'notes' => 'Withheld row note'])]]);
        $clientless = $this->pattern(['name' => 'Withheld clientless template']);
        DB::table('roster_template_shifts')->where('roster_template_id', $clientless->id)->update(['client_id' => null]);
        $inactive = $this->pattern(['name' => 'Withheld inactive worker template']);
        $ended = $this->person();
        $ended->hrEmployeeProfile->update(['is_active' => false]);
        $inactive->templateShifts()->firstOrFail()->update(['user_id' => $ended->id]);
        $viewer = $this->person(['roster_templates.viewAny']);
        $this->actingAs($viewer);
        $before = $this->state();
        $queue = $this->queueState();
        $response = $this->get(route('operations.rostering.templates.index', ['week' => '2026-10-15']))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('operations/rostering/template-workspace')
            ->where('week', '2026-10-12')->where('workerTimezone', 'Pacific/Auckland')
            ->has('rosterTemplates', 1)->where('rosterTemplates.0.id', $this->template->id)
            ->where('rosterTemplates.0.source_revision', $this->source()['source_revision'])
            ->where('rosterTemplates.0.template_shifts.0.is_lone_worker', true)
            ->where('templateCapabilities.can_view', true)->where('templateCapabilities.can_create', false)
            ->where('templateCapabilities.can_edit', false)->where('templateCapabilities.can_delete', false)
            ->where('rosterTemplates.0.urls.update', null)->where('rosterTemplates.0.urls.duplicate', null)
            ->where('rosterTemplates.0.urls.delete', null)->where('rosterTemplates.0.urls.apply', null)
            ->where('urls.store', null)
            ->where('templateOptions.clients', fn ($rows) => collect($rows)->pluck('id')->contains($this->client->id)
                && ! collect($rows)->pluck('id')->contains($foreignClient->id)));
        foreach (['Withheld private resident', 'Withheld private template', 'Withheld description', 'Withheld row note',
            'Withheld clientless template', 'Withheld inactive worker template'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
        $this->assertNull(session('roster_template_result'));
    }

    public function test_existing_empty_pattern_is_readable_only_by_creator_or_exact_manager_and_roster_reader_redirects(): void
    {
        $empty = RosterTemplate::create(['name' => 'Own empty legacy', 'description' => null, 'template_type' => 'weekly',
            'is_active' => true, 'created_by' => $this->actor->id]);
        $viewer = $this->person(['roster_templates.viewAny']);
        $manager = $this->person(['roster_templates.viewAny', 'shifts.manageAny'], profile: false);
        $rosterViewer = $this->person(['rostering.viewAny', 'reports.viewAny']);
        $outside = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $outside->id]);
        $before = $this->state();
        $queue = $this->queueState();
        $this->get(route('operations.rostering.templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rosterTemplates', 2)->where('rosterTemplates', fn ($rows) => collect($rows)->pluck('id')->contains($empty->id)));
        $this->actingAs($viewer)->get(route('operations.rostering.templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rosterTemplates', 1)->where('rosterTemplates', fn ($rows) => ! collect($rows)->pluck('id')->contains($empty->id)));
        $this->actingAs($manager)->get(route('operations.rostering.templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rosterTemplates', 2)->where('rosterTemplates', fn ($rows) => collect($rows)->pluck('id')->contains($empty->id)));
        $this->actingAs($rosterViewer)->get(route('operations.rostering.templates.index', ['week' => '2026-10-15']))
            ->assertRedirect(route('operations.rostering.index', ['tab' => 'templates', 'week' => '2026-10-12']));
        $this->get(route('operations.rostering.index', ['tab' => 'templates', 'week' => '2026-10-12']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('operations/rostering/index')
                ->has('staff')->has('clients')->has('sites')->has('serviceContexts')
                ->where('templateCapabilities.can_view', true)->where('templateCapabilities.can_create', false)
                ->where('templateCapabilities.can_edit', false)->where('templateCapabilities.can_delete', false)
                ->has('rosterTemplates', 1)->where('rosterTemplates.0.id', $this->template->id)
                ->where('rosterTemplates.0.source_revision', $this->source()['source_revision'])
                ->where('templateOptions.clients', fn ($rows) => collect($rows)->pluck('id')->contains($this->client->id)
                    && ! collect($rows)->pluck('id')->contains($foreignClient->id))
                ->where('templateOptions.sites', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->site->id]));
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_validation_clears_previous_confirmation_and_another_requester_cannot_read_captured_receipt(): void
    {
        $other = $this->person(['roster_templates.viewAny']);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($other): void {
            $body = $this->body('create', $this->values());
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('create', $body, 'saved', true);
            $before = $this->state();
            $queue = $this->queueState();
            $invalid = $this->body('create', $this->values(['name' => '']));
            $this->send('create', $invalid)->assertSessionHasErrors('name');
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($before, $this->state());
            $this->withSession(['roster_template_result' => $receipt]);
            $this->assertSame($receipt, session('roster_template_result'));
            $this->get(route('operations.rostering.templates.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('flash.roster_template_result', $receipt));
            $this->withSession(['roster_template_result' => $receipt]);
            $this->assertSame($receipt, session('roster_template_result'));
            $this->actingAs($other)->get(route('operations.rostering.templates.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('flash.roster_template_result', null));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public static function malformedModernRequests(): array
    {
        return ['missing UUID' => ['uuid'], 'foreign source identity' => ['source_id'], 'malformed revision' => ['revision']];
    }

    #[DataProvider('malformedModernRequests')]
    public function test_modern_correlation_and_source_proof_are_required_without_any_write(string $kind): void
    {
        $body = $this->body('update', $this->values(['name' => 'Rejected modern intent']));
        if ($kind === 'uuid') {
            unset($body['request_id']);
        } elseif ($kind === 'source_id') {
            $body['expected_source']['template_id']++;
        } else {
            $body['expected_source']['source_revision'] = 'not-a-source-hash';
        }
        $this->commitFixtures();
        $this->withProductionManager(function () use ($body, $kind): void {
            $before = $this->state();
            $queue = $this->queueState();
            $response = $this->send('update', $body);
            if ($kind === 'source_id') {
                $response->assertStatus(409);
            } else {
                $response->assertSessionHasErrors($kind === 'uuid' ? 'request_id' : 'expected_source.source_revision');
            }
            $this->assertNull(session('roster_template_result'));
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queueState());
        });
    }

    public function test_saved_result_projection_failure_is_unknown_and_never_repeats_the_domain_write(): void
    {
        app()->instance(RosterTemplateReceipt::class, new class extends RosterTemplateReceipt
        {
            private int $calls = 0;

            protected function isPhysicalRoot(): bool
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Synthetic post-commit projection failure');
                }

                return parent::isPhysicalRoot();
            }
        });
        $this->commitFixtures();
        $this->withProductionManager(function (): void {
            $before = $this->state();
            $queue = $this->queueState();
            $body = $this->body('create', $this->values());
            $this->send('create', $body)->assertStatus(303)->assertSessionHasNoErrors()->assertSessionHas('warning')
                ->assertSessionMissing('status');
            $this->assertNull(session('roster_template_result'));
            $new = RosterTemplate::whereNotIn('id', array_column($before['roster_templates'], 'id'))->get();
            $this->assertCount(1, $new);
            $this->assertPattern($this->values(), $new->first());
            $this->assertSame($before['roster_templates'], $this->raw('roster_templates', exceptIds: [$new->first()->id]));
            $this->assertSuffixUnchanged($before);
            $this->assertSame($queue, $this->queueState());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    private function person(array $keys = [], ?Site $site = null, bool $profile = true): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'template-library-'.Str::uuid(), 'label' => 'Template fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        if ($profile) {
            HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
                'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
                'created_by' => $user->id, 'updated_by' => $user->id]);
        }

        return $user->fresh();
    }

    private function rowValues(array $changes = []): array
    {
        return array_replace(['client_id' => $this->client->id, 'user_id' => $this->worker->id, 'service_context_id' => $this->context->id,
            'day_of_week' => 1, 'start_time' => '09:17', 'end_time' => '11:43', 'shift_type' => 'on_call', 'is_sleepover' => false,
            'is_on_call' => true, 'is_lone_worker' => true, 'expected_break_minutes' => 20, 'required_skills' => ['Recorded requirement'],
            'location' => 'Synthetic location', 'notes' => 'Private synthetic note'], $changes);
    }

    private function values(array $changes = []): array
    {
        return array_replace(['name' => 'Synthetic template', 'description' => 'Private synthetic description', 'template_type' => 'fortnightly',
            'is_active' => true, 'template_shifts' => [$this->rowValues()]], $changes);
    }

    private function pattern(array $changes = []): RosterTemplate
    {
        $values = $this->values($changes);
        $rows = $values['template_shifts'];
        unset($values['template_shifts']);
        $template = RosterTemplate::create([...$values, 'created_by' => $this->actor->id]);
        $template->templateShifts()->createMany($rows);

        return $template->fresh();
    }

    private function patternValues(RosterTemplate $template): array
    {
        $template = $template->fresh();
        $fields = array_keys($this->rowValues());

        return ['name' => $template->name, 'description' => $template->description, 'template_type' => $template->template_type,
            'is_active' => $template->is_active, 'template_shifts' => $template->templateShifts()->orderBy('id')->get()
                ->map(fn ($row) => array_replace($row->only($fields), ['start_time' => substr($row->start_time, 0, 5), 'end_time' => substr($row->end_time, 0, 5)]))->all()];
    }

    private function source(?RosterTemplate $template = null): array
    {
        $template ??= $this->template;

        return app(RosterTemplateSource::class)->expected($template->fresh(), $template->templateShifts()->orderBy('id')->get());
    }

    private function body(string $action, array $values = [], ?RosterTemplate $template = null): array
    {
        return [...(in_array($action, ['create', 'update'], true) ? $values : []), 'request_id' => (string) Str::uuid(),
            ...($action === 'create' ? [] : ['expected_source' => $this->source($template)]), 'week' => '2026-10-15'];
    }

    private function send(string $action, array $body, ?RosterTemplate $template = null)
    {
        $this->withHeader('X-Roster-Template-Result', 'committed-v1')->from(route('operations.rostering.templates.index'));
        $template ??= $this->template;

        return match ($action) {
            'create' => $this->post(route('operations.rostering.templates.store'), $body),
            'update' => $this->put(route('operations.rostering.templates.update', $template), $body),
            'duplicate' => $this->post(route('operations.rostering.templates.duplicate', $template), $body),
            'delete' => $this->delete(route('operations.rostering.templates.destroy', $template), $body),
        };
    }

    private function assertReceipt(string $action, array $body, string $outcome, bool $changed, ?RosterTemplate $template = null, ?array $normalized = null): array
    {
        $template ??= $this->template;
        $receipt = session('roster_template_result');
        $this->assertIsArray($receipt);
        $this->assertSame(1, $receipt['version']);
        $this->assertSame('library', $receipt['scope']);
        $this->assertSame($action, $receipt['action']);
        $this->assertSame($body['request_id'], $receipt['request_id']);
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame($outcome, $receipt['outcome']);
        $this->assertSame($changed, $receipt['changed']);
        $this->assertSame($body['expected_source'] ?? null, $receipt['expected_source']);
        $this->assertIsInt($receipt['template_id']);
        if ($action !== 'create') {
            $this->assertSame($template->id, $receipt['template_id']);
            $this->assertSame($body['expected_source']['source_revision'], $receipt['source_revision']);
        } else {
            $this->assertNull($receipt['source_revision']);
        }
        $this->assertSame($action === 'duplicate', is_int($receipt['copy_id']));
        if ($action !== 'duplicate') {
            $this->assertNull($receipt['copy_id']);
        }
        $values = null;
        if (in_array($action, ['create', 'update'], true)) {
            $values = $normalized ?? array_intersect_key($body, array_flip(['name', 'description', 'template_type', 'is_active', 'template_shifts']));
        }
        $this->assertSame($this->hash(['action' => $action, 'template_id' => $action === 'create' ? null : $template->id,
            'expected_source' => $body['expected_source'] ?? null, 'values' => $values]), $receipt['values_hash']);
        $this->assertSame(now()->copy()->startOfSecond()->utc()->format('Y-m-d\TH:i:s.000\Z'), $receipt['committed_at']);
        if ($action === 'delete') {
            $this->assertNull($receipt['result_revision']);
        } else {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $receipt['result_revision']);
        }
        $resultId = $receipt['copy_id'] ?? $receipt['template_id'];
        $savedRows = RosterTemplateShift::where('roster_template_id', $resultId)->orderBy('id')->get();
        $this->assertSame($savedRows->count(), $receipt['template_shifts_count']);
        if ($action !== 'delete') {
            $this->assertSame(RosterTemplateSource::revision(RosterTemplate::findOrFail($resultId), $savedRows), $receipt['result_revision']);
        }
        $wire = json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private synthetic', $wire);
        $this->assertStringNotContainsString('Recorded requirement', $wire);

        return $receipt;
    }

    private function assertPattern(array $expected, RosterTemplate $template): void
    {
        $this->assertSame($expected, $this->patternValues($template));
    }

    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function raw(string $table, ?Connection $connection = null, array $exceptIds = [], array $exceptTemplateIds = []): array
    {
        $query = ($connection ?? DB::connection())->table($table)->orderBy('id');
        if ($exceptIds !== []) {
            $query->whereNotIn('id', $exceptIds);
        }
        if ($exceptTemplateIds !== []) {
            $query->whereNotIn('roster_template_id', $exceptTemplateIds);
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rawPattern(RosterTemplate $template, ?Connection $connection = null): array
    {
        $connection ??= DB::connection();

        return ['header' => (array) $connection->table('roster_templates')->where('id', $template->id)->first(),
            'rows' => $connection->table('roster_template_shifts')->where('roster_template_id', $template->id)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all()];
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['roster_templates', 'roster_template_shifts', 'shifts', 'shift_tasks', 'timeline_events', 'audit_logs',
            'shift_eligibility_overrides', 'coverage_reservations', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => $this->raw($table, $connection), $tables));
    }

    private function assertSuffixUnchanged(array $before): void
    {
        foreach (array_diff(array_keys($before), ['roster_templates', 'roster_template_shifts']) as $table) {
            $this->assertSame($before[$table], $this->raw($table), $table.' must not change during library commands');
        }
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        $name = 'template_library_writer';
        config(['database.connections.'.$name => array_replace(DB::connection()->getConfig(), ['name' => $name])]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function withProductionManager(callable $proof): void
    {
        $connection = DB::connection();
        $testing = app('db.transactions');
        $production = new DatabaseTransactionsManager;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        app()->instance('db.transactions', $production);
        $connection->setTransactionManager($production);
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->assertFalse($connection->getPdo()->inTransaction());
            app()->instance('db.transactions', $testing);
            $connection->setTransactionManager($testing);
        }
    }

    private function withObserver(callable $proof): void
    {
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        try {
            $proof();
        } finally {
            Model::setEventDispatcher($events);
        }
    }
}
