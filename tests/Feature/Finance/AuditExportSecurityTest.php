<?php

use App\Domain\Finance\Models\FinAuditExport;
use App\Domain\Finance\Services\AuditExportService;
use App\Jobs\EnforceDataRetentionJob;
use Illuminate\Support\Facades\Storage;

test('audit exports are stored encrypted and decrypted for download', function () {
    Storage::fake('local');
    config(['finance.audit_exports.disk' => 'local']);

    $export = FinAuditExport::create([
        'organization_id' => 1,
        'export_name' => 'Board audit pack',
        'period_from' => '2026-04-01',
        'period_to' => '2026-04-30',
        'include_journals' => false,
        'include_bank_reconciliations' => false,
        'include_ap' => false,
        'include_ar' => false,
        'include_gst' => false,
        'include_fixed_assets' => false,
        'status' => 'pending',
    ]);

    $service = app(AuditExportService::class);
    $path = $service->generate($export);
    $stored = Storage::disk('local')->get($path);
    $download = $service->contentsForDownload($export->fresh());

    Storage::disk('local')->assertExists($path);
    expect(str_ends_with($path, '.zip.enc'))->toBeTrue()
        ->and(str_starts_with($stored, 'PK'))->toBeFalse()
        ->and(substr((string) $download, 0, 2))->toBe('PK')
        ->and($export->fresh()->file_size_bytes)->toBe(strlen((string) $download));
});

test('data retention job prunes expired finance audit exports and files', function () {
    Storage::fake('local');
    config([
        'finance.audit_exports.disk' => 'local',
        'finance.audit_exports.retention_years' => 7,
    ]);

    $expired = FinAuditExport::create([
        'organization_id' => 1,
        'export_name' => 'Expired audit pack',
        'period_from' => '2017-04-01',
        'period_to' => '2018-03-31',
        'file_path' => 'audit-exports/1/expired.zip.enc',
        'file_size_bytes' => 100,
        'status' => 'completed',
        'generated_at' => now()->subYears(8),
    ]);

    $current = FinAuditExport::create([
        'organization_id' => 1,
        'export_name' => 'Current audit pack',
        'period_from' => '2025-04-01',
        'period_to' => '2026-03-31',
        'file_path' => 'audit-exports/1/current.zip.enc',
        'file_size_bytes' => 100,
        'status' => 'completed',
        'generated_at' => now()->subYear(),
    ]);

    Storage::disk('local')->put($expired->file_path, 'expired');
    Storage::disk('local')->put($current->file_path, 'current');

    app(EnforceDataRetentionJob::class)->handle();

    Storage::disk('local')->assertMissing($expired->file_path);
    Storage::disk('local')->assertExists($current->file_path);

    $this->assertDatabaseMissing('fin_audit_exports', ['id' => $expired->id]);
    $this->assertDatabaseHas('fin_audit_exports', ['id' => $current->id]);
});
