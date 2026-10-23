import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    alertActions,
    primaryAlertAction,
    type AlertActionKey,
} from '@/components/control-room/alert-record/alert-actions';
import { AlertActionsMenu } from '@/components/control-room/alert-record/alert-actions-menu';
import {
    alertReference,
    alertSummary,
    plainLabel,
    severityBadge,
    statusBadge,
    targetBadge,
} from '@/components/control-room/alert-record/alert-labels';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { alertTargets, nextOpenTarget } from '@/components/control-room/alert-record/alert-targets';
import { OwnerChip, rowClock, UrgencyBar } from '@/components/control-room/live-response/alert-glance';
import {
    responseClock,
    useNow,
} from '@/components/control-room/live-response/response-clock';
import { ResponseTrack } from '@/components/control-room/live-response/response-track';
import { TargetLens } from '@/components/control-room/live-response/target-lens';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, MousePointerClick } from 'lucide-react';
import type { ReactNode } from 'react';

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-3 border-t border-border py-2 text-sm first:border-t-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 font-medium break-words text-foreground">
                {children}
            </dd>
        </div>
    );
}

/**
 * The selected alert beside the Live response list (Stephan, 23 Oct 2026:
 * list + preview; 24 Oct: person first, countdown lens, plain track). Who
 * and where, what happened, who is on it, where it's at, and the one next
 * step — "Open full record" for everything else. It keeps showing the
 * last details while a refresh runs, so it never flickers to grey.
 */
