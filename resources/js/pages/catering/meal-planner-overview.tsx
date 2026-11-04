/**
 * The Meal Planner on every site (/catering from the side menu).
 *
 * One row per site the person can plan for: the week (main meals planned per
 * day and how full the plan is), unresolved allergen conflicts, low stock and
 * spend against the weekly food budget — the same numbers each site's own
 * planner shows. A row opens that site's planner (/catering?site=…), which
 * links back here through its breadcrumb and site switcher.
 *
 * The rows are worked out in the background (Inertia::defer + DeferredPanel):
 * the header band and filters paint first, then the numbers fill in.
 */
import { DeferredPanel } from '@/components/deferred-panel';
import {
    DayBars,
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    ProgressValue,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { WeekPicker } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/datetime';
import {
    formatMoneyFromCents,
    fromIsoDate,
    startOfWeek,
    toIsoDate,
} from '@/pages/sites/meal-planner/_helpers';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarRange,
    ChefHat,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Home,
    LayoutGrid,
    ListFilter,
    ShieldAlert,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Data                                                               */
/* ------------------------------------------------------------------ */

type WeekInfo = {
    start: string;
    end: string;
    number: number;
    is_current: boolean;
    previous: string;
    next: string;
    days: string[];
};

type OverviewSite = {
    id: number;
    name: string;
    type: string;
    is_house: boolean;
    suburb: string | null;
    region: string | null;
    residents: number;
    week: {
        days: { date: string; meals: number; slots: number }[];
        meals: number;
        served: number;
        slots_filled: number;
        slots_total: number;
        fill_percent: number;
    };
    allergens: { unresolved: number; overrides: number };
    stock: {
        tracked: number;
        low: number;
        out: number;
        at_par_percent: number | null;
    };
    spend: { total_cents: number; budget_cents: number | null };
};

type Overview = { week_start: string; sites: OverviewSite[] };

type PageProps = {
    week: WeekInfo;
    can_create_sites: boolean;
    overview?: Overview;
};

type View = 'all' | 'houses' | 'offices';
type Show = 'all' | 'gaps' | 'conflicts' | 'stock' | 'budget';

const SHOW_OPTIONS: { value: Show; label: string }[] = [
    { value: 'all', label: 'Show all' },
    { value: 'gaps', label: 'Plan gaps' },
    { value: 'conflicts', label: 'Allergen conflicts' },
    { value: 'stock', label: 'Low stock' },
    { value: 'budget', label: 'Over budget' },
];

const DAY_INITIALS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

const plural = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

const hasBudget = (s: OverviewSite) =>
    s.spend.budget_cents != null && s.spend.budget_cents > 0;

const overBudget = (s: OverviewSite) =>
    hasBudget(s) && s.spend.total_cents > (s.spend.budget_cents ?? 0);

const hasPlanGaps = (s: OverviewSite) =>
    s.is_house && s.week.slots_filled < s.week.slots_total;

const hasConflicts = (s: OverviewSite) =>
    s.is_house && s.allergens.unresolved > 0;

const hasLowStock = (s: OverviewSite) => s.stock.low + s.stock.out > 0;

const SHOW_TEST: Record<Show, (s: OverviewSite) => boolean> = {
    all: () => true,
    gaps: hasPlanGaps,
    conflicts: hasConflicts,
    stock: hasLowStock,
    budget: overBudget,
};

const VIEW_TEST: Record<View, (s: OverviewSite) => boolean> = {
    all: () => true,
    houses: (s) => s.is_house,
    offices: (s) => !s.is_house,
};

const VIEW_LABEL: Record<View, string> = {
    all: 'All sites',
    houses: 'Houses',
    offices: 'Offices & facilities',
};

function weekLabel(week: WeekInfo) {
    return `Wk ${week.number} · ${formatDate(week.start)} – ${formatDate(week.end)}`;
}

function plannerHref(site: OverviewSite, week: WeekInfo) {
    return week.is_current
        ? `/catering?site=${site.id}`
        : `/catering?site=${site.id}&week=${week.start}`;
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function MealPlannerOverviewPage() {
    const { week, can_create_sites, overview } = usePage<PageProps>().props;
    const [search, setSearch] = useState('');
    const [view, setView] = useState<View>('all');
    const [show, setShow] = useState<Show>('all');
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekBtnRef = useRef<HTMLButtonElement>(null);

    // Rail views by site type, once the rows say both kinds are here. Rows
    // from another week never count for the week on screen.
    const sites =
        overview?.week_start === week.start ? overview.sites : undefined;
    const railItems = useMemo<PageHeaderRailItem<View>[]>(() => {
        const items: PageHeaderRailItem<View>[] = [
            {
                key: 'all',
                label: VIEW_LABEL.all,
                icon: LayoutGrid,
                count: sites?.length,
            },
        ];
        const houses = sites?.filter((s) => s.is_house).length ?? 0;
        const offices = (sites?.length ?? 0) - houses;
        if (houses > 0 && offices > 0) {
            items.push(
                {
                    key: 'houses',
                    label: VIEW_LABEL.houses,
                    icon: Home,
                    count: houses,
                },
                {
                    key: 'offices',
                    label: VIEW_LABEL.offices,
                    icon: Building2,
                    count: offices,
                },
            );
        }
        return items;
    }, [sites]);
    const activeView: View = railItems.some((i) => i.key === view)
        ? view
        : 'all';

    const goWeek = (start: string | null) => {
        setPickerOpen(false);
        const thisWeek = toIsoDate(startOfWeek(new Date()));
        router.get(
            '/catering',
            start && start !== thisWeek ? { week: start } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setShow('all');
        setView('all');
    };

    const header = (
        <PageHeader
            icon={ChefHat}
            title="Meal planner"
            subline="Meals, allergens, stock and spend across your sites"
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search sites, suburbs, regions…"
                />
            }
            meters={
                <div className="grid w-full">
                    <DeferredPanel<{ overview: Overview }>
                        key={week.start}
                        data="overview"
                        label="Meal planner numbers"
                        fallback={<LoadingMeters />}
                    >
                        {({ overview: loaded }) =>
                            loaded.week_start === week.start ? (
                                <OverviewMeters
                                    sites={loaded.sites}
                                    show={show}
                                    onShow={(next) => {
                                        setView('all');
                                        setShow(next);
                                    }}
                                />
                            ) : (
                                <LoadingMeters />
                            )
                        }
                    </DeferredPanel>
                </div>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous week"
                        onClick={() => goWeek(week.previous)}
                    />
                    <PageHeaderFilterButton
                        ref={weekBtnRef}
                        icon={CalendarRange}
                        aria-haspopup="dialog"
                        aria-expanded={pickerOpen}
                        onClick={() => setPickerOpen((v) => !v)}
                        className="tnum"
                    >
                        {weekLabel(week)}
                        <ChevronDown className="size-3 opacity-70" />
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next week"
                        onClick={() => goWeek(week.next)}
                    />
                    {!week.is_current ? (
                        <PageHeaderFilterButton onClick={() => goWeek(null)}>
                            Today
                        </PageHeaderFilterButton>
                    ) : null}
                    {pickerOpen ? (
                        <WeekPicker
                            selectedWeekStart={
                                fromIsoDate(week.start) ??
                                startOfWeek(new Date())
                            }
                            anchorRef={weekBtnRef}
                            showContextMenu={false}
                            onSelect={(d) => goWeek(toIsoDate(startOfWeek(d)))}
                            onClose={() => setPickerOpen(false)}
                        />
                    ) : null}
                    <PageHeaderFilterSelect
                        icon={ListFilter}
                        label="Show all"
                        value={show}
                        options={SHOW_OPTIONS}
                        onChange={(v) => setShow(v as Show)}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={activeView}
                    onSelect={setView}
                    ariaLabel="Site views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Meal planner', href: '/catering' },
            ]}
        >
            <Head title="Meal planner" />
            <PageLayout hero={header}>
                <DeferredPanel<{ overview: Overview }>
                    key={week.start}
                    data="overview"
                    label="Sites"
                    fallback={<LoadingTable />}
                >
                    {({ overview: loaded }) =>
                        loaded.week_start === week.start ? (
                            <SitesList
                                sites={loaded.sites}
                                week={week}
                                view={activeView}
                                show={show}
                                search={search}
                                canCreateSites={can_create_sites}
                                onClearFilters={clearFilters}
                            />
                        ) : (
                            <LoadingTable />
                        )
                    }
                </DeferredPanel>
            </PageLayout>
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Header meters                                                      */
/* ------------------------------------------------------------------ */

const METER_LABELS = [
    'Plan filled',
    'Allergen conflicts',
    'Low stock',
    'Week spend',
];

function LoadingMeters() {
    return (
        <div className="flex flex-wrap items-stretch gap-2">
            {METER_LABELS.map((label) => (
                <div key={label} className="eh-meter">
                    <span className="eh-meter-label text-xs leading-snug font-semibold tracking-[0.08em] uppercase">
                        {label}
                    </span>
                    <Skeleton className="h-5 w-14 bg-band-foreground/15" />
                    <span className="text-[10.5px] leading-tight text-band-foreground/70">
                        Loading…
                    </span>
                </div>
            ))}
        </div>
    );
}

function OverviewMeters({
    sites,
    show,
    onShow,
}: {
    sites: OverviewSite[];
    show: Show;
    onShow: (show: Show) => void;
}) {
    const houses = sites.filter((s) => s.is_house);
    const sum = (rows: OverviewSite[], pick: (s: OverviewSite) => number) =>
        rows.reduce((total, s) => total + pick(s), 0);

    const meals = sum(houses, (s) => s.week.meals);
    const slotsFilled = sum(houses, (s) => s.week.slots_filled);
    const slotsTotal = sum(houses, (s) => s.week.slots_total);
    const fillPct = slotsTotal
        ? Math.round((slotsFilled / slotsTotal) * 100)
        : 0;
    const gapHouses = houses.filter(hasPlanGaps).length;

    const conflicts = sum(houses, (s) => s.allergens.unresolved);
    const conflictHouses = houses.filter(hasConflicts).length;

    const tracked = sum(sites, (s) => s.stock.tracked);
    const low = sum(sites, (s) => s.stock.low);
    const out = sum(sites, (s) => s.stock.out);

    const spend = sum(sites, (s) => s.spend.total_cents);
    const budgeted = sites.filter(hasBudget);
    const budgetTotal = sum(budgeted, (s) => s.spend.budget_cents ?? 0);
    const budgetedSpend = sum(budgeted, (s) => s.spend.total_cents);
    const overSites = sites.filter(overBudget).length;

    return (
        <div className="flex flex-wrap items-stretch gap-2">
            {houses.length > 0 ? (
                <PageHeaderMeterBlock
                    label="Plan filled"
                    value={plural(meals, 'meal')}
                    ariaLabel="Show houses with gaps in this week's plan"
                    pressed={show === 'gaps'}
                    onClick={() => onShow('gaps')}
                >
                    <PageHeaderMeterBar percent={fillPct} />
                    <PageHeaderMeterCaption>
                        {fillPct}% of main meals ·{' '}
                        {gapHouses > 0
                            ? `${plural(gapHouses, 'house')} with gaps`
                            : 'every house planned'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            {houses.length > 0 ? (
                <PageHeaderMeterBlock
                    label="Allergen conflicts"
                    tone={conflicts > 0 ? 'critical' : 'success'}
                    ariaLabel="Show houses with allergen conflicts"
                    pressed={show === 'conflicts'}
                    onClick={() => onShow('conflicts')}
                >
                    <PageHeaderMeterBig>{conflicts}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {conflicts > 0
                            ? `at ${plural(conflictHouses, 'house')} · review before serving`
                            : 'all clear'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            <PageHeaderMeterBlock
                label="Low stock"
                tone={
                    tracked === 0
                        ? 'brand'
                        : out > 0
                          ? 'critical'
                          : low > 0
                            ? 'warning'
                            : 'success'
                }
                ariaLabel="Show sites with low stock"
                pressed={show === 'stock'}
                onClick={() => onShow('stock')}
            >
                <PageHeaderMeterBig>
                    {tracked === 0 ? '—' : low}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {tracked === 0
                        ? 'no stock tracked yet'
                        : `${out} out of stock`}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {/* Spend against the weekly food budgets is the bar-meter form
                (DESIGN.md "Numbers-only meter blocks"): the amount stays the
                big number, the bar tracks the budgets. With no budget set
                there's no honest fraction, so it stays a plain amount. */}
            <PageHeaderMeterBlock
                label="Week spend"
                tone={overSites > 0 ? 'warning' : 'brand'}
                ariaLabel={
                    overSites > 0
                        ? 'Show sites over their weekly food budget'
                        : 'Show spend by site'
                }
                pressed={overSites > 0 && show === 'budget'}
                onClick={() => onShow(overSites > 0 ? 'budget' : 'all')}
            >
                <PageHeaderMeterBig>
                    {formatMoneyFromCents(spend)}
                </PageHeaderMeterBig>
                {budgetTotal > 0 ? (
                    <>
                        <PageHeaderMeterBar
                            percent={Math.round(
                                (budgetedSpend / budgetTotal) * 100,
                            )}
                        />
                        <PageHeaderMeterCaption>
                            {overSites > 0
                                ? `${plural(overSites, 'site')} over budget`
                                : `${formatMoneyFromCents(budgetTotal - budgetedSpend)} left of ${formatMoneyFromCents(budgetTotal)}`}
                        </PageHeaderMeterCaption>
                    </>
                ) : (
                    <PageHeaderMeterCaption>
                        planned this week · no budgets set
                    </PageHeaderMeterCaption>
                )}
            </PageHeaderMeterBlock>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  The sites table                                                    */
/* ------------------------------------------------------------------ */

function LoadingTable() {
    return (
        <Card className="gap-0 rounded-[14px] p-4">
            <SkeletonTable rows={4} columns={6} />
        </Card>
    );
}

function SitesList({
    sites,
    week,
    view,
    show,
    search,
    canCreateSites,
    onClearFilters,
}: {
    sites: OverviewSite[];
    week: WeekInfo;
    view: View;
    show: Show;
    search: string;
    canCreateSites: boolean;
    onClearFilters: () => void;
}) {
    const ctxMenu = useEntityContextMenu<OverviewSite>();
    const today = toIsoDate(new Date());

    const needle = search.trim().toLowerCase();
    const rows = sites.filter(
        (s) =>
            VIEW_TEST[view](s) &&
            SHOW_TEST[show](s) &&
            (!needle ||
                `${s.name} ${s.suburb ?? ''} ${s.region ?? ''}`
                    .toLowerCase()
                    .includes(needle)),
    );

    if (sites.length === 0) {
        return (
            <EmptyState
                icon={Home}
                title="No sites to plan meals for"
                description={
                    canCreateSites
                        ? 'Add a house or office to start planning meals, tracking stock and building shopping lists.'
                        : "You aren't linked to a house or office yet. Ask your manager to add you to one."
                }
                action={
                    canCreateSites ? (
                        <Button asChild>
                            <Link href="/sites">Open sites</Link>
                        </Button>
                    ) : undefined
                }
            />
        );
    }

    const open = (s: OverviewSite) => router.visit(plannerHref(s, week));
    const actionsFor = (s: OverviewSite): MenuItem[] => [
        {
            label: 'Open meal planner',
            icon: ChefHat,
            onClick: () => open(s),
        },
        {
            label: 'Open site profile',
            icon: s.is_house ? Home : Building2,
            onClick: () => router.visit(`/sites/${s.id}`),
        },
    ];

    const columns: EntityTableColumn<OverviewSite>[] = [
        {
            key: 'week',
            label: 'This week',
            width: '1fr',
            cell: (s) =>
                s.is_house ? (
                    <DayBars
                        target={3}
                        label={`Main meals planned: ${s.week.days
                            .map((d) => `${formatDate(d.date)} ${d.slots} of 3`)
                            .join(', ')}`}
                        days={s.week.days.map((d, i) => ({
                            key: d.date,
                            initial: DAY_INITIALS[i] ?? '',
                            value: d.slots,
                            current: d.date === today,
                            title: `${formatDate(d.date)} · ${d.slots} of 3 main meals · ${plural(d.meals, 'meal')} planned`,
                        }))}
                    />
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'plan',
            label: 'Plan filled',
            width: '1fr',
            cell: (s) =>
                s.is_house ? (
                    <ProgressValue
                        percent={
                            s.week.fill_percent > 0 ? s.week.fill_percent : null
                        }
                    >
                        {s.week.slots_filled} of {s.week.slots_total} main meals
                    </ProgressValue>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'allergens',
            label: 'Allergen conflicts',
            width: '1.05fr',
            cell: (s) => <AllergenCell site={s} />,
        },
        {
            key: 'stock',
            label: 'Low stock',
            width: '1fr',
            cell: (s) => <StockCell site={s} />,
        },
        {
            key: 'spend',
            label: 'Spend this week',
            width: '1.1fr',
            cell: (s) => <SpendCell site={s} />,
        },
    ];

    return (
        <div className="flex flex-col gap-5">
            <ListCaption
                title={
                    show === 'all'
                        ? VIEW_LABEL[view]
                        : `${VIEW_LABEL[view]} · ${SHOW_OPTIONS.find((o) => o.value === show)?.label ?? ''}`
                }
                caption={`${rows.length} of ${sites.length} shown`}
            />
            {rows.length === 0 ? (
                <EmptyState
                    icon={ListFilter}
                    title="No sites match"
                    description={
                        show !== 'all' && !needle
                            ? 'Nothing needs attention here this week.'
                            : 'Try clearing the search or a filter.'
                    }
                    action={
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onClearFilters}
                        >
                            <X className="size-3.5" />
                            Clear filters
                        </Button>
                    }
                />
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(s) => s.id}
                    identityLabel="Site"
                    identity={(s) => ({
                        icon: s.is_house ? Home : Building2,
                        name: s.name,
                        linkLabel: `Open the meal planner for ${s.name}`,
                        subline: [
                            s.suburb,
                            s.region,
                            s.is_house
                                ? plural(s.residents, 'resident')
                                : 'Kitchen supplies',
                        ]
                            .filter(Boolean)
                            .join(' · '),
                    })}
                    hrefFor={(s) => plannerHref(s, week)}
                    columns={columns}
                    actionsFor={actionsFor}
                    onOpen={open}
                    onRowContextMenu={(e, s) => ctxMenu.open(e, s)}
                    minWidth={980}
                />
            )}
            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={ctxMenu.ctx.record.is_house ? Home : Building2}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </div>
    );
}

function AllergenCell({ site }: { site: OverviewSite }) {
    if (!site.is_house) return <EmptyValue />;
    const { unresolved, overrides } = site.allergens;
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            {unresolved > 0 ? (
                <EntityStatusChip variant="critical" icon={ShieldAlert}>
                    {unresolved} to resolve
                </EntityStatusChip>
            ) : site.residents === 0 ? (
                <EntityStatusChip variant="neutral">
                    No residents linked
                </EntityStatusChip>
            ) : (
                <EntityStatusChip variant="success" icon={ShieldCheck}>
                    All clear
                </EntityStatusChip>
            )}
            {overrides > 0 ? (
                <EntityStatusChip variant="warning">
                    {plural(overrides, 'override')}
                </EntityStatusChip>
            ) : null}
        </span>
    );
}

function StockCell({ site }: { site: OverviewSite }) {
    const { tracked, low, out } = site.stock;
    if (tracked === 0) return <EntityChip>Not tracked</EntityChip>;
    if (low === 0 && out === 0) {
        return <EntityStatusChip variant="success">Stocked</EntityStatusChip>;
    }
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            {out > 0 ? (
                <EntityStatusChip variant="critical">
                    {out} out of stock
                </EntityStatusChip>
            ) : null}
            {low > 0 ? (
                <EntityStatusChip variant="warning">{low} low</EntityStatusChip>
            ) : null}
        </span>
    );
}

function SpendCell({ site }: { site: OverviewSite }) {
    const spend = site.spend.total_cents;
    const budget = site.spend.budget_cents ?? 0;
    if (!hasBudget(site)) {
        return (
            <span className="flex min-w-0 flex-col">
                <span className="text-xs font-semibold tabular-nums">
                    {formatMoneyFromCents(spend)}
                </span>
                <span className="text-caption truncate">No budget set</span>
            </span>
        );
    }
    const over = spend > budget;
    return (
        <ProgressValue
            percent={spend > 0 ? Math.round((spend / budget) * 100) : null}
            tone={over ? 'warning' : 'brand'}
        >
            {over
                ? `${formatMoneyFromCents(spend)} · ${formatMoneyFromCents(spend - budget)} over`
                : `${formatMoneyFromCents(spend)} of ${formatMoneyFromCents(budget)}`}
        </ProgressValue>
    );
}
