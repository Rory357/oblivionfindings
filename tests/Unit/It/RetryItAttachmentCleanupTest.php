<?php

use App\Console\Commands\RetryItAttachmentCleanup;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Models\ItAutomationRun;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $this->previousFacadeApp = Facade::getFacadeApplication();
    $this->container = new Container;
    Container::setInstance($this->container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->container);
    $this->storage = Mockery::mock();
    $this->container->instance(ItAttachmentStorageService::class, $this->storage);
    $this->runs = Mockery::mock(ItAutomationRunRecorder::class);
    $this->run = (new ItAutomationRun)->forceFill(['id' => 71]);
    $this->recorded = null;
});

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousFacadeApp);
});

function cleanupCounts(array $changes = []): array
{
    return array_replace(['requested' => 0, 'deleted' => 0, 'failed' => 0, 'deferred' => 0, 'reconciliation_required' => 0], $changes);
}

function cleanupReadiness(array $missingTables = [], array $missingColumns = []): void
{
    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->andReturnUsing(fn ($table) => ! in_array($table, $missingTables, true));
    $schema->shouldReceive('hasColumns')->andReturnUsing(fn ($table, $columns) => count($columns) > 0 && ! in_array($table, $missingColumns, true));
    // This unit harness models the older direct-intent schema. Real inbound evidence is covered with MySQL.
    $schema->shouldReceive('hasColumn')->with('it_attachments', 'inbound_storage_state')->andReturn(false);
    Schema::swap($schema);
}

function cleanupRemaining(array $counts = [], bool $throw = false): void
{
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
    $query->shouldReceive('select')->once()->with('state')->andReturnSelf();
    $query->shouldReceive('selectRaw')->once()->with('COUNT(*) AS total')->andReturnSelf();
    $query->shouldReceive('whereIn')->once()->with('state', ['cleanup_pending', 'reserved', 'reconciliation_required'])->andReturnSelf();
    $query->shouldReceive('groupBy')->once()->with('state')->andReturnSelf();
    if ($throw) {
        $query->shouldReceive('get')->once()->andThrow(new RuntimeException('private SQL and file path must not be emitted'));
    } else {
        $query->shouldReceive('get')->once()->andReturn(collect($counts)->map(fn ($total, $state) => (object) ['state' => $state, 'total' => $total])->values());
    }
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('table')->once()->with('it_attachment_storage_intents')->andReturn($query);
    DB::swap($database);
}

function cleanupRecording(bool $completionFailure = false): void
{
    test()->runs->shouldReceive('begin')->once()->with(RetryItAttachmentCleanup::AUTOMATION_KEY)->andReturn(test()->run);
    test()->runs->shouldReceive('completeRun')->once()->andReturnUsing(function ($run, $status, $runtime, $error, $result) use ($completionFailure): void {
        expect($run)->toBe(test()->run)->and($runtime)->toBeInt()->toBeGreaterThanOrEqual(0);
        test()->recorded = compact('status', 'error', 'result');
        if ($completionFailure) {
            throw new RuntimeException('secret unavailable recorder endpoint');
        }
    });
}

function invokeCleanup(?string $limit = null): array
{
    $command = new RetryItAttachmentCleanup;
    $input = new ArrayInput($limit === null ? [] : ['--limit' => $limit], $command->getDefinition());
    $output = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));
    $exit = $command->handle(test()->runs);
    $raw = trim($output->fetch());

    return [$exit, json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $raw];
}

it('rejects invalid batch sizes without querying readiness or starting any run', function (string $limit) {
    [$exit, $report] = invokeCleanup($limit);
    expect($exit)->toBe(2)->and($report['status'])->toBe('not_run')->and($report['error_code'])->toBe('invalid_limit')
        ->and($report['counts'])->toBeNull()->and($report['remaining'])->toBeNull();
})->with(['0', '1001', '-1', '1.5', 'many']);

it('does not touch storage when automation recording is unavailable', function () {
    cleanupReadiness(['it_automation_runs']);
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['error_code'])->toBe('automation_storage_unavailable')->and($report['counts'])->toBeNull();
});

it('records incomplete000010 readiness as failed without calling cleanup', function (bool $tableMissing) {
    cleanupReadiness($tableMissing ? ['it_attachment_storage_intents'] : [], $tableMissing ? [] : ['it_attachment_storage_intents']);
    cleanupRecording();
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['error_code'])->toBe('attachment_storage_unavailable')->and($report['message'])->toContain('000010')
        ->and($this->recorded['status'])->toBe('failed')->and($this->recorded['result']['counts'])->toBeNull();
})->with([true, false]);

