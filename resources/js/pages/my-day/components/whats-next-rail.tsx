import { Link } from '@inertiajs/react';

import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';

import {
    type StreamItem,
    groupByTime,
    nowHourMinute,
    nowRuleIndex,
} from '../lib/stream-grouping';
import type { MyDayResident } from '../lib/types';

import { NowRule } from './now-rule';
import { StreamItemRow } from './stream-item';

interface WhatsNextRailProps {
    stream: StreamItem[];
    residents: MyDayResident[];
    activeResidentId: 'all' | number;
    onToggleTask: (taskId: number) => void;
    onGiveMed: (medicationId: number, scheduledFor: string) => void;
    onRefuseMed?: (medicationId: number, scheduledFor: string) => void;
    onSnoozeMed?: (medicationId: number, scheduledFor: string) => void;
    onAddNote?: (item: StreamItem) => void;
    onOpenContextMenu: (item: StreamItem, x: number, y: number) => void;
    /** Override the "now" wall clock — used in tests + the briefing example. */
    now?: string;
    /**
     * Override the "See full care plan →" link. When omitted, the rail derives
     * a resident-scoped href whenever the active filter narrows to a single
     * person (workers have `clients.viewAssigned` but not `care_plans.viewAny`,
     * so an org-wide link would 403 on a multi-resident shift).
     */
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
    carePlanHref,
}: WhatsNextRailProps) {
    const t = useMyDayLabels();
    const residentById = new Map<number, MyDayResident>();
    residents.forEach((r) => residentById.set(r.id, r));

    const buckets = groupByTime(stream);
    const wallClock = now ?? nowHourMinute();
    const insertNowAt = nowRuleIndex(buckets, wallClock);
    const showResident = activeResidentId === 'all';
    const activeResident =
        activeResidentId === 'all' ? null : residentById.get(activeResidentId);
    const activeName = activeResident?.first_name ?? null;

    // When the rail is filtered to a single resident, scope the link to that
    // resident's care page; otherwise (and on a 1:1 shift where `residents`
    // only has one entry) point at the lone resident; only fall back to the
    // org-wide care-plan list when an explicit `carePlanHref` is provided.
    const resolvedCarePlanHref =
        carePlanHref ??
        (activeResident
            ? `/clients/${activeResident.id}?tab=care_plans`
            : null) ??
        (residents.length === 1
            ? `/clients/${residents[0].id}?tab=care_plans`
            : null);

    return (
        <section data-test="my-day-whats-next">
            <div className="mb-3 flex flex-wrap items-baseline gap-3 px-1">
                <h2 className="text-lg font-semibold tracking-tight">
                    {t('whats_next')}
                </h2>
                <span className="text-xs text-muted-foreground">
                    {activeName
                        ? t('todays_care_for', { name: activeName })
                        : t('todays_care_all')}
                </span>
                {resolvedCarePlanHref ? (
                    <Link
                        href={resolvedCarePlanHref}
                        className="ml-auto text-xs font-medium text-primary transition-colors hover:text-primary/80"
                    >
                        {t('see_full_care_plan')}
                    </Link>
                ) : null}
            </div>

            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                {buckets.length === 0 ? (
                    <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                        {t('no_tasks_or_meds')}
                    </div>
                ) : null}
                {buckets.map((bucket, idx) => {
                    const isNow = bucket.time === wallClock;
                    return (
                        <div key={bucket.time}>
                            {insertNowAt === idx && !isNow ? (
                                <NowRule time={wallClock} />
                            ) : null}
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
                {insertNowAt === -1 && buckets.length > 0 ? (
                    <NowRule time={wallClock} />
                ) : null}
            </div>
            <p className="mt-2 px-1 text-[11px] text-text-faint">
                {t('right_click_tip')}
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
    onGiveMed: (medicationId: number, scheduledFor: string) => void;
    onRefuseMed?: (medicationId: number, scheduledFor: string) => void;
    onSnoozeMed?: (medicationId: number, scheduledFor: string) => void;
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
                    'border-r border-border px-4 py-3.5 text-base font-semibold tracking-tight tabular-nums',
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
                        resident={
                            item.clientId
                                ? residentById.get(item.clientId)
                                : undefined
                        }
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
