import type { Filters, TransportRecord, WorkspaceView } from './types';
export const transportPath = (
    view: WorkspaceView,
    filters: Filters,
    extras: Record<string, string> = {},
) =>
    `/fleet-assets/transports/${view}?${new URLSearchParams({ ...filters, ...extras })}`;
export const exportPath = (
    filters: Filters,
    id?: number,
    selection: Record<string, string> = {},
) =>
    `/fleet-assets/transports/workspace/export?${new URLSearchParams({ ...filters, ...selection, ...(id ? { request_id: String(id) } : {}) })}`;
export const transportDay = (date: string) =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(date));
export const RETURN_LABELS: Record<string, string> = {
    not_due: 'Not due',
    due: 'Vehicle due back',
    overdue: 'Overdue return',
    keys: 'Keys need a storage location',
    items: 'Items to receive',
    handover: 'Incoming worker to acknowledge',
    exceptions: 'Handover needs review',
    complete: 'Return work complete',
};
export function attentionPriority(row: TransportRecord) {
    if (row.return_stage === 'overdue') return 0;
    if (['items', 'keys', 'handover', 'exceptions'].includes(row.return_stage))
        return 1;
    if (row.stage === 'returned') return 2;
    if (row.stage === 'decision') return 3;
    return 4;
}
export function inQueue(
    r: TransportRecord,
    view: WorkspaceView,
    filter: string,
) {
    if (view === 'returns') {
        if (filter === 'all') return r.return_stage !== 'not_due';
        if (filter === 'attention')
            return !['not_due', 'complete'].includes(r.return_stage);
        if (filter === 'items')
            return !!r.booking?.returned_at && r.missing_items.length > 0;
        if (filter === 'keys')
            return !!r.booking?.returned_at && !r.keys?.room_id;
        if (filter === 'due') return r.booking?.status === 'checked_out';
        if (filter === 'handover')
            return (
                r.handovers?.some((h) => h.status === 'pending_acceptance') ??
                false
            );
        if (filter === 'exceptions')
            return r.handovers?.some((h) => h.status === 'disputed') ?? false;
        return r.return_stage === filter;
    }
    if (view === 'journeys')
        return filter === 'all' ? !!r.booking : r.stage === filter;
    if (view === 'planner')
        return filter === 'planned'
            ? ['pending', 'approved', 'rejected'].includes(
                  r.booking?.status || '',
              ) && r.stage !== 'cancelled'
            : !r.booking && r.stage === 'allocation';
    if (filter === 'all') return true;
    return r.stage === filter;
}
export function stageCounts(rows: TransportRecord[]) {
    const stages = [
        {
            key: 'assessment',
            label: 'Needs assessment',
            view: 'requests',
            stages: ['assessment', 'information'],
            color: 'var(--chart-1)',
        },
        {
            key: 'allocation',
            label: 'Ready to plan',
            view: 'planner',
            stages: ['allocation', 'plan_review', 'allocated'],
            color: 'var(--chart-2)',
        },
        {
            key: 'decision',
            label: 'Booking decision',
            view: 'requests',
            stages: ['decision'],
            color: 'var(--chart-3)',
        },
        {
            key: 'ready',
            label: 'Preparing',
            view: 'journeys',
            stages: ['ready', 'depart'],
            color: 'var(--chart-4)',
        },
        {
            key: 'travelling',
            label: 'Travelling / returned',
            view: 'journeys',
            stages: ['travelling', 'returned'],
            color: 'var(--chart-5)',
        },
        {
            key: 'completed',
            label: 'Completed',
            view: 'journeys',
            stages: ['completed'],
            color: 'var(--status-success)',
        },
        {
            key: 'cancelled',
            label: 'Cancelled',
            view: 'requests',
            stages: ['cancelled'],
            color: 'var(--muted-foreground)',
        },
    ];
    return stages.map((s) => ({
        ...s,
        view: s.view as WorkspaceView,
        value: rows.filter((r) => s.stages.includes(r.stage)).length,
    }));
}
export function scheduleCounts(rows: TransportRecord[]) {
    rows = rows.filter(
        (r) =>
            r.stage !== 'cancelled' &&
            !['cancelled', 'rejected'].includes(r.booking?.status || ''),
    );
    const hour = (date: string) =>
        Number(
            new Intl.DateTimeFormat('en-NZ', {
                timeZone: 'Pacific/Auckland',
                hour: '2-digit',
                hourCycle: 'h23',
            }).format(new Date(date)),
        );
    return Array.from({ length: 24 }, (_, h) => ({
        hour: h,
        label: `${h % 12 || 12}${h < 12 ? 'am' : 'pm'}`,
        departures: rows.filter(
            (r) =>
                r.stage !== 'cancelled' &&
                hour(r.booking?.start || r.start) === h,
        ).length,
        returns: rows.filter(
            (r) =>
                r.stage !== 'cancelled' &&
                (r.booking?.end || r.end) &&
                hour((r.booking?.end || r.end)!) === h,
        ).length,
    }));
}
