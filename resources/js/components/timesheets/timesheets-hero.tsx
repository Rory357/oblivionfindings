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
    Search,
    Send,
    Users,
} from 'lucide-react';
import { type ReactNode } from 'react';

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
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function fmtWeekdayMonthDay(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' });
}

// ─────────────────────────────────────────────────────────────────────
// Sub-components
// ─────────────────────────────────────────────────────────────────────

function StatTile({
    value,
    label,
    tone,
}: {
    value: number | string;
    label: string;
    tone?: 'warning' | 'critical' | 'success' | 'default';
}) {
    const valueColor =
        tone === 'warning'
            ? 'text-amber-200'
            : tone === 'critical'
                ? 'text-rose-200'
                : tone === 'success'
                    ? 'text-emerald-200'
                    : 'text-white';
    return (
        <div className="rounded-xl border border-white/15 bg-white/5 px-3 py-2.5 text-center">
            <div className={cn('text-2xl font-bold tabular-nums leading-tight', valueColor)}>{value}</div>
            <div className="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/70">{label}</div>
        </div>
    );
}

function HeroMetaItem({ icon: Icon, children }: { icon: typeof CalendarDays; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-[12.5px] text-white/80">
            <Icon className="h-3.5 w-3.5 text-white/60" />
            {children}
        </span>
    );
}

function HeroBadge({ tone, children }: { tone: 'warning' | 'critical' | 'info' | 'success'; children: ReactNode }) {
    const map: Record<string, string> = {
        warning: 'bg-amber-300/15 text-amber-50 border-amber-200/40',
        critical: 'bg-rose-300/15 text-rose-50 border-rose-200/40',
        info: 'bg-white/15 text-white border-white/30',
        success: 'bg-emerald-300/15 text-emerald-50 border-emerald-200/40',
    };
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold',
                map[tone],
            )}
        >
            {children}
        </span>
    );
}

