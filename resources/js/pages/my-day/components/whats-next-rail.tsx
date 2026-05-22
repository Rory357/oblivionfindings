import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

import { cn } from '@/lib/utils';

import type { MyDayResident } from '../lib/types';
import {
    type StreamItem,
    groupByTime,
    nowHourMinute,
    nowRuleIndex,
} from '../lib/stream-grouping';

import { NowRule } from './now-rule';
import { StreamItemRow } from './stream-item';

interface WhatsNextRailProps {
    stream: StreamItem[];
    residents: MyDayResident[];
    activeResidentId: 'all' | number;
    onToggleTask: (taskId: number) => void;
    onGiveMed: (medId: number) => void;
    onRefuseMed?: (medId: number) => void;
    onSnoozeMed?: (medId: number) => void;
    onAddNote?: (item: StreamItem) => void;
    onOpenContextMenu: (item: StreamItem, x: number, y: number) => void;
    /** Override the "now" wall clock — used in tests + the briefing example. */
    now?: string;
    /** Link to the worker's care plan landing page. */
    carePlanHref?: string;
}

export function WhatsNextRail({
    stream,
    residents,
    activeResidentId,
    onToggleTask,
    onGiveMed,
    onRefuseMed,
    onSnoozeMed,
    onAddNote,
    onOpenContextMenu,
    now,
    carePlanHref = '/care-plans',
}: WhatsNextRailProps) {
    const residentById = new Map<number, MyDayResident>();
    residents.forEach((r) => residentById.set(r.id, r));

    const buckets = groupByTime(stream);
    const wallClock = now ?? nowHourMinute();
    const insertNowAt = nowRuleIndex(buckets, wallClock);
    const showResident = activeResidentId === 'all';
    const activeName =
        activeResidentId === 'all'
            ? null
            : residentById.get(activeResidentId)?.first_name ?? null;

    return (
        <section data-test="my-day-whats-next">
            <div className="mb-3 flex flex-wrap items-baseline gap-3 px-1">
                <h2 className="text-lg font-semibold tracking-tight">What&rsquo;s next</h2>
                <span className="text-xs text-muted-foreground">
                    {activeName
                        ? `today's care for ${activeName}`
                        : "today's care across all residents, in order"}
                </span>
                <button
                    type="button"
                    className="ml-auto inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <Plus className="h-3 w-3" /> New task
                </button>
                <Link
                    href={carePlanHref}
                    className="text-xs font-medium text-primary transition-colors hover:text-primary/80"
                >
                    See full care plan →
                </Link>
            </div>

            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                {buckets.length === 0 ? (
                    <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                        No scheduled tasks or meds for this view.
                    </div>
                ) : null}
                {buckets.map((bucket, idx) => {
                    const isNow = bucket.time === wallClock;
                    return (
                        <div key={bucket.time}>
                            {insertNowAt === idx && !isNow ? <NowRule time={wallClock} /> : null}
                            <TimeBlock
                                time={bucket.time}
                                items={bucket.items}
                                isNow={isNow}
                                residentById={residentById}
                                showResident={showResident}
                                onToggleTask={onToggleTask}
                                onGiveMed={onGiveMed}
                                onRefuseMed={onRefuseMed}
                                onSnoozeMed={onSnoozeMed}
                                onAddNote={onAddNote}
                                onOpenContextMenu={onOpenContextMenu}
                            />
                        </div>
                    );
                })}
                {insertNowAt === -1 && buckets.length > 0 ? <NowRule time={wallClock} /> : null}
            </div>
            <p className="mt-2 px-1 text-[11px] text-text-faint">
                Tip · right-click any row for more options
            </p>
        </section>
    );
}

function TimeBlock({
    time,
    items,
    isNow,
    residentById,
    showResident,
    onToggleTask,
    onGiveMed,
    onRefuseMed,
    onSnoozeMed,
    onAddNote,
    onOpenContextMenu,
}: {
    time: string;
    items: StreamItem[];
    isNow: boolean;
    residentById: Map<number, MyDayResident>;
    showResident: boolean;
    onToggleTask: (taskId: number) => void;
    onGiveMed: (medId: number) => void;
    onRefuseMed?: (medId: number) => void;
    onSnoozeMed?: (medId: number) => void;
    onAddNote?: (item: StreamItem) => void;
    onOpenContextMenu: (item: StreamItem, x: number, y: number) => void;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-[76px_1fr] border-b border-border last:border-b-0',
                isNow && 'bg-accent',
            )}
        >
            <div
                className={cn(
                    'border-r border-border px-4 py-3.5 text-base font-semibold tabular-nums tracking-tight',
                    isNow ? 'text-primary' : 'text-muted-foreground',
                )}
            >
                {time || '—'}
            </div>
            <div>
                {items.map((item, i) => (
                    <StreamItemRow
                        key={`${item.kind}-${item.data.id ?? i}`}
                        item={item}
                        isNow={isNow}
                        showResident={showResident}
                        resident={item.clientId ? residentById.get(item.clientId) : undefined}
                        onToggleTask={onToggleTask}
                        onGiveMed={onGiveMed}
                        onRefuseMed={onRefuseMed}
                        onSnoozeMed={onSnoozeMed}
                        onAddNote={onAddNote}
                        onOpenContextMenu={onOpenContextMenu}
                    />
                ))}
            </div>
        </div>
    );
}

export default WhatsNextRail;
