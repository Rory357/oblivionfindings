<?php

namespace Tests\Feature\Privacy;

use App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob;
use App\Domain\Finance\Models\FinAuditExport;
use App\Jobs\EnforceDataRetentionJob;
use App\Models\Client;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\RespiteReferral;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnforceDataRetentionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_soft_deletes_records_past_retention_period(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $client = $this->oldClient();
        $this->policy([
            'retention_period_years' => 1,
            'archive_after_years' => null,
            'hard_delete_after_years' => null,
        ]);

        (new EnforceDataRetentionJob)->handle();

        $this->assertTrue(Client::withTrashed()->findOrFail($client->id)->trashed());
    }

    public function test_anonymizes_records_past_hard_delete_period(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $client = $this->oldClient([
            'first_name' => 'Private',
            'last_name' => 'Person',
            'date_of_birth' => '1980-01-01',
        ]);
        $this->policy([
            'retention_period_years' => null,
            'archive_after_years' => null,
            'hard_delete_after_years' => 1,
        ]);

        (new EnforceDataRetentionJob)->handle();

        $client->refresh();
        $this->assertSame('[REDACTED]', $client->first_name);
        $this->assertSame('[REDACTED]', $client->last_name);
        $this->assertNull($client->date_of_birth);
        $this->assertFalse($client->trashed());
    }

    public function test_archives_records_past_archive_period(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $client = $this->oldClient();
        $this->policy([
            'retention_period_years' => null,
            'archive_after_years' => 1,
            'hard_delete_after_years' => null,
        ]);

        (new EnforceDataRetentionJob)->handle();

        $this->assertTrue(Client::withTrashed()->findOrFail($client->id)->trashed());
    }

    public function test_active_legal_hold_exempts_matching_records(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $client = $this->oldClient();
        $this->policy([
            'retention_period_years' => 1,
            'archive_after_years' => null,
            'hard_delete_after_years' => null,
            'legal_hold_exemption' => true,
        ]);

        LegalHold::factory()->create([
            'holdable_type' => Client::class,
            'holdable_id' => $client->id,
            'status' => 'active',
            'imposed_at' => now()->subDays(10),
        ]);

        (new EnforceDataRetentionJob)->handle();

        $this->assertFalse(Client::withTrashed()->findOrFail($client->id)->trashed());
    }

    public function test_retention_conditions_filter_declined_never_converted_respite_referrals(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        $declined = $this->oldReferral([
            'status' => 'declined',
            'linked_booking_request_id' => null,
        ]);
        $accepted = $this->oldReferral([
            'status' => 'accepted',
            'linked_booking_request_id' => null,
        ]);
        $converted = $this->oldReferral([
            'status' => 'declined',
            'linked_booking_request_id' => 123,
        ]);

        $this->policy([
            'model_type' => RespiteReferral::class,
            'policy_name' => 'Declined respite referral disposal',
            'retention_period_years' => 1,
            'retention_conditions' => [
                'status' => 'declined',
                'linked_booking_request_id' => null,
            ],
        ]);

        (new EnforceDataRetentionJob)->handle();

        $this->assertTrue(RespiteReferral::withTrashed()->findOrFail($declined->id)->trashed());
        $this->assertFalse(RespiteReferral::withTrashed()->findOrFail($accepted->id)->trashed());
        $this->assertFalse(RespiteReferral::withTrashed()->findOrFail($converted->id)->trashed());
    }

    public function test_finance_audit_exports_are_pruned_by_finance_job(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');
        Storage::fake('local');
        config([
            'finance.audit_exports.disk' => 'local',
            'finance.audit_exports.retention_years' => 7,
        ]);

        $oldPath = 'finance/audit-exports/old.zip';
        $newPath = 'finance/audit-exports/new.zip';
        $pendingPath = 'finance/audit-exports/pending.zip';

        Storage::disk('local')->put($oldPath, 'old export');
        Storage::disk('local')->put($newPath, 'new export');
        Storage::disk('local')->put($pendingPath, 'pending export');

        $old = $this->auditExport($oldPath, 'completed', now()->subYears(8));
        $new = $this->auditExport($newPath, 'completed', now()->subYears(2));
        $pending = $this->auditExport($pendingPath, 'pending', now()->subYears(8));

        (new PruneFinanceAuditExportsJob)->handle();

        $this->assertDatabaseMissing('fin_audit_exports', ['id' => $old->id]);
        $this->assertDatabaseHas('fin_audit_exports', ['id' => $new->id]);
        $this->assertDatabaseHas('fin_audit_exports', ['id' => $pending->id]);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertExists($pendingPath);
    }

    private function oldClient(array $attributes = []): Client
    {
        $client = Client::factory()->create(array_merge([
            'status' => 'inactive',
        ], $attributes));

        $client->forceFill([
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ])->save();

        return $client;
    }

    private function policy(array $attributes): DataRetentionPolicy
    {
        return DataRetentionPolicy::factory()->create(array_merge([
            'model_type' => Client::class,
            'policy_name' => 'Client retention',
            'retention_period_years' => null,
            'archive_after_years' => null,
            'hard_delete_after_years' => null,
            'legal_hold_exemption' => false,
            'active_case_exemption' => false,
            'active' => true,
        ], $attributes));
    }

    private function oldReferral(array $attributes = []): RespiteReferral
    {
        $referral = RespiteReferral::query()->create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'referrer_name' => 'NASC coordinator',
            'referral_reason' => 'Planned respite support',
            'status' => 'received',
            'received_at' => now()->subYears(2),
        ], $attributes));

        $referral->forceFill([
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ])->save();

        return $referral;
    }

    private function auditExport(string $path, string $status, Carbon $generatedAt): FinAuditExport
    {
        $export = FinAuditExport::query()->create([
            'organization_id' => 1,
            'export_name' => basename($path),
            'period_from' => '2025-01-01',
            'period_to' => '2025-12-31',
            'file_path' => $path,
            'file_size_bytes' => 12,
            'status' => $status,
            'generated_at' => $generatedAt,
        ]);

        $export->forceFill([
            'created_at' => $generatedAt,
            'updated_at' => $generatedAt,
        ])->save();

        return $export;
    }
}
