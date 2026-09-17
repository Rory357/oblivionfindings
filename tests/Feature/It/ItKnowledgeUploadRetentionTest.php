<?php

use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake(ItAttachment::DISK);
    $this->article = ItKbArticle::factory()->create();
    $this->author = User::factory()->create(['approved_at' => now()]);
});

function retentionFile($test, string $state, int $ageDays, bool $withBytes = true): ItKbFile
{
    $path = 'it_knowledge/'.Str::uuid();
    if ($withBytes) {
        Storage::disk(ItAttachment::DISK)->put($path, 'Synthetic retention bytes');
    }

    return ItKbFile::query()->create([
        'article_id' => $test->article->id, 'series_id' => (string) Str::uuid(), 'version' => 1,
        'name' => 'fixture.pdf', 'mime' => 'application/pdf', 'size' => 25,
        'sha256' => hash('sha256', 'Synthetic retention bytes'), 'path' => $path,
        'state' => $state, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'audience' => 'all_staff', 'site_scope' => null,
        'uploaded_by_user_id' => $test->author->id, 'created_at' => now()->subDays($ageDays),
    ]);
}

test('stale unfinished uploads are abandoned with their bytes disposed while saved and fresh files are untouched', function () {
    $disk = Storage::disk(ItAttachment::DISK);
    $staleReserved = retentionFile($this, 'reserved', 20);
    $staleUnavailable = retentionFile($this, 'scan_unavailable', 20);
    $staleFailed = retentionFile($this, 'integrity_failed', 20);
    $freshReserved = retentionFile($this, 'reserved', 2);
    $ready = retentionFile($this, 'ready', 400);

    $this->artisan('it:prune-knowledge-uploads')->assertExitCode(0);

    foreach ([$staleReserved, $staleUnavailable, $staleFailed] as $file) {
        expect($file->fresh()->state)->toBe('abandoned')
            ->and($disk->exists($file->path))->toBeFalse();
    }
    expect($freshReserved->fresh()->state)->toBe('reserved')
        ->and($disk->exists($freshReserved->path))->toBeTrue()
        ->and($ready->fresh()->state)->toBe('ready')
        ->and($disk->exists($ready->path))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.knowledge.file.retention_pruned')->count())->toBe(3);
});

test('abandoned bytes and expired quarantine evidence are disposed on their own windows, keeping rows as history', function () {
    $disk = Storage::disk(ItAttachment::DISK);
    $abandoned = retentionFile($this, 'abandoned', 20);
    $freshQuarantine = retentionFile($this, 'quarantined', 20);
    $expiredQuarantine = retentionFile($this, 'quarantined', 40);

    $this->artisan('it:prune-knowledge-uploads')->assertExitCode(0);

    expect($disk->exists($abandoned->path))->toBeFalse()
        ->and($abandoned->fresh())->not->toBeNull()
        // Quarantine evidence is retained longer than ordinary retention…
        ->and($disk->exists($freshQuarantine->path))->toBeTrue()
        ->and($freshQuarantine->fresh()->state)->toBe('quarantined')
        // …but not forever.
        ->and($disk->exists($expiredQuarantine->path))->toBeFalse()
        ->and($expiredQuarantine->fresh()->state)->toBe('quarantined');

    // A second run has nothing left to do and writes no duplicate audit.
    $recorded = AuditLog::query()->where('action', 'it.knowledge.file.retention_pruned')->count();
    $this->artisan('it:prune-knowledge-uploads')->assertExitCode(0);
    expect(AuditLog::query()->where('action', 'it.knowledge.file.retention_pruned')->count())->toBe($recorded);
});
