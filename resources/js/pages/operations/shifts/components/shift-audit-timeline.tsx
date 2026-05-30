import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';

export type ShiftAuditTimelineEntry = {
    id: number;
    type: string;
    occurred_at?: string | null;
    subject?: string | null;
    body?: string | null;
    visibility?: string | null;
    meta?: Record<string, unknown> | null;
    actor?: { id: number; name: string } | null;
};

const eventLabels: Record<string, string> = {
    shift: 'Shift snapshot',
    shift_assigned: 'Assignment',
    shift_unassigned: 'Unassigned',
    shift_started: 'Started',
    shift_completed: 'Completed',
    shift_cancelled: 'Cancelled',
    shift_cancellation_cascade: 'Cancellation cascade',
    shift_handover_created: 'Handover created',
    shift_handover_submitted: 'Handover submitted',
    shift_handover_acknowledged: 'Handover acknowledged',
    shift_handover_waived: 'Handover waived',
    shift_note: 'Shift note',
    progress_note: 'Progress note',
    handover: 'Handover',
    note: 'Note',
};

function eventLabel(type: string): string {
    return (
        eventLabels[type] ??
        type
            .replace(/[._-]+/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase())
    );
}

function detailValue(value: unknown): string | null {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'number') return String(value);
    if (typeof value === 'string') return value;

    return null;
}

function auditDetails(entry: ShiftAuditTimelineEntry) {
    const meta = entry.meta ?? {};
    const rows: Array<[string, unknown]> = [
        ['Reason', meta.reason ?? meta.handover_waiver_reason],
        ['Previous staff', meta.previous_user_name],
        ['Assigned staff', meta.assigned_user_name],
        ['Handover status', meta.handover_status],
        ['Matched shift', meta.matched_incoming_shift_id],
        ['Status', meta.status],
    ];

    return rows
        .map(([label, value]) => [label, detailValue(value)] as const)
        .filter((row): row is readonly [string, string] => row[1] !== null);
}

export function ShiftAuditTimeline({
    entries,
}: {
    entries: ShiftAuditTimelineEntry[];
}) {
    const count = entries.length;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-semibold">Audit timeline</h2>
                    <Badge variant="secondary">
                        {count} event{count === 1 ? '' : 's'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                {count ? (
                    <ol className="space-y-3">
                        {entries.map((entry) => {
                            const details = auditDetails(entry);

                            return (
                                <li
                                    key={entry.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <div className="text-sm font-medium">
                                                    {entry.subject ||
                                                        eventLabel(entry.type)}
                                                </div>
                                                <Badge variant="outline">
                                                    {eventLabel(entry.type)}
                                                </Badge>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                <span>
                                                    {entry.actor?.name
                                                        ? `By ${entry.actor.name}`
                                                        : 'System'}
                                                </span>
                                                {entry.visibility ? (
                                                    <>
                                                        <span aria-hidden="true">
                                                            {' '}
                                                            ·{' '}
                                                        </span>
                                                        <span>
                                                            {entry.visibility}
                                                        </span>
                                                    </>
                                                ) : null}
                                            </div>
                                        </div>
                                        <div className="text-xs whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(entry.occurred_at)}
                                        </div>
                                    </div>

                                    {entry.body ? (
                                        <div className="mt-3 text-sm whitespace-pre-wrap">
                                            {entry.body}
                                        </div>
                                    ) : null}

                                    {details.length ? (
                                        <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            {details.map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="rounded-md bg-muted/40 px-3 py-2"
                                                >
                                                    <dt className="text-[11px] font-medium text-muted-foreground uppercase">
                                                        {label}
                                                    </dt>
                                                    <dd className="mt-1 text-sm">
                                                        {label ===
                                                        'Matched shift'
                                                            ? `#${value}`
                                                            : value}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ol>
                ) : (
                    <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                        No audit events recorded for this shift yet.
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
