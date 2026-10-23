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
    severityBadge,
    statusBadge,
    targetBadge,
} from '@/components/control-room/alert-record/alert-labels';
import {
    alertTargets,
    nextOpenTarget,
    targetCaption,
    targetHeadline,
} from '@/components/control-room/alert-record/alert-targets';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, MousePointerClick } from 'lucide-react';
import type { ReactNode } from 'react';

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-3 border-t border-border py-2.5 text-sm first:border-t-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 font-medium break-words text-foreground">
                {children}
            </dd>
        </div>
    );
}

/**
 * The selected alert beside the Live response worklist (Stephan's choice,
 * 23 Oct 2026: list + preview). Enough to act — owner, next step, target,
 * source — with the main step and Assign here, and "Open full record" for
 * everything else.
 */
export function AlertPreview({
    row,
    detail,
    onRun,
}: {
    /** The selected worklist row (shown at once). */
    row: AlertWorklistRow | null;
    /** The full alert, once it has loaded. */
    detail: AlertWorkspaceDetail | null;
    onRun: (key: AlertActionKey) => void;
}) {
    const ready = detail && row && detail.alert.id === row.id ? detail : null;
    const d = ready ?? (!row ? detail : null);

    if (!row && !d) {
        return (
            <Card className="min-w-0 px-5 py-10">
                <EmptyState
                    icon={MousePointerClick}
                    title="Choose an alert"
                    description="Select an alert in the worklist to see who owns it and what happens next."
                />
            </Card>
        );
    }

    const id = d?.alert.id ?? row!.id;
    const reference = d ? alertReference(d.alert) : (row!.reference_number ?? `Alert ${row!.id}`);
    const summary = d ? alertSummary(d.alert) : row!.summary;
    const status = statusBadge(d?.alert.status ?? row!.status);
    const severity = severityBadge(d?.alert.severity ?? row!.severity);
    const primary = d ? primaryAlertAction(d) : null;
    const others = d
        ? alertActions(d).filter((item) => item.key !== primary?.key)
        : [];
    const assign = others.find((item) => item.key === 'assign');
    const more = others.filter((item) => item.key !== 'assign');
    const targets = d ? alertTargets(d) : [];
    const next = nextOpenTarget(targets);
    const rowTarget = row?.sla.status ? targetBadge(row.sla.status) : null;
    const person = d?.client?.name ?? row?.person?.name ?? null;
    const site = row?.site?.name ?? null;
    const owner = d ? d.alert.assigned_to?.name : row?.assignee?.name;
    const raisedAt = d?.alert.triggered_at ?? row?.triggered_at ?? null;
    const it = d?.linked_it_work;
    const safeZone = (
        d?.alert.context?.signal_payload as { safe_zone?: unknown } | undefined
    )?.safe_zone;

    return (
        <Card
            className="min-w-0 gap-0 overflow-hidden py-0"
            aria-label={`Selected alert ${reference}`}
        >
            <div className="grid gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <p className="text-caption font-medium tabular-nums">
                        {reference}
                    </p>
                    <h2 className="text-section-title mt-0.5 break-words">
                        {summary}
                    </h2>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        <StatusBadge variant={status.tone} size="sm">
                            {status.label}
                        </StatusBadge>
                        <StatusBadge variant={severity.tone} size="sm">
                            {severity.label}
                        </StatusBadge>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
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
                    <Button asChild variant="outline">
                        <Link href={`/control-room/alerts/${id}`}>
                            Open full record
                            <ArrowUpRight aria-hidden="true" />
                        </Link>
                    </Button>
                    {d ? (
                        <AlertActionsMenu
                            items={more}
                            onRun={onRun}
                            appearance="outline"
                        />
                    ) : (
                        <Skeleton className="h-9 w-24" aria-label="Loading actions" />
                    )}
                </div>
            </div>

            <dl className="px-5 py-1.5">
                <Fact label="Person and site">
                    {[person, site].filter(Boolean).join(' · ') || 'Not recorded'}
                </Fact>
                <Fact label="Owner">
                    {owner ?? (
                        <span className="text-status-warning">
                            Unassigned — claim or assign
                        </span>
                    )}
                </Fact>
                <Fact label="Next step">
                    {d
                        ? (primary?.label ?? 'No response step left')
                        : (row?.next_action.label ?? '…')}
                </Fact>
                <Fact label="Response target">
                    {next ? (
                        <span
                            className={
                                next.state === 'missed'
                                    ? 'text-status-critical'
                                    : undefined
                            }
                        >
                            {next.label}: {targetHeadline(next)} ·{' '}
                            {targetCaption(next)}
                        </span>
                    ) : d ? (
                        'All targets met or none set'
                    ) : (
                        (rowTarget?.label ?? '…')
                    )}
                </Fact>
                <Fact label="Source">
                    {row?.source.label ?? d?.alert.source}
                    {raisedAt
                        ? ` · raised ${formatTime(raisedAt)}`
                        : ''}
                </Fact>
            </dl>

            {d ? (
                <div className="grid gap-3 border-t border-border px-5 py-4">
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
                            {d.alert.snoozed_by
                                ? ` by ${d.alert.snoozed_by.name}`
                                : ''}
                            .
                        </PopupCallout>
                    ) : null}
                    {it ? (
                        <section className="grid gap-1 rounded-[12px] border border-border px-3.5 py-3">
                            <h3 className="text-sm font-semibold">
                                Waiting on IT
                            </h3>
                            {it.access.state === 'available' ? (
                                <p className="text-subtle">
                                    {it.reference ?? 'IT work'}
                                    {it.title ? ` · ${it.title}` : ''}
                                    {it.status ? ` · ${it.status}` : ''}.{' '}
                                    {owner
                                        ? `${owner} stays the response owner.`
                                        : 'The response still needs an owner here.'}
                                </p>
                            ) : (
                                <p className="text-subtle">
                                    {it.access.message ??
                                        'IT work is linked, but you can’t open it.'}
                                </p>
                            )}
                        </section>
                    ) : null}
                    {d.linked_incident || d.linked_hs_event ? (
                        <section className="grid gap-1 rounded-[12px] border border-border px-3.5 py-3">
                            <h3 className="text-sm font-semibold">
                                Incident and H&S
                            </h3>
                            <p className="text-subtle">
                                {d.linked_incident
                                    ? `Incident ${d.linked_incident.reference_number}`
                                    : 'No incident'}
                                {d.linked_hs_event
                                    ? ` · H&S ${d.linked_hs_event.reference_number} (${
                                          d.linked_hs_event.handover.status ===
                                          'accepted'
                                              ? 'accepted'
                                              : 'waiting for acceptance'
                                      })`
                                    : ''}
                            </p>
                        </section>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-2 border-t border-border px-5 py-4" aria-busy="true">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-1/2" />
                </div>
            )}
        </Card>
    );
}
