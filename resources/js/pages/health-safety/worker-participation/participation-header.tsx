/**
 * Worker participation header (approved H&S drawings pg-hsn-104 … 104c): the
 * title row with search and the page's actions, four number boxes, the
 * filters, and the People and participation hub on the rail, whose three
 * views are this page's tabs (drawing D4). Every number is a link to the
 * matching all-period view and keeps the code's careful captions — recorded
 * coverage, cumulative (not annual) training — so nothing claims more than
 * the records show. The two numbers beyond four lead the body as a tally
 * (ParticipationTally), per the header rule.
 */
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderSearch,
} from '@/components/page';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { Tally } from '@/components/signature/blocks';
import { Users, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

export type ParticipationFilters = {
    tab: string;
    site_id: number | null;
    status: string | null;
    period: string;
    q: string | null;
    focus?: string | null;
};

export type ParticipationHero = {
    clusters: {
        participation: {
            active_reps: number;
            sites_without_rep: number;
            committees: number;
            meetings_quarter: number;
        };
        consultation: {
            open: number;
            awaiting_feedback: number;
            overdue_actions: number;
            meetings_with_outstanding_actions: number;
            minutes_outstanding: number;
        };
    };
    badges: {
        reps_coverage_pct: number;
        sites_total: number;
        sites_covered: number;
        minutes_overdue: number;
        consultations_awaiting: number;
        training_below_minimum: number;
    };
};

const BASE = '/health-safety/worker-participation';

/** A summary link: the current Site stays, search and an applied focus never carry over. */
export function participationHref(
    filters: ParticipationFilters,
    next: Partial<ParticipationFilters>,
): string {
    const values = { ...filters, q: null, focus: null, ...next };
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
        if (value !== null && value !== '') search.set(key, String(value));
    }
    return `${BASE}?${search}`;
}

