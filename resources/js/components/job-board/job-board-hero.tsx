import { type PageHeroBadge } from '@/components/page';
import {
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page/page-header';
import {
    WeekPicker,
    weekLabel as isoWeekLabel,
    ymd,
} from '@/components/rostering/week-picker';
import { StatusBadge } from '@/components/ui/status-badge';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import {
    AlertTriangle,
    Bell,
    Briefcase,
    Calendar,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Plus,
    Search,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import type { JobBoardScope, JobBoardStats, JobBoardWeek } from './types';

export interface JobBoardFilters {
    q?: string;
    date_range?: string;
    skill?: string;
    fit?: string;
}
interface JobBoardHeroProps {
    firstName?: string;
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
    rail?: ReactNode;
    onScopeChange?: (scope: JobBoardScope) => void;
    canApprove?: boolean;
    onSearchSubmit?: () => void;
    loading?: boolean;
    readNotice?: string | null;
}
export function JobBoardHero({
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
    rail,
    onScopeChange,
    canApprove = false,
    onSearchSubmit,
    loading = false,
    readNotice,
}: JobBoardHeroProps) {
    const weekRange = week.start_label + ' → ' + week.end_label;
    const pickerBtnRef = useRef<HTMLButtonElement>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const selectedWeekStart = new Date(week.start + 'T12:00:00');
    const badges = [
        complianceBadge,
        sleepoverBlockedBadge,
        availabilityBadge,
    ].filter((badge): badge is PageHeroBadge => Boolean(badge));
    const meters: [JobBoardScope, string, number, string][] = [
        ['all', 'Open', stats.open, 'Open positions this week'],
        [
            'for-you',
            'For you',
            stats.eligible_for_you,
            'Match your recorded eligibility',
        ],
        [
            canApprove ? 'approvals' : 'mine',
            canApprove ? 'Pending' : 'My claims',
            canApprove ? stats.pending_approval : stats.mine,
            canApprove
                ? 'Claims awaiting approval'
                : 'Positions you have claimed',
        ],
        [
            'replacements',
            'Replacements',
            stats.replacements,
            'Requests for replacement cover',
        ],
    ];
    const headerPrimaryAction = canPostPosition ? (
        <PageHeaderPrimaryButton icon={Plus} onClick={onPostPosition}>
            Post position
        </PageHeaderPrimaryButton>
    ) : null;
    const headerSecondaryActions = (
        <>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    if (onSearchSubmit) onSearchSubmit();
                    else onFilterChange('q', filters.q || null);
                }}
                className="w-full min-w-0 basis-full sm:w-auto sm:flex-1 sm:basis-auto"
            >
                <fieldset
                    disabled={loading}
                    className="flex min-w-0 flex-wrap gap-2"
                >
                    <PageHeaderSearch
                        value={filters.q ?? ''}
                        onChange={(value) => onFilterChange('q', value || null)}
                        placeholder="Search title, client, suburb…"
                        className="min-w-0"
                    />
                    <PageHeaderGlassButton type="submit" icon={Search}>
                        Search
                    </PageHeaderGlassButton>
                </fieldset>
            </form>
            <PageHeaderGlassButton
                icon={Bell}
                data-test="job-board-alert-me"
                active={alertsEnabled}
                title={
                    alertsEnabled
                        ? 'Notifications on — click to mute'
                        : 'Get notified when matching shifts open'
                }
                onClick={onAlertMe}
            >
                {alertsEnabled ? 'Alerts on' : 'Alert me'}
            </PageHeaderGlassButton>
        </>
    );

    return (
        <>
            <fieldset
                disabled={loading}
                aria-busy={loading}
                className="m-0 min-w-0 border-0 p-0"
            >
                <WorkforcePageHeader
                    title="Job Board"
                    icon={Briefcase}
                    subline={
                        weekRange +
                        ' · ' +
                        (sitesCount ?? 0) +
                        ' sites · Find and claim available shifts'
                    }
                    actions={
                        <>
                            {headerSecondaryActions}
                            {headerPrimaryAction}
                        </>
                    }
                    mobilePrimaryAction={headerPrimaryAction}
                    mobileSecondaryActions={headerSecondaryActions}
                    mobileSummary={weekRange}
                    meters={
                        <>
                            {meters.map(([scope, label, value, caption]) => (
                                <PageHeaderMeterBlock
                                    key={scope}
                                    label={label}
                                    ariaLabel={'View ' + label.toLowerCase()}
                                    {...(onScopeChange
                                        ? {
                                              onClick: () =>
                                                  onScopeChange(scope),
                                          }
                                        : {
                                              href:
                                                  '/operations/job-board?scope=' +
                                                  scope +
                                                  '&week=' +
                                                  week.start,
                                          })}
                                >
                                    <PageHeaderMeterBig>
                                        {value}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterButton
                                icon={ChevronLeft}
                                data-test="job-board-week-prev"
                                aria-label="Previous week"
                                onClick={() => onWeekChange(week.prev)}
                            />
                            <PageHeaderFilterButton
                                ref={pickerBtnRef}
                                icon={CalendarRange}
                                data-test="job-board-week-pick"
                                onClick={() => setPickerOpen((value) => !value)}
                                aria-haspopup="dialog"
                                aria-expanded={pickerOpen}
                            >
                                {isoWeekLabel(selectedWeekStart)} · {weekRange}{' '}
                                · pick week
                            </PageHeaderFilterButton>
                            <PageHeaderFilterButton
                                icon={ChevronRight}
                                data-test="job-board-week-next"
                                aria-label="Next week"
                                onClick={() => onWeekChange(week.next)}
                            />
                            <PageHeaderFilterSelect
                                testId="job-board-date-filter"
                                label="Any date"
                                allValue="__ANY__"
                                value={filters.date_range ?? '__ANY__'}
                                onChange={(value) =>
                                    onFilterChange(
                                        'date_range',
                                        value === '__ANY__' ? null : value,
                                    )
                                }
                                options={[
                                    { value: '__ANY__', label: 'Any date' },
                                    {
                                        value: 'next_7_days',
                                        label: 'Next 7 days',
                                    },
                                    {
                                        value: 'this_weekend',
                                        label: 'This weekend',
                                    },
                                    { value: 'tonight', label: 'Tonight' },
                                ]}
                            />
                            <PageHeaderFilterSelect
                                testId="job-board-skill-filter"
                                label="All skills"
                                allValue="__ANY__"
                                value={filters.skill ?? '__ANY__'}
                                onChange={(value) =>
                                    onFilterChange(
                                        'skill',
                                        value === '__ANY__' ? null : value,
                                    )
                                }
                                options={[
                                    { value: '__ANY__', label: 'All skills' },
                                    ...availableSkills.map((skill) => ({
                                        value: skill,
                                        label: skill,
                                    })),
                                ]}
                            />
                            <PageHeaderFilterSelect
                                testId="job-board-fit-filter"
                                label="Any fit"
                                value={filters.fit ?? 'all'}
                                onChange={(value) =>
                                    onFilterChange(
                                        'fit',
                                        value === 'all' ? null : value,
                                    )
                                }
                                options={[
                                    { value: 'all', label: 'Any fit' },
                                    {
                                        value: 'eligible',
                                        label: 'Eligible only',
                                    },
                                    {
                                        value: 'no-conflict',
                                        label: 'No double bookings',
                                    },
                                    {
                                        value: 'site',
                                        label: "Sites I've worked at",
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={rail}
                    details={
                        <>
                            {readNotice ? (
                                <p role="status">{readNotice}</p>
                            ) : null}
                            <p>
                                {stats.filled_today} filled today ·{' '}
                                {stats.expiring_soon} expiring within an hour ·{' '}
                                {sitesWorkedThisWeek} sites worked this week
                            </p>
                            {badges.length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {badges.map((badge, index) => (
                                        <StatusBadge
                                            key={index}
                                            variant={
                                                badge.tone === 'default'
                                                    ? 'neutral'
                                                    : (badge.tone ?? 'neutral')
                                            }
                                        >
                                            {badge.label}
                                        </StatusBadge>
                                    ))}
                                </div>
                            ) : null}
                        </>
                    }
                />
            </fieldset>
            {pickerOpen ? (
                <WeekPicker
                    selectedWeekStart={selectedWeekStart}
                    anchorRef={pickerBtnRef}
                    onSelect={(date) => {
                        setPickerOpen(false);
                        onWeekChange(ymd(date));
                    }}
                    onClose={() => setPickerOpen(false)}
                />
            ) : null}
        </>
    );
}
export const HeroBadgeIcons = { Calendar, AlertTriangle };
export default JobBoardHero;
