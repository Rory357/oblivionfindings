import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { AlertStatus } from '@/components/control-room/alert-worklist/alert-status';
import {
    AlertWorkspaceDialog,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-workspace-dialog';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    SafetyHandoverLenses,
    type SafetyHandoverLens,
} from '@/components/control-room/safety-handover-lenses';
import { PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    Copy,
    ExternalLink,
    Eye,
    FileWarning,
    Filter,
    HeartPulse,
    MapPin,
    RadioTower,
    Search,
    UserRound,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';

type PersonRef = { id: number; name: string };

type SafetyJourney = {
    id: number;
    reference_number: string | null;
    summary: string;
    status: string;
    severity: string;
    priority: { level: string; rank: number; reason: string };
    triggered_at: string | null;
    next_deadline_at: string | null;
    sla: { status: string | null; next_deadline_at: string | null };
    site: PersonRef | null;
    person: PersonRef | null;
    assignee: PersonRef | null;
    queue: PersonRef | null;
    alert: {
        id: number;
        reference_number: string | null;
        status: string;
        severity: string;
        summary: string;
        href: string;
    };
    incident: {
        id: number;
        reference_number: string | null;
        status: string;
        severity: string;
        title: string | null;
        occurred_at: string | null;
        href: string | null;
    } | null;
    health_safety: {
        id: number;
        reference_number: string | null;
        status: string;
        severity: string;
        handover_status: string;
        owner: PersonRef | null;
        accepted_by: PersonRef | null;
        accepted_at: string | null;
        href: string | null;
    } | null;
    stage:
        | 'needs_incident'
        | 'awaiting_health_safety'
        | 'accepted_in_progress'
        | 'operational_complete_governance_open'
        | 'complete';
    next_action: {
        key: string;
        label: string;
        href: string | null;
    };
};

type Props = {
    journeys: {
        data: SafetyJourney[];
        links: {
            first: string | null;
            last: string | null;
            prev: string | null;
            next: string | null;
        };
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
            from: number | null;
            to: number | null;
        };
    };
    filters: Record<string, string | undefined>;
    lenses: SafetyHandoverLens[];
    stats: {
        total: number;
        needs_incident: number;
        awaiting_health_safety: number;
        accepted_in_progress: number;
        governance_open: number;
        complete: number;
    };
    sites: Array<{ id: number; name: string }>;
    detail?: AlertWorkspaceDetail | null;
};

const STAGE_COPY: Record<
    SafetyJourney['stage'],
    { label: string; explanation: string; tone: string }
> = {
    needs_incident: {
        label: 'Needs incident',
        explanation:
            'Control Room must create the official record and hand it to H&S.',
        tone: 'border-status-critical/30 bg-status-critical/10 text-status-critical',
    },
    awaiting_health_safety: {
        label: 'Awaiting H&S',
        explanation:
            'The incident exists; H&S acceptance is the next ownership step.',
        tone: 'border-status-warning/30 bg-status-warning/10 text-status-warning',
    },
    accepted_in_progress: {
        label: 'Accepted / in progress',
        explanation:
            'H&S owns governance while Control Room can finish operational work.',
        tone: 'border-primary/30 bg-primary/10 text-primary',
    },
    operational_complete_governance_open: {
        label: 'Operations done / H&S open',
        explanation:
            'The operational response is complete; governance still needs closure.',
        tone: 'border-status-warning/30 bg-status-warning/10 text-status-warning',
    },
    complete: {
        label: 'Complete',
        explanation: 'Operational and governance work are both closed.',
        tone: 'border-status-success/30 bg-status-success/10 text-status-success',
    },
};

