<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits implicitly. Reconcile every pre-existing claim
        // before adding columns or unique constraints so a blocked migration
        // leaves the old schema wholly intact and reports actionable counts.
        $legacy = $this->preflightLegacyBindings();

        Schema::table('funding_claims', function (Blueprint $table): void {
            $table->foreignId('site_id')
                ->nullable()
                ->after('client_id')
                ->constrained('sites', 'id', 'fund_claims_site_fk')
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->after('claim_reference')
                ->constrained('users', 'id', 'fund_claims_created_by_fk')
                ->restrictOnDelete();
            $table->uuid('creation_request_uuid')->nullable()->after('created_by');
            $table->char('creation_request_hash', 64)->nullable()->after('creation_request_uuid');
            $table->char('provenance_digest', 64)->nullable()->after('creation_request_hash');
            $table->string('integrity_state', 32)->default('unverified')->after('provenance_digest');
            $table->string('integrity_message')->nullable()->after('integrity_state');
            $table->string('gl_posting_status', 24)->default('not_requested')->after('gl_posted_at');
            $table->foreignId('reversal_journal_id')
                ->nullable()
                ->after('gl_posting_status')
                ->constrained('fin_journals', 'id', 'fund_claims_reversal_journal_fk')
                ->restrictOnDelete();
            $table->timestamp('gl_reversed_at')->nullable()->after('reversal_journal_id');
            $table->text('gl_reversal_reason')->nullable()->after('gl_reversed_at');
            $table->unsignedInteger('gl_posting_attempts')->default(0)->after('gl_reversal_reason');
            $table->timestamp('gl_posting_attempted_at')->nullable()->after('gl_posting_attempts');
            $table->text('gl_posting_error')->nullable()->after('gl_posting_attempted_at');

            $table->unique('creation_request_uuid', 'fund_claims_create_req_uq');
            $table->unique('provenance_digest', 'fund_claims_provenance_uq');
            $table->index(['site_id', 'status'], 'fund_claims_site_status_idx');
            $table->index(['gl_posting_status', 'status'], 'fund_claims_gl_state_idx');
        });

        Schema::table('funding_claim_items', function (Blueprint $table): void {
            $table->foreignId('billing_entry_id')
                ->nullable()
                ->after('funding_claim_id')
                ->constrained('billing_entries', 'id', 'fund_claim_items_billing_fk')
                ->restrictOnDelete();
            $table->char('delivery_digest', 64)->nullable()->after('funding_contract_reference');
        });

        foreach ($legacy['bindings'] as $binding) {
            $reserved = DB::table('billing_entries')
                ->where('id', $binding['billing_entry_id'])
                ->whereIn('status', ['pending', 'approved'])
                ->update(['status' => 'claimed']);
            if ($reserved !== 1) {
                throw new RuntimeException(
                    'FUND-BIND-01 legacy reservation changed after preflight; rerun 000140 during a write-fenced deployment.'
                );
            }

            DB::table('funding_claim_items')
                ->where('id', $binding['item_id'])
                ->update([
                    'billing_entry_id' => $binding['billing_entry_id'],
                    'delivery_digest' => $binding['delivery_digest'],
                ]);
        }

        if ($legacy['claim_ids'] !== []) {
            DB::table('funding_claims')
                ->whereIn('id', $legacy['claim_ids'])
                ->update([
                    'site_id' => DB::raw('(SELECT clients.site_id FROM clients WHERE clients.id = funding_claims.client_id)'),
                    'integrity_state' => 'legacy_bound_read_only',
                    'integrity_message' => 'Legacy delivery use was reserved during 000140; finance review is required before any further workflow action.',
                ]);
            DB::table('funding_claims')
                ->whereIn('id', $legacy['claim_ids'])
                ->whereNotNull('journal_id')
                ->update(['gl_posting_status' => 'posted']);
        }

        Schema::table('funding_claim_items', function (Blueprint $table): void {
            $table->unique('billing_entry_id', 'fund_claim_items_billing_uq');
            $table->unique('delivery_digest', 'fund_claim_items_delivery_uq');
        });

        Schema::table('fin_invoice_lines', function (Blueprint $table): void {
            $table->unique('billing_entry_id', 'fin_invoice_lines_billing_entry_uq');
        });

        Schema::table('billing_entries', function (Blueprint $table): void {
            $table->unique(
                ['timesheet_id', 'client_id'],
                'billing_entries_delivery_uq',
            );
            $table->index(
                ['service_agreement_id', 'client_id', 'status'],
                'billing_entries_claim_source_idx',
            );
        });

        $permissionIds = collect([
            [
                'key' => 'funding.viewAllSites',
                'description' => 'View authorised funding records across all active Sites',
            ],
            [
                'key' => 'funding.claims.retryPosting',
                'description' => 'Retry failed Funding Claim journal posting',
            ],
        ])->map(fn (array $definition): int => Permission::query()->updateOrCreate(
            ['key' => $definition['key']],
            [
                'description' => $definition['description'],
                'group' => 'funding',
                'module' => 'Finance',
            ],
        )->id);
        Role::query()
            ->whereIn('name', ['admin', 'finance'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds));
    }

    public function down(): void
    {
        $permissionIds = Permission::query()
            ->whereIn('key', ['funding.viewAllSites', 'funding.claims.retryPosting'])
            ->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            Permission::query()->whereIn('id', $permissionIds)->delete();
        }

        DB::table('billing_entries')
            ->where('status', 'claimed')
            ->whereIn(
                'id',
                DB::table('funding_claim_items')->whereNotNull('billing_entry_id')->select('billing_entry_id'),
            )
            ->update(['status' => 'pending']);

        Schema::table('fin_invoice_lines', function (Blueprint $table): void {
            $table->dropUnique('fin_invoice_lines_billing_entry_uq');
        });

        Schema::table('billing_entries', function (Blueprint $table): void {
            $table->dropUnique('billing_entries_delivery_uq');
            $table->dropIndex('billing_entries_claim_source_idx');
        });

        Schema::table('funding_claim_items', function (Blueprint $table): void {
            $table->dropForeign('fund_claim_items_billing_fk');
            $table->dropUnique('fund_claim_items_billing_uq');
            $table->dropUnique('fund_claim_items_delivery_uq');
            $table->dropColumn(['billing_entry_id', 'delivery_digest']);
        });

        Schema::table('funding_claims', function (Blueprint $table): void {
            $table->dropForeign('fund_claims_site_fk');
            $table->dropForeign('fund_claims_created_by_fk');
            $table->dropForeign('fund_claims_reversal_journal_fk');
            $table->dropUnique('fund_claims_create_req_uq');
            $table->dropUnique('fund_claims_provenance_uq');
            $table->dropIndex('fund_claims_site_status_idx');
            $table->dropIndex('fund_claims_gl_state_idx');
            $table->dropColumn([
                'site_id',
                'created_by',
                'creation_request_uuid',
                'creation_request_hash',
                'provenance_digest',
                'integrity_state',
                'integrity_message',
                'gl_posting_status',
                'reversal_journal_id',
                'gl_reversed_at',
                'gl_reversal_reason',
                'gl_posting_attempts',
                'gl_posting_attempted_at',
                'gl_posting_error',
            ]);
        });
    }

    /**
     * @return array{claim_ids: array<int, int>, bindings: array<int, array{item_id:int,billing_entry_id:int,delivery_digest:string}>}
     */
    private function preflightLegacyBindings(): array
    {
        $billingSourceDuplicates = DB::table('billing_entries')
            ->whereNotNull('timesheet_id')
            ->select(['timesheet_id', 'client_id'])
            ->groupBy(['timesheet_id', 'client_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $invoiceDeliveryDuplicates = DB::table('fin_invoice_lines')
            ->whereNotNull('billing_entry_id')
            ->select('billing_entry_id')
            ->groupBy('billing_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $claimIds = DB::table('funding_claims')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($claimIds === [] && $billingSourceDuplicates === 0 && $invoiceDeliveryDuplicates === 0) {
            return ['claim_ids' => [], 'bindings' => []];
        }

        $counts = [
            'missing' => 0,
            'ambiguous' => 0,
            'mismatched' => 0,
            'already_invoiced' => 0,
            'duplicate_use' => 0,
            'billing_source_duplicates' => $billingSourceDuplicates,
            'invoice_delivery_duplicates' => $invoiceDeliveryDuplicates,
        ];
        $items = DB::table('funding_claim_items as item')
            ->join('funding_claims as claim', 'claim.id', '=', 'item.funding_claim_id')
            ->whereIn('claim.id', $claimIds)
            ->orderBy('item.id')
            ->get([
                'item.id',
                'item.funding_claim_id',
                'item.service_agreement_line_item_id',
                'item.shift_id',
                'item.timesheet_id',
                'item.service_date',
                'item.quantity',
                'item.unit_price',
                'item.total_amount',
                'claim.service_agreement_id',
                'claim.client_id',
            ]);
        $claimsWithItems = $items->pluck('funding_claim_id')->map(fn ($id): int => (int) $id)->unique();
        $counts['missing'] += count(array_diff($claimIds, $claimsWithItems->all()));

        $seenEntryIds = [];
        $bindings = [];
        foreach ($items as $item) {
            if (! $item->service_agreement_line_item_id || ! $item->shift_id || ! $item->timesheet_id) {
                $counts['missing']++;

                continue;
            }

            $agreement = DB::table('service_agreements')
                ->where('id', $item->service_agreement_id)
                ->first(['client_id', 'starts_at', 'ends_at']);
            $clientSiteId = DB::table('clients')
                ->where('id', $item->client_id)
                ->value('site_id');
            $siteIsCurrent = $clientSiteId && DB::table('sites')
                ->where('id', $clientSiteId)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->whereNull('deleted_at')
                ->exists();
            $lineAgreementId = DB::table('service_agreement_line_items')
                ->where('id', $item->service_agreement_line_item_id)
                ->value('service_agreement_id');
            $timesheet = DB::table('timesheets')
                ->where('id', $item->timesheet_id)
                ->first([
                    'shift_id',
                    'client_id',
                    'user_id',
                    'site_id',
                    'shift_site_id',
                    'work_date',
                    'starts_at',
                    'ends_at',
                    'break_minutes',
                    'status',
                    'approved_at',
                    'approved_by',
                    'client_name_snapshot',
                    'staff_name_snapshot',
                    'shift_type_snapshot',
                ]);
            $shift = DB::table('shifts')
                ->where('id', $item->shift_id)
                ->first(['site_id', 'user_id', 'starts_at']);
            if (
                ! $agreement
                || (int) $agreement->client_id !== (int) $item->client_id
                || ! $siteIsCurrent
                || ! $lineAgreementId
                || (int) $lineAgreementId !== (int) $item->service_agreement_id
                || ! $timesheet
                || (int) $timesheet->shift_id !== (int) $item->shift_id
                || (string) $timesheet->work_date !== (string) $item->service_date
                || ! $shift
                || (int) $shift->site_id !== (int) $clientSiteId
                || (int) $shift->user_id !== (int) $timesheet->user_id
                || substr((string) $shift->starts_at, 0, 10) !== (string) $item->service_date
                || ($timesheet->site_id !== null && (int) $timesheet->site_id !== (int) $clientSiteId)
                || ($timesheet->shift_site_id !== null && (int) $timesheet->shift_site_id !== (int) $clientSiteId)
                || $timesheet->status !== 'approved'
                || ! $timesheet->approved_at
                || ! $timesheet->approved_by
                || trim((string) $timesheet->client_name_snapshot) === ''
                || trim((string) $timesheet->staff_name_snapshot) === ''
                || trim((string) $timesheet->shift_type_snapshot) === ''
                || ($agreement->starts_at !== null && (string) $item->service_date < substr((string) $agreement->starts_at, 0, 10))
                || ($agreement->ends_at !== null && (string) $item->service_date > substr((string) $agreement->ends_at, 0, 10))
            ) {
                $counts['mismatched']++;

                continue;
            }

            $allocationRows = DB::table('timesheet_client_allocations')
                ->where('timesheet_id', $item->timesheet_id)
                ->orderBy('id')
                ->get(['client_id', 'hours']);
            $allocationHours = null;
            if ($allocationRows->isNotEmpty()) {
                $matchingAllocations = $allocationRows
                    ->filter(fn ($allocation): bool => (int) $allocation->client_id === (int) $item->client_id)
                    ->values();
                if ($matchingAllocations->count() === 1) {
                    $allocationHours = bcadd((string) $matchingAllocations->first()->hours, '0', 2);
                }
            } elseif (
                (int) $timesheet->client_id === (int) $item->client_id
                && $timesheet->starts_at
                && $timesheet->ends_at
            ) {
                $startsAt = strtotime((string) $timesheet->starts_at);
                $endsAt = strtotime((string) $timesheet->ends_at);
                if ($startsAt !== false && $endsAt !== false && $endsAt >= $startsAt) {
                    $minutes = max(0, intdiv($endsAt - $startsAt, 60) - (int) $timesheet->break_minutes);
                    $hundredths = intdiv(($minutes * 100) + 30, 60);
                    $allocationHours = sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
                }
            }
            if ($allocationHours === null || bccomp($allocationHours, (string) $item->quantity, 2) !== 0) {
                $counts['mismatched']++;

                continue;
            }

            $structuralCandidates = DB::table('billing_entries')
                ->where('service_agreement_id', $item->service_agreement_id)
                ->where('client_id', $item->client_id)
                ->where('line_item_id', $item->service_agreement_line_item_id)
                ->where('shift_id', $item->shift_id)
                ->where('timesheet_id', $item->timesheet_id)
                ->whereDate('service_date', (string) $item->service_date)
                ->orderBy('id')
                ->get(['id', 'site_id', 'staff_id', 'status', 'hours', 'rate', 'amount']);
            if ($structuralCandidates->isEmpty()) {
                $counts['missing']++;

                continue;
            }

            $candidates = $structuralCandidates->filter(fn ($entry): bool => bccomp((string) $entry->hours, (string) $item->quantity, 2) === 0
                && bccomp((string) $entry->rate, (string) $item->unit_price, 2) === 0
                && bccomp((string) $entry->amount, (string) $item->total_amount, 2) === 0
            )->values();
            if ($candidates->isEmpty()) {
                $counts['mismatched']++;

                continue;
            }
            if ($candidates->count() !== 1) {
                $counts['ambiguous']++;

                continue;
            }

            $entry = $candidates->first();
            if (
                (int) $entry->site_id !== (int) $clientSiteId
                || (int) $entry->staff_id !== (int) $timesheet->user_id
            ) {
                $counts['mismatched']++;

                continue;
            }
            if (
                in_array($entry->status, ['invoiced', 'paid'], true)
                || DB::table('fin_invoice_lines')->where('billing_entry_id', $entry->id)->exists()
            ) {
                $counts['already_invoiced']++;

                continue;
            }
            if (! in_array($entry->status, ['pending', 'approved'], true)) {
                $counts['mismatched']++;

                continue;
            }
            if (isset($seenEntryIds[(int) $entry->id])) {
                $counts['duplicate_use']++;

                continue;
            }
            $seenEntryIds[(int) $entry->id] = true;

            $digest = hash('sha256', json_encode([
                'legacy_funding_claim_item_id' => (int) $item->id,
                'billing_entry_id' => (int) $entry->id,
                'service_agreement_id' => (int) $item->service_agreement_id,
                'client_id' => (int) $item->client_id,
                'service_agreement_line_item_id' => (int) $item->service_agreement_line_item_id,
                'shift_id' => (int) $item->shift_id,
                'timesheet_id' => (int) $item->timesheet_id,
                'service_date' => (string) $item->service_date,
                'quantity' => bcadd((string) $item->quantity, '0', 2),
                'unit_price' => bcadd((string) $item->unit_price, '0', 2),
                'total_amount' => bcadd((string) $item->total_amount, '0', 2),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $bindings[] = [
                'item_id' => (int) $item->id,
                'billing_entry_id' => (int) $entry->id,
                'delivery_digest' => $digest,
            ];
        }

        if (array_sum($counts) > 0) {
            throw new RuntimeException(sprintf(
                'FUND-BIND-01 legacy preflight blocked before DDL: missing=%d, ambiguous=%d, mismatched=%d, already_invoiced=%d, duplicate_use=%d, billing_source_duplicates=%d, invoice_delivery_duplicates=%d. Reconcile the reported legacy Funding Claim, invoice-line and Billing Entry rows and rerun 000140.',
                $counts['missing'],
                $counts['ambiguous'],
                $counts['mismatched'],
                $counts['already_invoiced'],
                $counts['duplicate_use'],
                $counts['billing_source_duplicates'],
                $counts['invoice_delivery_duplicates'],
            ));
        }

        return ['claim_ids' => $claimIds, 'bindings' => $bindings];
    }
};
