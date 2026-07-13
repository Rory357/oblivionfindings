/* Board (kanban) view — Draft / Awaiting sign-off / Acknowledged columns. */
import {
    ArrowRight,
    Check,
    ClipboardCheck,
    ListChecks,
    Pill,
    ShieldAlert,
} from 'lucide-react';
import { useMemo } from 'react';

import { cn } from '@/lib/utils';

import { type CardHandlers } from './cards-view';
import {
    type Handover,
    HueAvatar,
    cardCounts,
    clientName,
    fmtTime,
    moodEmoji,
    relTime,
} from './shared';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

const COLUMNS: {
    status: 'draft' | 'submitted' | 'acknowledged';
    label: string;
    dot: string;
}[] = [
    { status: 'draft', label: 'Draft', dot: 'bg-muted-foreground' },
    {
        status: 'submitted',
        label: 'Awaiting sign-off',
        dot: 'bg-status-warning',
    },
    {
        status: 'acknowledged',
        label: 'Acknowledged',
        dot: 'bg-status-success',
    },
];

function BoardCard({
    h,
    onOpen,
    onSubmit,
    onAcknowledge,
}: { h: Handover } & CardHandlers) {
    const c = cardCounts(h);
    return (
        <GuardrailCard unstyled
            role="button"
            tabIndex={0}
            onClick={() => onOpen(h)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(h);
                }
            }}
            className="cursor-pointer rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <div className="flex items-center justify-between gap-2">
                <span className="flex min-w-0 items-center gap-1.5">
                    {h.outgoing_staff ? (
                        <HueAvatar name={h.outgoing_staff.name} size={24} />
                    ) : null}
                    <span className="truncate text-[12.5px] font-bold">
                        {clientName(h.client)}
                    </span>
                </span>
                {h.lock.locked ? (
                    <ShieldAlert
                        className="h-3.5 w-3.5 shrink-0 text-status-critical"
                        aria-label="Locked · manager only"
                    />
                ) : h.client_mood ? (
                    <span
                        className="text-[15px] leading-none"
                        title={h.client_mood}
                    >
                        {moodEmoji(h.client_mood)}
                    </span>
                ) : null}
            </div>

            <div className="mt-2 flex items-center gap-1 text-[11.5px] text-muted-foreground">
                <span className="font-medium text-foreground">
                    {h.outgoing_staff?.name.split(' ')[0] ?? '—'}
                </span>
                <ArrowRight className="h-3 w-3" />
                {h.incoming_staff ? (
                    <span className="font-medium text-foreground">
                        {h.incoming_staff.name.split(' ')[0]}
                    </span>
                ) : (
                    <span className="font-semibold text-status-warning">
                        Open
                    </span>
                )}
                {h.outgoing_shift ? (
                    <span>
                        {' '}
                        · {h.outgoing_shift.label}{' '}
                        {fmtTime(h.outgoing_shift.starts_at)}
                    </span>
                ) : null}
            </div>

            <p className="mt-2 line-clamp-2 text-[12.5px] leading-snug text-foreground/90">
                {h.handover_notes}
            </p>

            <div className="mt-2.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                {c.meds > 0 ? (
                    <span className="inline-flex items-center gap-0.5 text-status-critical">
                        <Pill className="h-3 w-3" />
                        {c.meds}
                    </span>
                ) : null}
                {c.incidents > 0 ? (
                    <span className="inline-flex items-center gap-0.5 text-status-critical">
                        <ShieldAlert className="h-3 w-3" />
                        {c.incidents}
                    </span>
                ) : null}
                {c.followups > 0 ? (
                    <span className="inline-flex items-center gap-0.5">
                        <ListChecks className="h-3 w-3" />
                        {c.followups}
                    </span>
                ) : null}
                {c.tasks > 0 ? (
                    <span className="inline-flex items-center gap-0.5">
                        <ClipboardCheck className="h-3 w-3" />
                        {c.tasks}
                    </span>
                ) : null}
                <span
                    className="ml-auto"
                    onClick={(e) => e.stopPropagation()}
                >
                    {h.status === 'submitted' && h.can_acknowledge ? (
                        <GuardrailButton unstyled
                            type="button"
                            onClick={() => onAcknowledge(h)}
                            className="inline-flex items-center gap-1 rounded-md bg-primary px-2 py-1 text-[11px] font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            <Check className="h-3 w-3" />
                            Ack
                        </GuardrailButton>
                    ) : h.status === 'draft' && h.can_submit ? (
                        <GuardrailButton unstyled
                            type="button"
                            onClick={() => onSubmit(h)}
                            className="rounded-md border border-border bg-background px-2 py-1 text-[11px] font-semibold transition-colors hover:bg-accent"
                        >
                            Submit
                        </GuardrailButton>
                    ) : (
                        <span className="text-muted-foreground">
                            {h.status === 'acknowledged' && h.acknowledged_at
                                ? relTime(h.acknowledged_at)
                                : relTime(h.created_at)}
                        </span>
                    )}
                </span>
            </div>
        </GuardrailCard>
    );
}

export function BoardView({
    handovers,
    ...handlers
}: { handovers: Handover[] } & CardHandlers) {
    const byStatus = useMemo(() => {
        const map: Record<string, Handover[]> = {
            draft: [],
            submitted: [],
            acknowledged: [],
        };
        for (const h of handovers) {
            (map[h.status] ??= []).push(h);
        }
        for (const list of Object.values(map))
            list.sort(
                (a, b) =>
                    new Date(b.created_at ?? 0).getTime() -
                    new Date(a.created_at ?? 0).getTime(),
            );
        return map;
    }, [handovers]);

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            {COLUMNS.map((col) => {
                const items = byStatus[col.status] ?? [];
                return (
                    <div
                        key={col.status}
                        className="flex flex-col rounded-2xl border border-border bg-muted/30"
                    >
                        <div className="flex items-center gap-2 border-b border-border px-3.5 py-3">
                            <span
                                className={cn('h-2 w-2 rounded-full', col.dot)}
                            />
                            <span className="text-[13px] font-bold">
                                {col.label}
                            </span>
                            <span className="ml-auto rounded-full bg-card px-2 text-[11px] font-semibold text-muted-foreground tabular-nums">
                                {items.length}
                            </span>
                        </div>
                        <div className="flex flex-col gap-2.5 p-3">
                            {items.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-border py-6 text-center text-xs text-muted-foreground">
                                    Nothing here
                                </div>
                            ) : (
                                items.map((h) => (
                                    <BoardCard
                                        key={h.id}
                                        h={h}
                                        {...handlers}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