// Week-nav chip — matches rostering's exact styling: rounded-md squared,
// xs-bold text, white/10 on white/20 border.
function WeekChip({
    children,
    onClick,
    solid,
    title,
}: {
    children: ReactNode;
    onClick?: () => void;
    solid?: boolean;
    title?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
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

// Right-hand filter pill — matches rostering's EntityFilter `onDark` styling:
// rounded-full pill, search icon, count suffix, ChevronDown.
function FilterPill({
    icon: Icon,
    label,
    count,
    onClick,
}: {
    icon: typeof Search;
    label: string;
    count?: number;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-haspopup="listbox"
            className="inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            <span className="max-w-[200px] truncate">
                {label}
                {typeof count === 'number' ? ` · ${count}` : ''}
            </span>
            <ChevronDown className="h-3 w-3 opacity-70" />
        </button>
    );
}

// ─────────────────────────────────────────────────────────────────────
// TimesheetsHero — custom hero matching the prototype design.
// ─────────────────────────────────────────────────────────────────────
export default function TimesheetsHero({
    summary,
    onCreateTimesheet,
    onPrevWeek,
    onNextWeek,
    onPickWeek,
    onMore,
    canCreate,
    sitesCount,
    staffCount,
}: {
    summary: TimesheetsHeroSummary;
    onCreateTimesheet: () => void;
    onPrevWeek?: () => void;
    onNextWeek?: () => void;
    onPickWeek?: () => void;
    onMore?: () => void;
    canCreate: boolean;
    sitesCount?: number;
    staffCount?: number;
}) {
    const coveragePct =
        summary.hours_target > 0
            ? Math.min(100, Math.round((summary.hours_this_week / summary.hours_target) * 100))
            : 0;

    const weekStart = new Date(summary.week_start);
    const prevWeekStart = new Date(weekStart);
    prevWeekStart.setDate(prevWeekStart.getDate() - 7);
    const nextWeekStart = new Date(weekStart);
    nextWeekStart.setDate(nextWeekStart.getDate() + 7);

    return (
        // Outer: rounded card with ops gradient. The orbs are absolutely-
        // positioned decorative circles clipped to the rounded shape.
        <section
            className="relative overflow-hidden rounded-2xl text-primary-foreground shadow-[0_8px_30px_-12px_rgba(80,0,150,0.35)]"
            style={{ background: 'linear-gradient(135deg, color-mix(in oklch, var(--category-ops) 90%, transparent), var(--category-ops), color-mix(in oklch, var(--category-ops) 80%, transparent))' }}
        >
            {/* Decorative orbs */}
            <div className="pointer-events-none absolute inset-0">
                <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
                <div className="absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                <div className="absolute right-1/3 top-1/4 h-24 w-24 rounded-full bg-white/5" />
            </div>

            <div className="relative p-6 md:p-8">
                <div className="flex flex-col items-center gap-6 md:flex-row md:items-start">
                    {/* Icon medallion */}
                    <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-white/20 bg-white/10 shadow-xl md:h-28 md:w-28">
                        <FileText className="h-12 w-12 text-white md:h-14 md:w-14" />
                    </div>

                    {/* Title cluster */}
                    <div className="min-w-0 flex-1 text-center md:text-left">
                        <div className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wider text-white/80 md:justify-start justify-center">
                            <span aria-hidden="true" className="relative inline-flex h-2 w-2">
                                <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-emerald-300/70" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300 ring-2 ring-emerald-300/30" />
                            </span>
                            Live timesheets · refreshed just now
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">
                            <span className="font-normal text-white/80">Kia ora {summary.firstName}, your week of timesheets — </span>
                            <span className="border-b-2 border-white/40 pb-0.5">
                                {fmtWeekdayMonthDay(summary.week_start)} → {fmtWeekdayMonthDay(summary.week_end)}
                            </span>
                        </h1>
                        <p className="mt-2 max-w-3xl text-[13.5px] leading-relaxed text-white/80">
                            <span className="font-semibold tabular-nums text-white">{summary.unapproved}</span> timesheet
                            {summary.unapproved === 1 ? '' : 's'} need a decision,{' '}
                            <span className="font-semibold tabular-nums text-white">{summary.timesheets_returned}</span> have been
                            returned for changes, and{' '}
                            <span className="font-semibold tabular-nums text-white">{summary.hours_this_week}</span> of{' '}
                            <span className="tabular-nums">{summary.hours_target}</span> rostered hours have been logged so far. Payroll
                            closes <span className="font-semibold text-white">{summary.next_payroll_date}</span>.
                        </p>

                        {/* Meta row */}
                        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 md:justify-start justify-center">
                            <HeroMetaItem icon={CalendarDays}>Week {summary.week_number} · Mon–Sun</HeroMetaItem>
                            <HeroMetaItem icon={MapPin}>
                                {summary.sites_count} site{summary.sites_count === 1 ? '' : 's'} · {summary.regions_count} region
                                {summary.regions_count === 1 ? '' : 's'}
                            </HeroMetaItem>
                            <HeroMetaItem icon={Users}>
                                {summary.rostered_today} rostered · {summary.staff_on_shift} on shift
                            </HeroMetaItem>
                        </div>

                        {/* Tone badges */}
                        <div className="mt-3 flex flex-wrap items-center gap-1.5 md:justify-start justify-center">
                            <HeroBadge tone="warning">
                                <AlertCircle className="h-3 w-3" />
                                {summary.unapproved} awaiting approval
                            </HeroBadge>
                            <HeroBadge tone="critical">
                                <Send className="h-3 w-3" />
                                {summary.timesheets_returned} returned to staff
                            </HeroBadge>
                            <HeroBadge tone="info">
                                <DollarSign className="h-3 w-3" />
                                Payroll closes {summary.next_payroll_date}
                            </HeroBadge>
                        </div>
                    </div>

                    {/* Right column: actions + 4-tile stats + hours progress */}
                    <div className="flex w-full shrink-0 flex-col items-stretch gap-3 md:w-[420px] md:items-end">
                        <div className="flex flex-wrap gap-2 md:justify-end">
                            {canCreate ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={onCreateTimesheet}
                                    className="bg-white text-primary hover:bg-white/90"
                                    data-testid="open-create-timesheet"
                                >
                                    <FilePlus2 className="mr-1.5 h-4 w-4" />
                                    Create timesheet
                                </Button>
                            ) : null}
                            <button
                                type="button"
                                onClick={onMore}
                                aria-label="More actions"
                                className="grid h-9 w-9 place-items-center rounded-lg border border-white/30 bg-white/5 text-white hover:bg-white/15"
                            >
                                <MoreHorizontal className="h-4 w-4" />
                            </button>
                        </div>

                        {/* 4 stat tiles */}
                        <div className="grid w-full grid-cols-4 gap-2">
                            <StatTile value={summary.timesheets_total} label="Total" />
                            <StatTile value={summary.timesheets_submitted} label="Pending" tone="warning" />
                            <StatTile value={summary.timesheets_approved} label="Approved" tone="success" />
                            <StatTile value={summary.timesheets_returned} label="Returned" tone="critical" />
                        </div>

                        {/* Hours-to-target progress */}
                        <div className="w-full rounded-xl border border-white/15 bg-white/5 p-3">
                            <div className="mb-1.5 flex items-center justify-between text-[11.5px] text-white/80">
                                <span className="font-medium text-white">Hours logged vs. rostered</span>
                                <span className="tabular-nums">
                                    {summary.hours_this_week} / {summary.hours_target}h · {coveragePct}%
                                </span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-white/15">
                                <div
                                    className="h-full rounded-full bg-white/85 transition-all"
                                    style={{ width: coveragePct + '%' }}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Footer band: week chips + filters — mirrors rostering's layout exactly */}
            <div className="relative border-t border-primary-foreground/20 px-4 md:px-6">
                <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <WeekChip onClick={onPrevWeek} title="Previous week">
                            <ChevronLeft className="h-3.5 w-3.5" />
                            Wk {summary.week_number - 1}
                        </WeekChip>
                        <WeekChip solid onClick={onPickWeek}>
                            <CalendarRange className="h-3.5 w-3.5" />
                            Wk {summary.week_number} · {fmtMonthDay(summary.week_start)} → {fmtMonthDay(summary.week_end)} · pick week
                            <ChevronDown className="h-3 w-3" />
                        </WeekChip>
                        <WeekChip onClick={onNextWeek} title="Next week">
                            Wk {summary.week_number + 1}
                            <ChevronRight className="h-3.5 w-3.5" />
                        </WeekChip>
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <FilterPill icon={Users} label="All staff" count={staffCount} />
                        <FilterPill icon={MapPin} label="All sites" count={sitesCount ?? summary.sites_count} />
                    </div>
                </div>
            </div>
        </section>
    );
}
