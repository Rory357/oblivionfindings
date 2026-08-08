/* Cards view — handovers grouped by day, newest first. */
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ClipboardCheck,
    Clock,
    Home,
    Inbox,
    ListChecks,
    Pill,
    Send,
    ShieldAlert,
    User,
} from 'lucide-react';
import { type MouseEvent as ReactMouseEvent, useMemo } from 'react';

import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { useHandoverContextMenu } from './handover-context-menu';
import {
    type Handover,
    HueAvatar,
    MoodChip,
    StatusPill,
    cardCounts,
    clientName,
    fmtShiftRange,
    handoverDate,
    humanizeRole,
    relTime,
    ymd,
} from './shared';

export function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-card/50 py-16 text-center">
            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Inbox className="h-6 w-6" />
            </div>
            <h2 className="text-base font-semibold">No handovers match</h2>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Try a different week, clear your filters, or start a new
                handover.
            </p>
        </div>
    );
}

export type CardHandlers = {
    onOpen: (h: Handover) => void;
    onSubmit: (h: Handover) => void;
    onAcknowledge: (h: Handover) => void;
    /** Optional — when present, the card's right-click menu offers "Edit". */
    onEdit?: (h: Handover) => void;
};

function HandoverCard({
    h,
    onOpen,
    onSubmit,
    onAcknowledge,
    onContextMenu,
}: {
    h: Handover;
    onContextMenu: (e: ReactMouseEvent, h: Handover) => void;
} & CardHandlers) {
    const counts = cardCounts(h);
    const attn = h.status === 'submitted';
    const outRole = humanizeRole(h.outgoing_staff?.role);
    const incRole = humanizeRole(h.incoming_staff?.role);

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onOpen(h)}
            onContextMenu={(e) => onContextMenu(e, h)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(h);
                }
            }}
            className={cn(
                'group cursor-pointer rounded-[14px] border border-border bg-card p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-[0_12px_30px_-16px_rgba(20,12,40,0.22)] focus:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:p-[18px]',
                attn && 'border-l-[3px] border-l-status-warning',
            )}
        >
            {/* Flow header */}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    {h.outgoing_staff ? (
                        <div className="flex items-center gap-2">
                            <HueAvatar name={h.outgoing_staff.name} />
                            <div className="min-w-0 leading-tight">
                                <div className="truncate text-[13px] font-bold">
                                    {h.outgoing_staff.name}
                                </div>
                                <div className="truncate text-[11px] text-muted-foreground">
                                    Outgoing{outRole ? ` · ${outRole}` : ''}
                                </div>
                            </div>
                        </div>
                    ) : null}
                    <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                    {h.incoming_staff ? (
                        <div className="flex items-center gap-2">
                            <HueAvatar name={h.incoming_staff.name} />
                            <div className="min-w-0 leading-tight">
                                <div className="truncate text-[13px] font-bold">
                                    {h.incoming_staff.name}
                                </div>
                                <div className="truncate text-[11px] text-muted-foreground">
                                    Incoming{incRole ? ` · ${incRole}` : ''}
                                </div>
                            </div>
                        </div>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-dashed border-status-warning/50 bg-status-warning-bg px-2.5 py-1 text-[11px] font-semibold text-status-warning">
                            <ShieldAlert className="h-3.5 w-3.5" />
                            Incoming shift open
                        </span>
                    )}
                </div>

                <div
                    className="flex items-center gap-2"
                    onClick={(e) => e.stopPropagation()}
                >
                    <StatusPill status={h.status} />
                    {h.status === 'draft' && h.can_submit ? (
                        <GuardrailButton
                            unstyled
                            type="button"
                            onClick={() => onSubmit(h)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold transition-colors hover:bg-accent"
                        >
                            <Send className="h-3.5 w-3.5" />
                            Submit
                        </GuardrailButton>
                    ) : null}
                    {h.status === 'submitted' && h.can_acknowledge ? (
                        <GuardrailButton
                            unstyled
                            type="button"
                            onClick={() => onAcknowledge(h)}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            <Check className="h-3.5 w-3.5" />
                            Acknowledge
                        </GuardrailButton>
                    ) : null}
                </div>
            </div>

            {/* Chips */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {h.client ? (
                    <Link
                        href={`/operations/clients/${h.client.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="inline-flex items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/15"
                    >
                        <User className="h-3 w-3" />
                        {clientName(h.client)}
                    </Link>
                ) : null}
                {h.outgoing_shift ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground">
                        <Clock className="h-3 w-3" />
                        {h.outgoing_shift.label} ·{' '}
                        {fmtShiftRange(h.outgoing_shift)}
                    </span>
                ) : null}
                {h.site ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground">
                        <Home className="h-3 w-3" />
                        {h.site.name}
                    </span>
                ) : null}
                <MoodChip mood={h.client_mood} />
            </div>

            {/* Narrative */}
            <p className="mt-3 text-[13.5px] leading-relaxed text-foreground/90">
                {h.handover_notes.length > 240
                    ? `${h.handover_notes.slice(0, 240)}…`
                    : h.handover_notes}
            </p>

            {/* Meta footer */}
            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-dashed border-border pt-3 text-[11px] text-muted-foreground">
                {counts.meds > 0 ? (
                    <span className="inline-flex items-center gap-1 text-status-critical">
                        <Pill className="h-3.5 w-3.5" />
                        <b>{counts.meds}</b> med due
                    </span>
                ) : null}
                {counts.incidents > 0 ? (
                    <span className="inline-flex items-center gap-1 text-status-critical">
                        <ShieldAlert className="h-3.5 w-3.5" />
                        <b>{counts.incidents}</b>{' '}
                        {counts.incidents === 1 ? 'incident' : 'incidents'}
                    </span>
                ) : null}
                {counts.followups > 0 ? (
                    <span className="inline-flex items-center gap-1">
                        <ListChecks className="h-3.5 w-3.5" />
                        <b>{counts.followups}</b>{' '}
                        {counts.followups === 1 ? 'follow-up' : 'follow-ups'}
                    </span>
                ) : null}
                {counts.tasks > 0 ? (
                    <span className="inline-flex items-center gap-1">
                        <ClipboardCheck className="h-3.5 w-3.5" />
                        <b>{counts.tasks}</b>{' '}
                        {counts.tasks === 1 ? 'task' : 'tasks'}
                    </span>
                ) : null}
                <span className="ml-auto">
                    {h.status === 'acknowledged' && h.acknowledger
                        ? `Acknowledged by ${h.acknowledger.name}`
                        : h.submitted_at && h.status !== 'draft'
                          ? `Submitted ${relTime(h.submitted_at)}`
                          : `Draft · edited ${relTime(h.created_at)}`}
                </span>
            </div>
        </div>
    );
}

function dayLabel(date: Date): string {
    const today = ymd(new Date());
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    if (ymd(date) === today) return 'Today';
    if (ymd(date) === ymd(yesterday)) return 'Yesterday';
    return date.toLocaleDateString('en-NZ', { weekday: 'long' });
}

export function CardsView({
    handovers,
    ...handlers
}: { handovers: Handover[] } & CardHandlers) {
    const { openCtx, menu } = useHandoverContextMenu(handlers);
    const groups = useMemo(() => {
        const byDay = new Map<string, { date: Date; items: Handover[] }>();
        for (const h of handovers) {
            const date = handoverDate(h);
            const key = ymd(date);
            if (!byDay.has(key)) byDay.set(key, { date, items: [] });
            byDay.get(key)!.items.push(h);
        }
        const arr = Array.from(byDay.values());
        for (const g of arr)
            g.items.sort(
                (a, b) =>
                    new Date(b.created_at ?? 0).getTime() -
                    new Date(a.created_at ?? 0).getTime(),
            );
        arr.sort((a, b) => b.date.getTime() - a.date.getTime());
        return arr;
    }, [handovers]);

    if (handovers.length === 0) return <EmptyState />;

    return (
        <>
            <div className="space-y-6">
                {groups.map((g) => (
                    <div key={ymd(g.date)} className="space-y-3">
                        <div className="flex items-center gap-3">
                            <span className="text-sm font-bold">
                                {dayLabel(g.date)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {g.date.toLocaleDateString('en-NZ', {
                                    day: 'numeric',
                                    month: 'long',
                                })}
                            </span>
                            <span className="h-px flex-1 bg-border" />
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {g.items.length} handover
                                {g.items.length === 1 ? '' : 's'}
                            </span>
                        </div>
                        {g.items.map((h) => (
                            <HandoverCard
                                key={h.id}
                                h={h}
                                {...handlers}
                                onContextMenu={openCtx}
                            />
                        ))}
                    </div>
                ))}
            </div>
            {menu}
        </>
    );
}
