/* List view — compact table; row click opens the detail pop-up. */
import { Check, Flag, Lock, NotebookPen } from 'lucide-react';
import { useMemo } from 'react';

import { cn } from '@/lib/utils';

import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
} from '@/components/lists';
import { Button as GuardrailButton } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useIsMobile } from '@/hooks/use-mobile';
import { CardsView, noteMenu, type NoteHandlers } from './cards-view';
import { TypeBadge, clientName, fmtShiftChip, type ShiftNote } from './shared';

export function ListView({
    notes,
    onOpen,
    onFlag,
    onReview,
    timeZone,
    canFlag = false,
    canReview = false,
}: { notes: ShiftNote[] } & NoteHandlers) {
    const mobile = useIsMobile();
    const ctx = useEntityContextMenu<ShiftNote>();
    const menuHandlers = { onOpen, onFlag, onReview, canFlag, canReview };
    const sorted = useMemo(
        () =>
            [...notes].sort(
                (a, b) =>
                    new Date(b.created_at ?? 0).getTime() -
                    new Date(a.created_at ?? 0).getTime(),
            ),
        [notes],
    );

    if (mobile)
        return (
            <CardsView
                notes={notes}
                onOpen={onOpen}
                onFlag={onFlag}
                onReview={onReview}
                timeZone={timeZone}
                canFlag={canFlag}
                canReview={canReview}
            />
        );
    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="grid grid-cols-[1.4fr_auto] items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5 text-xs font-bold tracking-wide text-muted-foreground uppercase md:grid-cols-[1.4fr_1fr_1.1fr_auto_auto]">
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
                            role="group"
                            aria-label={`Note #${n.id} for ${clientName(n.client)}`}
                            onClick={() => onOpen(n)}
                            onContextMenu={(e) => ctx.open(e, n)}
                            className="grid cursor-pointer grid-cols-[1.4fr_auto] items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset md:grid-cols-[1.4fr_1fr_1.1fr_auto_auto]"
                        >
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                    <span className="truncate text-sm font-semibold">
                                        {clientName(n.client)}
                                    </span>
                                    {n.lock.locked ? (
                                        <Lock
                                            className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                            aria-label="Edit locked"
                                        />
                                    ) : null}
                                </div>
                                <div className="truncate text-sm text-muted-foreground">
                                    {n.site?.name ?? 'No house'}
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="truncate text-sm font-medium">
                                    {author}
                                </div>
                            </div>

                            <div className="hidden min-w-0 md:block">
                                <div className="flex items-center gap-1.5">
                                    <TypeBadge type={n.type} />
                                </div>
                                <div className="mt-1 truncate text-sm text-muted-foreground">
                                    {n.shift
                                        ? fmtShiftChip(n.shift, timeZone)
                                        : '—'}
                                </div>
                            </div>

                            <div className="hidden md:block">
                                <NoteStatusBadge note={n} />
                            </div>

                            <div
                                className="flex items-center justify-end gap-1.5"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {!n.reviewed_at && canReview && n.can_review ? (
                                    <GuardrailButton
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => onReview(n)}
                                    >
                                        <Check className="size-3.5" />
                                        Mark reviewed
                                    </GuardrailButton>
                                ) : null}
                                {canFlag && n.can_flag && (
                                    <GuardrailButton
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className={cn(
                                            !n.is_flagged &&
                                                'text-status-critical',
                                        )}
                                        onClick={() => onFlag(n)}
                                    >
                                        <Flag className="size-3.5" />
                                        {n.is_flagged ? 'Remove flag' : 'Flag'}
                                    </GuardrailButton>
                                )}
                                <EntityKebab
                                    label={`Actions for the note for ${clientName(n.client)}`}
                                    actions={noteMenu(n, menuHandlers)}
                                />
                            </div>
                        </div>
                    );
                })}
            </div>
            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={NotebookPen}
                    title={clientName(ctx.ctx.record.client)}
                    items={noteMenu(ctx.ctx.record, menuHandlers)}
                    onClose={ctx.close}
                />
            ) : null}
        </div>
    );
}

function NoteStatusBadge({ note }: { note: ShiftNote }) {
    if (note.reviewed_at)
        return <StatusBadge variant="success">Reviewed</StatusBadge>;
    if (note.is_flagged)
        return (
            <StatusBadge variant="critical">
                <Flag className="h-3 w-3" />
                Flagged
            </StatusBadge>
        );
    return <StatusBadge variant="neutral">Awaiting review</StatusBadge>;
}
