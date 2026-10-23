import { useAlertActions } from '@/components/control-room/alert-record/alert-actions';
import {
    severityBadge,
    statusBadge,
} from '@/components/control-room/alert-record/alert-labels';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { AlertPreview } from '@/components/control-room/live-response/alert-preview';
import {
    LiveResponseFilters,
    LiveResponseSearch,
    applyLiveResponseFilters,
} from '@/components/control-room/live-response/live-response-filters';
import { useAlertSelection } from '@/components/control-room/live-response/use-alert-selection';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowUpRight, Copy, ExternalLink, Eye, HeartPulse, Layers } from 'lucide-react';
import { useEffect, useMemo } from 'react';

type PersonRef = { id: number; name: string };

/** A step filter sent by ControlRoomIncidentController (key, label, help, count). */
type SafetyHandoverLens = { key: string; label: string; help: string; count: number };

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
    next_action: { key: string; label: string; href: string | null };
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

const STAGE: Record<
    SafetyJourney['stage'],
    { label: string; explanation: string; tone: StatusVariant }
> = {
    needs_incident: {
        label: 'Needs an incident report',
        explanation: 'Control Room reports the incident and hands it to H&S.',
        tone: 'critical',
    },
    awaiting_health_safety: {
        label: 'Waiting for H&S',
        explanation: 'The incident exists; H&S still has to accept it.',
        tone: 'warning',
    },
    accepted_in_progress: {
        label: 'H&S in progress',
        explanation: 'H&S owns the follow-up; Control Room can finish its own work.',
        tone: 'info',
    },
    operational_complete_governance_open: {
        label: 'Response done, H&S open',
        explanation: 'The response is finished; H&S still has to close its follow-up.',
        tone: 'warning',
    },
    complete: {
        label: 'Complete',
        explanation: 'The response and the H&S follow-up are both closed.',
        tone: 'success',
    },
};

const basePath = '/control-room/incidents';

/** A journey read as a worklist row, so the shared preview can show its alert. */
function asRow(journey: SafetyJourney): AlertWorklistRow {
    return {
        id: journey.alert.id,
        reference_number: journey.alert.reference_number,
        summary: journey.alert.summary,
        source: { key: 'safety_handover', label: 'Safety handover' },
        status: journey.alert.status as AlertWorklistRow['status'],
        severity: journey.alert.severity as AlertWorklistRow['severity'],
        priority: journey.priority,
        triggered_at: journey.triggered_at,
        next_deadline_at: journey.next_deadline_at,
        sla: {
            status: journey.sla.status as AlertWorklistRow['sla']['status'],
            next_deadline_at: journey.sla.next_deadline_at,
        },
        site: journey.site,
        person: journey.person,
        assignee: journey.assignee,
        queue: journey.queue,
        journey: {
            incident_reference: journey.incident?.reference_number ?? null,
            health_safety_reference: journey.health_safety?.reference_number ?? null,
            handover_status: journey.health_safety?.handover_status ?? null,
        },
        next_action: {
            label: journey.next_action.label,
            href: journey.next_action.href ?? journey.alert.href,
        },
        actions: {
            can_claim: false,
            can_acknowledge: false,
            can_move_queue: false,
            can_escalate: false,
            can_create_incident: journey.next_action.key === 'create_incident',
            can_snooze: false,
            can_unsnooze: false,
            can_copy_reference: true,
            incident_href: journey.incident?.href ?? null,
            health_safety_href: journey.health_safety?.href ?? null,
        },
        href: journey.alert.href,
    };
}

/**
 * Live response › Safety handovers — alerts on their way into an incident
 * report and H&S follow-up (its own tab, Stephan 23 Oct 2026). Each row says
 * which step it is at and who owns it; the preview shows the alert.
 */
