/**
 * The Operations dashboard header: the Event Horizon page header
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md), built the same way as the
 * Respite header. Every meter links to the page its number comes from; the
 * filter row holds the week stepper and the site, staff and client filters.
 */
import { Link } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarPlus,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    LayoutDashboard,
} from 'lucide-react';
import { useRef, useState } from 'react';

import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import { WeekPicker, startOfWeek } from '@/components/rostering/week-picker';

import type { Hero, SiteOption, Week } from './types';

/** Draws MultiEntityFilter's on-band trigger as a 23px filter-row chip. */
const FILTER_CHIP = 'box-border h-[23px] rounded-[8px] px-2 py-0 text-[11.5px]';

const ymdLocal = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

type Props = {
    todayLabel: string;
    week: Week;
    hero: Hero;
    siteOptions: SiteOption[];
    siteFilter: number[];
    onSiteFilterChange: (next: number[]) => void;
    staffFilter: number[];
    onStaffFilterChange: (next: number[]) => void;
    clientFilter: number[];
    onClientFilterChange: (next: number[]) => void;
    onWeekChange: (anchorIso: string) => void;
};

export function OperationsHeader({
    todayLabel,
    week,
    hero,
    siteOptions,
    siteFilter,
    onSiteFilterChange,
    staffFilter,
    onStaffFilterChange,
    clientFilter,
    onClientFilterChange,
    onWeekChange,
}: Props) {
    const pickerBtnRef = useRef<HTMLButtonElement>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const selectedWeekStart = new Date(`${week.start}T00:00:00`);
    const thisWeek = ymdLocal(startOfWeek(new Date()));

    return (
        <PageHeader
            icon={LayoutDashboard}
            title="Operations"
            subline={`${todayLabel} · ${hero.sites_count} site${hero.sites_count === 1 ? '' : 's'} · ${hero.regions_count} region${hero.regions_count === 1 ? '' : 's'}`}
            actions={
                <>
                    <PageHeaderGlassButton asChild icon={CalendarDays}>
                        <Link href="/operations/rostering">Open roster</Link>
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton asChild icon={ClipboardCheck}>
                        <Link href="/operations/timesheets/approvals">
                            Approve timesheets
                        </Link>
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton asChild icon={CalendarPlus}>
                        <Link href="/operations/shifts?create=1">
                            Add shift
                        </Link>
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Coverage"
                        value={`${hero.coverage_pct}%`}
                        href="/operations/rostering"
                        ariaLabel="View today's roster"
                    >
                        <PageHeaderMeterBar percent={hero.coverage_pct} />
                        <PageHeaderMeterCaption>
                            {hero.shifts_today > 0
                                ? "Of today's shifts have staff"
                                : 'No shifts today'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Shifts today"
                        href="/operations/shifts"
                        ariaLabel="View shifts"
                    >
                        <PageHeaderMeterBig>
                            {hero.shifts_today}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {`Across ${hero.sites_count} site${hero.sites_count === 1 ? '' : 's'}`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Staff on shift"
                        href="/attendance"
                        ariaLabel="View who is on shift"
                    >
                        <PageHeaderMeterBig>
                            {hero.staff_on_shift}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {`${hero.rostered_today} rostered today`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open shifts"
                        tone={
                            hero.unassigned_open_24h > 0 ? 'warning' : 'brand'
                        }
                        href="/operations/rostering?filter=open"
                        ariaLabel="View open shifts"
                    >
                        <PageHeaderMeterBig>
                            {hero.unassigned_open_24h}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            No staff, starting in the next 24 hours
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <PageHeaderFilterButton
                            icon={ChevronLeft}
                            onClick={() => onWeekChange(week.prev)}
                            aria-label={`Previous week, week ${week.prev_number}`}
                        />
                        <PageHeaderFilterButton
                            ref={pickerBtnRef}
                            icon={CalendarRange}
                            onClick={() => setPickerOpen((open) => !open)}
                            aria-haspopup="dialog"
                            aria-expanded={pickerOpen}
                        >
                            Week {week.number} · {week.start_label} →{' '}
                            {week.end_label}
                        </PageHeaderFilterButton>
                        <PageHeaderFilterButton
                            icon={ChevronRight}
                            onClick={() => onWeekChange(week.next)}
                            aria-label={`Next week, week ${week.next_number}`}
                        />
                        {week.start !== thisWeek ? (
                            <PageHeaderFilterButton
                                onClick={() => onWeekChange(thisWeek)}
                            >
                                This week
                            </PageHeaderFilterButton>
                        ) : null}
                    </div>
                    <MultiEntityFilter
                        label="Site"
                        allLabel="All sites"
                        items={siteOptions}
                        value={siteFilter}
                        onChange={onSiteFilterChange}
                        onDark
                        className={FILTER_CHIP}
                    />
                    <MultiEntityFilter
                        label="Staff"
                        allLabel="All staff"
                        pluralLabel="staff"
                        items={[]}
                        value={staffFilter}
                        onChange={onStaffFilterChange}
                        onDark
                        className={FILTER_CHIP}
                    />
                    <MultiEntityFilter
                        label="Client"
                        allLabel="All clients"
                        items={[]}
                        value={clientFilter}
                        onChange={onClientFilterChange}
                        onDark
                        className={FILTER_CHIP}
                    />
                    {pickerOpen ? (
                        <WeekPicker
                            selectedWeekStart={selectedWeekStart}
                            anchorRef={pickerBtnRef}
                            onSelect={(ws) => {
                                onWeekChange(ymdLocal(ws));
                                setPickerOpen(false);
                            }}
                            onClose={() => setPickerOpen(false)}
                        />
                    ) : null}
                </>
            }
        />
    );
}
