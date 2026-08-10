import { PageHero, type PageHeroBadge } from '@/components/page';
import { WeekPicker } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    CalendarDays,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    DollarSign,
    FilePlus2,
    FileText,
    MapPin,
    MoreHorizontal,
    Send,
    Users,
    X,
} from 'lucide-react';
import { useRef, useState, type ReactNode, type RefObject } from 'react';

export type TimesheetsHeroSummary = {
    firstName: string;
    week_start: string;
    week_end: string;
    week_number: number;
    timesheets_total: number;
    timesheets_submitted: number;
    timesheets_approved: number;
    timesheets_returned: number;
    unapproved: number;
    hours_this_week: number;
    hours_target: number;
    next_payroll_date: string;
    sites_count: number;
    regions_count: number;
    rostered_today: number;
    staff_on_shift: number;
};

function fmtMonthDay(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

function fmtWeekdayMonthDay(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

// Week-nav chip — matches rostering's exact styling: rounded-md squared,
// xs-bold text on the dark hero.
function WeekChip({
    children,
    onClick,
    solid,
    title,
    buttonRef,
    ariaHasPopup,
    ariaExpanded,
}: {
    children: ReactNode;
    onClick?: () => void;
    solid?: boolean;
    title?: string;
    buttonRef?: RefObject<HTMLButtonElement | null>;
    ariaHasPopup?: 'dialog';
    ariaExpanded?: boolean;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button.
        <button
            type="button"
            ref={buttonRef}
            onClick={onClick}
            title={title}
            aria-haspopup={ariaHasPopup}
            aria-expanded={ariaExpanded}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors',
                solid
                    ? 'border-primary-foreground/35 bg-primary-foreground/20 hover:bg-primary-foreground/30'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 hover:bg-primary-foreground/20',
            )}
        >
            {children}
        </button>
    );
}