export default function SafetyHandovers({
    journeys,
    filters,
    lenses,
    stats,
    sites,
    detail = null,
}: Props) {
    const rows = useMemo(() => journeys.data.map(asRow), [journeys.data]);
    const actions = useAlertActions(detail);
    const selection = useAlertSelection({ rows, detail, runAction: actions.run });
    const lens = filters.lens ?? 'attention';

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (!document.hidden)
                router.reload({
                    async: true,
                    only: ['journeys', 'lenses', 'stats', 'detail'],
                    preserveScroll: true,
                });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);

    const apply = (next: Record<string, string | undefined>) =>
        applyLiveResponseFilters(basePath, { ...filters, ...next, page: undefined, alert: undefined });

    const meter = (
        key: keyof typeof STAGE_LENS,
        count: number,
        caption: string,
        tone: 'critical' | 'warning' | 'brand',
    ) => (
        <PageHeaderMeterBlock
            label={STAGE_LENS[key].label}
            tone={count > 0 ? tone : 'brand'}
            onClick={() => apply({ lens: STAGE_LENS[key].lens })}
            pressed={lens === STAGE_LENS[key].lens}
            ariaLabel={`Show ${STAGE_LENS[key].label.toLowerCase()}`}
        >
            <PageHeaderMeterBig>{count}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );

    return (
        <ControlRoomPage
            headTitle="Safety handovers"
            header={{
                subline: 'Alerts going into an incident report and H&S follow-up',
                actions: (
                    <LiveResponseSearch
                        value={filters.search ?? ''}
                        onApply={(search) => apply({ search: search || undefined })}
                        placeholder="Search alert, incident or H&S reference"
                    />
                ),
                meters: (
                    <>
                        {meter('needs_incident', stats.needs_incident, 'report needed', 'critical')}
                        {meter('awaiting_health_safety', stats.awaiting_health_safety, 'to accept ownership', 'warning')}
                        {meter('accepted_in_progress', stats.accepted_in_progress, 'H&S working on it', 'brand')}
                        {meter('governance_open', stats.governance_open, 'response finished', 'warning')}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Layers}
                            label="Needs attention"
                            allValue="attention"
                            value={lens}
                            onChange={(value) => apply({ lens: value })}
                            options={lenses.map((l) => ({
                                value: l.key,
                                label: `${l.label} (${l.count})`,
                            }))}
                        />
                        <LiveResponseFilters
                            values={{ severity: filters.severity, site_id: filters.site_id }}
                            rows={rows}
                            sites={sites}
                            onChange={(next) =>
                                apply({
                                    severity: next.severity ? String(next.severity) : undefined,
                                    site_id: next.site_id ? String(next.site_id) : undefined,
                                })
                            }
                            show={{ severity: true, site: true }}
                        />
                    </>
                ),
            }}
        >
            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]">
                <Card className="min-w-0 gap-0 overflow-hidden py-0">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div className="min-w-0">
                            <h2 className="text-section-title">Safety handovers</h2>
                            <p className="text-subtle mt-0.5">
                                {lenses.find((l) => l.key === lens)?.help ??
                                    'Each alert with the step it is at and who owns it.'}
                            </p>
                        </div>
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {journeys.meta.total} {journeys.meta.total === 1 ? 'alert' : 'alerts'}
                        </StatusBadge>
                    </div>
                    {journeys.data.length === 0 ? (
                        <div className="px-5 py-10">
                            <EmptyState
                                icon={HeartPulse}
                                title="Nothing in this step"
                                description="Try another step or clear a filter."
                            />
                        </div>
                    ) : (
                        <ol className="divide-y divide-border">
                            {journeys.data.map((journey) => (
                                <JourneyRow
                                    key={journey.id}
                                    journey={journey}
                                    selected={journey.alert.id === selection.selectedId}
                                    onSelect={() => selection.select(journey.alert.id)}
                                    onReport={() => selection.select(journey.alert.id, 'report_incident')}
                                />
                            ))}
                        </ol>
                    )}
                    {journeys.meta.last_page > 1 ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                            <p className="text-caption">
                                Showing {journeys.meta.from ?? 0}–{journeys.meta.to ?? 0} of{' '}
                                {journeys.meta.total}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!journeys.links.prev}
                                    onClick={() =>
                                        journeys.links.prev &&
                                        router.get(journeys.links.prev, {}, { preserveState: true })
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!journeys.links.next}
                                    onClick={() =>
                                        journeys.links.next &&
                                        router.get(journeys.links.next, {}, { preserveState: true })
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </Card>
                <div className="min-w-0 xl:sticky xl:top-5">
                    <AlertPreview row={selection.row} detail={detail} onRun={actions.run} />
                </div>
            </div>
            {actions.host}
        </ControlRoomPage>
    );
}

