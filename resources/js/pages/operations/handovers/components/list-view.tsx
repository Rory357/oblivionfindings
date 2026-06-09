/* List view — compact table; row click opens the detail pop-up. */
import {
    ArrowRight,
    Check,
    ChevronRight,
    ClipboardCheck,
    ListChecks,
    Pill,
    ShieldAlert,
} from 'lucide-react';
import { useMemo } from 'react';

import { cn } from '@/lib/utils';

import { EmptyState, type CardHandlers } from './cards-view';
import {
    type Handover,
    HueAvatar,
    StatusPill,
    cardCounts,
    clientName,
    fmtShiftRange,
    handoverDate,
    moodEmoji,
} from './shared';

export function ListView({
    handovers,
    onOpen,
    onSubmit,
    onAcknowledge,
}: { handovers: Handover[] } & CardHandlers) {
    const sorted = useMemo(
        () =>
            [...handovers].sort(
                (a, b) =>
                    new Date(b.created_at ?? 0).getTime() -
                    new Date(a.created_at ?? 0).getTime(),
            ),
        [handovers],
    );

    if (handovers.length === 0) return <EmptyState />;

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="grid grid-cols-[1.4fr_auto] items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase md:grid-cols-[1.4fr_1.4fr_1fr_auto_auto]">
                <div>Client / house</div>
                <div className="hidden md:block">Handover</div>
                <div className="hidden md:block">Shift</div>
                <div className="hidden md:block">Status</div>
                <div className="text-right">Actions</div>
            </div>
            <div className="divide-y divide-border">
                {sorted.map((h) => {
                    const c = cardCounts(h);
                    return (
                        <div
                            key={h.id}
                            role="button"
                            tabIndex={0}
                            onClick={() => onOpen(h)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    onOpen(h);
                                }
                            }}
                            className="grid cursor-pointer grid-cols-[1.4fr_auto] items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset md:grid-cols-[1.4fr_1.4fr_1fr_auto_auto]"
                        >
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                    <span className="truncate text-[13px] font-semibold">
                                        {clientName(h.client)}
                                    </span>
                                    {h.lock.locked ? (
                                        <ShieldAlert
                                            className="h-3.5 w-3.5 shrink-0 text-status-critical"
                                            aria-label="Locked · manager only"
                                        />
                                    ) : null}
                                </div>
                                <div className="truncate text-[11.5px] text-muted-foreground">
                                    {h.site?.name ?? 'No house'} ·{' '}
                                    {h.client_mood
                                        ? `${moodEmoji(h.client_mood)} ${h.client_mood}`
                                        : 'No mood logged'}
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="flex items-center gap-1.5">
                                    {h.outgoing_staff ? (
                                        <>
                                            <HueAvatar
                                                name={h.outgoing_staff.name}
                                                size={22}
                                            />
                                            <span className="text-[12px] font-medium">
                                                {
                                                    h.outgoing_staff.name.split(
                                                        ' ',
                                                    )[0]
                                                }
                                            </span>
                                        </>
                                    ) : null}
                                    <ArrowRight className="h-3 w-3 text-muted-foreground" />
                                    {h.incoming_staff ? (
                                        <>
                                            <HueAvatar
                                                name={h.incoming_staff.name}
                                                size={22}
                                            />
                                            <span className="text-[12px] font-medium">
                                                {
                                                    h.incoming_staff.name.split(
                                                        ' ',
                                                    )[0]
                                                }
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-[12px] font-semibold text-status-warning">
                                            Open
                                        </span>
                                    )}
                                </div>
                                <div className="mt-1 flex items-center gap-2 text-[11px] text-muted-foreground">
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
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="text-[12.5px] font-semibold">
                                    {h.outgoing_shift?.label ?? '—'}
                                </div>
                                <div className="truncate text-[11.5px] text-muted-foreground">
                                    {h.outgoing_shift
                                        ? `${fmtShiftRange(h.outgoing_shift)} · ${handoverDate(
                                              h,
                                          ).toLocaleDateString('en-NZ', {
                                              day: 'numeric',
                                              month: 'short',
                                          })}`
                                        : ''}
                                </div>
                            </div>

                            <div className="hidden md:block">
                                <StatusPill status={h.status} />
                            </div>

                            <div
                                className="flex items-center justify-end gap-1.5"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {h.status === 'draft' && h.can_submit ? (
                                    <button
                                        type="button"
                                        onClick={() => onSubmit(h)}
                                        className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold transition-colors hover:bg-accent"
                                    >
                                        Submit
                                    </button>
                                ) : null}
                                {h.status === 'submitted' &&
                                h.can_acknowledge ? (
                                    <button
                                        type="button"
                                        onClick={() => onAcknowledge(h)}
                                        className="inline-flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        <Check className="h-3.5 w-3.5" />
                                        Ack
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => onOpen(h)}
                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    Open
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