export function ParticipationHeader({
    filters,
    hero,
    sites,
    periods,
    statuses,
    actions,
    rail,
    onFilters,
    onClear,
}: {
    filters: ParticipationFilters;
    hero: ParticipationHero;
    sites: { id: number; name: string }[];
    periods: { key: string; label: string }[];
    statuses: [string, string][];
    actions?: ReactNode;
    /** The People and participation hub rail: its three views are this page's tabs. */
    rail?: ReactNode;
    onFilters: (next: Partial<ParticipationFilters>) => void;
    onClear: () => void;
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    useEffect(() => setQuery(filters.q ?? ''), [filters.q]);
    const c = hero.clusters;
    const b = hero.badges;
    const hasFilters = !!(
        filters.site_id ||
        filters.status ||
        filters.q ||
        filters.focus ||
        query ||
        filters.period !== 'quarter'
    );
    const href = (next: Partial<ParticipationFilters>) => participationHref(filters, next);
    return (
        <PageHeader
            title="Worker participation"
            icon={Users}
            wrapTitle
            subline="Representatives, committee meetings and staff consultations"
            actions={
                <>
                    <PageHeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder="Search participation records"
                        ariaLabel="Search participation records"
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                onFilters({ q: query || null });
                            }
                        }}
                    />
                    {actions}
                </>
            }
            strip={
                <PageHeaderNumbers>
                    <PageHeaderNumber
                        label="Active representatives"
                        value={c.participation.active_reps}
                        caption={
                            b.sites_total > 0
                                ? `${b.sites_covered} of ${b.sites_total} sites have a representative · ${c.participation.sites_without_rep} without one`
                                : 'No sites to check yet'
                        }
                        tone={c.participation.sites_without_rep > 0 ? 'warning' : undefined}
                        href={href({ tab: 'representatives', status: 'active', period: 'all' })}
                        preserveState
                        preserveScroll
                    />
                    <PageHeaderNumber
                        label="Open consultations"
                        value={c.consultation.open}
                        caption={`${c.consultation.awaiting_feedback} open or with feedback received · ${b.consultations_awaiting} waiting for the next step`}
                        href={href({ tab: 'consultations', status: 'open', period: 'all' })}
                        preserveState
                        preserveScroll
                    />
                    <PageHeaderNumber
                        label="Meetings with unfinished actions"
                        value={c.consultation.meetings_with_outstanding_actions}
                        caption={`${c.consultation.overdue_actions} ${c.consultation.overdue_actions === 1 ? 'action' : 'actions'} still to do from these meetings`}
                        tone={c.consultation.meetings_with_outstanding_actions > 0 ? 'warning' : undefined}
                        href={href({ tab: 'meetings', status: null, period: 'all', focus: 'actions_outstanding' })}
                        preserveState
                        preserveScroll
                    />
                    <PageHeaderNumber
                        label="Minutes missing"
                        value={c.consultation.minutes_outstanding}
                        caption={`${b.minutes_overdue} ${b.minutes_overdue === 1 ? 'meeting' : 'meetings'} held with no minutes uploaded`}
                        tone={c.consultation.minutes_outstanding > 0 ? 'warning' : undefined}
                        href={href({ tab: 'meetings', status: null, period: 'all', focus: 'minutes_missing' })}
                        preserveState
                        preserveScroll
                    />
                </PageHeaderNumbers>
            }
            filters={
                <>
                    {sites.length > 0 && (
                        <PageHeaderFilterSelect
                            label="Site"
                            value={filters.site_id === null ? 'all' : String(filters.site_id)}
                            onChange={(site) => onFilters({ site_id: site === 'all' ? null : Number(site) })}
                            options={[
                                { value: 'all', label: 'All sites' },
                                ...sites.map((site) => ({ value: String(site.id), label: site.name })),
                            ]}
                        />
                    )}
                    <PageHeaderFilterSelect
                        label="Period"
                        value={filters.period}
                        onChange={(period) => onFilters({ period })}
                        options={[
                            { value: 'all', label: 'All periods' },
                            ...periods.map((item) => ({ value: item.key, label: item.label })),
                        ]}
                    />
                    {filters.tab !== 'consultations' && (
                        <PageHeaderFilterSelect
                            label="Show"
                            value={filters.focus ?? 'all'}
                            onChange={(focus) =>
                                onFilters({
                                    focus: focus === 'all' ? null : focus,
                                    status: null,
                                    period: 'all',
                                })
                            }
                            options={
                                filters.tab === 'representatives'
                                    ? [
                                          { value: 'all', label: 'All representatives' },
                                          { value: 'training_below_two', label: 'Active representatives with under 2 training days' },
                                      ]
                                    : [
                                          { value: 'all', label: 'All meetings' },
                                          { value: 'actions_outstanding', label: 'Meetings with unfinished actions' },
                                          { value: 'minutes_missing', label: 'Held meetings with no minutes uploaded' },
                                      ]
                            }
                        />
                    )}
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? 'all'}
                        onChange={(status) => onFilters({ status: status === 'all' ? null : status })}
                        options={[{ value: 'all', label: 'All' }, ...statuses.map(([value, label]) => ({ value, label }))]}
                    />
                    {hasFilters && (
                        <PageHeaderFilterButton
                            icon={X}
                            onClick={() => {
                                setQuery('');
                                onClear();
                            }}
                        >
                            Clear filters
                        </PageHeaderFilterButton>
                    )}
                </>
            }
            rail={rail}
        />
    );
}

/** The two numbers beyond the header's four: every meeting, and representatives under two recorded training days. */
export function ParticipationTally({
    filters,
    hero,
    meetingCount,
}: {
    filters: ParticipationFilters;
    hero: ParticipationHero;
    meetingCount: number;
}) {
    const c = hero.clusters.participation;
    return (
        <Tally
            title="Committees and training"
            keepZeros
            items={[
                {
                    value: meetingCount,
                    label: 'All meetings',
                    sub: `${c.committees} active committees · ${c.meetings_quarter} meetings this quarter`,
                    href: participationHref(filters, { tab: 'meetings', status: null, period: 'all' }),
                    linkLabel: 'View all meetings',
                },
                {
                    value: hero.badges.training_below_minimum,
                    label: 'Representatives with under 2 training days',
                    sub: 'Counts every training day ever recorded, not just this year',
                    tone: hero.badges.training_below_minimum > 0 ? 'warning' : undefined,
                    href: participationHref(filters, {
                        tab: 'representatives',
                        status: 'active',
                        period: 'all',
                        focus: 'training_below_two',
                    }),
                    linkLabel: 'View representatives with under 2 training days',
                },
            ]}
        />
    );
}