export default function SafetyHandovers({
    journeys,
    filters,
    lenses,
    stats,
    sites,
    detail = null,
}: Props) {
    const searchRef = useRef<HTMLInputElement>(null);
    const basePath = '/control-room/incidents';

    const visit = useCallback((next: Record<string, string | undefined>) => {
        router.get(basePath, next as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
        });
    }, []);

    const applyFilter = (key: string, value?: string) =>
        visit({ ...filters, [key]: value || undefined, page: undefined });

    const openWorkspace = (alertId: number) =>
        router.get(
            basePath,
            { ...filters, alert: String(alertId) } as Record<string, string>,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['detail'],
            },
        );

    const closeWorkspace = () =>
        router.get(basePath, filters as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
            only: ['detail'],
        });

    const hasFilters = Boolean(
        filters.search ||
        filters.severity ||
        filters.site_id ||
        filters.date_from ||
        filters.date_to,
    );

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: ['journeys', 'lenses', 'stats'],
                preserveScroll: true,
            });
        }, 30_000);

        return () => window.clearInterval(interval);
    }, []);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Safety handovers', href: basePath },
            ]}
        >
            <Head title="Safety handovers - Control Room" />

            <PageLayout>
                <CommandCentrePage
                    current={basePath}
                    icon={HeartPulse}
                    title="Safety handovers"
                    description="Follow every Control Room alert into its official incident and governed H&S ownership."
                    status="Canonical safety journey"
                    metricGroups={[
                        {
                            title: 'Safety continuity',
                            icon: HeartPulse,
                            metrics: [
                                {
                                    label: 'Needs incident',
                                    value: String(stats.needs_incident),
                                    caption: 'record required',
                                    tone:
                                        stats.needs_incident > 0
                                            ? 'critical'
                                            : 'success',
                                },
                                {
                                    label: 'Waiting for H&S',
                                    value: String(stats.awaiting_health_safety),
                                    caption: 'accept ownership',
                                    tone:
                                        stats.awaiting_health_safety > 0
                                            ? 'warning'
                                            : 'success',
                                },
                                {
                                    label: 'In progress',
                                    value: String(stats.accepted_in_progress),
                                    caption: 'governance active',
                                    tone: 'neutral',
                                },
                                {
                                    label: 'Governance open',
                                    value: String(stats.governance_open),
                                    caption: 'operations complete',
                                    tone:
                                        stats.governance_open > 0
                                            ? 'warning'
                                            : 'success',
                                },
                            ],
                        },
                    ]}
                >
                    <Card className="flex flex-row items-center gap-3 overflow-x-auto rounded-xl p-3">
                        <JourneyKey
                            icon={RadioTower}
                            title="Control Room"
                            text="Respond and stabilise"
                        />
                        <ArrowRight className="h-5 w-5 shrink-0 text-muted-foreground" />
                        <JourneyKey
                            icon={FileWarning}
                            title="Incident"
                            text="Official record of what happened"
                        />
                        <ArrowRight className="h-5 w-5 shrink-0 text-muted-foreground" />
                        <JourneyKey
                            icon={HeartPulse}
                            title="Health & Safety"
                            text="Investigate, govern and close"
                        />
                    </Card>

                    <SafetyHandoverLenses
                        lenses={lenses}
                        activeLens={filters.lens ?? 'attention'}
                        onSelect={(lens) => visit({ lens, page: undefined })}
                    />

                    <Card className="flex flex-col gap-3 rounded-xl p-3 sm:flex-row sm:items-end">
                        <div className="flex items-center gap-1.5 pb-2 text-sm font-medium text-muted-foreground">
                            <Filter className="h-4 w-4" />
                            Filters
                        </div>
                        <div className="relative min-w-0 flex-1">
                            <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                ref={searchRef}
                                aria-label="Search safety handovers"
                                placeholder="Search CR, INC or HS reference…"
                                defaultValue={filters.search ?? ''}
                                className="h-9 w-full pl-8"
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        applyFilter(
                                            'search',
                                            (event.target as HTMLInputElement)
                                                .value,
                                        );
                                    }
                                }}
                            />
                        </div>
                        <Select
                            value={filters.severity ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter(
                                    'severity',
                                    value === 'all' ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger className="h-9 w-full sm:w-40">
                                <SelectValue placeholder="Severity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All severities
                                </SelectItem>
                                <SelectItem value="critical">
                                    Critical
                                </SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="low">Low</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.site_id ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter(
                                    'site_id',
                                    value === 'all' ? undefined : value,
                                )
                            }
                        >
                            <SelectTrigger className="h-9 w-full sm:w-52">
                                <SelectValue placeholder="Site" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All accessible sites
                                </SelectItem>
                                {sites.map((site) => (
                                    <SelectItem
                                        key={site.id}
                                        value={String(site.id)}
                                    >
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {hasFilters ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-9"
                                onClick={() =>
                                    visit({ lens: filters.lens ?? 'attention' })
                                }
                            >
                                <X className="mr-1.5 h-4 w-4" />
                                Clear filters
                            </Button>
                        ) : null}
                    </Card>

                    <section className="overflow-hidden rounded-xl border border-border bg-card">
                        <div className="hidden grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)_minmax(14rem,0.8fr)_auto] gap-4 border-b border-border bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase lg:grid">
                            <span>Journey</span>
                            <span>Current handover</span>
                            <span>Owner and timing</span>
                            <span className="text-right">Next action</span>
                        </div>

                        {journeys.data.length ? (
                            journeys.data.map((journey) => (
                                <SafetyJourneyRow
                                    key={journey.alert.id}
                                    journey={journey}
                                    onOpenWorkspace={openWorkspace}
                                />
                            ))
                        ) : (
                            <div className="flex min-h-64 flex-col items-center justify-center gap-2 px-6 text-center">
                                <CheckCircle2 className="h-9 w-9 text-status-success" />
                                <p className="font-semibold text-foreground">
                                    Nothing in this handover view
                                </p>
                                <p className="max-w-lg text-sm text-muted-foreground">
                                    There is no matching operational or
                                    governance work. Try another lens or clear
                                    the filters.
                                </p>
                            </div>
                        )}
                    </section>

                    <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                            {journeys.meta.total
                                ? `Showing ${journeys.meta.from}–${journeys.meta.to} of ${journeys.meta.total} journeys`
                                : 'No journeys'}
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!journeys.links.prev}
                                onClick={() =>
                                    journeys.links.prev &&
                                    router.visit(journeys.links.prev)
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                Page {journeys.meta.current_page} of{' '}
                                {journeys.meta.last_page}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!journeys.links.next}
                                onClick={() =>
                                    journeys.links.next &&
                                    router.visit(journeys.links.next)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CommandCentrePage>
            </PageLayout>

            {detail ? (
                <AlertWorkspaceDialog
                    detail={detail}
                    open
                    onClose={closeWorkspace}
                />
            ) : null}
        </AppLayout>
    );
}

function JourneyKey({
    icon: Icon,
    title,
    text,
}: {
    icon: typeof RadioTower;
    title: string;
    text: string;
}) {
    return (
        <div className="flex items-center gap-3">
            <span className="rounded-lg bg-primary/10 p-2 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <span>
                <span className="block text-sm font-semibold text-foreground">
                    {title}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {text}
                </span>
            </span>
        </div>
    );
}

function SafetyJourneyRow({
    journey,
    onOpenWorkspace,
}: {
    journey: SafetyJourney;
    onOpenWorkspace: (alertId: number) => void;
}) {
    const stage = STAGE_COPY[journey.stage];
    const reference =
        journey.alert.reference_number ?? `Alert ${journey.alert.id}`;
    const runNextAction = () => {
        if (journey.next_action.key === 'create_incident') {
            onOpenWorkspace(journey.alert.id);
            return;
        }
        if (journey.next_action.href) router.visit(journey.next_action.href);
        else onOpenWorkspace(journey.alert.id);
    };
    const actions: ControlRoomRowAction[] = [
        {
            key: 'open-alert',
            label: 'Open alert workspace',
            icon: Eye,
            onSelect: () => onOpenWorkspace(journey.alert.id),
        },
        {
            key: 'next-action',
            label: journey.next_action.label,
            icon: ArrowRight,
            onSelect: runNextAction,
        },
    ];

    if (journey.incident?.href) {
        actions.push({
            key: 'open-incident',
            label: 'Open incident record',
            icon: ExternalLink,
            onSelect: () => router.visit(journey.incident!.href!),
        });
    }
    if (journey.health_safety?.href) {
        actions.push({
            key: 'open-health-safety',
            label: 'Open H&S event',
            icon: ExternalLink,
            onSelect: () => router.visit(journey.health_safety!.href!),
        });
    }
    actions.push({
        key: 'copy-reference',
        label: 'Copy alert reference',
        icon: Copy,
        onSelect: () => void navigator.clipboard?.writeText(reference),
    });

    return (
        <ControlRoomRowActions
            label={`Actions for ${reference}`}
            items={actions}
        >
            {({ rowProps, overflowButton }) => (
                <article
                    {...rowProps}
                    className="grid gap-4 border-b border-border px-4 py-4 last:border-b-0 hover:bg-muted/20 lg:grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)_minmax(14rem,0.8fr)_auto] lg:items-center"
                >
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                unstyled
                                type="button"
                                className="font-mono text-xs font-semibold text-primary hover:underline"
                                onClick={() =>
                                    onOpenWorkspace(journey.alert.id)
                                }
                            >
                                {reference}
                            </Button>
                            {journey.incident?.reference_number &&
                            journey.incident.href ? (
                                <Link
                                    href={journey.incident.href}
                                    className="font-mono text-xs font-semibold text-foreground hover:text-primary hover:underline"
                                >
                                    {journey.incident.reference_number}
                                </Link>
                            ) : null}
                            {journey.health_safety?.reference_number &&
                            journey.health_safety.href ? (
                                <Link
                                    href={journey.health_safety.href}
                                    className="font-mono text-xs font-semibold text-foreground hover:text-primary hover:underline"
                                >
                                    {journey.health_safety.reference_number}
                                </Link>
                            ) : null}
                        </div>
                        <p className="truncate text-sm font-semibold text-foreground">
                            {journey.summary}
                        </p>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {journey.site ? (
                                <span className="inline-flex items-center gap-1">
                                    <MapPin className="h-3.5 w-3.5" />
                                    {journey.site.name}
                                </span>
                            ) : null}
                            {journey.person ? (
                                <span className="inline-flex items-center gap-1">
                                    <UserRound className="h-3.5 w-3.5" />
                                    {journey.person.name}
                                </span>
                            ) : null}
                            {journey.triggered_at ? (
                                <span
                                    title={formatDateTime(journey.triggered_at)}
                                >
                                    Raised{' '}
                                    {formatRelative(journey.triggered_at)}
                                </span>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <span
                            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${stage.tone}`}
                        >
                            {stage.label}
                        </span>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {stage.explanation}
                        </p>
                        <AlertStatus
                            status={journey.alert.status}
                            severity={journey.alert.severity}
                            slaStatus={journey.sla.status}
                        />
                    </div>

                    <div className="space-y-2 text-xs">
                        <p className="font-medium text-foreground">
                            {journey.health_safety?.owner?.name ??
                                journey.assignee?.name ??
                                'Unassigned'}
                        </p>
                        {journey.health_safety?.accepted_by ? (
                            <p className="text-muted-foreground">
                                Accepted by{' '}
                                {journey.health_safety.accepted_by.name}
                            </p>
                        ) : null}
                        {journey.next_deadline_at ? (
                            <p
                                className="inline-flex items-center gap-1 text-muted-foreground"
                                title={formatDateTime(journey.next_deadline_at)}
                            >
                                <Clock3 className="h-3.5 w-3.5" />
                                Due {formatRelative(journey.next_deadline_at)}
                            </p>
                        ) : null}
                        <p className="inline-flex items-center gap-1 text-muted-foreground">
                            <ClipboardCheck className="h-3.5 w-3.5" />
                            {journey.priority.reason}
                        </p>
                    </div>

                    <div className="flex items-center justify-end gap-1">
                        <Button
                            size="sm"
                            onClick={runNextAction}
                            aria-label={`${journey.next_action.label} for ${reference}`}
                        >
                            {journey.next_action.label}
                            <ArrowRight className="ml-1.5 h-4 w-4" />
                        </Button>
                        {overflowButton}
                    </div>
                </article>
            )}
        </ControlRoomRowActions>
    );
}