export function AlertPreview({
    row,
    detail,
    onRun,
    hideWhenEmpty = false,
}: {
    /** The selected list row (shown at once). */
    row: AlertWorklistRow | null;
    /** The full alert, once it has loaded. */
    detail: AlertWorkspaceDetail | null;
    onRun: (key: AlertActionKey) => void;
    /** An empty list shows no "choose an alert" box beside it. */
    hideWhenEmpty?: boolean;
}) {
    const now = useNow();
    const ready = detail && row && detail.alert.id === row.id ? detail : null;
    const d = ready ?? (!row ? detail : null);

    if (!row && !d) {
        if (hideWhenEmpty) return null;
        return (
            <Card className="min-w-0 px-5 py-10">
                <EmptyState
                    icon={MousePointerClick}
                    title="Choose an alert"
                    description="Pick an alert in the list to see who is on it and what happens next."
                />
            </Card>
        );
    }

    const id = d?.alert.id ?? row!.id;
    const reference = d ? alertReference(d.alert) : (row!.reference_number ?? `Alert ${row!.id}`);
    const summary = row?.summary ?? alertSummary(d!.alert);
    const status = statusBadge(d?.alert.status ?? row!.status);
    const severityKey = d?.alert.severity ?? row!.severity;
    const severity = severityBadge(severityKey);
    const primary = d ? primaryAlertAction(d) : null;
    const others = d
        ? alertActions(d).filter((item) => item.key !== primary?.key)
        : [];
    const assign = others.find((item) => item.key === 'assign');
    const more = others.filter((item) => item.key !== 'assign');
    const person = d?.client?.name ?? row?.person?.name ?? null;
    const site = row?.site?.name ?? null;
    const owner = d ? d.alert.assigned_to?.name : row?.assignee?.name;
    const raisedAt = d?.alert.triggered_at ?? row?.triggered_at ?? null;
    const source = row?.source.label ?? (d ? plainLabel(d.alert.source) : null);
    const it = d?.linked_it_work;
    const safeZone = (
        d?.alert.context?.signal_payload as { safe_zone?: unknown } | undefined
    )?.safe_zone;

    const next = d ? nextOpenTarget(alertTargets(d, now)) : null;
    const clock = d
        ? responseClock(
              {
                  raisedAt,
                  deadlineAt: next?.deadline ?? null,
                  targetStatus: next?.state === 'missed' ? 'breached' : null,
              },
              now,
          )
        : rowClock(row!, now);
    const target =
        row?.sla.status === 'breached' || row?.sla.status === 'at_risk'
            ? targetBadge(row.sla.status)
            : null;

    return (
        <Card
            className="relative min-w-0 gap-0 overflow-hidden py-0"
            aria-label={`Selected alert ${reference}`}
        >
            <UrgencyBar severity={severityKey} />
            <div className="grid gap-3 border-b border-border py-4 pr-5 pl-6">
                <div className="flex min-w-0 items-start gap-3.5">
                    <TargetLens clock={clock} label={next ? `${next.label} target` : undefined} />
                    <div className="min-w-0 flex-1">
                        <h2 className="text-section-title break-words">
                            {person ?? site ?? source ?? reference}
                            {person && site ? (
                                <span className="font-normal text-muted-foreground"> · {site}</span>
                            ) : null}
                        </h2>
                        <p className="mt-0.5 text-sm break-words text-foreground">{summary}</p>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <StatusBadge variant={severity.tone} size="sm">
                                {severity.label}
                            </StatusBadge>
                            <StatusBadge variant={status.tone} size="sm">
                                {status.label}
                            </StatusBadge>
                            {target ? (
                                <StatusBadge variant={target.tone} size="sm">
                                    {target.label}
                                </StatusBadge>
                            ) : null}
                        </div>
                    </div>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm">
                        <OwnerChip name={owner} size="md" />
                    </span>
                    <div className="flex flex-wrap items-center gap-2">
                        {d ? (
                            <>
                                {primary ? (
                                    <Button type="button" onClick={() => onRun(primary.key)}>
                                        {primary.label}
                                    </Button>
                                ) : null}
                                {assign ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => onRun('assign')}
                                    >
                                        {assign.label}
                                    </Button>
                                ) : null}
                                <AlertActionsMenu
                                    items={more}
                                    onRun={onRun}
                                    appearance="outline"
                                />
                            </>
                        ) : (
                            <Skeleton className="h-9 w-40" aria-label="Loading actions" />
                        )}
                    </div>
                </div>
            </div>

            {d ? (
                <div className="grid gap-4 py-4 pr-5 pl-6">
                    <section aria-label="Where it's at" className="grid gap-2">
                        <h3 className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                            Where it&apos;s at
                        </h3>
                        <ResponseTrack detail={d} now={now} />
                    </section>

                    {safeZone ? (
                        <PopupCallout tone="warning">
                            Check the reported location before acting. A new
                            location report does not close the alert.
                        </PopupCallout>
                    ) : null}
                    {d.monitoring_recovery ? (
                        <PopupCallout tone="info">
                            The device reported back at{' '}
                            {formatDateTime(d.monitoring_recovery.observed_at)}.
                            {d.monitoring_recovery.verification_required
                                ? ' A device recovering does not prove the person is safe — check before resolving.'
                                : ''}
                        </PopupCallout>
                    ) : null}
                    {d.alert.is_snoozed && d.alert.snoozed_until ? (
                        <PopupCallout tone="info">
                            Snoozed until {formatDateTime(d.alert.snoozed_until)}
                            {d.alert.snoozed_by ? ` by ${d.alert.snoozed_by.name}` : ''}.
                        </PopupCallout>
                    ) : null}

                    <dl>
                        {person ? <Fact label="Person">{person}</Fact> : null}
                        {site ? <Fact label="House">{site}</Fact> : null}
                        {source ? <Fact label="Came from">{source}</Fact> : null}
                        {raisedAt ? (
                            <Fact label="Raised">{formatDateTime(raisedAt)}</Fact>
                        ) : null}
                        {d.linked_incident || d.linked_hs_event ? (
                            <Fact label="Incident">
                                {d.linked_incident
                                    ? `Reported as ${d.linked_incident.reference_number}`
                                    : 'Not reported yet'}
                                {d.linked_hs_event
                                    ? ` · H&S ${
                                          d.linked_hs_event.handover.status === 'accepted'
                                              ? 'has taken it on'
                                              : 'yet to accept it'
                                      }`
                                    : ''}
                            </Fact>
                        ) : null}
                        {it ? (
                            <Fact label="IT">
                                {it.access.state === 'available'
                                    ? `${it.reference ?? 'IT work'}${it.title ? ` · ${it.title}` : ''}${it.status ? ` · ${it.status}` : ''}`
                                    : (it.access.message ?? 'IT work is linked, but you can’t open it.')}
                            </Fact>
                        ) : null}
                    </dl>
                </div>
            ) : (
                <div className="grid gap-2 py-4 pr-5 pl-6" aria-busy="true">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-4 w-3/5" />
                </div>
            )}

            <div className="flex items-center justify-between gap-3 border-t border-border py-3 pr-5 pl-6">
                <span className="text-caption tabular-nums">{reference}</span>
                <Button asChild variant="outline" size="sm">
                    <Link href={`/control-room/alerts/${id}`}>
                        Open full record
                        <ArrowUpRight aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </Card>
    );
}
