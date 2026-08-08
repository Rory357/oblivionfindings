/* List view — compact table; row click opens the detail pop-up. */
import { Check, ChevronRight, Flag, Lock } from 'lucide-react';
import { useMemo } from 'react';

import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { type NoteHandlers } from './cards-view';
import { type ShiftNote, TypeBadge, clientName, fmtShiftChip } from './shared';

export function ListView({
    notes,
    onOpen,
    onFlag,
    onReview,
}: { notes: ShiftNote[] } & NoteHandlers) {
    const sorted = useMemo(
        () =>
            [...notes].sort(
                (a, b) =>
                    new Date(b.created_at ?? 0).getTime() -
                    new Date(a.created_at ?? 0).getTime(),
            ),
        [notes],
    );

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="grid grid-cols-[1.4fr_auto] items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase md:grid-cols-[1.4fr_1fr_1.1fr_auto_auto]">
                <div>Person / House</div>
                <div className="hidden md:block">Author</div>
                <div className="hidden md:block">Shift</div>
                <div className="hidden md:block">Status</div>
                <div className="text-right">Actions</div>
            </div>
            <div className="divide-y divide-border">
                {sorted.map((n) => {
                    const author = n.user?.name ?? 'Unknown';
                    return (
                        <div
                            key={n.id}
                            role="button"
                            tabIndex={0}
                            onClick={() => onOpen(n)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    onOpen(n);
                                }
                            }}
                            className="grid cursor-pointer grid-cols-[1.4fr_auto] items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset md:grid-cols-[1.4fr_1fr_1.1fr_auto_auto]"
                        >
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                    <span className="truncate text-[13px] font-semibold">
                                        {clientName(n.client)}
                                    </span>
                                    {n.lock.locked ? (
                                        <Lock
                                            className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                            aria-label="Edit locked"
                                        />
                                    ) : null}
                                </div>
                                <div className="truncate text-[11.5px] text-muted-foreground">
                                    {n.site?.name ?? 'No house'}
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="truncate text-[12.5px] font-medium">
                                    {author}
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="flex items-center gap-1.5">
                                    <TypeBadge type={n.type} />
                                </div>
                                <div className="mt-1 truncate text-[11.5px] text-muted-foreground">
                                    {n.shift ? fmtShiftChip(n.shift) : '—'}
                                </div>
                            </div>

                            <div className="hidden md:block">
                                <StatusBadge note={n} />
                            </div>

                            <div
                                className="flex items-center justify-end gap-1.5"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {!n.reviewed_at ? (
                                    <GuardrailButton
                                        unstyled
                                        type="button"
                                        onClick={() => onReview(n)}
                                        className="inline-flex items-center gap-1 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold transition-colors hover:bg-accent"
                                    >
                                        <Check className="h-3.5 w-3.5" />
                                        Review
                                    </GuardrailButton>
                                ) : null}
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={() => onFlag(n)}
                                    className={cn(
                                        'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors',
                                        n.is_flagged
                                            ? 'border-border bg-background hover:bg-accent'
                                            : 'border-status-critical/30 text-status-critical hover:bg-status-critical-bg',
                                    )}
                                >
                                    <Flag className="h-3.5 w-3.5" />
                                    {n.is_flagged ? 'Unflag' : 'Flag'}
                                </GuardrailButton>
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={() => onOpen(n)}
                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    Open
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </GuardrailButton>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function StatusBadge({ note }: { note: ShiftNote }) {
    if (note.reviewed_at)
        return (
            <span className="inline-flex items-center rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">
                Reviewed
            </span>
        );
    if (note.is_flagged)
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                <Flag className="h-3 w-3" />
                Flagged
            </span>
        );
    return (
        <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
            Awaiting
        </span>
    );
}
