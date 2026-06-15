<?php

namespace App\Domain\Finance\Services\Calendar;

use App\Domain\Finance\Services\Calendar\Contracts\FinanceObligationProvider;
use App\Domain\Finance\Services\Calendar\Providers\BillDueProvider;
use App\Domain\Finance\Services\Calendar\Providers\GstReturnProvider;
use App\Domain\Finance\Services\Calendar\Providers\InvoiceDueProvider;
use App\Domain\Finance\Services\Calendar\Providers\PaymentRunProvider;
use App\Domain\Finance\Services\Calendar\Providers\PayrollObligationProvider;
use App\Domain\Finance\Services\Calendar\Providers\PeriodCloseProvider;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Support\Carbon;

/**
 * The finance calendar's single source of truth: unions read-only money
 * obligations auto-derived from the Finance modules (AR invoice due dates, AP
 * bill due dates, scheduled payment runs, GST filing deadlines) into one feed of
 * normalised {@see FinanceCalendarItem}s.
 *
 * Obligations are never persisted as calendar events — each links back to its
 * source record, so the calendar can never drift from the underlying ledgers.
 * Mirrors the Sites {@see SiteCalendarAggregator}
 * pattern (static default-provider registry + optional injected override).
 */
class FinanceCalendarAggregator
{
    /** @var FinanceObligationProvider[] */
    private array $providers;

    /**
     * @param  FinanceObligationProvider[]|null  $providers  Defaults to the full registry.
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? self::defaultProviders();
    }

    /**
     * The full obligation-provider registry.
     *
     * @return FinanceObligationProvider[]
     */
    public static function defaultProviders(): array
    {
        return [
            new InvoiceDueProvider,
            new BillDueProvider,
            new PaymentRunProvider,
            new GstReturnProvider,
            new PayrollObligationProvider,
            new PeriodCloseProvider,
        ];
    }

    /**
     * Unified finance obligations for the organisation/range, sorted by date.
     *
     * @param  array{sources?:string[]|null}  $filters
     * @return FinanceCalendarItem[]
     */
    public function itemsForRange(?int $orgId, Carbon $start, Carbon $end, array $filters = []): array
    {
        $sources = $filters['sources'] ?? null;
        $items = [];

        foreach ($this->providers as $provider) {
            if (! $this->sourceEnabled($provider->sourceKey(), $sources)) {
                continue;
            }

            foreach ($provider->obligations($orgId, $start, $end) as $item) {
                $items[] = $item;
            }
        }

        usort($items, fn (FinanceCalendarItem $a, FinanceCalendarItem $b) => strcmp($a->start, $b->start));

        return $items;
    }

    /**
     * Same as {@see itemsForRange()} but returns plain arrays for JSON/Inertia.
     *
     * @param  array{sources?:string[]|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function arrayForRange(?int $orgId, Carbon $start, Carbon $end, array $filters = []): array
    {
        return array_map(
            fn (FinanceCalendarItem $item) => $item->toArray(),
            $this->itemsForRange($orgId, $start, $end, $filters),
        );
    }

    /**
     * The source keys this aggregator can emit (for the legend/filter UI).
     *
     * @return string[]
     */
    public function sources(): array
    {
        return array_map(fn (FinanceObligationProvider $p) => $p->sourceKey(), $this->providers);
    }

    /**
     * @param  string[]|null  $sources  null = all sources enabled.
     */
    private function sourceEnabled(string $key, ?array $sources): bool
    {
        return $sources === null || in_array($key, $sources, true);
    }
}