it('reports completed batches with additional pending work and leaves reserved evidence unclassified', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->with(2)->andReturn(cleanupCounts(['requested' => 2, 'deleted' => 2]));
    cleanupRemaining(['cleanup_pending' => '3', 'reserved' => '4']);
    [$exit, $report] = invokeCleanup('2');
    expect($exit)->toBe(0)->and($report['outcome'])->toBe('batch_complete')->and($report['remaining'])->toBe([
        'cleanup_pending' => 3, 'reserved_unclassified' => 4, 'reconciliation_required' => 0,
    ])->and($report['message'])->toContain('More explicit cleanup')->and($this->recorded['status'])->toBe('succeeded');
});

it('uses the bounded default and never classifies reserved intentions as deletable or complete', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->with(100)->andReturn(cleanupCounts());
    cleanupRemaining(['reserved' => 2]);
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(0)->and($report['outcome'])->toBe('no_pending')->and($report['remaining']['reserved_unclassified'])->toBe(2)
        ->and($report['message'])->toContain('reserved intentions remain unclassified');
});

it('returns failed for partial deletion without erasing successful counts', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->with(100)->andReturn(cleanupCounts(['requested' => 2, 'deleted' => 1, 'failed' => 1]));
    cleanupRemaining(['cleanup_pending' => 1]);
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['outcome'])->toBe('partial')->and($report['counts']['deleted'])->toBe(1)
        ->and($report['counts']['failed'])->toBe(1)->and($this->recorded['status'])->toBe('failed');
});

it('does not report a healthy batch while existing canonical reconciliation remains', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->with(100)->andReturn(cleanupCounts());
    cleanupRemaining(['reconciliation_required' => 2]);
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['outcome'])->toBe('reconciliation_required')
        ->and($report['remaining']['reconciliation_required'])->toBe(2)->and($this->recorded['status'])->toBe('failed');
});

it('keeps deferred or newly fenced outcomes non-successful', function (string $key) {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->with(100)->andReturn(cleanupCounts(['requested' => 1, $key => 1]));
    cleanupRemaining();
    [$exit, $report] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['counts'][$key])->toBe(1)->and($this->recorded['status'])->toBe('failed');
})->with(['deferred', 'reconciliation_required']);

it('does not begin physical cleanup when the run cannot be recorded', function () {
    cleanupReadiness();
    $this->runs->shouldReceive('begin')->once()->andThrow(new RuntimeException('secret unavailable recorder endpoint'));
    [$exit, $report, $raw] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['counts'])->toBeNull()->and($report['error_code'])->toBe('run_recording_unavailable')
        ->and($raw)->not->toContain('secret');
});

it('records a safe failure when retry throws without exposing provider paths or exception text', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->andThrow(new RuntimeException('secret unavailable private path'));
    [$exit, $report, $raw] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['counts'])->toBeNull()->and($report['error_code'])->toBe('cleanup_retry_unavailable')
        ->and($raw)->not->toContain('secret')->and($this->recorded['error'])->not->toContain('secret');
});

it('rejects partial malformed or contradictory result envelopes without fabricated counts', function (array $result) {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->andReturn($result);
    [$exit, $report, $raw] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['counts'])->toBeNull()->and($report['error_code'])->toBe('cleanup_result_invalid')
        ->and($raw)->not->toContain('private provider error');
})->with([
    'partial' => [['deleted' => 1]],
    'negative' => [cleanupCounts(['requested' => -1])],
    'contradictory' => [cleanupCounts(['requested' => 2, 'deleted' => 1])],
    'private extra' => [[...cleanupCounts(), 'provider_error' => 'private provider error']],
]);

it('retains known cleanup counts when later reconciliation evidence is unavailable', function () {
    cleanupReadiness();
    cleanupRecording();
    $this->storage->shouldReceive('retryPending')->once()->andReturn(cleanupCounts(['requested' => 1, 'deleted' => 1]));
    cleanupRemaining(throw: true);
    [$exit, $report, $raw] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['counts']['deleted'])->toBe(1)->and($report['remaining'])->toBeNull()
        ->and($report['error_code'])->toBe('cleanup_evidence_unavailable')->and($raw)->not->toContain('private SQL');
});

it('retains known counts but reports failure when the terminal automation write fails', function () {
    cleanupReadiness();
    cleanupRecording(completionFailure: true);
    $this->storage->shouldReceive('retryPending')->once()->andReturn(cleanupCounts(['requested' => 1, 'deleted' => 1]));
    cleanupRemaining();
    [$exit, $report, $raw] = invokeCleanup();
    expect($exit)->toBe(1)->and($report['status'])->toBe('failed')->and($report['counts']['deleted'])->toBe(1)
        ->and($report['error_code'])->toBe('run_recording_unavailable')->and($raw)->not->toContain('secret');
});
