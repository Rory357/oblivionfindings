<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Jobs\PostFundingClaimJournalJob;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FundingClaimJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\FundingClaimService;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FundingClaimJournalDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $storageContextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run($this->storageContextId);

        FinFiscalPeriod::create([
            'organization_id' => $this->storageContextId,
            'name' => 'FY2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_submitted_funding_claim_posts_journal_once(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['approved_at' => now()]);
        $actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::query()->create([
            'user_id' => $actor->id,
            'employee_number' => 'EMP-FUND-JOURNAL-'.$actor->id,
            'work_email' => $actor->email,
            'position_title' => 'Funding Officer',
            'position_role' => 'finance',
            'employment_type' => 'full_time',
            'start_date' => today()->subMonth(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $serviceDate = today()->subDay();
        $agreement = ServiceAgreement::factory()
            ->for($client)
            ->create([
                'status' => 'active',
                'funding_body' => 'private',
                'total_budget' => 1000,
                'starts_at' => $serviceDate->copy()->subMonth(),
                'ends_at' => $serviceDate->copy()->addMonth(),
            ]);
        $line = $agreement->lineItems()->create([
            'description' => 'Journal-backed delivery',
            'unit_price' => '62.75',
            'quantity' => '10.00',
            'budget_allocated' => '627.50',
            'category' => 'weekday',
        ]);
        $startsAt = $serviceDate->copy()->setTime(9, 0);
        $endsAt = $serviceDate->copy()->setTime(11, 0);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'status' => 'completed',
        ]);
        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'shift_site_id' => $site->id,
            'work_date' => $serviceDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'approved',
            'submitted_at' => $endsAt,
            'submitted_by' => $staff->id,
            'approved_at' => $endsAt->copy()->addHour(),
            'approved_by' => $staff->id,
            'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
        ]);
        $entry = BillingEntry::query()->create([
            'timesheet_id' => $timesheet->id,
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_agreement_id' => $agreement->id,
            'line_item_id' => $line->id,
            'service_date' => $serviceDate,
            'hours' => '2.00',
            'rate' => '62.75',
            'amount' => '125.50',
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);
        $claim = app(FundingClaimService::class)->createDraft($actor, [
            'service_agreement_id' => $agreement->id,
            'client_id' => $client->id,
            'claim_reference' => 'FC-TEST-001',
            'client_request_uuid' => (string) Str::uuid(),
            'period_start' => $serviceDate->copy()->startOfMonth()->toDateString(),
            'period_end' => $serviceDate->copy()->endOfMonth()->toDateString(),
            'items' => [[
                'billing_entry_id' => $entry->id,
                'description' => $line->description,
                'quantity' => '2.00',
                'unit_price' => '62.75',
                'service_date' => $serviceDate->toDateString(),
            ]],
        ])['claim'];
        $claim->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'gl_posting_status' => 'queued',
        ])->saveQuietly();

        $job = new PostFundingClaimJournalJob($claim->id);
        $job->handle(app(FundingClaimJournalService::class));
        $job->handle(app(FundingClaimJournalService::class));
        $claim->refresh();

        $this->assertNotNull($claim->journal_id);
        $this->assertNotNull($claim->gl_posted_at);
        $this->assertSame('posted', $claim->gl_posting_status);
        $this->assertSame(1, $claim->gl_posting_attempts);

        $journal = FinJournal::with('lines.account')->findOrFail($claim->journal_id);

        $this->assertSame('posted', $journal->status);
        $this->assertSame('billing', $journal->type);
        $this->assertSame('funding_claim', $journal->source_type);
        $this->assertSame($claim->id, $journal->source_id);
        $this->assertSame('125.50', (string) $journal->total_amount);
        $this->assertCount(2, $journal->lines);

        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1100'
                && (string) $line->debit === '125.50'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '4030'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '125.50'
        ));

        $this->assertSame(1, FinJournal::where('source_type', 'funding_claim')
            ->where('source_id', $claim->id)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'funding.claim.gl.posted',
            'auditable_id' => $claim->id,
            'user_id' => $actor->id,
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', 'funding.claim.gl.posted')
                ->where('auditable_id', $claim->id)
                ->count(),
        );
    }
}
