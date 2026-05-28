import {
    AlertTriangle,
    Bell,
    Briefcase,
    Calendar,
    CalendarDays,
    CalendarRange,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    MapPin,
    Plus,
    Search,
    UserCheck,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

import { WeekPicker, ymd } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero, type PageHeroBadge } from '@/components/page';

import type { JobBoardStats, JobBoardWeek } from './types';

const ANY = '__ANY__';

export interface JobBoardFilters {
    q?: string;
    date_range?: string;
    skill?: string;
    fit?: string;
}

interface JobBoardHeroProps {
    firstName: string;
    week: JobBoardWeek;
    stats: JobBoardStats;
    availableSkills: string[];
    filters: JobBoardFilters;
    onFilterChange: (key: keyof JobBoardFilters, value: string | null) => void;
    onWeekChange: (anchor: string) => void;
    canPostPosition?: boolean;
    sitesCount?: number;
    sitesWorkedThisWeek?: number;
    complianceBadge?: PageHeroBadge | null;
    sleepoverBlockedBadge?: PageHeroBadge | null;
    availabilityBadge?: PageHeroBadge | null;
    onPostPosition?: () => void;
    onAlertMe?: () => void;
    alertsEnabled?: boolean;
}

export function JobBoardHero({
    firstName,
    week,
    stats,
    availableSkills,
    filters,
    onFilterChange,
    onWeekChange,
    canPostPosition = false,
    sitesCount,
    sitesWorkedThisWeek = 0,
    complianceBadge,
    sleepoverBlockedBadge,
    availabilityBadge,
    onPostPosition,
    onAlertMe,
    alertsEnabled = false,
}: JobBoardHeroProps) {
    const weekRange = `${week.start_label} → ${week.end_label}`;
    const openCount = stats.open;
    const pickerBtnRef = useRef<HTMLButtonElement>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const selectedWeekStart = new Date(`${week.start}T00:00:00`);

    const badges: PageHeroBadge[] = [
        complianceBadge ?? {
            label: 'Your compliance is current',
            tone: 'success',
            icon: Check,
        },
        sleepoverBlockedBadge ?? null,
        availabilityBadge ?? null,
    ].filter(Boolean) as PageHeroBadge[];

    const liveTitle: ReactNode = (
        <span>
            <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                <span
                    aria-hidden="true"
                    className="relative inline-flex h-2 w-2"
                >
                    <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-emerald-300/70" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300 ring-2 ring-emerald-300/30" />
                </span>
                Live board · refreshed just now · {openCount} open shifts
            </span>
            <span className="block">
                <span className="font-normal text-primary-foreground/80">
                    Kia ora {firstName}, shifts ready to claim —
                </span>{' '}
                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                    {weekRange}
                </span>
            </span>
        </span>
    );

    return (
        <PageHero
            category="ops"
            icon={Briefcase}
            title={liveTitle}
            description={
                <span>
                    {stats.open} open position{stats.open === 1 ? '' : 's'} this
                    week,{' '}
                    <strong>{stats.eligible_for_you} match your eligibility</strong>
                    , and {stats.expiring_soon} expire within the hour.
                </span>
            }
            meta={[
                {
                    icon: CalendarDays,
                    label: `${weekRange} · Mon–Sun`,
                },
                {
                    icon: MapPin,
                    label: `${sitesCount ?? 0} site${sitesCount === 1 ? '' : 's'} · ${sitesWorkedThisWeek} site${sitesWorkedThisWeek === 1 ? '' : 's'} you've worked at this week`,
                },
                {
                    icon: UserCheck,
                    label: `${stats.eligible_for_you} eligible · ${stats.expiring_soon} expiring soon`,
                },
            ]}
            badges={badges}
            stats={[
                { label: 'Open', value: stats.open },
                { label: 'For you', value: stats.eligible_for_you },
                { label: 'Pending', value: stats.pending_approval },
                { label: 'Filled today', value: stats.filled_today },
            ]}
            actions={
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="job-board-alert-me"
                        title={
                            alertsEnabled
                                ? 'Notifications on — click to mute'
                                : 'Get notified when matching shifts open'
                        }
                        className={
                            alertsEnabled
                                ? 'border-emerald-300/60 bg-emerald-400/15 text-primary-foreground hover:bg-emerald-400/25'
                                : 'border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10'
                        }
                        onClick={onAlertMe}
                    >
                        <Bell className="mr-1 h-4 w-4" />
                        {alertsEnabled ? 'Alerts on' : 'Alert me'}
                    </Button>
                    {canPostPosition ? (
                        <Button
                            size="sm"
                            className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                            onClick={onPostPosition}
                        >
                            <Plus className="mr-1 h-4 w-4" /> Post position
                        </Button>
                    ) : null}
                </>
            }
            footer={
                <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            data-test="job-board-week-prev"
                            className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                            onClick={() => onWeekChange(week.prev)}
                        >
                            <ChevronLeft className="h-3.5 w-3.5" /> Prev week
                        </button>
                        <button
                            ref={pickerBtnRef}
                            type="button"
                            data-test="job-board-week-pick"
                            className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                            onClick={() => setPickerOpen((v) => !v)}
                            aria-haspopup="dialog"
                            aria-expanded={pickerOpen}
                        >
                            <CalendarRange className="h-3.5 w-3.5" />
                            {weekRange} · pick week
                            <ChevronDown className="h-3 w-3" />
                        </button>
                        <button
                            type="button"
                            data-test="job-board-week-next"
                            className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                            onClick={() => onWeekChange(week.next)}
                        >
                            Next week <ChevronRight className="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                            <Input
                                placeholder="Search title, client, suburb…"
                                className="h-8 w-[220px] border-primary-foreground/30 bg-primary-foreground/10 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/55"
                                value={filters.q ?? ''}
                                onChange={(event) =>
                                    onFilterChange(
                                        'q',
                                        event.target.value || null,
                                    )
                                }
                            />
                        </div>
                        <Select
                            value={filters.date_range ?? ANY}
                            onValueChange={(value) =>
                                onFilterChange(
                                    'date_range',
                                    value === ANY ? null : value,
                                )
                            }
                        >
                            <SelectTrigger
                                data-test="job-board-date-filter"
                                className="h-8 w-[130px] border-primary-foreground/30 bg-primary-foreground/10 text-xs text-primary-foreground [&>svg]:text-primary-foreground/80"
                            >
                                <SelectValue placeholder="Any date" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any date</SelectItem>
                                <SelectItem value="next_7_days">
                                    Next 7 days
                                </SelectItem>
                                <SelectItem value="this_weekend">
                                    This weekend
                                </SelectItem>
                                <SelectItem value="tonight">Tonight</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.skill ?? ANY}
                            onValueChange={(value) =>
                                onFilterChange(
                                    'skill',
                                    value === ANY ? null : value,
                                )
                            }
                        >
                            <SelectTrigger
                                data-test="job-board-skill-filter"
                                className="h-8 w-[140px] border-primary-foreground/30 bg-primary-foreground/10 text-xs text-primary-foreground [&>svg]:text-primary-foreground/80"
                            >
                                <SelectValue placeholder="All skills" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All skills</SelectItem>
                                {availableSkills.map((skill) => (
                                    <SelectItem key={skill} value={skill}>
                                        {skill}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.fit ?? 'all'}
                            onValueChange={(value) =>
                                onFilterChange(
                                    'fit',
                                    value === 'all' ? null : value,
                                )
                            }
                        >
                            <SelectTrigger
                                data-test="job-board-fit-filter"
                                className="h-8 w-[170px] border-primary-foreground/30 bg-primary-foreground/10 text-xs text-primary-foreground [&>svg]:text-primary-foreground/80"
                            >
                                <SelectValue placeholder="Any fit" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Any fit</SelectItem>
                                <SelectItem value="eligible">
                                    Eligible only
                                </SelectItem>
                                <SelectItem value="no-conflict">
                                    No double bookings
                                </SelectItem>
                                <SelectItem value="site">
                                    Sites I've worked at
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    {pickerOpen ? (
                        <WeekPicker
                            selectedWeekStart={selectedWeekStart}
                            anchorRef={pickerBtnRef}
                            onSelect={(nextMonday) => {
                                setPickerOpen(false);
                                onWeekChange(ymd(nextMonday));
                            }}
                            onClose={() => setPickerOpen(false)}
                        />
                    ) : null}
                </div>
            }
        />
    );
}

// Re-export Calendar for the badge fallback caller.
export const HeroBadgeIcons = { Calendar, AlertTriangle };

export default JobBoardHero;