// ─────────────────────────────────────────────────────────────────────
// TimesheetsHero — Rostering-pattern PageHero banner for the timesheet
// approval workspace. Week-nav chips render only when the parent wires
// the handlers; unwired controls are hidden rather than shipped as
// decorative no-ops.
// ─────────────────────────────────────────────────────────────────────
export default function TimesheetsHero({
    summary,
    onCreateTimesheet,
    onPrevWeek,
    onNextWeek,
    onPickWeek,
    onClearWeek,
    weekFilterActive = false,
    onMore,
    canCreate,
    sitesCount,
    staffCount,
}: {
    summary: TimesheetsHeroSummary;
    onCreateTimesheet: () => void;
    onPrevWeek?: () => void;
    onNextWeek?: () => void;
    onPickWeek?: (weekStart: Date) => void;
    /** Shown as an "All weeks" chip when the list is scoped to one week. */
    onClearWeek?: () => void;
    weekFilterActive?: boolean;
    onMore?: () => void;
    canCreate: boolean;
    sitesCount?: number;
    staffCount?: number;
}) {
    const weekBtnRef = useRef<HTMLButtonElement | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const coveragePct =
        summary.hours_target > 0
            ? Math.min(
                  100,
                  Math.round(
                      (summary.hours_this_week / summary.hours_target) * 100,
                  ),
              )
            : 0;

    const badges: PageHeroBadge[] = [
        {
            icon: AlertCircle,
            label: `${summary.unapproved} awaiting approval`,
            tone: 'warning',
        },
        {
            icon: Send,
            label: `${summary.timesheets_returned} returned to staff`,
            tone: 'critical',
        },
        {
            icon: DollarSign,
            label: `Payroll closes ${summary.next_payroll_date}`,
            tone: 'info',
        },
    ];

    const hasWeekNav = Boolean(onPrevWeek || onNextWeek || onPickWeek);

    return (
        <>
            <PageHero
                category="ops"
                icon={FileText}
                title={
                    <span>
                        <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                            <span
                                aria-hidden="true"
                                className="relative inline-flex h-2 w-2"
                            >
                                <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                            </span>
                            Live timesheets · refreshed just now
                        </span>
                        <span className="block">
                            <span className="font-normal text-primary-foreground/80">
                                Kia ora {summary.firstName}, your week of
                                timesheets —{' '}
                            </span>
                            <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                {fmtWeekdayMonthDay(summary.week_start)} →{' '}
                                {fmtWeekdayMonthDay(summary.week_end)}
                            </span>
                        </span>
                    </span>
                }
                description={
                    <span>
                        <span className="font-semibold text-primary-foreground tabular-nums">
                            {summary.unapproved}
                        </span>{' '}
                        timesheet
                        {summary.unapproved === 1 ? '' : 's'} need a decision,{' '}
                        <span className="font-semibold text-primary-foreground tabular-nums">
                            {summary.timesheets_returned}
                        </span>{' '}
                        have been returned for changes, and{' '}
                        <span className="font-semibold text-primary-foreground tabular-nums">
                            {summary.hours_this_week}
                        </span>{' '}
                        of{' '}
                        <span className="tabular-nums">
                            {summary.hours_target}
                        </span>{' '}
                        rostered hours have been logged so far. Payroll closes{' '}
                        <span className="font-semibold text-primary-foreground">
                            {summary.next_payroll_date}
                        </span>
                        .
                    </span>
                }
                meta={[
                    {
                        icon: CalendarDays,
                        label: `Week ${summary.week_number} · Mon–Sun`,
                    },
                    {
                        icon: MapPin,
                        label: `${sitesCount ?? summary.sites_count} site${(sitesCount ?? summary.sites_count) === 1 ? '' : 's'} · ${summary.regions_count} region${summary.regions_count === 1 ? '' : 's'}`,
                    },
                    {
                        icon: Users,
                        label: `${summary.rostered_today} rostered · ${summary.staff_on_shift} on shift${typeof staffCount === 'number' && staffCount > 0 ? ` · ${staffCount} staff` : ''}`,
                    },
                ]}
                badges={badges}
                stats={[
                    { label: 'Total', value: summary.timesheets_total },
                    {
                        label: 'Pending',
                        value: summary.timesheets_submitted,
                        tone:
                            summary.timesheets_submitted > 0
                                ? 'warning'
                                : undefined,
                    },
                    {
                        label: 'Approved',
                        value: summary.timesheets_approved,
                        tone: 'success',
                    },
                    {
                        label: 'Returned',
                        value: summary.timesheets_returned,
                        tone:
                            summary.timesheets_returned > 0
                                ? 'critical'
                                : undefined,
                    },
                ]}
                actions={
                    <>
                        {canCreate ? (
                            <Button
                                type="button"
                                size="sm"
                                onClick={onCreateTimesheet}
                                className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                data-testid="open-create-timesheet"
                            >
                                <FilePlus2 className="mr-1.5 h-4 w-4" />
                                Create timesheet
                            </Button>
                        ) : null}
                        {onMore ? (
                            <Button
                                type="button"
                                size="icon"
                                variant="outline"
                                onClick={onMore}
                                aria-label="More actions"
                                className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                            >
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        ) : null}
                    </>
                }
                children={
                    <div className="max-w-xl rounded-xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
                        <div className="mb-1.5 flex items-center justify-between text-[11.5px] text-primary-foreground/80">
                            <span className="font-medium text-primary-foreground">
                                Hours logged vs. rostered
                            </span>
                            <span className="tabular-nums">
                                {summary.hours_this_week} /{' '}
                                {summary.hours_target}h · {coveragePct}%
                            </span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-primary-foreground/15">
                            <div
                                className="h-full rounded-full bg-primary-foreground/85 transition-all"
                                style={{ width: coveragePct + '%' }}
                            />
                        </div>
                    </div>
                }
                footer={
                    hasWeekNav ? (
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {onPrevWeek ? (
                                    <WeekChip
                                        onClick={onPrevWeek}
                                        title="Previous week"
                                    >
                                        <ChevronLeft className="h-3.5 w-3.5" />
                                        Wk {summary.week_number - 1}
                                    </WeekChip>
                                ) : null}
                                {onPickWeek ? (
                                    <WeekChip
                                        solid
                                        buttonRef={weekBtnRef}
                                        ariaHasPopup="dialog"
                                        ariaExpanded={pickerOpen}
                                        onClick={() => setPickerOpen((v) => !v)}
                                    >
                                        <CalendarRange className="h-3.5 w-3.5" />
                                        Wk {summary.week_number} ·{' '}
                                        {fmtMonthDay(summary.week_start)} →{' '}
                                        {fmtMonthDay(summary.week_end)} · pick
                                        week
                                        <ChevronDown className="h-3 w-3" />
                                    </WeekChip>
                                ) : null}
                                {onNextWeek ? (
                                    <WeekChip
                                        onClick={onNextWeek}
                                        title="Next week"
                                    >
                                        Wk {summary.week_number + 1}
                                        <ChevronRight className="h-3.5 w-3.5" />
                                    </WeekChip>
                                ) : null}
                                {weekFilterActive && onClearWeek ? (
                                    <WeekChip
                                        onClick={onClearWeek}
                                        title="Show every week"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        All weeks
                                    </WeekChip>
                                ) : null}
                            </div>
                        </div>
                    ) : undefined
                }
            />

            {pickerOpen && onPickWeek ? (
                <WeekPicker
                    selectedWeekStart={
                        new Date(summary.week_start + 'T00:00:00')
                    }
                    anchorRef={weekBtnRef}
                    showContextMenu={false}
                    onSelect={(next) => {
                        setPickerOpen(false);
                        onPickWeek(next);
                    }}
                    onClose={() => setPickerOpen(false)}
                />
            ) : null}
        </>
    );
}
