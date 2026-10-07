import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page';
import { EntityFilter, WeekPicker } from '@/components/rostering';
import { formatDateOnly, formatDateTimeInZone } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import type { TimesheetFilters } from './use-timesheet-filters';

export type TimesheetsHeroSummary = {
    week_start: string;
    week_end: string;
    week_number: number;
    hours_this_week: number;
    hours_target: number | null;
    next_payroll_date: string | null;
    regions_count: number | null;
    evidence: { timezone: string; checked_at: string; scope: string };
};
type Choice = { id: number; name: string };
function availableChoice(
    items: Choice[],
    selected: number | null,
    label: string,
) {
    return selected !== null && !items.some((item) => item.id === selected)
        ? [...items, { id: selected, name: `Selected ${label} unavailable` }]
        : items;
}
export default function TimesheetsHero({
    summary,
    counts,
    filters,
    clients,
    staff,
    canCreate,
    canReviewAdjustments = false,
    ownOnly,
    loading,
    onCreateTimesheet,
    onSearch,
    onSearchSubmit,
    onChange,
    onClear,
    onPrevWeek,
    onNextWeek,
    onPickWeek,
    onClearWeek,
    rangeLabel,
    notice,
    rail,
}: {
    summary: TimesheetsHeroSummary;
    counts: Record<string, number>;
    filters: TimesheetFilters;
    clients: Choice[];
    staff: Choice[];
    canCreate: boolean;
    canReviewAdjustments?: boolean;
    ownOnly: boolean;
    loading: boolean;
    onCreateTimesheet: () => void;
    onSearch: (value: string) => void;
    onSearchSubmit: () => void;
    onChange: (value: Partial<TimesheetFilters>) => void;
    onClear: () => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onPickWeek: (date: Date) => void;
    onClearWeek: () => void;
    rangeLabel: string;
    notice: string | null;
    rail: ReactNode;
}) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekRef = useRef<HTMLButtonElement>(null);
    const dated = Boolean(filters.from || filters.to);
    const hasFilters =
        dated ||
        Boolean(
            filters.search ||
            filters.client_id ||
            filters.staff_id ||
            filters.tab !== 'all',
        );
    const period = dated
        ? `${filters.from ? formatDateOnly(filters.from) : 'Any start date'} → ${filters.to ? formatDateOnly(filters.to) : 'Any end date'}`
        : 'All weeks';
    const meters = [
        ['all', 'Active records', 'Matching filters in the All tab'],
        [
            'submitted',
            'Awaiting approval',
            'Matching records you may view for review',
        ],
        ['returned', 'Returned', 'Matching records returned for changes'],
        [
            'approved',
            'Approved',
            'Matching approved records; payment is separate',
        ],
    ];
    return (
        <>
            <PageHeader
                variant="index"
                frontline
                title={ownOnly ? 'My timesheets' : 'Timesheets'}
                icon={FileText}
                subline={`${period} · ${summary.evidence.timezone} · Review recorded work, hours and approval decisions`}
                actions={
                    <>
                        <form
                            className="w-full min-w-0 basis-full sm:w-auto sm:flex-1 sm:basis-auto"
                            onSubmit={(event) => {
                                event.preventDefault();
                                onSearchSubmit();
                            }}
                        >
                            <fieldset
                                disabled={loading}
                                className="flex min-w-0 flex-wrap gap-2"
                            >
                                <PageHeaderSearch
                                    value={filters.search}
                                    onChange={onSearch}
                                    placeholder="Search names or location"
                                    className="min-w-0"
                                />
                                <PageHeaderGlassButton
                                    type="submit"
                                    icon={Search}
                                >
                                    Search
                                </PageHeaderGlassButton>
                            </fieldset>
                        </form>
                        {canReviewAdjustments && (
                            <PageHeaderGlassButton
                                asChild
                                icon={ClipboardCheck}
                            >
                                <Link href="/operations/timesheets/payroll-adjustments">
                                    Payroll adjustments
                                </Link>
                            </PageHeaderGlassButton>
                        )}
                        {canCreate && (
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                disabled={loading}
                                onClick={onCreateTimesheet}
                            >
                                Create timesheet
                            </PageHeaderPrimaryButton>
                        )}
                    </>
                }
                meters={
                    <div className="grid w-full min-w-0 grid-cols-2 gap-2 xl:grid-cols-4">
                        {meters.map(([tab, label, caption]) => (
                            <PageHeaderMeterBlock
                                key={tab}
                                label={label}
                                ariaLabel={`View ${label.toLowerCase()}`}
                                onClick={() => {
                                    if (!loading) onChange({ tab });
                                }}
                            >
                                <PageHeaderMeterBig>
                                    {counts[tab] ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {caption}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </div>
                }
                filters={
                    <div className="w-full min-w-0 space-y-3">
                        <fieldset
                            disabled={loading}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <PageHeaderGlassButton
                                icon={ChevronLeft}
                                onClick={onPrevWeek}
                                aria-label="Previous week"
                            >
                                Previous
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                ref={weekRef}
                                icon={CalendarRange}
                                onClick={() => setPickerOpen(!pickerOpen)}
                                aria-haspopup="dialog"
                                aria-expanded={pickerOpen}
                            >
                                Week {summary.week_number} · Choose week
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={ChevronRight}
                                onClick={onNextWeek}
                                aria-label="Next week"
                            >
                                Next
                            </PageHeaderGlassButton>
                            {dated && (
                                <PageHeaderGlassButton onClick={onClearWeek}>
                                    All weeks
                                </PageHeaderGlassButton>
                            )}
                            <EntityFilter
                                onDark
                                label="Client"
                                allLabel="All clients"
                                items={availableChoice(
                                    clients,
                                    filters.client_id,
                                    'client',
                                )}
                                value={filters.client_id}
                                onChange={(client_id) =>
                                    onChange({ client_id })
                                }
                            />
                            {(!ownOnly || filters.staff_id !== null) && (
                                <EntityFilter
                                    onDark
                                    label="Staff"
                                    allLabel="All staff"
                                    items={availableChoice(
                                        staff,
                                        filters.staff_id,
                                        'staff member',
                                    )}
                                    value={filters.staff_id}
                                    onChange={(staff_id) =>
                                        onChange({ staff_id })
                                    }
                                />
                            )}
                            {hasFilters && (
                                <PageHeaderGlassButton
                                    icon={X}
                                    onClick={onClear}
                                >
                                    Clear filters
                                </PageHeaderGlassButton>
                            )}
                        </fieldset>
                        <p className="text-caption text-band-foreground!">
                            Week of {formatDateOnly(summary.week_start)} →{' '}
                            {formatDateOnly(summary.week_end)}:{' '}
                            {summary.hours_this_week}h recorded with the current
                            filters, before status.{' '}
                            {summary.hours_target === null
                                ? 'Rostered hours are unavailable for a text search.'
                                : `${summary.hours_target}h planned in assigned shifts starting that week.`}{' '}
                            {dated ? '' : 'The list includes all weeks.'}
                        </p>
                        <p
                            role="status"
                            className="text-caption text-band-foreground!"
                        >
                            {loading ? 'Loading timesheets…' : rangeLabel} ·
                            Updated{' '}
                            {formatDateTimeInZone(
                                summary.evidence.checked_at,
                                summary.evidence.timezone,
                            )}
                        </p>
                        {notice && (
                            <p
                                role="alert"
                                className="text-caption text-band-foreground!"
                            >
                                {notice}
                            </p>
                        )}
                    </div>
                }
                rail={rail}
            />
            {pickerOpen && (
                <WeekPicker
                    anchorRef={weekRef}
                    selectedWeekStart={
                        new Date(`${summary.week_start}T12:00:00`)
                    }
                    showContextMenu={false}
                    onSelect={(date) => {
                        setPickerOpen(false);
                        onPickWeek(date);
                    }}
                    onClose={() => setPickerOpen(false)}
                />
            )}
        </>
    );
}
