import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintReturn,
} from '@/lib/health-safety/restraint-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    Download,
    FileText,
    Plus,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    RESTRAINT_TYPE_OPTIONS,
    SEVERITY_OPTIONS,
    titleCase,
    type RestraintFilters,
    type RestraintHero,
    type RestraintTabCounts,
} from './shared';

type EventRow = {
    id: number;
    reference: string;
    client: { id: number; name: string };
    site: { id: number; name: string } | null;
    restraint_type: string;
    severity: string;
    started_at: string | null;
    duration_minutes: number | null;
    within_support_plan: boolean;
    injury_occurred: boolean;
    needs_review: boolean;
    source_revision: number;
};
type PlanRow = {
    id: number;
    reference: string;
    title: string;
    client: { id: number; name: string };
    status: string;
    restrictive_practice_type: string | null;
    review_date: string | null;
    review_state: string;
    published_version_id: number | null;
    authorization_state: string;
};
type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    current_page: number;
    total: number;
};
type Props = {
    lens: 'events' | 'plans';
    tab: string;
    events: Page<EventRow>;
    plans: Page<PlanRow>;
    tabCounts: RestraintTabCounts;
    hero: RestraintHero;
    filters: RestraintFilters;
    clients: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    can: { create: boolean; review: boolean; manage: boolean };
};
const EVENT_TABS = [
    { key: 'all', label: 'All interventions' },
    { key: 'unreviewed', label: 'Needs review' },
    { key: 'out_of_plan', label: 'Reported outside plan' },
    { key: 'injury', label: 'Injury recorded' },
    { key: 'critical', label: 'Critical' },
    { key: '30d', label: 'Last 30 days' },
];
const PLAN_TABS = [
    { key: 'all', label: 'Current plans' },
    { key: 'active', label: 'Active' },
    { key: 'draft', label: 'Drafts' },
    { key: 'review_due', label: 'Review due' },
    { key: 'under_review', label: 'Under review' },
    { key: 'archived', label: 'Archived' },
];

