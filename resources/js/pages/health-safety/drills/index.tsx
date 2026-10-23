import { DrillPlanningForm } from '@/components/health-safety/drills/drill-planning-form';
import type { DrillPickers } from '@/components/health-safety/drills/types';
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
    PageLayout,
} from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    DRILL_REGISTER,
    drillHref,
    drillReturn,
} from '@/lib/health-safety/drill-navigation';
import {
    DRILL_TYPE_OPTIONS,
    outcomeMeta,
    statusMeta,
    type DrillFilters,
    type DrillHero,
    type DrillRow,
} from '@/pages/health-safety/drills/shared';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    BarChart3,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    FileDown,
    LayoutList,
    Play,
    Plus,
    Siren,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Row = DrillRow & { attended_count: number; href: string };
type Props = {
    drills: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: DrillFilters;
    tab: string;
    tabCounts: Record<string, number>;
    hero: DrillHero;
    pickers: DrillPickers;
    can: { manage: boolean; schedule: boolean };
    exports: { register_href: string };
};
export default function DrillsIndex({
    drills,
    filters,
    tab,
    tabCounts,
    hero,
    pickers,
    can,
    exports,
}: Props) {
    const { url } = usePage();
    const returnTo = drillReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const creationPanel = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(
        new URLSearchParams(url.split('?')[1]).get('schedule') === '1' &&
            can.schedule,
    );
    useEffect(() => {
        if (creating) {
            creationPanel.current?.focus();
            creationPanel.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [creating]);
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(
        () =>
            setCreating(
                new URLSearchParams(url.split('?')[1]).get('schedule') ===
                    '1' && can.schedule,
            ),
        [url, can.schedule],
    );
    const params = (next: Partial<DrillFilters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value != null && value !== '',
            ),
        ) as Record<string, string | number>;
    const query = (next: Partial<DrillFilters> = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const go = (next: Partial<DrillFilters>) =>
        router.get(DRILL_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const openCreation = () =>
        drafts.request(() =>
            router.replace({
                url:
                    returnTo +
                    (returnTo.includes('?') ? '&' : '?') +
                    'schedule=1',
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setCreating(true),
            }),
        );
    const closeCreation = () =>
        drafts.request(() =>
            router.replace({
                url: returnTo,
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setCreating(false),
            }),
        );
    const tabs = [
        { key: 'all', label: 'All', icon: LayoutList },
        { key: 'scheduled', label: 'Scheduled', icon: CalendarClock },
        { key: 'overdue', label: 'Overdue', icon: AlarmClock },
        { key: 'in_progress', label: 'In progress', icon: Play },
        { key: 'completed', label: 'Completed', icon: CheckCircle2 },
        { key: 'findings', label: 'Findings open', icon: ClipboardList },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Emergency drills', href: DRILL_REGISTER },
            ]}
        >
            <Head title="Emergency drills" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            frontline
                            icon={Siren}
                            title="Emergency drills"
                            subline="Plan drills, record what happened and follow through on findings"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                go({
                                                    q: search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search drills"
                                        ariaLabel="Search drills"
                                    />
                                    <PageHeaderGlassButton asChild>
                                        <a href={exports.register_href}>
                                            <FileDown className="size-4" />
                                            Export
                                        </a>
                                    </PageHeaderGlassButton>
                                    {can.schedule && (
                                        <PageHeaderPrimaryButton
                                            onClick={openCreation}
                                        >
                                            <Plus className="size-4" />
                                            Schedule drill
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    {[
                                        {
                                            key: 'scheduled',
                                            label: 'Scheduled',
                                            caption: 'Upcoming drills',
                                        },
                                        {
                                            key: 'overdue',
                                            label: 'Overdue',
                                            caption:
                                                'Start time passed',
                                        },
                                        {
                                            key: 'in_progress',
                                            label: 'In progress',
                                            caption:
                                                'Completion needed',
                                        },
                                        {
                                            key: 'findings',
                                            label: 'Findings open',
                                            caption:
                                                'Drills needing follow-up',
                                        },
                                    ].map((item) => (
                                        <PageHeaderMeterBlock
                                            key={item.key}
                                            label={item.label}
                                            href={
                                                DRILL_REGISTER +
                                                '?' +
                                                query({
                                                    tab: item.key,
                                                    q: null,
                                                    drill_type: null,
                                                    outcome: null,
                                                })
                                            }
                                        >
                                            <PageHeaderMeterBig>
                                                {tabCounts[item.key] ?? 0}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {item.caption}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    ))}
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="Period"
                                        value={filters.period}
                                        onChange={(value) =>
                                            go({ period: value })
                                        }
                                        options={[
                                            { value: '30d', label: '30 days' },
                                            {
                                                value: 'quarter',
                                                label: 'Quarter',
                                            },
                                            { value: '6mo', label: '6 months' },
                                            {
                                                value: 'all',
                                                label: 'All dates',
                                            },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={
                                            filters.site_id
                                                ? String(filters.site_id)
                                                : ''
                                        }
                                        onChange={(value) =>
                                            go({
                                                site_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All accessible sites',
                                            },
                                            ...pickers.sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Type"
                                        value={filters.drill_type ?? ''}
                                        onChange={(value) =>
                                            go({ drill_type: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All drill types',
                                            },
                                            ...DRILL_TYPE_OPTIONS,
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Outcome"
                                        value={filters.outcome ?? ''}
                                        onChange={(value) =>
                                            go({ outcome: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All outcomes',
                                            },
                                            ...pickers.outcomes.map(
                                                (value) => ({
                                                    value,
                                                    label:
                                                        outcomeMeta(value)
                                                            ?.label ?? value,
                                                }),
                                            ),
                                        ]}
                                    />
                                    {(filters.q ||
                                        filters.site_id ||
                                        filters.drill_type ||
                                        filters.outcome ||
                                        filters.period !== '6mo') && (
                                        <PageHeaderGlassButton
                                            onClick={() =>
                                                go({
                                                    q: null,
                                                    site_id: null,
                                                    drill_type: null,
                                                    outcome: null,
                                                    period: '6mo',
                                                })
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderGlassButton>
                                    )}
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={tabs}
                                    value={tab}
                                    onSelect={(value) => go({ tab: value })}
                                    ariaLabel="Drill views"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <p className="text-subtle">
                            Summary counts cover the selected Sites and period,
                            before search, type and outcome filters.
                        </p>
                        {creating && can.schedule && (
                            <Card>
                                <CardContent
                                    ref={creationPanel}
                                    tabIndex={-1}
                                    className="p-5 outline-none sm:p-6"
                                >
                                    <DrillPlanningForm
                                        detail={null}
                                        pickers={pickers}
                                        mode="create"
                                        returnTo={returnTo}
                                        onSaved={() => setCreating(false)}
                                        onCancel={closeCreation}
                                    />
                                </CardContent>
                            </Card>
                        )}
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-section-title">
                                Drill register
                            </h2>
                            <Link
                                href="/health-safety/analytics"
                                className="frontline-tap frontline-focus inline-flex items-center gap-2 text-primary underline"
                            >
                                <BarChart3 className="size-4" />
                                Drill analytics
                            </Link>
                        </div>
                        <p className="text-subtle">
                            {drills.total
                                ? (drills.from ?? 1) +
                                  '–' +
                                  (drills.to ?? drills.data.length) +
                                  ' of ' +
                                  drills.total +
                                  ' matching drills'
                                : 'No drills match these filters.'}
                        </p>
                        <div className="space-y-3 md:hidden">
                            {drills.data.map((row) => (
                                <Card key={row.id}>
                                    <CardContent className="space-y-3 p-4">
                                        <DrillTitle
                                            row={row}
                                            returnTo={returnTo}
                                        />
                                        <DrillState row={row} />
                                        <dl className="grid grid-cols-1 gap-2 text-sm">
                                            <div>
                                                <dt className="text-subtle">
                                                    Site
                                                </dt>
                                                <dd>
                                                    {row.site?.name ??
                                                        'Not recorded'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-subtle">
                                                    Scheduled for
                                                </dt>
                                                <dd>
                                                    {formatDateTimeLong(
                                                        row.scheduled_at,
                                                        'Not recorded',
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-subtle">
                                                    Attendance
                                                </dt>
                                                <dd>
                                                    {row.attended_count}{' '}
                                                    confirmed ·{' '}
                                                    {row.participants_count}{' '}
                                                    assigned
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-subtle">
                                                    Findings
                                                </dt>
                                                <dd>
                                                    {row.findings_open} open
                                                    {row.flags.finding_overdue
                                                        ? ' · overdue work'
                                                        : ''}
                                                </dd>
                                            </div>
                                        </dl>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                        {drills.data.length > 0 && (
                            <Card className="hidden overflow-x-auto md:block">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">
                                        Emergency drills matching the selected
                                        filters
                                    </caption>
                                    <thead className="border-b border-border bg-muted/30">
                                        <tr>
                                            {[
                                                'Drill',
                                                'Site',
                                                'Scheduled for',
                                                'Status & outcome',
                                                'Attendance',
                                                'Findings',
                                            ].map((label) => (
                                                <th
                                                    key={label}
                                                    scope="col"
                                                    className="px-4 py-3 font-medium"
                                                >
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {drills.data.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-b border-border last:border-b-0"
                                            >
                                                <td className="max-w-sm px-4 py-3">
                                                    <DrillTitle
                                                        row={row}
                                                        returnTo={returnTo}
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {row.site?.name ??
                                                        'Not recorded'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {formatDateTimeLong(
                                                        row.scheduled_at,
                                                        'Not recorded',
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <DrillState row={row} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {row.attended_count}{' '}
                                                    confirmed
                                                    <br />
                                                    <span className="text-subtle">
                                                        {row.participants_count}{' '}
                                                        assigned
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Link
                                                        className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                                        href={drillHref(
                                                            row.id,
                                                            {
                                                                section:
                                                                    'findings',
                                                                returnTo,
                                                            },
                                                        )}
                                                    >
                                                        {row.findings_open} open
                                                    </Link>
                                                    {row.flags
                                                        .finding_overdue && (
                                                        <p className="text-sm text-status-critical">
                                                            Overdue work
                                                        </p>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Card>
                        )}
                        <LaravelPagination
                            links={drills.links}
                            lastPage={drills.last_page}
                            preserveScroll
                        />
                        <Card>
                            <CardContent className="space-y-2 p-4">
                                <h2 className="font-semibold">
                                    Drill cadence and follow-up
                                </h2>
                                <p className="text-subtle">
                                    {hero.attention.sites_overdue} Sites overdue
                                    under the current drill schedule ·{' '}
                                    {hero.attention.findings_open} open findings
                                    · {hero.attention.findings_overdue} overdue
                                    findings.
                                </p>
                                <p className="text-subtle">
                                    {hero.badges.fenz_reviews_due}{' '}
                                    evacuation-scheme reviews due. Drill recency
                                    and recorded outcomes remain separate.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
function DrillTitle({ row, returnTo }: { row: Row; returnTo: string }) {
    return (
        <div className="space-y-1">
            <Link
                href={drillHref(row.id, { returnTo })}
                className="frontline-tap frontline-focus inline-flex items-center font-medium break-words text-primary underline"
            >
                {row.reference} · {row.title}
            </Link>
            <p className="text-subtle">{row.type_label}</p>
        </div>
    );
}
function DrillState({ row }: { row: Row }) {
    const status = statusMeta(row.status);
    const outcome = outcomeMeta(row.outcome);
    return (
        <div className="flex flex-wrap gap-2">
            <StatusBadge variant={status.tone}>{status.label}</StatusBadge>
            {outcome && (
                <StatusBadge variant={outcome.tone}>
                    {outcome.label}
                </StatusBadge>
            )}
        </div>
    );
}
