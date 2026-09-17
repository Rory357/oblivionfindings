<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance obligation calendar — a read-only view of upcoming money obligations
 * (invoice/bill due dates, scheduled payment runs, GST deadlines, payroll runs
 * and period closes) aggregated from the finance ledgers. Gated by
 * finance.dashboard.
 *
 * The page renders the shared SiteCalendar (DESIGN.md "Calendars — always the
 * Site Calendar style") fed by `lib/finance-calendar-adapter.ts`, so this
 * controller's job is the source list the viewer may see plus the JSON feed.
 */
class FinanceCalendarController extends Controller
{
    /**
     * Plain source names for the shared calendar's filters. Mirrors
     * FINANCE_CALENDAR_SOURCES in resources/js/lib/finance-calendar-adapter.ts.
     * Keys are the dashed wire format (the calendar builds `--src-{key}` from
     * them); the aggregator's provider keys are the underscored siblings.
     */
    private const SOURCE_LABELS = [
        'invoice-due' => [
            'label' => 'Invoices due',
            'short' => 'Invoices',
            'icon' => 'Receipt',
            'origin' => 'Invoices',
            'note' => 'When a customer invoice falls due',
        ],
        'bill-due' => [
            'label' => 'Bills due',
            'short' => 'Bills',
            'icon' => 'FileText',
            'origin' => 'Bills',
            'note' => 'When a supplier bill falls due',
        ],
        'payment-run' => [
            'label' => 'Payment runs',
            'short' => 'Payments',
            'icon' => 'Banknote',
            'origin' => 'Payment runs',
            'note' => 'When a scheduled payment run is paid',
        ],
        'gst-due' => [
            'label' => 'GST returns due',
            'short' => 'GST',
            'icon' => 'Percent',
            'origin' => 'GST returns',
            'note' => 'When a GST return must be filed and paid',
        ],
        'payroll' => [
            'label' => 'Payroll runs',
            'short' => 'Payroll',
            'icon' => 'Users',
            'origin' => 'Payroll',
            'note' => 'When a pay period is paid',
        ],
        'period-close' => [
            'label' => 'Period closes',
            'short' => 'Periods',
            'icon' => 'CalendarRange',
            'origin' => 'Fiscal periods',
            'note' => 'When a fiscal period closes',
        ],
    ];

    public function __construct(
        private FinanceCalendarAggregator $aggregator,
    ) {}

    /**
     * The calendar page shell. Entries are loaded from the JSON feed (events())
     * as the viewer navigates, so this hands the page only the sources it may
     * filter by.
     */
    public function index(Request $request): Response
    {
        $allowed = $this->allowedSources($request);

        return Inertia::render('finance/Calendar', [
            'sources' => array_map(
                fn (string $key) => ['key' => $key, 'group' => 'auto', ...self::SOURCE_LABELS[$key]],
                $allowed,
            ),
            'initialSources' => $allowed,
        ]);
    }

    /**
     * JSON entry feed consumed by the calendar, scoped to [start, end] and to
     * the sources the viewer may see.
     */
    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'sources' => 'nullable|string',
        ]);

        $start = isset($validated['start'])
            ? Carbon::parse($validated['start'])
            : Carbon::now()->startOfMonth();
        $end = isset($validated['end'])
            ? Carbon::parse($validated['end'])
            : Carbon::now()->endOfMonth();

        $allowed = $this->allowedSources($request);
        $requested = ! empty($validated['sources'])
            ? array_values(array_filter(array_map('trim', explode(',', $validated['sources']))))
            : $allowed;

        // Hiding a source is never the security boundary on its own, but the
        // feed still refuses to emit one the viewer may not see.
        $sources = array_values(array_intersect($requested, $allowed));

        return response()->json($this->aggregator->itemsPayload(
            $request->user()->organization_id,
            $start,
            $end,
            ['sources' => $sources],
        ));
    }

    /**
     * The sources this viewer may see. GST deadlines are tax data, so they need
     * finance.tax.view on top of the calendar's own finance.dashboard gate.
     *
     * @return string[]
     */
    private function allowedSources(Request $request): array
    {
        $user = $request->user();

        return array_values(array_filter(
            $this->aggregator->sourceSlugs(),
            fn (string $key) => isset(self::SOURCE_LABELS[$key])
                && ($key !== 'gst-due' || (bool) $user?->canDo('finance.tax.view')),
        ));
    }
}
