import { Button } from '@/components/ui/button';
import { show as showShift } from '@/routes/operations/shifts';
import { create as createTimesheet } from '@/routes/operations/timesheets';
import { Link } from '@inertiajs/react';
import * as React from 'react';

type ClientLite = { id: number; first_name: string; last_name: string };
type SiteLite = { id: number; name: string };

export type ActivityEventLite = {
    id: number;
    type: string;
    occurred_at: string;
    subject: string;
    body?: string | null;
    meta?: Record<string, any>;
    client?: ClientLite | null;
    site?: SiteLite | null;
};

function fmtTime(iso: string) {
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(iso: string) {
    const d = new Date(iso);
    return d.toLocaleDateString([], {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function isSameDay(aIso: string, bIso: string) {
    const a = new Date(aIso);
    const b = new Date(bIso);
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

function typeLabel(type: string) {
    switch (type) {
        case 'shift':
            return 'Shift';
        case 'note':
            return 'Note';
        case 'unifi_sync':
            return 'UniFi';
        case 'unifi_access':
            return 'UniFi';
        default:
            return type.replaceAll('_', ' ');
    }
}

export function ActivityTimeline({
    title,
    events,
    emptyText,
}: {
    title: string;
    events: ActivityEventLite[];
    emptyText?: string;
}) {
    const [range, setRange] = React.useState<'today' | 'week'>('today');
    const todayIso = React.useMemo(() => new Date().toISOString(), []);

    const filtered = React.useMemo(() => {
        if (range === 'today') {
            return events.filter((e) => isSameDay(e.occurred_at, todayIso));
        }
        return events;
    }, [range, events, todayIso]);

    const grouped = React.useMemo(() => {
        const map = new Map<string, ActivityEventLite[]>();
        for (const e of filtered) {
            const key = fmtDate(e.occurred_at);
            map.set(key, [...(map.get(key) ?? []), e]);
        }
        for (const [k, arr] of map) {
            arr.sort(
                (a, b) =>
                    new Date(a.occurred_at).getTime() -
                    new Date(b.occurred_at).getTime(),
            );
            map.set(k, arr);
        }
        return Array.from(map.entries());
    }, [filtered]);

    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-sm font-semibold">{title}</div>
                    <div className="text-xs text-muted-foreground">
                        {range === 'today' ? 'Today' : 'Next 7 days'}
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant={range === 'today' ? 'default' : 'outline'}
                        onClick={() => setRange('today')}
                    >
                        Today
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={range === 'week' ? 'default' : 'outline'}
                        onClick={() => setRange('week')}
                    >
                        Next 7 days
                    </Button>
                </div>
            </div>

            <div className="mt-4 max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                {grouped.length === 0 ? (
                    <div className="text-sm text-muted-foreground">
                        {emptyText ?? 'No activity scheduled.'}
                    </div>
                ) : (
                    grouped.map(([dateLabel, items]) => (
                        <div key={dateLabel} className="space-y-2">
                            <div className="text-xs font-medium text-muted-foreground">
                                {dateLabel}
                            </div>

                            <div className="space-y-2">
                                {items.map((e) => {
                                    const shiftId = e.meta?.shift_id;
                                    const clientName = e.client
                                        ? `${e.client.first_name} ${e.client.last_name}`
                                        : null;

                                    return (
                                        <div
                                            key={e.id}
                                            className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="flex min-w-0 items-start gap-3">
                                                <div className="mt-0.5 w-24 shrink-0 text-xs font-medium">
                                                    {fmtTime(e.occurred_at)}
                                                </div>

                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-medium">
                                                        {e.subject}
                                                    </div>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                        <span className="rounded-md border px-2 py-0.5">
                                                            {typeLabel(e.type)}
                                                        </span>
                                                        {clientName ? (
                                                            <span>
                                                                {clientName}
                                                            </span>
                                                        ) : null}
                                                        {e.site?.name ? (
                                                            <span>
                                                                {e.site.name}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                {shiftId ? (
                                                    <>
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            <Link
                                                                href={showShift.url(
                                                                    shiftId,
                                                                )}
                                                            >
                                                                View
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                        >
                                                            <Link
                                                                href={createTimesheet.url(
                                                                    {
                                                                        query: {
                                                                            shift_id:
                                                                                shiftId,
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                Timesheet
                                                            </Link>
                                                        </Button>
                                                    </>
                                                ) : null}

                                                {!shiftId && e.client?.id ? (
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={`/clients/${e.client.id}/timeline`}
                                                        >
                                                            Open
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
