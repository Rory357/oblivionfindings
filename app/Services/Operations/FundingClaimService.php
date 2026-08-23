<?php

namespace App\Services\Operations;

use App\Domain\Finance\Jobs\PostFundingClaimJournalJob;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\FundingClaimItem;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementLineItem;
use App\Models\ServiceAgreementRate;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FundingClaimService
{
    private const SITE_BYPASS_PERMISSIONS = ['funding.viewAllSites'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return Collection<int, BillingEntry> */
    public function eligibleDeliveries(User $actor): Collection
    {
        return BillingEntry::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('site_id')
            ->whereNotNull('timesheet_id')
            ->whereNotNull('shift_id')
            ->whereNotNull('service_agreement_id')
            ->whereNotNull('line_item_id')
            ->whereHas('client', fn (Builder $client) => $this->siteAccess->applyClientScope(
                $client->whereColumn('clients.site_id', 'billing_entries.site_id'),
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->whereHas('serviceAgreement', fn (Builder $agreement) => $agreement
                ->whereColumn('service_agreements.client_id', 'billing_entries.client_id')
                ->where('status', 'active'))
            ->whereHas('lineItem', fn (Builder $lineItem) => $lineItem
                ->whereColumn('service_agreement_line_items.service_agreement_id', 'billing_entries.service_agreement_id'))
            ->whereHas('timesheet', fn (Builder $timesheet) => $timesheet
                ->where('status', 'approved')
                ->whereNotNull('approved_at')
                ->whereNotNull('approved_by')
                ->whereNotNull('client_name_snapshot')
                ->whereNotNull('staff_name_snapshot')
                ->whereNotNull('shift_type_snapshot')
                ->whereColumn('timesheets.shift_id', 'billing_entries.shift_id')
                ->whereColumn('timesheets.user_id', 'billing_entries.staff_id')
                ->where(function (Builder $site): void {
                    $site->whereNull('timesheets.site_id')
                        ->orWhereColumn('timesheets.site_id', 'billing_entries.site_id');
                })
                ->where(function (Builder $site): void {
                    $site->whereNull('timesheets.shift_site_id')
                        ->orWhereColumn('timesheets.shift_site_id', 'billing_entries.site_id');
                })
                ->where(function (Builder $site): void {
                    $site->whereNotNull('timesheets.site_id')
                        ->orWhereNotNull('timesheets.shift_site_id');
                }))
            ->whereHas('shift', fn (Builder $shift) => $shift
                ->whereColumn('shifts.user_id', 'billing_entries.staff_id')
                ->whereColumn('shifts.site_id', 'billing_entries.site_id'))
            ->with([
                'client:id,first_name,last_name,site_id',
                'lineItem:id,service_agreement_id,description,unit_price,funding_contract_reference',
                'serviceAgreement:id,client_id,title,reference_number',
                'timesheet:id,shift_id,client_id,site_id,shift_site_id,work_date,status,approved_at,starts_at,ends_at,break_minutes',
            ])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array{claim: FundingClaim, replayed: bool} */
    public function createDraft(User $actor, array $data): array
    {
        $data['period_start'] = CarbonImmutable::parse($data['period_start'])->toDateString();
        $data['period_end'] = CarbonImmutable::parse($data['period_end'])->toDateString();
        $requestHash = $this->requestHash($data);
        $requestUuid = filled($data['client_request_uuid'] ?? null)
            ? (string) $data['client_request_uuid']
            : $this->deterministicRequestUuid($actor, $requestHash);

        return DB::transaction(function () use ($actor, $data, $requestHash, $requestUuid): array {
            $agreement = $this->agreementFor($actor, (int) $data['service_agreement_id'], true);

            $existing = FundingClaim::query()
                ->where('creation_request_uuid', $requestUuid)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    (int) $existing->created_by !== (int) $actor->id
                    || (int) $existing->service_agreement_id !== (int) $agreement->id
                    || ! hash_equals((string) $existing->creation_request_hash, $requestHash)
                ) {
                    throw ValidationException::withMessages([
                        'client_request_uuid' => 'This submission identifier has already been used for another funding claim.',
                    ]);
                }

                $this->assertClaimIntegrity($existing);

                return ['claim' => $existing, 'replayed' => true];
            }

            abort_unless((int) $agreement->client_id === (int) $data['client_id'], 404);
            abort_unless($agreement->client?->site_id, 404);
            if ($agreement->status !== 'active') {
                throw ValidationException::withMessages([
                    'service_agreement_id' => 'The selected Service Agreement is not active.',
                ]);
            }

            $preparedItems = $this->canonicalItems(
                $agreement,
                (string) $data['period_start'],
                (string) $data['period_end'],
                $data['items'],
            );
            $totalAmount = collect($preparedItems)
                ->reduce(fn (string $total, array $item): string => bcadd($total, $item['total_amount'], 2), '0.00');
            $provenanceDigest = $this->claimDigest(
                $agreement,
                (int) $agreement->client->site_id,
                (string) $data['period_start'],
                (string) $data['period_end'],
                $totalAmount,
                $preparedItems,
            );

            $claim = FundingClaim::query()->create([
                'service_agreement_id' => $agreement->id,
                'client_id' => $agreement->client_id,
                'site_id' => $agreement->client->site_id,
                'claim_reference' => $data['claim_reference'] ?? null,
                'created_by' => $actor->id,
                'creation_request_uuid' => $requestUuid,
                'creation_request_hash' => $requestHash,
                'provenance_digest' => $provenanceDigest,
                'integrity_state' => 'verified',
                'integrity_message' => null,
                'status' => 'draft',
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'total_amount' => $totalAmount,
                'gl_posting_status' => 'not_requested',
            ]);

            foreach ($preparedItems as $prepared) {
                FundingClaimItem::query()->create([
                    'funding_claim_id' => $claim->id,
                    'billing_entry_id' => $prepared['billing_entry_id'],
                    'service_agreement_line_item_id' => $prepared['service_agreement_line_item_id'],
                    'shift_id' => $prepared['shift_id'],
                    'timesheet_id' => $prepared['timesheet_id'],
                    'description' => $prepared['description'],
                    'quantity' => $prepared['quantity'],
                    'unit_price' => $prepared['unit_price'],
                    'total_amount' => $prepared['total_amount'],
                    'service_date' => $prepared['service_date'],
                    'funding_contract_reference' => $prepared['funding_contract_reference'],
                    'delivery_digest' => $prepared['delivery_digest'],
                ]);

                $reserved = BillingEntry::query()
                    ->whereKey($prepared['billing_entry_id'])
                    ->whereIn('status', ['pending', 'approved'])
                    ->update(['status' => 'claimed']);
                abort_unless($reserved === 1, 409, 'The delivered-support record was monetised concurrently.');
            }

            AuditLogger::logOrFail('funding.claim.create', $claim, [
                'actor_id' => $actor->id,
                'client_id' => $claim->client_id,
                'site_id' => $claim->site_id,
                'service_agreement_id' => $claim->service_agreement_id,
                'creation_request_uuid' => $claim->creation_request_uuid,
                'provenance_digest' => $claim->provenance_digest,
                'delivery_count' => count($preparedItems),
                'total_amount' => $totalAmount,
            ]);

            return ['claim' => $claim, 'replayed' => false];
        }, 3);
    }

    /** @return array{claim: FundingClaim, replayed: bool, posting_failed: bool} */
    public function submit(User $actor, int $claimId): array
    {
        $result = DB::transaction(function () use ($actor, $claimId): array {
            $claim = $this->claimFor($actor, $claimId, true);
            $this->assertClaimIntegrity($claim);
            if (in_array($claim->status, ['submitted', 'approved'], true)) {
                return ['claim' => $claim, 'replayed' => true, 'dispatch' => false];
            }
            abort_unless($claim->status === 'draft', 409, 'Only a draft Funding Claim can be submitted.');
            abort_unless(
                $claim->serviceAgreement->status === 'active',
                409,
                'The Funding Claim Service Agreement is no longer active.',
            );

            $claim->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'gl_posting_status' => 'queued',
                'gl_posting_error' => null,
            ])->saveQuietly();

            AuditLogger::logOrFail('funding.claim.submit', $claim, [
                'actor_id' => $actor->id,
                'client_id' => $claim->client_id,
                'site_id' => $claim->site_id,
                'service_agreement_id' => $claim->service_agreement_id,
                'provenance_digest' => $claim->provenance_digest,
            ]);

            return ['claim' => $claim, 'replayed' => false, 'dispatch' => true];
        }, 3);

        $postingFailed = $result['dispatch'] && ! $this->dispatchPosting($result['claim']);

        return [
            'claim' => $result['claim']->fresh(),
            'replayed' => $result['replayed'],
            'posting_failed' => $postingFailed,
        ];
    }

    public function approve(User $actor, int $claimId): FundingClaim
    {
        return DB::transaction(function () use ($actor, $claimId): FundingClaim {
            $claim = $this->claimFor($actor, $claimId, true);
            $this->assertClaimIntegrity($claim);
            if ($claim->status === 'approved') {
                return $claim;
            }
            abort_unless($claim->status === 'submitted', 409, 'Only a submitted Funding Claim can be approved.');
            abort_unless(
                $claim->journal_id !== null && $claim->gl_posting_status === 'posted',
                409,
                'The Funding Claim journal must post successfully before approval.',
            );

            $claim->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ])->saveQuietly();

            AuditLogger::logOrFail('funding.claim.approve', $claim, [
                'actor_id' => $actor->id,
                'client_id' => $claim->client_id,
                'site_id' => $claim->site_id,
                'service_agreement_id' => $claim->service_agreement_id,
                'journal_id' => $claim->journal_id,
                'provenance_digest' => $claim->provenance_digest,
            ]);

            return $claim;
        }, 3);
    }

    /** @return array{claim: FundingClaim, posting_failed: bool} */
    public function retryPosting(User $actor, int $claimId): array
    {
        $claim = DB::transaction(function () use ($actor, $claimId): FundingClaim {
            $claim = $this->claimFor($actor, $claimId, true);
            abort_unless(in_array($claim->status, ['submitted', 'approved'], true), 409);
            abort_unless($claim->journal_id === null && $claim->gl_posting_status === 'failed', 409);

            $this->assertClaimIntegrity($claim);
            $claim->forceFill([
                'gl_posting_status' => 'queued',
                'gl_posting_error' => null,
            ])->saveQuietly();

            AuditLogger::logOrFail('funding.claim.gl.retry', $claim, [
                'actor_id' => $actor->id,
                'client_id' => $claim->client_id,
                'site_id' => $claim->site_id,
                'provenance_digest' => $claim->provenance_digest,
            ]);

            return $claim;
        }, 3);

        return [
            'claim' => $claim->fresh(),
            'posting_failed' => ! $this->dispatchPosting($claim),
        ];
    }

    public function claimFor(User $actor, int $claimId, bool $lock = false): FundingClaim
    {
        $query = FundingClaim::query()
            ->whereKey($claimId)
            ->whereHas('client', fn (Builder $client) => $this->siteAccess->applyClientScope(
                $client,
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->whereHas('serviceAgreement', fn (Builder $agreement) => $agreement
                ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
            ->where(function (Builder $scope): void {
                $scope->whereNull('site_id')
                    ->orWhereHas('client', fn (Builder $client) => $client
                        ->whereColumn('clients.site_id', 'funding_claims.site_id'));
            });

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function agreementFor(User $actor, int $agreementId, bool $lock): ServiceAgreement
    {
        $query = ServiceAgreement::query()
            ->whereKey($agreementId)
            ->whereHas('client', fn (Builder $client) => $this->siteAccess->applyClientScope(
                $client,
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->with('client:id,site_id');

        $agreement = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if (! $lock) {
            return $agreement;
        }

        $client = Client::query()->whereKey($agreement->client_id)->lockForUpdate()->firstOrFail();
        abort_unless(
            (int) $client->site_id > 0
                && in_array(
                    (int) $client->site_id,
                    $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS),
                    true,
                ),
            404,
        );
        Site::query()
            ->whereKey($client->site_id)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->firstOrFail();
        $agreement->setRelation('client', $client);

        return $agreement;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requestedItems
     * @return array<int, array<string, mixed>>
     */
    private function canonicalItems(
        ServiceAgreement $agreement,
        string $periodStart,
        string $periodEnd,
        array $requestedItems,
        bool $expectClaimed = false,
    ): array {
        $resolvedItems = collect($requestedItems);
        if ($resolvedItems->contains(fn (array $item): bool => (int) ($item['billing_entry_id'] ?? 0) <= 0)) {
            throw ValidationException::withMessages([
                'items' => 'Select the exact delivered-support record for every Funding Claim item.',
            ]);
        }

        $requested = $resolvedItems->keyBy(fn (array $item): int => (int) $item['billing_entry_id']);
        $entryIds = $requested->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        if ($entryIds->isEmpty() || $entryIds->count() !== $resolvedItems->count()) {
            throw ValidationException::withMessages([
                'items' => 'Each delivered-support record may appear only once in a Funding Claim.',
            ]);
        }

        $entries = BillingEntry::query()
            ->whereIn('id', $entryIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        abort_unless($entries->count() === $entryIds->count(), 404);

        $timesheets = Timesheet::query()
            ->whereIn('id', $entries->pluck('timesheet_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $shifts = Shift::query()
            ->whereIn('id', $entries->pluck('shift_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $lineItems = ServiceAgreementLineItem::query()
            ->whereIn('id', $entries->pluck('line_item_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $canonical = [];
        foreach ($entries as $entry) {
            $input = $requested->get((int) $entry->id);
            $timesheet = $timesheets->get((int) $entry->timesheet_id);
            $shift = $shifts->get((int) $entry->shift_id);
            $lineItem = $lineItems->get((int) $entry->line_item_id);
            abort_unless($timesheet && $shift && $lineItem, 404);
            $siteIds = collect([
                $agreement->client->site_id,
                $entry->site_id,
                $timesheet->site_id,
                $timesheet->shift_site_id,
                $shift->site_id,
            ])->filter(fn ($siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                ->map(fn ($siteId): int => (int) $siteId)
                ->unique()
                ->values();
            abort_unless(
                (int) $entry->service_agreement_id === (int) $agreement->id
                    && (int) $entry->client_id === (int) $agreement->client_id
                    && (int) $lineItem->service_agreement_id === (int) $agreement->id
                    && (int) $timesheet->shift_id === (int) $entry->shift_id
                    && (int) $entry->staff_id === (int) $timesheet->user_id
                    && (int) $shift->user_id === (int) $timesheet->user_id
                    && $shift->starts_at?->toDateString() === $entry->service_date->toDateString()
                    && $siteIds->count() === 1
                    && (int) $siteIds->first() === (int) $entry->site_id,
                404,
            );
            if ($expectClaimed && $entry->status !== 'claimed') {
                abort(409, 'Funding Claim delivery-use provenance is no longer reserved.');
            }
            if (! $expectClaimed && ! in_array($entry->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'items' => 'One or more delivered-support records have already been monetised.',
                ]);
            }
            if (! $expectClaimed && FundingClaimItem::query()->where('billing_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more delivered-support records are already assigned to a Funding Claim.',
                ]);
            }
            if (DB::table('fin_invoice_lines')->where('billing_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more delivered-support records are already assigned to an invoice.',
                ]);
            }
            if (
                $timesheet->status !== 'approved'
                || ! $timesheet->approved_at
                || ! $timesheet->approved_by
                || ! $timesheet->is_snapshot_complete
            ) {
                throw ValidationException::withMessages([
                    'items' => 'Funding Claims require approved delivered-support evidence with complete snapshots.',
                ]);
            }

            $serviceDate = $entry->service_date->toDateString();
            abort_unless($timesheet->work_date?->toDateString() === $serviceDate, 404);
            if ($serviceDate < $periodStart || $serviceDate > $periodEnd) {
                throw ValidationException::withMessages([
                    'items' => 'Every delivered-support date must fall inside the Funding Claim period.',
                ]);
            }
            if (
                ($agreement->starts_at && $serviceDate < $agreement->starts_at->toDateString())
                || ($agreement->ends_at && $serviceDate > $agreement->ends_at->toDateString())
            ) {
                throw ValidationException::withMessages([
                    'items' => 'Delivered support must fall inside the active Service Agreement dates.',
                ]);
            }

            $allocation = $timesheet->effectiveClientAllocations()
                ->first(fn (array $row): bool => (int) $row['client_id'] === (int) $entry->client_id);
            abort_unless($allocation, 404);
            $quantity = $this->decimal($allocation['hours']);
            $rate = $this->canonicalRate($agreement, $lineItem, $entry->rate_type, $serviceDate);
            $amount = bcmul($quantity, $rate, 2);
            if (
                bccomp($quantity, (string) $entry->hours, 2) !== 0
                || bccomp($rate, (string) $entry->rate, 2) !== 0
                || bccomp($amount, (string) $entry->amount, 2) !== 0
            ) {
                throw ValidationException::withMessages([
                    'items' => 'A delivered-support amount no longer matches its approved quantity and agreement rate.',
                ]);
            }

            $description = trim((string) $lineItem->description);
            $contractReference = $lineItem->funding_contract_reference ?: $agreement->funding_reference;
            $this->assertRequestedSnapshot(
                $input,
                $description,
                $quantity,
                $rate,
                $serviceDate,
                $contractReference,
                (int) $lineItem->id,
                (int) $shift->id,
                (int) $timesheet->id,
            );

            $delivery = [
                'billing_entry_id' => (int) $entry->id,
                'service_agreement_line_item_id' => (int) $lineItem->id,
                'shift_id' => (int) $shift->id,
                'timesheet_id' => (int) $timesheet->id,
                'timesheet_allocation_id' => $allocation['id'] ? (int) $allocation['id'] : null,
                'timesheet_allocation_method' => (string) $allocation['allocation_method'],
                'client_id' => (int) $entry->client_id,
                'site_id' => (int) $entry->site_id,
                'rate_type' => (string) $entry->rate_type,
                'service_date' => $serviceDate,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $rate,
                'total_amount' => $amount,
                'funding_contract_reference' => $contractReference,
                'timesheet_approved_at' => $timesheet->approved_at?->toIso8601String(),
                'timesheet_approved_by' => (int) $timesheet->approved_by,
            ];
            $delivery['delivery_digest'] = hash('sha256', json_encode(
                $delivery,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            $canonical[] = $delivery;
        }

        return $canonical;
    }

    public function assertClaimIntegrity(FundingClaim $claim): void
    {
        $claim->loadMissing(['serviceAgreement.client', 'items']);
        abort_unless(
            $claim->creation_request_uuid
                && $claim->creation_request_hash
                && $claim->provenance_digest
                && $claim->integrity_state === 'verified'
                && (int) $claim->created_by > 0
                && (int) $claim->site_id > 0
                && $claim->items->isNotEmpty()
                && $claim->items->every(fn (FundingClaimItem $item): bool => (int) $item->billing_entry_id > 0
                    && filled($item->delivery_digest)),
            409,
            'This legacy Funding Claim has no canonical delivery provenance and requires finance review.',
        );
        $agreement = $claim->serviceAgreement;
        abort_unless(
            $agreement
                && (int) $agreement->client_id === (int) $claim->client_id
                && (int) $agreement->client?->site_id === (int) $claim->site_id,
            409,
            'Funding Claim ownership provenance is invalid.',
        );

        $requested = $claim->items->map(fn (FundingClaimItem $item): array => [
            'billing_entry_id' => $item->billing_entry_id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'service_date' => $item->service_date->toDateString(),
            'funding_contract_reference' => $item->funding_contract_reference,
        ])->all();

        $canonical = $this->canonicalItems(
            $agreement,
            $claim->period_start->toDateString(),
            $claim->period_end->toDateString(),
            $requested,
            true,
        );

        $byBillingEntry = $claim->items->keyBy('billing_entry_id');
        foreach ($canonical as $delivery) {
            $item = $byBillingEntry->get($delivery['billing_entry_id']);
            abort_unless(
                $item && hash_equals((string) $item->delivery_digest, $delivery['delivery_digest']),
                409,
                'Funding Claim delivery provenance has changed.',
            );
        }

        $total = collect($canonical)
            ->reduce(fn (string $sum, array $item): string => bcadd($sum, $item['total_amount'], 2), '0.00');
        $digest = $this->claimDigest(
            $agreement,
            (int) $claim->site_id,
            $claim->period_start->toDateString(),
            $claim->period_end->toDateString(),
            $total,
            $canonical,
        );
        abort_unless(
            bccomp($total, (string) $claim->total_amount, 2) === 0
                && hash_equals((string) $claim->provenance_digest, $digest),
            409,
            'Funding Claim monetisation provenance has changed.',
        );
    }

    private function canonicalRate(
        ServiceAgreement $agreement,
        ServiceAgreementLineItem $lineItem,
        string $rateType,
        string $serviceDate,
    ): string {
        $rateSchedule = ServiceAgreementRate::query()
            ->where('service_agreement_id', $agreement->id)
            ->where('rate_type', $rateType)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
        $rate = $rateSchedule->first(fn (ServiceAgreementRate $candidate): bool => ($candidate->effective_from === null || $candidate->effective_from->toDateString() <= $serviceDate)
            && ($candidate->effective_to === null || $candidate->effective_to->toDateString() >= $serviceDate)
        )?->rate;

        if ($rateSchedule->isNotEmpty() && $rate === null) {
            throw ValidationException::withMessages([
                'items' => 'The delivered support has no agreement rate effective on its service date.',
            ]);
        }

        $canonical = $rate ?? $lineItem->unit_price ?? $agreement->hourly_rate;
        if ($canonical === null || bccomp((string) $canonical, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'items' => 'The delivered support has no positive agreement rate effective on its service date.',
            ]);
        }

        return $this->decimal($canonical);
    }

    private function assertRequestedSnapshot(
        array $input,
        string $description,
        string $quantity,
        string $rate,
        string $serviceDate,
        ?string $contractReference,
        int $lineItemId,
        int $shiftId,
        int $timesheetId,
    ): void {
        $mismatch = (array_key_exists('description', $input) && trim((string) $input['description']) !== $description)
            || (array_key_exists('quantity', $input) && bccomp((string) $input['quantity'], $quantity, 2) !== 0)
            || (array_key_exists('unit_price', $input) && bccomp((string) $input['unit_price'], $rate, 2) !== 0)
            || (array_key_exists('service_date', $input) && (string) $input['service_date'] !== $serviceDate)
            || (array_key_exists('funding_contract_reference', $input)
                && ($input['funding_contract_reference'] ?: null) !== $contractReference)
            || (filled($input['service_agreement_line_item_id'] ?? null)
                && (int) $input['service_agreement_line_item_id'] !== $lineItemId)
            || (filled($input['shift_id'] ?? null) && (int) $input['shift_id'] !== $shiftId)
            || (filled($input['timesheet_id'] ?? null) && (int) $input['timesheet_id'] !== $timesheetId);

        if ($mismatch) {
            throw ValidationException::withMessages([
                'items' => 'Claim descriptions, dates, quantities and rates must match the selected delivered-support evidence.',
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function claimDigest(
        ServiceAgreement $agreement,
        int $siteId,
        string $periodStart,
        string $periodEnd,
        string $totalAmount,
        array $items,
    ): string {
        return hash('sha256', json_encode([
            'service_agreement_id' => (int) $agreement->id,
            'service_agreement_funding_body' => $agreement->funding_body ?: null,
            'service_agreement_funding_reference' => $agreement->funding_reference ?: null,
            'client_id' => (int) $agreement->client_id,
            'site_id' => $siteId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_amount' => $totalAmount,
            'delivery_digests' => collect($items)->pluck('delivery_digest')->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function requestHash(array $data): string
    {
        return hash('sha256', json_encode([
            'service_agreement_id' => (int) $data['service_agreement_id'],
            'client_id' => (int) $data['client_id'],
            'claim_reference' => $data['claim_reference'] ?? null,
            'period_start' => (string) $data['period_start'],
            'period_end' => (string) $data['period_end'],
            'items' => collect($data['items'])
                ->sortBy(fn (array $item): string => implode('|', [
                    (string) ($item['billing_entry_id'] ?? 0),
                    (string) ($item['service_date'] ?? ''),
                    (string) ($item['description'] ?? ''),
                ]))
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function deterministicRequestUuid(User $actor, string $requestHash): string
    {
        $hex = hash('sha256', "funding-claim|{$actor->id}|{$requestHash}");

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            '5'.substr($hex, 13, 3),
            'a'.substr($hex, 17, 3),
            substr($hex, 20, 12),
        ]);
    }

    private function dispatchPosting(FundingClaim $claim): bool
    {
        try {
            PostFundingClaimJournalJob::dispatch((int) $claim->id);

            return true;
        } catch (Throwable $exception) {
            DB::transaction(function () use ($claim, $exception): void {
                $failed = FundingClaim::query()
                    ->whereKey($claim->id)
                    ->whereNull('journal_id')
                    ->lockForUpdate()
                    ->first();
                if (! $failed) {
                    return;
                }

                $failed->forceFill([
                    'gl_posting_status' => 'failed',
                    'gl_posting_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->saveQuietly();

                AuditLogger::logOrFail('funding.claim.gl.dispatch_failed', $failed, [
                    'actor_id' => (int) ($failed->submitted_by ?: $failed->created_by),
                    'client_id' => $failed->client_id,
                    'site_id' => $failed->site_id,
                    'service_agreement_id' => $failed->service_agreement_id,
                    'provenance_digest' => $failed->provenance_digest,
                ]);
            }, 3);

            return false;
        }
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