export default function RestraintWorkspaceIndex({
    lens,
    tab,
    events,
    plans,
    tabCounts,
    hero,
    filters,
    clients,
    sites,
    can,
}: Props) {
    const { url } = usePage();
    const origin = restraintReturn(url);
    const [search, setSearch] = useState(filters.q ?? '');
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    const params = (next: Record<string, string | number | null> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, lens, tab, ...next }).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );
    const query = (next: Record<string, string | number | null> = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const href = (next: Record<string, string | number | null> = {}) =>
        `${RESTRAINT_REGISTER}?${query(next)}`;
    const go = (next: Record<string, string | number | null>) =>
        router.get(RESTRAINT_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const report = (kind: 'event' | 'plan') =>
        `${RESTRAINT_REGISTER}/report?${new URLSearchParams({ kind, return_to: origin, ...(filters.client_id ? { client_id: String(filters.client_id) } : {}), ...(filters.site_id ? { site_id: String(filters.site_id) } : {}) })}`;
    const filter = (
        key: keyof RestraintFilters,
        label: string,
        options: { value: string; label: string }[],
    ) => (
        <PageHeaderFilterSelect
            key={key}
            label={label}
            value={String(filters[key] ?? 'all')}
            options={[{ value: 'all', label: 'All' }, ...options]}
            onChange={(value) => go({ [key]: value === 'all' ? null : value })}
        />
    );
    const tabs = lens === 'events' ? EVENT_TABS : PLAN_TABS;
    const rows = lens === 'events' ? events : plans;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Restrictive interventions',
                    href: RESTRAINT_REGISTER,
                },
            ]}
        >
            <Head title="Restrictive interventions and behaviour support" />
            <PageLayout
                hero={
                    <PageHeader
                        frontline
                        wrapTitle
                        icon={ShieldAlert}
                        title="Restrictive interventions"
                        subline="Recorded interventions, behaviour-support plans and separate review work"
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter')
                                            go({ q: search.trim() || null });
                                    }}
                                    placeholder="Search person or record text"
                                    ariaLabel="Search restrictive interventions and plans"
                                />
                                <PageHeaderGlassButton asChild>
                                    <a
                                        href={`${RESTRAINT_REGISTER}/export?${query()}`}
                                    >
                                        <Download className="size-4" />
                                        Export filtered records
                                    </a>
                                </PageHeaderGlassButton>
                                {can.create && (
                                    <PageHeaderPrimaryButton asChild>
                                        <Link
                                            href={report(
                                                lens === 'plans'
                                                    ? 'plan'
                                                    : 'event',
                                            )}
                                        >
                                            <Plus className="size-4" />
                                            {lens === 'plans'
                                                ? 'Create plan'
                                                : 'Record intervention'}
                                        </Link>
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Interventions · 30 days"
                                    href={href({
                                        lens: 'events',
                                        tab: '30d',
                                        period: 'all',
                                        client_id: null,
                                        site_id: null,
                                        q: null,
                                        restraint_type: null,
                                        severity: null,
                                        within_plan: null,
                                        review_state: null,
                                        from: null,
                                        to: null,
                                    })}
                                >
                                    <PageHeaderMeterBig>
                                        {hero.live.events_30d}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        All currently accessible records
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Needs review"
                                    href={href({
                                        lens: 'events',
                                        tab: 'unreviewed',
                                        period: 'all',
                                        client_id: null,
                                        site_id: null,
                                        q: null,
                                        restraint_type: null,
                                        severity: null,
                                        within_plan: null,
                                        review_state: null,
                                        from: null,
                                        to: null,
                                    })}
                                >
                                    <PageHeaderMeterBig>
                                        {hero.attention.unreviewed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Current source revision not reviewed
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Injury recorded · 30 days"
                                    href={href({
                                        lens: 'events',
                                        tab: 'injury',
                                        period: '30d',
                                        client_id: null,
                                        site_id: null,
                                        q: null,
                                        restraint_type: null,
                                        severity: null,
                                        within_plan: null,
                                        review_state: null,
                                        from: null,
                                        to: null,
                                    })}
                                >
                                    <PageHeaderMeterBig>
                                        {hero.live.injuries}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded facts need individual review
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Plan reviews due"
                                    href={href({
                                        lens: 'plans',
                                        tab: 'review_due',
                                        period: 'all',
                                        client_id: null,
                                        site_id: null,
                                        q: null,
                                        restraint_type: null,
                                        severity: null,
                                        within_plan: null,
                                        review_state: null,
                                        from: null,
                                        to: null,
                                    })}
                                >
                                    <PageHeaderMeterBig>
                                        {hero.attention.plans_review_due}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Active plans with a due review
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                <PageHeaderViewToggle
                                    value={lens}
                                    onChange={(value) =>
                                        go({
                                            lens: value,
                                            tab: 'all',
                                            review_state: null,
                                        })
                                    }
                                    options={[
                                        {
                                            value: 'events',
                                            label: 'Interventions',
                                            icon: ShieldAlert,
                                        },
                                        {
                                            value: 'plans',
                                            label: 'Behaviour-support plans',
                                            icon: FileText,
                                        },
                                    ]}
                                    ariaLabel="Register record type"
                                />
                                {filter(
                                    'client_id',
                                    'Person',
                                    clients.map((item) => ({
                                        value: String(item.id),
                                        label: item.name,
                                    })),
                                )}
                                {filter(
                                    'site_id',
                                    'Site',
                                    sites.map((item) => ({
                                        value: String(item.id),
                                        label: item.name,
                                    })),
                                )}
                                {filter(
                                    'restraint_type',
                                    'Type',
                                    RESTRAINT_TYPE_OPTIONS,
                                )}
                                {lens === 'events' && (
                                    <>
                                        {filter(
                                            'severity',
                                            'Severity',
                                            SEVERITY_OPTIONS,
                                        )}
                                        {filter(
                                            'within_plan',
                                            'Reported plan use',
                                            [
                                                {
                                                    value: 'yes',
                                                    label: 'Reported within plan',
                                                },
                                                {
                                                    value: 'no',
                                                    label: 'Reported outside plan',
                                                },
                                            ],
                                        )}
                                        {filter('review_state', 'Review', [
                                            {
                                                value: 'reviewed',
                                                label: 'Current revision reviewed',
                                            },
                                            {
                                                value: 'unreviewed',
                                                label: 'Current revision needs review',
                                            },
                                        ])}
                                    </>
                                )}
                                {lens === 'plans' &&
                                    filter('review_state', 'Review date', [
                                        {
                                            value: 'due',
                                            label: 'Due within 30 days',
                                        },
                                        { value: 'overdue', label: 'Overdue' },
                                    ])}
                                <PageHeaderFilterSelect
                                    label="Period"
                                    value={filters.period ?? '30d'}
                                    options={[
                                        { value: 'week', label: 'Last 7 days' },
                                        { value: '30d', label: 'Last 30 days' },
                                        {
                                            value: 'quarter',
                                            label: 'Last 3 months',
                                        },
                                        { value: 'all', label: 'All time' },
                                    ]}
                                    onChange={(value) => go({ period: value })}
                                />
                                <DatePicker
                                    compact
                                    id="restraint-register-from"
                                    label="From"
                                    value={filters.from ?? ''}
                                    onChange={(value) =>
                                        go({ from: value || null })
                                    }
                                    allowClear
                                />
                                <DatePicker
                                    compact
                                    id="restraint-register-to"
                                    label="To"
                                    value={filters.to ?? ''}
                                    onChange={(value) =>
                                        go({ to: value || null })
                                    }
                                    allowClear
                                />
                                <PageHeaderGlassButton
                                    type="button"
                                    onClick={() =>
                                        go({
                                            q: null,
                                            client_id: null,
                                            site_id: null,
                                            restraint_type: null,
                                            severity: null,
                                            within_plan: null,
                                            review_state: null,
                                            period: '30d',
                                            from: null,
                                            to: null,
                                            tab: 'all',
                                        })
                                    }
                                >
                                    Clear filters
                                </PageHeaderGlassButton>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                value={tab}
                                onChange={(value) => go({ tab: value })}
                                items={tabs.map((item) => ({
                                    ...item,
                                    count: (
                                        tabCounts[lens] as Record<
                                            string,
                                            number
                                        >
                                    )[item.key],
                                }))}
                            />
                        }
                    />
                }
            >
                <Card>
                    <CardContent className="space-y-5 pt-5">
                        <p className="text-caption text-muted-foreground">
                            {rows.total} matching{' '}
                            {lens === 'events'
                                ? 'intervention records'
                                : 'plans'}
                            . Open a record for its evidence, history and
                            connected work.
                        </p>
                        {rows.data.length === 0 ? (
                            <p role="status" className="text-subtle">
                                No records match these filters.
                            </p>
                        ) : lens === 'events' ? (
                            <ul className="space-y-3">
                                {events.data.map((row) => (
                                    <li
                                        key={row.id}
                                        className="grid gap-3 rounded-xl border border-border p-4 lg:grid-cols-[minmax(0,1fr)_auto]"
                                    >
                                        <div className="min-w-0 space-y-2">
                                            <Link
                                                href={restraintHref(
                                                    'events',
                                                    row.id,
                                                    { returnTo: origin },
                                                )}
                                                className="frontline-hit text-subtle inline-flex font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {row.reference} ·{' '}
                                                {row.client.name}
                                            </Link>
                                            <p className="text-caption">
                                                {titleCase(row.restraint_type)}{' '}
                                                ·{' '}
                                                {row.started_at
                                                    ? formatDateTimeLong(
                                                          row.started_at,
                                                      )
                                                    : 'Time not recorded'}{' '}
                                                ·{' '}
                                                {row.site?.name ??
                                                    'Site not recorded'}
                                            </p>
                                            <p className="text-caption text-muted-foreground">
                                                {row.within_support_plan
                                                    ? 'Reported within plan'
                                                    : 'Reported outside plan'}{' '}
                                                ·{' '}
                                                {row.injury_occurred
                                                    ? 'Injury recorded'
                                                    : 'No injury recorded'}
                                                {row.duration_minutes !== null
                                                    ? ' · ' +
                                                      row.duration_minutes +
                                                      ' minutes'
                                                    : ''}{' '}
                                                · Source revision{' '}
                                                {row.source_revision}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <StatusBadge status={row.severity}>
                                                {titleCase(row.severity)}
                                            </StatusBadge>
                                            <StatusBadge
                                                status={
                                                    row.needs_review
                                                        ? 'warning'
                                                        : 'neutral'
                                                }
                                            >
                                                {row.needs_review
                                                    ? 'Needs review'
                                                    : 'Current revision reviewed'}
                                            </StatusBadge>
                                            {can.review && (
                                                <Link
                                                    href={restraintHref(
                                                        'events',
                                                        row.id,
                                                        {
                                                            workspace: 'review',
                                                            section: 'review',
                                                            returnTo: origin,
                                                        },
                                                    )}
                                                    className="frontline-hit text-caption inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    <ClipboardCheck className="size-4" />
                                                    Review record
                                                </Link>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <ul className="space-y-3">
                                {plans.data.map((row) => (
                                    <li
                                        key={row.id}
                                        className="grid gap-3 rounded-xl border border-border p-4 lg:grid-cols-[minmax(0,1fr)_auto]"
                                    >
                                        <div className="min-w-0 space-y-2">
                                            <Link
                                                href={restraintHref(
                                                    'plans',
                                                    row.id,
                                                    { returnTo: origin },
                                                )}
                                                className="frontline-hit text-subtle inline-flex font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {row.reference} · {row.title}
                                            </Link>
                                            <p className="text-caption">
                                                {row.client.name} ·{' '}
                                                {row.restrictive_practice_type
                                                    ? titleCase(
                                                          row.restrictive_practice_type,
                                                      )
                                                    : 'Practice type not recorded'}{' '}
                                                ·{' '}
                                                {row.review_date
                                                    ? 'Review ' +
                                                      formatDateOnly(
                                                          row.review_date,
                                                      )
                                                    : 'Review date not recorded'}
                                            </p>
                                            <p className="text-caption text-muted-foreground">
                                                {row.published_version_id
                                                    ? 'Published version recorded; open for applicable approval evidence'
                                                    : 'No retained published version'}{' '}
                                                · Approval applicability needs
                                                the individual record.
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <StatusBadge status={row.status}>
                                                {titleCase(row.status)}
                                            </StatusBadge>
                                            {row.review_state !== 'ok' && (
                                                <StatusBadge status="warning">
                                                    {row.review_state ===
                                                    'overdue'
                                                        ? 'Review overdue'
                                                        : 'Review due'}
                                                </StatusBadge>
                                            )}
                                            {can.review && (
                                                <Link
                                                    href={restraintHref(
                                                        'plans',
                                                        row.id,
                                                        {
                                                            workspace: 'review',
                                                            section: 'review',
                                                            returnTo: origin,
                                                        },
                                                    )}
                                                    className="frontline-hit text-caption inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    <ClipboardCheck className="size-4" />
                                                    Review plan
                                                </Link>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {rows.last_page > 1 && (
                            <LaravelPagination links={rows.links} frontline />
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