/** Header numbers → the server's lenses (keys from ControlRoomIncidentController). */
const STAGE_LENS = {
    needs_incident: { label: 'Needs a report', lens: 'needs_incident' },
    awaiting_health_safety: { label: 'Waiting for H&S', lens: 'awaiting_health_safety' },
    accepted_in_progress: { label: 'H&S in progress', lens: 'accepted_in_progress' },
    governance_open: { label: 'H&S still open', lens: 'operational_complete_governance_open' },
} as const;

function JourneyRow({
    journey,
    selected,
    onSelect,
    onReport,
}: {
    journey: SafetyJourney;
    selected: boolean;
    onSelect: () => void;
    onReport: () => void;
}) {
    const stage = STAGE[journey.stage];
    const status = statusBadge(journey.alert.status);
    const severity = severityBadge(journey.alert.severity);
    const reference = journey.alert.reference_number ?? `Alert ${journey.alert.id}`;
    const owner = journey.health_safety?.owner?.name ?? journey.assignee?.name ?? null;
    const runNext = () => {
        if (journey.next_action.key === 'create_incident') onReport();
        else if (journey.next_action.href) router.visit(journey.next_action.href);
        else onSelect();
    };
    const items: ControlRoomRowAction[] = [
        { key: 'preview', label: 'Show in preview', icon: Eye, onSelect },
        {
            key: 'open',
            label: 'Open full record',
            icon: ArrowUpRight,
            onSelect: () => router.visit(journey.alert.href),
        },
        { key: 'next', label: journey.next_action.label, icon: ArrowUpRight, onSelect: runNext },
    ];
    if (journey.incident?.href)
        items.push({
            key: 'incident',
            label: 'Open incident record',
            icon: ExternalLink,
            onSelect: () => router.visit(journey.incident!.href!),
        });
    if (journey.health_safety?.href)
        items.push({
            key: 'hs',
            label: 'Open H&S event',
            icon: ExternalLink,
            onSelect: () => router.visit(journey.health_safety!.href!),
        });
    items.push({
        key: 'copy',
        label: 'Copy alert reference',
        icon: Copy,
        onSelect: () => void navigator.clipboard?.writeText(reference),
    });

    return (
        <ControlRoomRowActions label={`Actions for ${reference}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <li
                    onContextMenu={rowProps.onContextMenu}
                    className={cn(
                        'flex items-start gap-3 px-5 py-3.5 transition-colors',
                        selected
                            ? 'bg-primary-fill/10 shadow-[inset_3px_0_0_var(--primary)]'
                            : 'hover:bg-muted/40',
                    )}
                >
                    <Button
                        unstyled
                        type="button"
                        onClick={onSelect}
                        aria-current={selected ? 'true' : undefined}
                        className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="flex items-start justify-between gap-3">
                            <span className="min-w-0 font-semibold text-foreground">
                                {journey.summary}
                            </span>
                            <span
                                className="text-caption shrink-0 tabular-nums"
                                title={formatDateTime(journey.triggered_at)}
                            >
                                {formatRelative(journey.triggered_at)}
                            </span>
                        </span>
                        <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <StatusBadge variant={stage.tone} size="sm">
                                {stage.label}
                            </StatusBadge>
                            <StatusBadge variant={severity.tone} size="sm">
                                {severity.label}
                            </StatusBadge>
                            <StatusBadge variant={status.tone} size="sm">
                                {status.label}
                            </StatusBadge>
                        </span>
                        <span className="text-caption mt-1 block">
                            {[
                                reference,
                                journey.incident?.reference_number
                                    ? `Incident ${journey.incident.reference_number}`
                                    : null,
                                journey.health_safety?.reference_number
                                    ? `H&S ${journey.health_safety.reference_number}`
                                    : null,
                                journey.site?.name,
                                journey.person?.name,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                        <span className="text-caption mt-1 block">
                            {stage.explanation}{' '}
                            {owner ? `Owner: ${owner}.` : (
                                <span className="font-medium text-status-warning">No owner yet.</span>
                            )}
                        </span>
                    </Button>
                    <div className="flex shrink-0 items-center gap-1">
                        <Button type="button" size="sm" variant="outline" onClick={runNext}>
                            {journey.next_action.label}
                        </Button>
                        {overflowButton}
                    </div>
                </li>
            )}
        </ControlRoomRowActions>
    );
}
