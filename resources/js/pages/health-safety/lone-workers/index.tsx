import {
    canonicalControlRoomAlertId,
    type Alert,
    type Filters,
    type Hero,
    type Paginator,
} from '@/components/health-safety/lone-worker-types';
import {
    observationLabel,
    recordedAt,
    SessionFact,
    words,
} from '@/components/health-safety/lone-workers/session-panels';
import { SessionStartForm } from '@/components/health-safety/lone-workers/session-start-form';
import type {
    SafetySession,
    SessionPickers,
} from '@/components/health-safety/lone-workers/types';
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
import {
    LONE_WORKER_REGISTER,
    loneWorkerHref,
    loneWorkerReturn,
} from '@/lib/health-safety/lone-worker-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    FileDown,
    FileText,
    List,
    Plus,
    Radio,
    Siren,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type SessionRow = Pick<
    SafetySession,
    | 'id'
    | 'reference'
    | 'status'
    | 'user'
    | 'site'
    | 'client'
    | 'activity_description'
    | 'started_at'
    | 'expected_end_at'
    | 'ended_at'
    | 'observation'
    | 'is_check_in_overdue'
>;
type Coverage = {
    state: string;
    expected_count: number | null;
    required_count: number;
    monitored_count: number;
    unmonitored_count: number;
    unknown_count: number;
    active_duty_count: number;
    checked_at: string | null;
    upcoming_expected_count: number;
    upcoming_unknown_count: number;
};
type Props = {
    tab: 'sessions' | 'alerts';
    sessions: Paginator<SessionRow>;
    alerts: Paginator<Alert>;
    hero: Hero;
    coverage: Coverage;
    filters: Filters;
    pickers: SessionPickers;
    can: { manage: boolean; view: boolean; view_control_room: boolean };
};
const linkClass =
    'frontline-tap frontline-focus inline-flex items-center gap-2 break-words text-primary underline';
