import {
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    EntityFilter,
    WeekPicker,
    addDaysWP,
    formatWeekRange,
    weekNumberISO,
} from '@/components/rostering';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import { formatDateTimeInZone } from '@/lib/datetime';
import {
    ArrowLeftRight,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    GitBranch,
    LayoutGrid,
    ListChecks,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import type { Catalogue, Filters, StatusTab, ViewMode } from './shared';
import { clientName } from './shared';

export type HeroCounts = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
    openIncoming: number;
};
export function HandoversHero({
    weekStart,
    counts,
    search,
    onSearch,
    filters,
    onFilter,
    catalogue,
    onNewHandover,
    onWeekChange,
    canCreate,
    onStatus,
    rail,
    evidence,
    onSearchSubmit,
    view,
    onView,
    hasFilters,
    onClear,
    loading,
    rangeLabel,
    readNotice,
}: {
    weekStart: Date;
    counts: HeroCounts;
    search: string;
    onSearch: (value: string) => void;
    filters: Filters;
    onFilter: (next: Partial<Filters>) => void;
    catalogue: Catalogue;
    onNewHandover: () => void;
    onWeekChange: (week: Date) => void;
    canCreate: boolean;
    onStatus: (status: StatusTab) => void;
    rail?: ReactNode;
    evidence: { checked_at: string; timezone: string };
    onSearchSubmit: () => void;
    view: ViewMode;
    onView: (view: ViewMode) => void;
    hasFilters: boolean;
    onClear: () => void;
    loading: boolean;
    rangeLabel: string;
    readNotice: string | null;
}) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekBtnRef = useRef<HTMLButtonElement>(null);
    const range = formatWeekRange(weekStart);
    // Keep a selected, now-unavailable filter visible without resolving its identity.
    const options = (
        items: { id: number; name: string; description?: string | null }[],
        selected: number | null,
        label: string,
    ) =>
        selected !== null && !items.some((item) => item.id === selected)
            ? [
                  ...items,
                  { id: selected, name: `Selected ${label} unavailable` },
              ]
            : items;

    const fmt = (date: Date) =>
        date.toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    const stats: [StatusTab, string, number, string][] = [
        [
            'all',
            'Recorded handovers',
            counts.total,
            'Matching week, search and people filters',
        ],
        ['draft', 'Drafts', counts.draft, 'Not submitted'],
        ['submitted', 'Awaiting', counts.submitted, 'Submitted handovers'],
        [
            'acknowledged',
            'Acknowledged',
            counts.acknowledged,
            'Recorded acknowledgements',
        ],
    ];
    const headerPrimaryAction = canCreate ? (
        <PageHeaderPrimaryButton
            icon={Plus}
            onClick={onNewHandover}
            disabled={loading}
        >
            New handover
        </PageHeaderPrimaryButton>
    ) : null;
    const headerSecondaryActions = (
        <>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSearchSubmit();
                }}
                className="w-full min-w-0 basis-full sm:w-auto sm:flex-1 sm:basis-auto"
            >
                <fieldset
                    disabled={loading}
                    className="flex min-w-0 flex-wrap gap-2"
                >
                    <PageHeaderSearch
                        value={search}
                        onChange={onSearch}
                        placeholder="Search handovers, people or staff"
                        className="min-w-0"
                    />
                    <PageHeaderGlassButton type="submit" icon={Search}>
                        Search
                    </PageHeaderGlassButton>
                </fieldset>
            </form>
        </>
    );

    return (
        <>
            <WorkforcePageHeader
                variant="index"
                title="Handovers"
                icon={ArrowLeftRight}
                subline={`${fmt(weekStart)} → ${fmt(range.end)} · ${evidence.timezone} · Review recorded handovers and incoming responsibilities`}
                actions={
                    <>
                        {headerSecondaryActions}
                        {headerPrimaryAction}
                    </>
                }
                mobilePrimaryAction={headerPrimaryAction}
                mobileSecondaryActions={headerSecondaryActions}
                mobileSummary={`${fmt(weekStart)} → ${fmt(range.end)}`}
                meters={
                    <div className="grid w-full min-w-0 grid-cols-2 gap-2 lg:grid-cols-4">
                        {stats.map(([key, label, value, caption]) => (
                            <PageHeaderMeterBlock
                                key={key}
                                label={label}
                                ariaLabel={
                                    key === 'submitted'
                                        ? 'View awaiting acknowledgement'
                                        : `View ${label.toLowerCase()}`
                                }
                                onClick={() => {
                                    if (!loading) onStatus(key);
                                }}
                            >
                                <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {caption}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </div>
                }
                details={
                    <p className="text-caption" role="status">
                        {loading
                            ? 'Updating handovers…'
                            : (readNotice ?? rangeLabel)}{' '}
                        · Counts cover all matching records across every page ·
                        Updated{' '}
                        {formatDateTimeInZone(
                            evidence.checked_at,
                            evidence.timezone,
                        )}
                    </p>
                }
                filters={
                    <fieldset
                        disabled={loading}
                        className="flex w-full min-w-0 flex-wrap items-center justify-end gap-1.5"
                    >
                        <div className="flex flex-wrap items-center gap-1.5">
                            <PageHeaderFilterButton
                                icon={ChevronLeft}
                                onClick={() =>
                                    onWeekChange(addDaysWP(weekStart, -7))
                                }
                                aria-label="Previous week"
                            />
                            <PageHeaderFilterButton
                                ref={weekBtnRef}
                                icon={CalendarRange}
                                onClick={() => setPickerOpen(!pickerOpen)}
                                aria-haspopup="dialog"
                                aria-expanded={pickerOpen}
                            >
                                Week {weekNumberISO(weekStart)} · Choose week
                            </PageHeaderFilterButton>
                            <PageHeaderFilterButton
                                icon={ChevronRight}
                                onClick={() =>
                                    onWeekChange(addDaysWP(weekStart, 7))
                                }
                                aria-label="Next week"
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <EntityFilter
                                compact
                                onDark
                                label="Staff"
                                allLabel="All staff"
                                pluralLabel="staff"
                                items={options(
                                    catalogue.staff.map((s) => ({
                                        id: s.id,
                                        name: s.name,
                                        description: s.email,
                                    })),
                                    filters.staff,
                                    'staff member',
                                )}
                                value={filters.staff}
                                onChange={(staff) => onFilter({ staff })}
                            />
                            <EntityFilter
                                compact
                                onDark
                                label="Client"
                                allLabel="All clients"
                                items={options(
                                    catalogue.clients.map((c) => ({
                                        id: c.id,
                                        name: clientName(c),
                                    })),
                                    filters.client,
                                    'client',
                                )}
                                value={filters.client}
                                onChange={(client) => onFilter({ client })}
                            />
                            <EntityFilter
                                compact
                                onDark
                                label="Site"
                                allLabel="All sites"
                                items={options(
                                    catalogue.sites.map((s) => ({
                                        id: s.id,
                                        name: s.name,
                                    })),
                                    filters.site,
                                    'site',
                                )}
                                value={filters.site}
                                onChange={(site) => onFilter({ site })}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <PageHeaderViewToggle
                                value={view}
                                onChange={onView}
                                ariaLabel="Handover display"
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'list',
                                        label: 'List',
                                        icon: ListChecks,
                                    },
                                    {
                                        value: 'board',
                                        label: 'Board',
                                        icon: GitBranch,
                                    },
                                ]}
                            />
                            <PageHeaderFilterButton
                                icon={X}
                                onClick={onClear}
                                disabled={!hasFilters}
                                aria-label="Clear filters"
                                title="Clear filters"
                            />
                        </div>
                    </fieldset>
                }
                rail={rail}
            />
            {pickerOpen ? (
                <WeekPicker
                    selectedWeekStart={weekStart}
                    anchorRef={weekBtnRef}
                    onSelect={(d) => {
                        onWeekChange(d);
                        setPickerOpen(false);
                    }}
                    onClose={() => setPickerOpen(false)}
                    showContextMenu={false}
                />
            ) : null}
        </>
    );
}
