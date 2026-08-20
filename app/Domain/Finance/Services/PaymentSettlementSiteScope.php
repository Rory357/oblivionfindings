<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Site boundary for payment settlement and allocation history.
 *
 * Finance capabilities authorize the operation; this resolver authorizes the
 * target. Application-wide settlement remains a separately granted exception.
 */
final class PaymentSettlementSiteScope
{
    public const GLOBAL_VIEW_PERMISSION = 'finance.payments.viewAllSites';

    public const GLOBAL_PERMISSION = 'finance.payments.manageAllSites';

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function assertCanAccessBill(User $actor, FinBill $bill): void
    {
        abort_unless(
            (int) $bill->organization_id === (int) $actor->organization_id
                && $bill->site_id !== null
                && $this->activeSiteExists((int) $bill->site_id)
                && in_array((int) $bill->site_id, $this->accessibleSiteIds($actor), true),
            404,
        );
    }

    public function assertCanAccessInvoice(User $actor, FinInvoice $invoice): void
    {
        $invoice->loadMissing('client:id,site_id');
        $siteId = $invoice->client?->site_id;

        abort_unless(
            (int) $invoice->organization_id === (int) $actor->organization_id
                && $siteId !== null
                && $this->activeSiteExists((int) $siteId)
                && in_array((int) $siteId, $this->accessibleSiteIds($actor), true),
            404,
        );
    }

    public function invoiceSiteId(FinInvoice $invoice): int
    {
        $invoice->loadMissing('client:id,site_id');
        abort_unless($invoice->client?->site_id !== null, 404);

        $siteId = (int) $invoice->client->site_id;
        abort_unless($this->activeSiteExists($siteId), 404);

        return $siteId;
    }

    public function billSiteId(FinBill $bill): int
    {
        abort_unless($bill->site_id !== null, 404);

        $siteId = (int) $bill->site_id;
        abort_unless($this->activeSiteExists($siteId), 404);

        return $siteId;
    }

    public function applyAllocationScope(Builder $query, User $actor): Builder
    {
        if ($actor->canDo(self::GLOBAL_VIEW_PERMISSION)
            || $actor->canDo(self::GLOBAL_PERMISSION)) {
            return $query->where(function (Builder $allocations): void {
                $allocations
                    ->where(function (Builder $traceable): void {
                        $traceable
                            ->where('integrity_state', FinPaymentAllocation::INTEGRITY_TRACEABLE)
                            ->whereHas('site', fn (Builder $sites): Builder => $this->applyActiveSiteScope($sites));
                    })
                    ->orWhere('integrity_state', FinPaymentAllocation::INTEGRITY_REVIEW_REQUIRED);
            });
        }

        $siteIds = $this->accessibleSiteIds($actor);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('integrity_state', FinPaymentAllocation::INTEGRITY_TRACEABLE)
            ->whereIn('site_id', $siteIds);
    }

    public function applyBillScope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->accessibleSiteIds($actor);

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn('site_id'), $siteIds);
    }

    public function applyInvoiceScope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->accessibleSiteIds($actor);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'client',
            fn (Builder $clients): Builder => $clients->whereIn('site_id', $siteIds),
        );
    }

    public function applyPaymentMatchScope(Builder $query, User $actor): Builder
    {
        // Match history is a read projection, so its explicit global bypass is
        // the same active-Site view authority used by payment-run history.
        // Suggest/confirm/reject continue to call the manage-only access path.
        $siteIds = $this->paymentRunSiteIds($actor, false);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn('site_id'), $siteIds);
    }

    public function applyPaymentRunScope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->paymentRunSiteIds($actor, false);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('items')
            ->whereDoesntHave('items', function (Builder $items) use ($siteIds): void {
                $items->whereNull('site_id')->orWhereNotIn('site_id', $siteIds);
            });
    }

    public function assertCanAccessPaymentRun(User $actor, FinPaymentRun $run, bool $manage = true): void
    {
        abort_unless((int) $run->organization_id === (int) $actor->organization_id, 404);
        $siteIds = $this->paymentRunSiteIds($actor, $manage);
        $run->loadMissing('items:id,payment_run_id,site_id');
        abort_unless(
            $run->items->isNotEmpty()
                && $run->items->every(
                    fn ($item): bool => $item->site_id !== null
                        && $this->activeSiteExists((int) $item->site_id)
                        && in_array((int) $item->site_id, $siteIds, true),
                ),
            404,
        );
    }

    public function assertStoredMatchSiteIsCurrent(FinPaymentMatch $match): void
    {
        $target = $match->matchable;
        $currentSiteId = match (true) {
            $target instanceof FinBill => $this->billSiteId($target),
            $target instanceof FinInvoice => $this->invoiceSiteId($target),
            default => abort(404),
        };

        abort_unless($match->site_id !== null && (int) $match->site_id === $currentSiteId, 404);
    }

    /** @return list<int> */
    public function accessibleSiteIdsFor(User $actor): array
    {
        return $this->accessibleSiteIds($actor);
    }

    /** @return list<int> */
    private function accessibleSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            $actor->canDo(self::GLOBAL_PERMISSION) ? [self::GLOBAL_PERMISSION] : [],
        );
    }

    /** @return list<int> */
    private function paymentRunSiteIds(User $actor, bool $manage): array
    {
        $bypass = $manage
            ? ($actor->canDo(self::GLOBAL_PERMISSION) ? [self::GLOBAL_PERMISSION] : [])
            : match (true) {
                $actor->canDo(self::GLOBAL_PERMISSION) => [self::GLOBAL_PERMISSION],
                $actor->canDo(self::GLOBAL_VIEW_PERMISSION) => [self::GLOBAL_VIEW_PERMISSION],
                default => [],
            };

        return $this->siteAccess->accessibleSiteIds($actor, $bypass);
    }

    private function activeSiteExists(int $siteId): bool
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->exists();
    }

    private function applyActiveSiteScope(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->whereNull('deleted_at');
    }
}
