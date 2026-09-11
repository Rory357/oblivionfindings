<?php

use App\Domain\It\Services\ItAttachmentCleanupReadService;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItAutomationRun;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function cleanupOperationsActor(array $keys): User
{
    $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::query()->create(['name' => 'cleanup-review-'.Str::uuid(), 'label' => 'Synthetic cleanup review', 'level' => 10, 'type' => 'custom']);
    // Every actor reaches the existing IT route boundary; manage/audit remain
    // independent capabilities under test rather than implied staff grants.
    foreach (array_unique(['it.view', ...$keys]) as $key) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']));
    }
    $actor->roles()->attach($role);

    return $actor;
}

function cleanupOperationsFixture(User $actor): ItAutomationRun
{
    $uuid = (string) Str::uuid();
    ItAttachmentStorageIntent::query()->create(['intent_uuid' => $uuid, 'path' => 'it_attachments/'.$uuid,
        'actor_user_id' => $actor->id, 'parent_type' => (new ItTicket)->getMorphClass(), 'parent_id' => 876543,
        'content_sha256' => hash('sha256', 'synthetic'), 'original_name_sha256' => hash('sha256', 'private-fixture-name.txt'), 'size' => 9, 'state' => 'reserved']);

    return ItAutomationRun::query()->create(['automation_key' => ItAttachmentCleanupReadService::KEY, 'status' => 'failed',
        'started_at' => now()->subMinute(), 'finished_at' => now(), 'error_summary' => 'Private provider exception must not leave server',
        'result_summary' => ['limit' => 100, 'counts' => ['requested' => 2, 'deleted' => 1, 'failed' => 1, 'deferred' => 0, 'reconciliation_required' => 0],
            'provider_error' => 'Private provider exception must not leave server', 'intent_uuid' => $uuid]]);
}

test('only an IT manager with existing audit access receives global cleanup counts', function () {
    $actor = cleanupOperationsActor(['it.manage', 'audit.viewAny']);
    cleanupOperationsFixture($actor);
    $this->actingAs($actor)->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operationsAudit.attachment_cleanup.viewer_user_id', $actor->id)
        ->where('operationsAudit.attachment_cleanup.can_view_counts', true)
        ->where('operationsAudit.attachment_cleanup.counts.reserved_unclassified', 1)
        ->where('automationRuns.0.cleanup.counts.deleted', 1)
        ->missing('automationRuns.0.cleanup.intent_uuid')->missing('automationRuns.0.cleanup.provider_error')
        ->where('automationRuns.0.error_summary', fn ($value) => ! str_contains($value, 'Private provider')));
});

test('authorized cleanup evidence includes accepted email copies and flags invalid owners without exposing files', function () {
    $actor = cleanupOperationsActor(['it.manage', 'audit.viewAny']);
    cleanupOperationsFixture($actor);
    $accepted = ItInboundEmail::create(['from_email' => '', 'status' => 'processed']);
    $quarantined = ItInboundEmail::create(['from_email' => '', 'status' => 'quarantined']);
    foreach ([$accepted->id, $quarantined->id, 987654321] as $parentId) {
        $file = new ItAttachment;
        $file->forceFill(['attachable_type' => (new ItInboundEmail)->getMorphClass(), 'attachable_id' => $parentId,
            'path' => 'it_attachments/'.Str::uuid(), 'original_name' => 'private-reconciliation-evidence.txt',
            'mime' => 'text/plain', 'size' => 1, 'inbound_storage_state' => 'cleanup_pending'])->save();
    }
    $this->actingAs($actor)->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operationsAudit.attachment_cleanup.counts.cleanup_pending', 1)
        ->where('operationsAudit.attachment_cleanup.counts.reconciliation_required', 2)
        ->where('operationsAudit.attachment_cleanup.counts.reserved_unclassified', 1))
        ->assertDontSee('private-reconciliation-evidence.txt');
    $actor->roles()->first()->permissions()->detach(Permission::query()->where('key', 'audit.viewAny')->value('id'));
    $this->actingAs($actor->fresh())->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operationsAudit.attachment_cleanup.counts', null)
        ->where('automationRuns.0.cleanup', null));
});

