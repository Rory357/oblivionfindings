/**
 * Lone-worker safety (approved H&S drawings pg-hsn-81, 81b — Stephan 23 Oct
 * 2026): one header with the three coverage numbers and the register filters,
 * the Risks and prevention hub on the rail and Sessions / Responses as
 * tier-two tabs (drawing D4), the safety caveat as a note, then the
 * register as a table. Two summaries (across your sites, the next 24 hours)
 * sit side by side under the sessions. The coverage and safety caveats keep
 * the code's wording.
 */
import { type SharedData } from '@/types';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
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
import {
    LeadBlock,
    RegisterBlocks,
    RegisterBoxes,
    RegisterMoreFilters,
    RegisterRailLine,
    type LeadRow,
} from '@/components/health-safety/register-kit';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import type {
    SafetySession,
    SessionPickers,
} from '@/components/health-safety/lone-workers/types';
import {
    dateTimeWithYear,
    dateWithYear,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    Note,
    Ring,
    QuietLine,
    Surface,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatTime, toDateInput } from '@/lib/datetime';
import {
    LONE_WORKER_REGISTER,
    loneWorkerHref,
    loneWorkerReturn,
} from '@/lib/health-safety/lone-worker-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
    Clock,
    ExternalLink,
    Eye,
    FileDown,
    Info,
    Plus,
    Radio,
    Siren,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

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
    /** The shifts on now without a matching session (LoneWorkerCoverageService::monitorableShifts). */
    options?: { shifts: Array<{ id: number; worker: { id: number; name: string } | null; site: { id: number; name: string } | null; starts_at: string | null; is_lone: boolean }> };
};
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
const titleLink =
    'rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export default function LoneWorkersIndex({
    tab,
    sessions,
    alerts,
    hero,
    coverage,
    filters,
    pickers,
    can,
    options,
}: Props) {
    const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
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
            new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }),
        );
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download =
            'lone-worker-' + tab + '-page-' + paginator.current_page + '.csv';
        anchor.click();
        URL.revokeObjectURL(objectUrl);
    };
    const filtered = !!(
        filters.q ||
        filters.site_id ||
        filters.user_id ||
        filters.status ||
        filters.period !== 'today'
    );
    const live = hero.clusters.live;
    const footer = (
        <>
            <p className="text-subtle">
                {paginator.total
                    ? `Showing ${(paginator.from ?? 1).toString()}–${(paginator.to ?? paginator.data.length).toString()} of ${paginator.total}`
                    : 'No records match these filters.'}
            </p>
            {paginator.last_page > 1 ? (
                <LaravelPagination
                    links={paginator.links}
                    lastPage={paginator.last_page}
                    preserveScroll
                />
            ) : null}
        </>
    );
    const headActions = (
        <Button asChild variant="outline" size="sm">
            <Link href="/health-safety/analytics">Safety trends</Link>
        </Button>
    );
    // Header box links keep the Site only.
    const siteLink = (next: Record<string, string> = {}) => {
        const query = new URLSearchParams(next);
        if (filters.site_id) query.set('site_id', String(filters.site_id));
        return LONE_WORKER_REGISTER + (query.size ? `?${query}` : '');
    };
    // G4-14: the lead block — shifts on now that need check-ins and have none set up yet (the server's own list).
    const unset = (options?.shifts ?? []).filter((shift) => shift.is_lone && (!filters.site_id || shift.site?.id === filters.site_id));
    const lead: LeadRow[] = unset.slice(0, 4).map((shift) => {
        const place = shift.site?.name ?? 'Site not recorded';
        const title = `${place} — ${shift.worker?.name ?? 'worker not recorded'}`;
        return {
            key: shift.id,
            when: shift.starts_at ? { day: formatTime(shift.starts_at), note: 'start' } : null,
            title,
            sub: [shift.starts_at ? `Starts ${formatTime(shift.starts_at)}` : null, 'no check-ins set up yet'].filter(Boolean).join(' · '),
            chip: { label: 'No check-ins set up', tone: 'warning' },
            action: can.manage ? { label: 'Set up check-ins', onClick: () => toggleCreation(true), ariaLabel: `Set up check-ins: ${title}` } : null,
            who: can.manage ? null : 'Health and safety set them up',
        };
    });
    const views = [
        { key: 'sessions', label: 'Working alone now', icon: ClipboardList },
        ...(can.view_control_room
            ? [{ key: 'alerts', label: 'Help responses to finish', icon: Siren, count: hero.clusters.alerts.unresolved || undefined }]
            : []),
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('prevention', safetyCan),
                { title: 'Working alone', href: LONE_WORKER_REGISTER },
            ]}
        >
            <Head title="Working alone" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            icon={Radio}
                            title="Working alone"
                            subline="Keep in touch with people working alone and follow through when help is needed"
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
                                        icon={FileDown}
                                        onClick={exportPage}
                                        disabled={!paginator.data.length}
                                        aria-label="Export this page"
                                        title="Export this page as a spreadsheet"
                                    >
                                        Export
                                    </PageHeaderGlassButton>
                                    {can.manage && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => toggleCreation(true)}
                                        >
                                            Set up check-ins
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            strip={
                                <RegisterBoxes
                                    boxes={[
                                        { key: 'unmonitored', label: 'Shifts with no check-ins set up', value: coverage.unmonitored_count, alert: true, icon: ClipboardList, href: siteLink() },
                                        { key: 'working_alone', label: 'Working alone now', value: live.active, icon: UserRound, href: siteLink({ status: 'active' }) },
                                        { key: 'missed', label: 'Missed check-ins', value: live.overdue, alert: true, icon: Clock, href: siteLink({ status: 'overdue' }), caption: live.overdue === 0 ? 'None right now' : undefined },
                                        { key: 'help', label: 'Asked for help now', value: live.emergency, alert: true, icon: Siren, href: siteLink({ status: 'emergency' }), caption: live.emergency === 0 ? 'None right now' : undefined },
                                    ]}
                                />
                            }
                            rail={
                                <RegisterRailLine
                                    rail={<SafetyWorkspaceRail hub="prevention" current="lone-workers" />}
                                    filters={
                                        <>
                                            <PageHeaderFilterSelect
                                                label={`All sites · ${pickers.sites.length}`}
                                                allValue=""
                                                value={String(filters.site_id ?? '')}
                                                onChange={(value) => go({ site_id: value ? Number(value) : null })}
                                                options={[
                                                    { value: '', label: 'All sites' },
                                                    ...pickers.sites.map((site) => ({ value: String(site.id), label: site.name })),
                                                ]}
                                            />
                                            <PageHeaderFilterSelect
                                                label="Today"
                                                allValue="today"
                                                value={filters.period}
                                                onChange={(value) => go({ period: value as Filters['period'] })}
                                                options={[
                                                    { value: 'today', label: 'Today' },
                                                    { value: 'week', label: '7 days' },
                                                    { value: '30d', label: '30 days' },
                                                    { value: 'all', label: 'All time' },
                                                ]}
                                            />
                                            <RegisterMoreFilters
                                                groups={[
                                                    ...(tab === 'sessions'
                                                        ? [
                                                              {
                                                                  key: 'worker',
                                                                  label: 'Worker',
                                                                  value: String(filters.user_id ?? ''),
                                                                  allValue: '',
                                                                  options: [
                                                                      { value: '', label: 'All workers' },
                                                                      ...pickers.workers.map((worker) => ({ value: String(worker.id), label: worker.name })),
                                                                  ],
                                                                  onChange: (value: string) => go({ user_id: value ? Number(value) : null }),
                                                              },
                                                          ]
                                                        : []),
                                                    {
                                                        key: 'state',
                                                        label: 'State',
                                                        value: filters.status ?? '',
                                                        allValue: '',
                                                        options: [{ value: '', label: 'All states' }, ...(tab === 'sessions' ? sessionStatuses : responseStatuses)],
                                                        onChange: (value: string) => go({ status: value || null }),
                                                    },
                                                ]}
                                                onClear={() => go({ user_id: null, status: null })}
                                            />
                                            {filtered && (
                                                <PageHeaderFilterButton
                                                    icon={X}
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
                                                </PageHeaderFilterButton>
                                            )}
                                        </>
                                    }
                                />
                            }
                        />
                    }
                    tabs={
                        <TierTwoTabs
                            tabs={views}
                            activeTab={tab}
                            onTab={(value) => go({ tab: value, status: null })}
                            testIdPrefix="lone-worker"
                            ariaLabel="Lone-worker views"
                            panelId="lone-worker-panel"
                            // Views carry no href, so the strip renders its own buttons.
                            renderLink={() => null}
                        />
                    }
                >
                    <div
                        id="lone-worker-panel"
                        role="tabpanel"
                        aria-label={tab === 'sessions' ? 'Working alone now' : 'Help responses to finish'}
                        className="grid min-w-0 gap-5"
                    >
                        {tab === 'sessions' ? (
                            <RegisterBlocks
                                lead={
                                    <LeadBlock
                                        sub="Shifts on now first"
                                        rows={lead}
                                        empty="Every shift on now that needs check-ins has them set up."
                                        more={unset.length > lead.length ? { text: `${unset.length - lead.length} more shifts have no check-ins set up` } : null}
                                    />
                                }
                                side={
                                    <QuietLine
                                        title={filters.site_id ? 'Next 24 hours at the selected sites' : 'Next 24 hours at your sites'}
                                        text={`${coverage.upcoming_expected_count} ${coverage.upcoming_expected_count === 1 ? 'shift needs' : 'shifts need'} check-ins${coverage.upcoming_unknown_count ? ` · not known for ${coverage.upcoming_unknown_count}` : ''}`}
                                        action={{ label: 'Shift coverage now', onClick: focusCoverage }}
                                    />
                                }
                            />
                        ) : null}
                        <Note
                            icon={coverage.state === 'unknown' ? AlertTriangle : Info}
                            tone={
                                coverage.state === 'unknown'
                                    ? 'warning'
                                    : undefined
                            }
                        >
                            {coverage.state === 'no_active_duty'
                                ? 'No active duty to assess.'
                                : coverage.state === 'configured_zero'
                                  ? 'No current shift has a recorded monitoring requirement.'
                                  : coverage.state === 'unknown'
                                    ? 'Some shift requirements have not been established; total coverage is unknown.'
                                    : 'A session does not confirm worker safety or a person watching continuously.'}{' '}
                            Checked {recordedAt(coverage.checked_at)}.
                        </Note>
                        {coverageOpen && (
                            <Surface title="Current shift coverage">
                                <div
                                    ref={coveragePanel}
                                    tabIndex={-1}
                                    className="grid gap-4 px-[18px] pt-2 pb-4 outline-none"
                                >
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
                                </div>
                            </Surface>
                        )}
                        {creating && can.manage && (
                            <Surface aria-label="Start a safety session">
                                <div
                                    ref={panel}
                                    tabIndex={-1}
                                    className="p-5 outline-none sm:p-6"
                                >
                                    <SessionStartForm
                                        pickers={pickers}
                                        returnTo={returnTo}
                                        onCancel={() => toggleCreation(false)}
                                    />
                                </div>
                            </Surface>
                        )}
                        {tab === 'sessions' ? (
                            <SessionRegister
                                rows={sessions.data}
                                returnTo={returnTo}
                                actions={headActions}
                                footer={footer}
                            />
                        ) : (
                            <ResponseRegister
                                rows={alerts.data}
                                returnTo={returnTo}
                                actions={headActions}
                                footer={footer}
                            />
                        )}
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
function sessionOverdue(row: SessionRow) {
    return (
        row.status !== 'emergency' && row.is_check_in_overdue && !row.ended_at
    );
}
function SessionState({ row }: { row: SessionRow }) {
    const variant: StatusVariant =
        row.status === 'emergency' || sessionOverdue(row)
            ? 'critical'
            : row.ended_at || row.status === 'completed'
              ? 'neutral'
              : 'info';
    return (
        <StatusBadge variant={variant}>
            {sessionOverdue(row) ? 'Check-in overdue' : sessionState(row.status)}
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
function responseVariant(row: Alert): StatusVariant {
    const key = row.canonical_status ?? row.status;
    if (key === 'open' || key === 'active') return 'warning';
    if (['ack', 'acknowledged', 'triaging', 'confirmed'].includes(key))
        return 'info';
    return 'neutral';
}
/** A time on the same NZ day as `base` shows as a time only, otherwise with its date. */
function clock(value: string | null | undefined, base?: string | null) {
    if (!value) return 'Not recorded';
    return base && toDateInput(value) === toDateInput(base)
        ? formatTime(value)
        : dateTimeWithYear(value);
}

function SessionRegister({
    rows,
    returnTo,
    actions,
    footer,
}: {
    rows: SessionRow[];
    returnTo: string;
    actions: ReactNode;
    footer: ReactNode;
}) {
    const open = (row: SessionRow) =>
        router.visit(loneWorkerHref(row.id, { returnTo }));
    const actionsFor = (row: SessionRow): MenuItem[] =>
        compactMenu([
            { label: 'Open session', icon: Eye, onClick: () => open(row) },
            {
                label: 'Open check-ins',
                icon: ClipboardList,
                onClick: () =>
                    router.visit(
                        loneWorkerHref(row.id, {
                            section: 'check-ins',
                            returnTo,
                        }),
                    ),
            },
            {
                label: 'Open help request and response',
                icon: Siren,
                onClick: () =>
                    router.visit(
                        loneWorkerHref(row.id, {
                            section: 'response',
                            returnTo,
                        }),
                    ),
            },
        ]);
    const columns: RecordTableColumn<SessionRow>[] = [
        {
            key: 'session',
            label: 'Session',
            className: 'min-w-[16rem] max-w-[24rem]',
            cell: (row) => (
                <RecordIdentity
                    title={
                        <Link
                            href={loneWorkerHref(row.id, { returnTo })}
                            onClick={(event) => event.stopPropagation()}
                            className={titleLink}
                        >
                            {row.user?.name ?? 'Worker not available'}
                        </Link>
                    }
                    sub={
                        <span className="line-clamp-2 break-words">
                            {row.reference} ·{' '}
                            {row.activity_description ??
                                'Activity not recorded'}
                        </span>
                    }
                />
            ),
        },
        {
            key: 'site',
            label: 'Site and client',
            nowrap: true,
            cell: (row) => (
                <RecordIdentity
                    title={row.site?.name ?? 'Not recorded'}
                    sub={row.client?.name ?? 'No client available to view'}
                />
            ),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (row) => <SessionState row={row} />,
        },
        {
            key: 'check-ins',
            label: 'Check-ins',
            className: 'min-w-[14rem]',
            cell: (row) => (
                <RecordIdentity
                    title={
                        <Link
                            href={loneWorkerHref(row.id, {
                                section: 'check-ins',
                                returnTo,
                            })}
                            onClick={(event) => event.stopPropagation()}
                            className={titleLink}
                        >
                            {observationLabel(row.observation.state)}
                        </Link>
                    }
                    sub={
                        <>
                            {row.observation.real_check_in_at
                                ? dateTimeWithYear(
                                      row.observation.real_check_in_at,
                                  )
                                : 'Starting is not a check-in.'}
                            {!row.ended_at
                                ? ` · Next checkpoint: ${clock(
                                      row.observation.next_due_at,
                                      row.observation.real_check_in_at ??
                                          row.started_at,
                                  )}`
                                : ''}
                        </>
                    }
                />
            ),
        },
        {
            key: 'end',
            label: 'Due to end',
            nowrap: true,
            cell: (row) =>
                row.expected_end_at ? (
                    <RecordIdentity
                        title={dateWithYear(row.expected_end_at)}
                        sub={`${formatTime(row.expected_end_at)}${
                            row.ended_at
                                ? ` · ended ${clock(row.ended_at, row.expected_end_at)}`
                                : ''
                        }`}
                    />
                ) : (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
    ];
    return (
        <RecordTable
            title="Sessions"
            sub="Live sessions remain visible outside the selected period · Coverage uses current shifts at the selected Sites"
            actions={actions}
            ariaLabel="Safety sessions matching the selected filters"
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={open}
            actionsFor={actionsFor}
            menuTitle={(row) =>
                `${row.reference} · ${row.user?.name ?? 'Worker not available'}`
            }
            menuIcon={Radio}
            columns={columns}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No records match these filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}

function ResponseRegister({
    rows,
    returnTo,
    actions,
    footer,
}: {
    rows: Alert[];
    returnTo: string;
    actions: ReactNode;
    footer: ReactNode;
}) {
    const responseHref = (row: Alert) => {
        const id = canonicalControlRoomAlertId(row.id);
        return id
            ? '/control-room/alerts/' +
                  id +
                  '?return_to=' +
                  encodeURIComponent(returnTo)
            : null;
    };
    const sessionHref = (row: Alert) =>
        row.session?.id
            ? loneWorkerHref(row.session.id, { section: 'response', returnTo })
            : null;
    const open = (row: Alert) => {
        const target = responseHref(row) ?? sessionHref(row);
        if (target) router.visit(target);
    };
    const actionsFor = (row: Alert): MenuItem[] => {
        const response = responseHref(row);
        const session = sessionHref(row);
        return compactMenu([
            response !== null && {
                label: 'Open response',
                icon: ExternalLink,
                onClick: () => router.visit(response),
            },
            session !== null && {
                label: 'Open source session',
                icon: Radio,
                onClick: () => router.visit(session),
            },
        ]);
    };
    const columns: RecordTableColumn<Alert>[] = [
        {
            key: 'response',
            label: 'Response',
            className: 'min-w-[16rem] max-w-[26rem]',
            cell: (row) => {
                const id = canonicalControlRoomAlertId(row.id);
                const href = responseHref(row);
                return (
                    <RecordIdentity
                        title={
                            href ? (
                                <Link
                                    href={href}
                                    onClick={(event) => event.stopPropagation()}
                                    className={titleLink}
                                >
                                    Response {id} · {words(row.type)}
                                </Link>
                            ) : (
                                'Response unavailable'
                            )
                        }
                        sub={
                            row.notes ? (
                                <span className="line-clamp-2 break-words whitespace-pre-wrap">
                                    {row.notes}
                                </span>
                            ) : undefined
                        }
                    />
                );
            },
        },
        {
            key: 'worker',
            label: 'Worker',
            nowrap: true,
            cell: (row) =>
                row.session?.user?.name ? (
                    <RecordPerson name={row.session.user.name} />
                ) : (
                    <span className="text-muted-foreground">
                        Source not available
                    </span>
                ),
        },
        {
            key: 'site',
            label: 'Site',
            nowrap: true,
            cell: (row) =>
                row.session?.site?.name ?? (
                    <span className="text-muted-foreground">
                        Source not available
                    </span>
                ),
        },
        {
            key: 'raised',
            label: 'Raised',
            nowrap: true,
            cell: (row) =>
                row.triggered_at ? (
                    <RecordIdentity
                        title={dateWithYear(row.triggered_at)}
                        sub={formatTime(row.triggered_at)}
                    />
                ) : (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (row) => (
                <StatusBadge variant={responseVariant(row)}>
                    {responseState(row)}
                </StatusBadge>
            ),
        },
    ];
    return (
        <RecordTable
            title="Control room responses"
            sub="The control room assesses, assigns and resolves each one"
            actions={actions}
            ariaLabel="Control room responses matching the selected filters"
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={open}
            actionsFor={actionsFor}
            menuTitle={(row) => `Response · ${words(row.type)}`}
            menuIcon={Siren}
            columns={columns}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No records match these filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}
