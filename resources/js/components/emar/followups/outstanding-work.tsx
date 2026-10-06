import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { ExternalLink } from 'lucide-react';
import { FollowupStatus } from './followup-list';
import type { OutstandingMedicationWorkData } from './types';

/** Read-only projection. Opening a separate work page preserves the chart or handover draft. */
export function OutstandingMedicationWork({
    work,
    clientId,
    handover = false,
}: {
    work: OutstandingMedicationWorkData;
    clientId?: number;
    handover?: boolean;
}) {
    const rows = work.followups ?? [];
    const legacy = work.legacy_effect_checks;
    const total =
        work.followup_counts?.open ?? rows.length + (legacy?.total ?? 0);
    if (!total) return null;
    const personId =
        clientId ?? rows[0]?.client.id ?? legacy?.data[0]?.client.id;
    const allUrl = `/emar/followups${personId ? `?client_id=${personId}` : ''}`;
    const shownRows = rows.slice(0, 5);
    const shownLegacy = (legacy?.data ?? []).slice(
        0,
        Math.max(0, 5 - shownRows.length),
    );
    const shown = shownRows.length + shownLegacy.length;

    return (
        <section
            aria-label="Current medication follow-ups"
            className="min-w-0 space-y-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-section-title">
                    Still to follow up{' '}
                    <span className="text-muted-foreground">({total})</span>
                </h3>
                <Button asChild variant="outline" className="frontline-tap">
                    <a href={allUrl} target="_blank" rel="noopener noreferrer">
                        Open follow-ups{' '}
                        <ExternalLink className="size-4" aria-hidden />
                        <span className="sr-only"> in a new tab</span>
                    </a>
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                Current outstanding work, including earlier doses, regardless of
                the selected {handover ? 'shift' : 'chart day'}.
                {handover
                    ? ' Acknowledgement alone does not complete these checks. Agree who will follow up; older checks may still need an owner.'
                    : ''}{' '}
                Follow-ups open in a new tab so you keep your place here.
            </p>
            {!!work.followup_counts &&
                (work.followup_counts.overdue > 0 ||
                    work.followup_counts.unscheduled > 0) && (
                    <div className="flex flex-wrap gap-2">
                        {work.followup_counts.overdue > 0 && (
                            <StatusBadge variant="critical">
                                {work.followup_counts.overdue} overdue
                            </StatusBadge>
                        )}
                        {work.followup_counts.unscheduled > 0 && (
                            <StatusBadge variant="warning">
                                {work.followup_counts.unscheduled} with no check
                                time
                            </StatusBadge>
                        )}
                    </div>
                )}
            <ul className="divide-y divide-border">
                {shownRows.map((row) => (
                    <li
                        key={`followup:${row.id}`}
                        className="flex flex-wrap items-start justify-between gap-3 py-3"
                    >
                        <div className="min-w-0 space-y-1">
                            <a
                                href={row.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="frontline-tap inline-flex items-center gap-2 font-medium text-primary underline"
                            >
                                {row.label} ·{' '}
                                {row.medication?.name ?? 'Medication work'}
                                <ExternalLink
                                    className="size-3.5 shrink-0"
                                    aria-hidden
                                />
                                <span className="sr-only">
                                    {' '}
                                    (opens in a new tab)
                                </span>
                            </a>
                            <p className="text-sm">
                                {formatDateTime(
                                    row.due_at,
                                    'Check time not set — arrange with the medication lead',
                                )}
                            </p>
                            <p className="text-caption">
                                Owner:{' '}
                                {row.owner?.name ??
                                    (row.lead ? 'House lead' : 'Not assigned')}
                                {row.original_owner &&
                                row.original_owner.id !== row.owner?.id
                                    ? ` · Originally ${row.original_owner.name}`
                                    : ''}
                            </p>
                        </div>
                        <FollowupStatus row={row} />
                    </li>
                ))}
                {shownLegacy.map((row) => (
                    <li key={row.source_key} className="space-y-1 py-3">
                        <a
                            href={
                                row.url ??
                                `${allUrl}${personId ? '&' : '?'}administration=${row.administration_id}`
                            }
                            target="_blank"
                            rel="noopener noreferrer"
                            className="frontline-tap inline-flex items-center gap-2 font-medium text-primary underline"
                        >
                            Effect check · {row.medication.name}
                            <ExternalLink
                                className="size-3.5 shrink-0"
                                aria-hidden
                            />
                            <span className="sr-only">
                                {' '}
                                (opens in a new tab)
                            </span>
                        </a>
                        <p className="text-sm">
                            Given{' '}
                            {formatDateTime(row.given_at, 'time not recorded')}{' '}
                            ·{' '}
                            {row.due_at
                                ? `Check by ${formatDateTime(row.due_at)}`
                                : 'Check time not set — arrange with the medication lead'}
                        </p>
                        <p className="text-caption">
                            Owner: {row.owner?.name ?? 'Not assigned'}
                            {row.can_prepare
                                ? ''
                                : ' · An authorised medication worker must open this check.'}
                        </p>
                    </li>
                ))}
            </ul>
            {total > shown && (
                <p className="text-caption">
                    Showing {shown} of {total}. Open follow-ups to see and
                    search the remaining work.
                </p>
            )}
        </section>
    );
}