test('restricted Setup access retains nonprivate readiness and freshness but withholds all cleanup counts', function () {
    $actor = cleanupOperationsActor(['it.manage']);
    cleanupOperationsFixture($actor);
    $this->actingAs($actor)->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operationsAudit.attachment_cleanup.readiness', 'ready')
        ->where('operationsAudit.attachment_cleanup.can_view_counts', false)
        ->where('operationsAudit.attachment_cleanup.counts', null)
        ->where('automationRuns.0.cleanup', null)
        ->where('automationRuns.0.error_summary', fn ($value) => ! str_contains($value, 'Private provider')));
    $auditOnly = cleanupOperationsActor(['audit.viewAny']);
    $this->actingAs($auditOnly)->get('/it/setup?tab=operations')->assertForbidden();
});

test('cleanup count permission is rechecked after a grant is removed', function () {
    $actor = cleanupOperationsActor(['it.manage', 'audit.viewAny']);
    cleanupOperationsFixture($actor);
    expect(app(ItAttachmentCleanupReadService::class)->health($actor)['can_view_counts'])->toBeTrue();
    $actor->roles()->first()->permissions()->detach(Permission::query()->where('key', 'audit.viewAny')->value('id'));
    $current = $actor->fresh();
    expect(app(ItAttachmentCleanupReadService::class)->health($current)['counts'])->toBeNull();
    $this->actingAs($current)->get('/it/setup?tab=operations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('operationsAudit.attachment_cleanup.can_view_counts', false)->where('automationRuns.0.cleanup', null));
});

test('missing cleanup schema is visible without fabricated counts or exposing raw failed results', function () {
    $actor = cleanupOperationsActor(['it.manage', 'audit.viewAny']);
    $run = cleanupOperationsFixture($actor);
    $schema = Schema::getFacadeRoot();
    $proxy = Mockery::mock($schema)->makePartial();
    $proxy->shouldReceive('hasTable')->with('it_attachment_storage_intents')->andReturn(false);
    Schema::swap($proxy);
    try {
        expect(app(ItAttachmentCleanupReadService::class)->health($actor))->toMatchArray(['readiness' => 'not_ready', 'counts' => null]);
    } finally {
        Schema::swap($schema);
    }
    $run->forceFill(['result_summary' => ['limit' => 100, 'counts' => 'malformed private text']])->save();
    expect(app(ItAttachmentCleanupReadService::class)->runSummary($actor, $run))->toBeNull();
});

test('cleanup schedule has finite overlap and its self-recorded result cannot be duplicated by scheduler events', function () {
    $catalogue = app(ItAutomationScheduleCatalog::class);
    $catalogue->register();
    $tasks = collect(app(Schedule::class)->events())->where('description', ItAttachmentCleanupReadService::KEY);
    expect($tasks)->toHaveCount(1);
    $task = $tasks->sole();
    expect($task->expression)->toBe('*/5 * * * *')->and($task->expiresAt)->toBe(10)
        ->and($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue()->and($task->command)->toContain('--limit=100');
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $run = $recorder->begin(ItAttachmentCleanupReadService::KEY);
    $recorder->completeRun($run, 'failed', 10, 'Synthetic partial cleanup');
    $recorder->finished(new ScheduledTaskFinished($task, 0.1));
    expect(ItAutomationRun::query()->where('automation_key', ItAttachmentCleanupReadService::KEY)->count())->toBe(1)
        ->and($run->fresh()->status)->toBe('failed');
    $recorder->skipped(new ScheduledTaskSkipped($task));
    expect(ItAutomationRun::query()->where('status', 'skipped')->count())->toBe(1);
});

test('stopped cleanup becomes stale despite an earlier success and a fresh recovery restores current evidence', function () {
    $this->travelTo(now()->startOfHour());
    $catalogue = app(ItAutomationScheduleCatalog::class);
    $run = ItAutomationRun::query()->create(['automation_key' => ItAttachmentCleanupReadService::KEY, 'status' => 'succeeded',
        'started_at' => now()->subMinutes(21), 'finished_at' => now()->subMinutes(20)]);
    expect($catalogue->freshnessFor(ItAttachmentCleanupReadService::KEY)['state'])->toBe('stale');
    $run->forceFill(['started_at' => now()->subMinute(), 'finished_at' => now()])->save();
    expect($catalogue->freshnessFor(ItAttachmentCleanupReadService::KEY)['state'])->toBe('fresh');
});
