import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page';
import type { RosterTabItem } from '@/components/rostering';
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

export function ParticipationHeader({
    filters,
    hero,
    meetingCount,
    sites,
    tabs,
    periods,
    statuses,
    actions,
    onFilters,
    onTab,
    onClear,
}: {
    filters: ParticipationFilters;
    hero: ParticipationHero;
    meetingCount: number;
    sites: { id: number; name: string }[];
    tabs: RosterTabItem[];
    periods: { key: string; label: string }[];
    statuses: [string, string][];
    actions?: ReactNode;
    onFilters: (next: Partial<ParticipationFilters>) => void;
    onTab: (tab: string) => void;
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
    const href = (next: Partial<ParticipationFilters>) => {
        const values = { ...filters, q: null, focus: null, ...next };
        const search = new URLSearchParams();
        for (const [key, value] of Object.entries(values)) {
            if (value !== null && value !== '') search.set(key, String(value));
        }
        return `${BASE}?${search}`;
    };
    const meters = [
        {
            label: 'Active representatives',
            value: c.participation.active_reps,
            caption:
                b.sites_total > 0
                    ? `${b.reps_coverage_pct}% recorded coverage · ${b.sites_covered}/${b.sites_total} sites · ${c.participation.sites_without_rep} without a rep`
                    : 'Site coverage not confirmed',
            href: href({
                tab: 'representatives',
                status: 'active',
                period: 'all',
            }),
        },
        {
            label: 'All meetings',
            value: meetingCount,
            caption: `${c.participation.committees} active committees · ${c.participation.meetings_quarter} meetings this quarter`,
            href: href({ tab: 'meetings', status: null, period: 'all' }),
        },
        {
            label: 'Open consultations',
            value: c.consultation.open,
            caption: `${c.consultation.awaiting_feedback} open or feedback received · ${b.consultations_awaiting} awaiting progression`,
            href: href({ tab: 'consultations', status: 'open', period: 'all' }),
        },
        {
            label: 'Meetings with outstanding actions',
            value: c.consultation.meetings_with_outstanding_actions,
            caption: `${c.consultation.overdue_actions} unfinished action items across these meetings`,
            href: href({
                tab: 'meetings',
                status: null,
                period: 'all',
                focus: 'actions_outstanding',
            }),
        },
        {
            label: 'Minutes missing',
            value: c.consultation.minutes_outstanding,
            caption: `${b.minutes_overdue} completed meetings without filed minutes`,
            href: href({
                tab: 'meetings',
                status: null,
                period: 'all',
                focus: 'minutes_missing',
            }),
        },
        {
            label: 'Rep training below 2 days',
            value: b.training_below_minimum,
            caption: 'Cumulative recorded days · annual training not confirmed',
            href: href({
                tab: 'representatives',
                status: 'active',
                period: 'all',
                focus: 'training_below_two',
            }),
        },
    ];
    return (
        <PageHeader
            title="Worker participation"
            icon={Users}
            wrapTitle
            frontline
            subline="Representatives, committee meetings and staff consultations"
            mobileSummary={`${tabs.find((item) => item.id === filters.tab)?.label ?? 'Participation records'} · recorded work`}
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
            meters={meters.map((meter) => (
                <PageHeaderMeterBlock
                    key={meter.label}
                    label={meter.label}
                    href={meter.href}
                    tone="neutral"
                    preserveState
                    preserveScroll
                >
                    <PageHeaderMeterBig>{meter.value}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {meter.caption}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ))}
            filters={
                <>
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
                                          {
                                              value: 'all',
                                              label: 'All representatives',
                                          },
                                          {
                                              value: 'training_below_two',
                                              label: 'Active reps below 2 training days',
                                          },
                                      ]
                                    : [
                                          {
                                              value: 'all',
                                              label: 'All meetings',
                                          },
                                          {
                                              value: 'actions_outstanding',
                                              label: 'Meetings with outstanding actions',
                                          },
                                          {
                                              value: 'minutes_missing',
                                              label: 'Completed meetings missing minutes',
                                          },
                                      ]
                            }
                        />
                    )}
                    <PageHeaderFilterSelect
                        label="Period"
                        value={filters.period}
                        onChange={(period) => onFilters({ period })}
                        options={[
                            { value: 'all', label: 'All periods' },
                            ...periods.map((item) => ({
                                value: item.key,
                                label: item.label,
                            })),
                        ]}
                    />
                    {sites.length > 0 && (
                        <PageHeaderFilterSelect
                            label="Site"
                            value={
                                filters.site_id === null
                                    ? 'all'
                                    : String(filters.site_id)
                            }
                            onChange={(site) =>
                                onFilters({
                                    site_id:
                                        site === 'all' ? null : Number(site),
                                })
                            }
                            options={[
                                { value: 'all', label: 'All sites' },
                                ...sites.map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                })),
                            ]}
                        />
                    )}
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? 'all'}
                        onChange={(status) =>
                            onFilters({
                                status: status === 'all' ? null : status,
                            })
                        }
                        options={[
                            { value: 'all', label: 'All' },
                            ...statuses.map(([value, label]) => ({
                                value,
                                label,
                            })),
                        ]}
                    />
                    {hasFilters && (
                        <PageHeaderGlassButton
                            icon={X}
                            onClick={() => {
                                setQuery('');
                                onClear();
                            }}
                        >
                            Clear filters
                        </PageHeaderGlassButton>
                    )}
                </>
            }
            rail={
                <PageHeaderRail
                    items={tabs.map((item) => ({
                        key: item.id,
                        label: item.label,
                        icon: item.icon,
                        count:
                            typeof item.badge === 'number'
                                ? item.badge
                                : undefined,
                        alert: item.tone === 'critical',
                    }))}
                    value={filters.tab}
                    onSelect={onTab}
                    ariaLabel="Participation views"
                />
            }
        />
    );
}