const sessionStatuses = [
    { value: 'active', label: 'Active' },
    { value: 'overdue', label: 'Check-in overdue' },
    { value: 'emergency', label: 'Help requested' },
    { value: 'completed', label: 'Ended' },
];
const responseStatuses = [
    { value: 'open', label: 'Open' },
    { value: 'ack', label: 'Acknowledged' },
    { value: 'triaging', label: 'Being assessed' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
    { value: 'dismissed', label: 'Dismissed' },
];

export default function LoneWorkersIndex({
    tab,
    sessions,
    alerts,
    hero,
    coverage,
    filters,
    pickers,
    can,
}: Props) {
    const { url } = usePage();
    const returnTo = loneWorkerReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(
        new URLSearchParams(url.split('?')[1]).get('start') === '1' &&
            can.manage,
    );
    const panel = useRef<HTMLDivElement>(null);
    const coveragePanel = useRef<HTMLDivElement>(null);
    const [coverageOpen, setCoverageOpen] = useState(false);
    const focusCoverage = () => {
        setCoverageOpen(true);
        coveragePanel.current?.focus();
        coveragePanel.current?.scrollIntoView({ block: 'nearest' });
    };
    useEffect(() => {
        if (coverageOpen) {
            coveragePanel.current?.focus();
            coveragePanel.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [coverageOpen]);
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(
        () =>
            setCreating(
                new URLSearchParams(url.split('?')[1]).get('start') === '1' &&
                    can.manage,
            ),
        [url, can.manage],
    );
    useEffect(() => {
        if (creating) {
            panel.current?.focus();
            panel.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [creating]);
    const go = (next: Partial<Filters> & { tab?: string }) =>
        router.get(
            LONE_WORKER_REGISTER,
            Object.fromEntries(
                Object.entries({
                    ...filters,
                    tab,
                    ...next,
                    ...((next.tab ?? tab) === 'alerts'
                        ? { user_id: null }
                        : {}),
                }).filter(([, value]) => value != null && value !== ''),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const toggleCreation = (open: boolean) =>
        drafts.request(() =>
            router.replace({
                url:
                    returnTo +
                    (open
                        ? (returnTo.includes('?') ? '&' : '?') + 'start=1'
                        : ''),
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setCreating(open),
            }),
        );
    const paginator = tab === 'sessions' ? sessions : alerts;
    const exportPage = () => {
        const rows =
            tab === 'sessions'
                ? [
                      [
                          'Reference',
                          'Worker',
                          'Site',
                          'Client',
                          'Status',
                          'Started (NZ)',
                          'Expected end (NZ)',
                          'Last actual check-in (NZ)',
                          'Observation',
                      ],
                      ...sessions.data.map((row) => [
                          row.reference,
                          row.user?.name,
                          row.site?.name,
                          row.client?.name,
                          row.status !== 'emergency' &&
                          row.is_check_in_overdue &&
                          !row.ended_at
                              ? 'Check-in overdue'
                              : sessionState(row.status),
                          recordedAt(row.started_at),
                          recordedAt(row.expected_end_at),
                          recordedAt(row.observation.real_check_in_at),
                          observationLabel(row.observation.state),
                      ]),
                  ]
                : [
                      [
                          'Response',
                          'Worker',
                          'Site',
                          'Type',
                          'Client',
                          'Source',
                          'Recorded state',
                          'Triggered (NZ)',
                      ],
                      ...alerts.data.map((row) => [
                          row.id,
                          row.session?.user?.name,
                          row.session?.site?.name,
                          words(row.type),
                          row.session?.client?.name,
                          row.source === 'control_room'
                              ? 'Control Room'
                              : 'Historical alerts',
                          responseState(row),
                          recordedAt(row.triggered_at),
                      ]),
                  ];
        const csv = rows
            .map((row) =>
                row
                    .map((value) => {
                        const text = String(value ?? '');
                        const safe = /^[\s]*[=+@-]/.test(text)
                            ? "'" + text
                            : text;
                        return '"' + safe.replaceAll('"', '""') + '"';
                    })
                    .join(','),
            )
            .join('\r\n');
        const objectUrl = URL.createObjectURL(
            new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }),
        );
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download =
            'lone-worker-' + tab + '-page-' + paginator.current_page + '.csv';
        anchor.click();
        URL.revokeObjectURL(objectUrl);
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Lone-worker safety', href: LONE_WORKER_REGISTER },
            ]}
        >
            <Head title="Lone-worker safety" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            frontline
                            icon={Radio}
                            title="Lone-worker safety"
                            subline="Keep contact with workers and follow through when help is needed"
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
                                        placeholder={
                                            tab === 'sessions'
                                                ? 'Search workers or activity'
                                                : 'Search responses'
                                        }
                                        ariaLabel={
                                            tab === 'sessions'
                                                ? 'Search safety sessions'
                                                : 'Search responses'
                                        }
                                    />
                                    <PageHeaderGlassButton
                                        onClick={exportPage}
                                        disabled={!paginator.data.length}
                                    >
                                        <FileDown className="size-4" />
                                        Export this page
                                    </PageHeaderGlassButton>
                                    {can.manage && (
                                        <PageHeaderPrimaryButton
                                            onClick={() => toggleCreation(true)}
                                        >
                                            <Plus className="size-4" />
                                            Start session
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Monitoring required"
                                        onClick={focusCoverage}
                                    >
                                        <PageHeaderMeterBig>
                                            {coverage.expected_count ??
                                                'Unknown'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {coverage.unknown_count
                                                ? coverage.required_count +
                                                  ' known · ' +
                                                  coverage.unknown_count +
                                                  ' requirements unknown'
                                                : 'Current shifts requiring a session'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Sessions in place"
                                        onClick={focusCoverage}
                                    >
                                        <PageHeaderMeterBig>
                                            {coverage.monitored_count}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Required shifts with a matching
                                            session
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Sessions missing"
                                        onClick={focusCoverage}
                                    >
                                        <PageHeaderMeterBig>
                                            {coverage.unmonitored_count}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Known requirement without a session
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={String(filters.site_id ?? '')}
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
                                                label: 'All approved Sites',
                                            },
                                            ...pickers.sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                    />
                                    {tab === 'sessions' && (
                                        <PageHeaderFilterSelect
                                            label="Worker"
                                            value={String(
                                                filters.user_id ?? '',
                                            )}
                                            onChange={(value) =>
                                                go({
                                                    user_id: value
                                                        ? Number(value)
                                                        : null,
                                                })
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'All workers',
                                                },
                                                ...pickers.workers.map(
                                                    (worker) => ({
                                                        value: String(
                                                            worker.id,
                                                        ),
                                                        label: worker.name,
                                                    }),
                                                ),
                                            ]}
                                        />
                                    )}
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={filters.status ?? ''}
                                        onChange={(value) =>
                                            go({ status: value || null })
                                        }
                                        options={[
                                            { value: '', label: 'All states' },
                                            ...(tab === 'sessions'
                                                ? sessionStatuses
                                                : responseStatuses),
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Period"
                                        value={filters.period}
                                        onChange={(value) =>
                                            go({
                                                period: value as Filters['period'],
                                            })
                                        }
                                        options={[
                                            { value: 'today', label: 'Today' },
                                            { value: 'week', label: '7 days' },
                                            { value: '30d', label: '30 days' },
                                            { value: 'all', label: 'All time' },
                                        ]}
                                    />
                                    {(filters.q ||
                                        filters.site_id ||
                                        filters.user_id ||
                                        filters.status ||
                                        filters.period !== 'today') && (
                                        <PageHeaderGlassButton
                                            onClick={() =>
                                                go({
                                                    q: null,
                                                    site_id: null,
                                                    user_id: null,
                                                    status: null,
                                                    period: 'today',
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
                                    items={[
                                        {
                                            key: 'sessions',
                                            label: 'Sessions',
                                            icon: List,
                                        },
                                        ...(can.view_control_room
                                            ? [
                                                  {
                                                      key: 'alerts',
                                                      label: 'Responses',
                                                      icon: Siren,
                                                  },
                                              ]
                                            : []),
                                    ]}
                                    value={tab}
                                    onSelect={(value) =>
                                        go({ tab: value, status: null })
                                    }
                                    ariaLabel="Lone-worker views"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <div className="text-subtle space-y-1">
                            <p>
                                Coverage uses current shifts at the selected
                                Sites. Search,{' '}
                                {tab === 'sessions' ? 'worker, ' : ''}status and
                                period filters apply to the register below.
                            </p>
                            <p>
                                {coverage.state === 'no_active_duty'
                                    ? 'No active duty to assess.'
                                    : coverage.state === 'configured_zero'
                                      ? 'No current shift has a recorded monitoring requirement.'
                                      : coverage.state === 'unknown'
                                        ? 'Some shift requirements have not been established; total coverage is unknown.'
                                        : 'A session does not confirm worker safety or a person watching continuously.'}{' '}
                                Checked {recordedAt(coverage.checked_at)}.
                            </p>
                        </div>
                        {coverageOpen && (
                            <Card>
                                <CardContent
                                    ref={coveragePanel}
                                    tabIndex={-1}
                                    className="space-y-4 p-5 outline-none"
                                >
                                    <h2 className="text-section-title">
                                        Current shift coverage
                                    </h2>
                                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <SessionFact label="Active duty">
                                            {coverage.active_duty_count}
                                        </SessionFact>
                                        <SessionFact label="Known monitoring requirements">
                                            {coverage.required_count}
                                        </SessionFact>
                                        <SessionFact label="Unknown requirements">
                                            {coverage.unknown_count}
                                        </SessionFact>
                                        <SessionFact label="Required shifts with a session">
                                            {coverage.monitored_count}
                                        </SessionFact>
                                        <SessionFact label="Required shifts missing a session">
                                            {coverage.unmonitored_count}
                                        </SessionFact>
                                        <SessionFact label="Source checked">
                                            {recordedAt(coverage.checked_at)}
                                        </SessionFact>
                                    </dl>
                                    <p className="text-subtle">
                                        Requirements come from the lone-worker
                                        setting on actually started Workforce
                                        shifts. A matching session must have the
                                        same worker, Site, client and shift.
                                        Standalone sessions do not satisfy
                                        another shift's requirement. Check-in
                                        freshness and response ownership are
                                        shown on each session.
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                        {creating && can.manage && (
                            <Card>
                                <CardContent
                                    ref={panel}
                                    tabIndex={-1}
                                    className="p-5 outline-none sm:p-6"
                                >
                                    <SessionStartForm
                                        pickers={pickers}
                                        returnTo={returnTo}
                                        onCancel={() => toggleCreation(false)}
                                    />
                                </CardContent>
                            </Card>
                        )}
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-section-title">
                                {tab === 'sessions'
                                    ? 'Safety sessions'
                                    : 'Control Room responses'}
                            </h2>
                            <div className="flex flex-wrap gap-4">
                                <Link
                                    href="/health-safety/analytics"
                                    className={linkClass}
                                >
                                    <BarChart3 className="size-4" />
                                    Safety analytics
                                </Link>
                                <Link
                                    href="/health-safety/reports/board-summary"
                                    className={linkClass}
                                >
                                    <FileText className="size-4" />
                                    Board summary
                                </Link>
                            </div>
                        </div>
                        <p className="text-subtle">
                            {paginator.total
                                ? (paginator.from ?? 1) +
                                  '–' +
                                  (paginator.to ?? paginator.data.length) +
                                  ' of ' +
                                  paginator.total +
                                  ' matching records'
                                : 'No records match these filters.'}{' '}
                            {tab === 'sessions'
                                ? 'Live sessions remain visible outside the selected period.'
                                : 'Assessment, assignment and resolution take place in Control Room.'}
                        </p>
                        {tab === 'sessions' ? (
                            <SessionRegister
                                rows={sessions.data}
                                returnTo={returnTo}
                            />
                        ) : (
                            <ResponseRegister
                                rows={alerts.data}
                                returnTo={returnTo}
                            />
                        )}
                        <LaravelPagination
                            links={paginator.links}
                            lastPage={paginator.last_page}
                            preserveScroll
                        />
                        <Card>
                            <CardContent className="space-y-2 p-4">
                                <h2 className="font-semibold">
                                    Across all your approved Sites
                                </h2>
                                <p className="text-subtle">
                                    {hero.clusters.live.overdue} overdue
                                    sessions · {hero.clusters.live.emergency}{' '}
                                    sessions with help requested ·{' '}
                                    {hero.clusters.live.ending_soon} ending
                                    soon.
                                </p>
                                <p className="text-subtle">
                                    Upcoming in the next 24 hours at the
                                    selected Sites:{' '}
                                    {coverage.upcoming_expected_count} shifts
                                    require monitoring;{' '}
                                    {coverage.upcoming_unknown_count} have an
                                    unknown requirement.
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

function sessionState(status: string) {
    return (
        sessionStatuses.find((item) => item.value === status)?.label ??
        words(status)
    );
}
function SessionState({ row }: { row: SessionRow }) {
    return (
        <StatusBadge
            variant={
                row.status === 'emergency'
                    ? 'critical'
                    : row.is_check_in_overdue && !row.ended_at
                      ? 'warning'
                      : 'neutral'
            }
        >
            {row.status !== 'emergency' &&
            row.is_check_in_overdue &&
            !row.ended_at
                ? 'Check-in overdue'
                : sessionState(row.status)}
        </StatusBadge>
    );
}
function responseState(row: Alert) {
    if (row.canonical_status)
        return (
            responseStatuses.find((item) => item.value === row.canonical_status)
                ?.label ?? words(row.canonical_status)
        );
    return row.status === 'active'
        ? 'Open'
        : row.status === 'acknowledged'
          ? 'Acknowledged or being assessed'
          : row.status === 'resolved'
            ? 'Resolved or closed'
            : words(row.status);
}
function SessionTitle({
    row,
    returnTo,
}: {
    row: SessionRow;
    returnTo: string;
}) {
    return (
        <div className="space-y-1">
            <Link
                href={loneWorkerHref(row.id, { returnTo })}
                className={linkClass}
            >
                {row.reference} · {row.user?.name ?? 'Worker not available'}
            </Link>
            <p className="text-subtle break-words">
                {row.activity_description ?? 'Activity not recorded'}
            </p>
        </div>
    );
}
function CheckInSummary({
    row,
    returnTo,
}: {
    row: SessionRow;
    returnTo: string;
}) {
    return (
        <div className="space-y-1">
            <Link
                href={loneWorkerHref(row.id, {
                    section: 'check-ins',
                    returnTo,
                })}
                className={linkClass}
            >
                {observationLabel(row.observation.state)}
            </Link>
            <p className="text-subtle">
                {row.observation.real_check_in_at
                    ? recordedAt(row.observation.real_check_in_at)
                    : 'Starting is not a check-in.'}
            </p>
            {!row.ended_at && (
                <p className="text-subtle">
                    Next checkpoint: {recordedAt(row.observation.next_due_at)}
                </p>
            )}
        </div>
    );
}
function SessionRegister({
    rows,
    returnTo,
}: {
    rows: SessionRow[];
    returnTo: string;
}) {
    return (
        <>
            <div className="space-y-3 md:hidden">
                {rows.map((row) => (
                    <Card key={row.id}>
                        <CardContent className="space-y-3 p-4">
                            <SessionTitle row={row} returnTo={returnTo} />
                            <SessionState row={row} />
                            <dl className="grid gap-3">
                                <SessionFact label="Site">
                                    {row.site?.name}
                                </SessionFact>
                                <SessionFact label="Expected end">
                                    {recordedAt(row.expected_end_at)}
                                </SessionFact>
                            </dl>
                            <CheckInSummary row={row} returnTo={returnTo} />
                        </CardContent>
                    </Card>
                ))}
            </div>
            {!!rows.length && (
                <Card className="hidden overflow-x-auto md:block">
                    <table className="w-full text-left text-sm">
                        <caption className="sr-only">
                            Safety sessions matching the selected filters
                        </caption>
                        <thead className="border-b border-border bg-muted/30">
                            <tr>
                                {[
                                    'Session',
                                    'Site & client',
                                    'Expected end',
                                    'State',
                                    'Check-ins',
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
                            {rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b border-border last:border-b-0"
                                >
                                    <td className="max-w-sm px-4 py-3">
                                        <SessionTitle
                                            row={row}
                                            returnTo={returnTo}
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.site?.name ?? 'Not recorded'}
                                        <p className="text-subtle">
                                            {row.client?.name ??
                                                'No client available to view'}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {recordedAt(row.expected_end_at)}
                                        {row.ended_at && (
                                            <p className="text-subtle">
                                                Ended {recordedAt(row.ended_at)}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <SessionState row={row} />
                                    </td>
                                    <td className="px-4 py-3">
                                        <CheckInSummary
                                            row={row}
                                            returnTo={returnTo}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
        </>
    );
}
function ResponseRegister({
    rows,
    returnTo,
}: {
    rows: Alert[];
    returnTo: string;
}) {
    return (
        <div className="grid gap-3 lg:grid-cols-2">
            {rows.map((row) => {
                const id = canonicalControlRoomAlertId(row.id);
                return (
                    <Card key={row.id}>
                        <CardContent className="space-y-3 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                {id ? (
                                    <Link
                                        className={linkClass}
                                        href={
                                            '/control-room/alerts/' +
                                            id +
                                            '?return_to=' +
                                            encodeURIComponent(returnTo)
                                        }
                                    >
                                        Response {id} · {words(row.type)}
                                    </Link>
                                ) : (
                                    <span>Response unavailable</span>
                                )}
                                <StatusBadge variant="neutral">
                                    {responseState(row)}
                                </StatusBadge>
                            </div>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <SessionFact label="Worker">
                                    {row.session?.user?.name ??
                                        'Source not available'}
                                </SessionFact>
                                <SessionFact label="Site">
                                    {row.session?.site?.name ??
                                        'Source not available'}
                                </SessionFact>
                                <SessionFact label="Triggered">
                                    {recordedAt(row.triggered_at)}
                                </SessionFact>
                            </dl>
                            {row.session?.id && (
                                <Link
                                    className={linkClass}
                                    href={loneWorkerHref(row.session.id, {
                                        section: 'response',
                                        returnTo,
                                    })}
                                >
                                    Open source session
                                </Link>
                            )}
                            {row.notes && (
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {row.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
