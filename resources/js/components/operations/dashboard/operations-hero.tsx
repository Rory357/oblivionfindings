import { Link } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarPlus,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    LayoutDashboard,
    MapPin,
    MoreHorizontal,
    Users,
    Zap,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

import { PageHero } from '@/components/page';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import { WeekPicker, startOfWeek } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { PulseDot } from './hover-popover';
import type { Hero, SiteOption, Week } from './types';

type Props = {
    firstName: string;
    todayLabel: string;
    week: Week;
    hero: Hero;
    activeClients: number;
    siteOptions: SiteOption[];
    siteFilter: number[];
    onSiteFilterChange: (next: number[]) => void;
    staffFilter: number[];
    onStaffFilterChange: (next: number[]) => void;
    clientFilter: number[];
    onClientFilterChange: (next: number[]) => void;
    onWeekChange: (anchorIso: string) => void;
};

export function OperationsHero({
    firstName,
    todayLabel,
    week,
    hero,
    activeClients,
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

    const title: ReactNode = (
        <span>
            <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase md:justify-start">
                <PulseDot />
                Live operations · refreshed just now
            </span>
            <span className="block">
                <span className="font-normal text-primary-foreground/80">
                    Kia ora {firstName},
                </span>{' '}
                ops at a glance —{' '}
                <span className="border-b-2 border-primary-foreground/40 pb-0.5 whitespace-nowrap">
                    {todayLabel}
                </span>
            </span>
        </span>
    );

    const description: ReactNode = (
        <span>
            <span className="font-semibold text-primary-foreground tabular-nums">
                {activeClients}
            </span>{' '}
            active clients across{' '}
            <span className="font-semibold text-primary-foreground tabular-nums">
                {hero.sites_count}
            </span>{' '}
            sites. Day is running on target — a few items need your attention
            below.
        </span>
    );

    const ymdLocal = (d: Date) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    return (
        <PageHero
            category="ops"
            icon={LayoutDashboard}
            title={title}
            description={description}
            meta={[
                {
                    icon: CalendarDays,
                    label: `Week ${week.number} · ${week.start_label} → ${week.end_label}`,
                },
                {
                    icon: MapPin,
                    label: `${hero.sites_count} sites · ${hero.regions_count} regions`,
                },
                {
                    icon: Users,
                    label: `${hero.rostered_today} rostered · ${hero.staff_on_shift} on shift · ${hero.on_leave} on leave`,
                },
            ]}
            stats={[
                { label: 'Coverage', value: `${hero.coverage_pct}%` },
                { label: 'Shifts today', value: hero.shifts_today },
                { label: 'Staff on', value: hero.staff_on_shift },
                {
                    label: 'Open',
                    value: hero.unassigned_open_24h,
                    tone: hero.unassigned_open_24h > 0 ? 'warning' : 'neutral',
                },
            ]}
            actions={
                <>
                    <Button
                        asChild
                        size="sm"
                        className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                    >
                        <Link href="/operations/shifts?create=1">
                            <CalendarPlus className="mr-1 h-3.5 w-3.5" /> Create
                            shift
                        </Link>
                    </Button>
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                    >
                        <Link href="/operations/rostering">
                            <CalendarDays className="mr-1 h-3.5 w-3.5" /> Open
                            roster
                        </Link>
                    </Button>
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                    >
                        <Link href="/operations/timesheets/approvals">
                            <ClipboardCheck className="mr-1 h-3.5 w-3.5" />{' '}
                            Approve queue
                        </Link>
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label="More"
                        className="h-8 w-8 border-primary-foreground/30 bg-transparent p-0 text-primary-foreground hover:bg-primary-foreground/10"
                    >
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </>
            }
            footer={
                <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Button
                            unstyled
                            type="button"
                            onClick={() => onWeekChange(week.prev)}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full border px-3.5 py-1.5 text-[12px] font-semibold text-primary-foreground hover:bg-primary-foreground/20',
                                'border-primary-foreground/20 bg-primary-foreground/10',
                            )}
                        >
                            <ChevronLeft className="h-3.5 w-3.5" />
                            Week {week.prev_number} · {week.prev_label}
                        </Button>
                        <Button
                            unstyled
                            ref={pickerBtnRef}
                            type="button"
                            onClick={() => setPickerOpen((v) => !v)}
                            aria-haspopup="dialog"
                            aria-expanded={pickerOpen}
                            className="inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/35 bg-primary-foreground/20 px-3.5 py-1.5 text-[12px] font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                        >
                            <CalendarRange className="h-3.5 w-3.5" />
                            Week {week.number} · {week.start_label} →{' '}
                            {week.end_label} · pick week
                            <ChevronDown className="h-3 w-3" />
                        </Button>
                        <Button
                            unstyled
                            type="button"
                            onClick={() => onWeekChange(week.next)}
                            className="inline-flex items-center gap-1 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3.5 py-1.5 text-[12px] font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                        >
                            Week {week.next_number} · {week.next_label}
                            <ChevronRight className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            unstyled
                            type="button"
                            onClick={() =>
                                onWeekChange(ymdLocal(startOfWeek(new Date())))
                            }
                            className="inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/20 px-3.5 py-1.5 text-[12px] font-medium text-primary-foreground/85 hover:bg-primary-foreground/10"
                        >
                            <Zap className="h-3.5 w-3.5" /> Jump to today
                        </Button>
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <MultiEntityFilter
                            label="Site"
                            allLabel="All sites"
                            items={siteOptions}
                            value={siteFilter}
                            onChange={onSiteFilterChange}
                            onDark
                        />
                        <MultiEntityFilter
                            label="Staff"
                            allLabel="All staff"
                            pluralLabel="staff"
                            items={[]}
                            value={staffFilter}
                            onChange={onStaffFilterChange}
                            onDark
                        />
                        <MultiEntityFilter
                            label="Client"
                            allLabel="All clients"
                            items={[]}
                            value={clientFilter}
                            onChange={onClientFilterChange}
                            onDark
                        />
                    </div>
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
                </div>
            }
        />
    );
}
