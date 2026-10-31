/* Cards view — notes grouped by day, newest first. Entire card opens the detail
 * popup; footer actions (review / flag / open) stop propagation. */
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    Flag,
    Home,
    Lock,
    NotebookPen,
    Plus,
    User,
    X,
} from 'lucide-react';
import { type MouseEvent as ReactMouseEvent, useMemo, useState } from 'react';

import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
    compactMenu,
    useEntityContextMenu,
} from '@/components/lists';

import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { EmptyState as SharedEmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    HueAvatar,
    type ShiftNote,
    TypeBadge,
    clientName,
    fmtShiftChip,
    noteCalendarDate,
    noteDate,
    relTime,
    shiftRole,
    typeMeta,
    ymd,
} from './shared';

export type NoteHandlers = {
    timeZone?: string;
    canFlag?: boolean;
    canReview?: boolean;
    onOpen: (note: ShiftNote) => void;
    onFlag: (note: ShiftNote) => void;
    onReview: (note: ShiftNote) => void;
};

export function EmptyState({
    filtersActive,
    canCreate,
    onClearFilters,
    onAddNote,
}: {
    filtersActive: boolean;
    canCreate: boolean;
    onClearFilters: () => void;
    onAddNote: () => void;
}) {
    return (
        <SharedEmptyState
            icon={FileText}
            title={filtersActive ? 'No notes match' : 'No notes this week'}
            description={
                filtersActive
                    ? 'Try adjusting your filters or clearing the day selection.'
                    : 'Notes will appear here as support workers document their shifts.'
            }
            action={
                filtersActive ? (
                    <GuardrailButton
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onClearFilters}
                    >
                        <X className="size-3.5" />
                        Clear filters
                    </GuardrailButton>
                ) : canCreate ? (
                    <GuardrailButton
                        type="button"
                        size="sm"
                        onClick={onAddNote}
                    >
                        <Plus className="size-3.5" />
                        Add shift note
                    </GuardrailButton>
                ) : undefined
            }
        />
    );
}

function ExpandableBody({ text, max = 320 }: { text: string; max?: number }) {
    const [open, setOpen] = useState(false);
    if (!text) return null;
    if (text.length <= max)
        return (
            <p className="mt-3 text-base leading-relaxed whitespace-pre-wrap text-foreground/90">
                {text}
            </p>
        );
    return (
        <div className="mt-3">
            <p className="text-base leading-relaxed whitespace-pre-wrap text-foreground/90">
                {open ? text : `${text.slice(0, max)}…`}
            </p>
            <GuardrailButton
                unstyled
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    setOpen((v) => !v);
                }}
                className="mt-1 text-xs font-semibold text-primary hover:underline"
            >
                {open ? 'Show less' : 'Show more'}
            </GuardrailButton>
        </div>
    );
}

function ActionButton({
    onClick,
    danger,
    children,
}: {
    onClick: () => void;
    danger?: boolean;
    children: React.ReactNode;
}) {
    return (
        <GuardrailButton
            type="button"
            size="sm"
            variant="outline"
            className={cn(danger && 'text-status-critical')}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
        >
            {children}
        </GuardrailButton>
    );
}

/** One item set for a note's ⋯ menu and its right-click menu. */
export function noteMenu(note: ShiftNote, handlers: NoteHandlers): MenuItem[] {
    return compactMenu([
        { label: 'Open note', icon: Eye, onClick: () => handlers.onOpen(note) },
        !note.reviewed_at && handlers.canReview && note.can_review
            ? {
                  label: 'Mark reviewed',
                  icon: Check,
                  onClick: () => handlers.onReview(note),
              }
            : null,
        handlers.canFlag && note.can_flag
            ? {
                  label: note.is_flagged ? 'Remove flag' : 'Flag for review',
                  icon: Flag,
                  onClick: () => handlers.onFlag(note),
              }
            : null,
    ]);
}

function NoteCard({
    note,
    onOpen,
    onFlag,
    onReview,
    timeZone,
    canFlag = false,
    canReview = false,
    onContextMenu,
}: {
    note: ShiftNote;
    onContextMenu: (e: ReactMouseEvent, note: ShiftNote) => void;
} & NoteHandlers) {
    const meta = typeMeta(note.type);
    const author = note.user?.name ?? 'Unknown';
    const house = note.site?.name ?? null;

    return (
        <div
            role="group"
            aria-label={`Note #${note.id} for ${clientName(note.client)}`}
            onClick={() => onOpen(note)}
            onContextMenu={(e) => onContextMenu(e, note)}
            className="group cursor-pointer rounded-[12px] border border-l-[4px] border-border bg-card px-4 py-[15px] text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-[0_12px_30px_-16px_rgba(20,12,40,0.22)] focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            style={{ borderLeftColor: meta.color }}
        >
            {/* Top row */}
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <HueAvatar name={author} size={38} />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-sm font-bold">{author}</span>
                            <TypeBadge type={note.type} />
                            {note.is_private ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">
                                    <Lock className="h-3 w-3" />
                                    Private
                                </span>
                            ) : null}
                            {note.is_flagged ? (
                                <StatusBadge variant="critical">
                                    <Flag className="h-3 w-3" />
                                    Flagged
                                </StatusBadge>
                            ) : null}
                            {note.edited_at ? (
                                <span className="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">
                                    Edited
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-0.5 truncate text-sm text-muted-foreground">
                            {shiftRole(note.shift, timeZone)}
                            {house ? ` · ${house}` : ''}
                        </div>
                    </div>
                </div>
                <span className="shrink-0 text-sm text-muted-foreground">
                    {relTime(note.created_at)}
                </span>
            </div>

            {/* Chips */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {note.client ? (
                    <Link
                        href={`/operations/clients/${note.client.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="inline-flex items-center gap-1.5 rounded-full bg-primary-fill/10 px-2.5 py-1 text-xs font-semibold text-primary transition-colors hover:bg-primary/15"
                    >
                        <User className="h-3 w-3" />
                        {clientName(note.client)}
                    </Link>
                ) : null}
                {note.shift ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">
                        <Clock className="h-3 w-3" />
                        {fmtShiftChip(note.shift, timeZone)}
                    </span>
                ) : null}
                {house ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">
                        <Home className="h-3 w-3" />
                        {house}
                    </span>
                ) : null}
            </div>

            {/* Body */}
            <ExpandableBody text={note.body} />

            {/* Footer */}
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-border pt-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    {note.type === 'incident' ? (
                        <span className="inline-flex items-center gap-1 text-status-critical">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            Incident note
                        </span>
                    ) : null}
                    {note.reviewed_at ? (
                        <span className="inline-flex items-center gap-1 text-status-success">
                            <CheckCircle2 className="h-3.5 w-3.5" />
                            Reviewed
                            {note.reviewer ? ` · ${note.reviewer.name}` : ''}
                        </span>
                    ) : note.is_flagged ? (
                        <span className="inline-flex items-center gap-1 text-status-critical">
                            <Flag className="h-3 w-3" />
                            {note.flagged_reason ?? 'Flagged for review'}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                            <Clock className="h-3 w-3" />
                            Awaiting review
                        </span>
                    )}
                </div>
                <div
                    className="flex items-center gap-1.5"
                    onClick={(e) => e.stopPropagation()}
                >
                    {!note.reviewed_at && canReview && note.can_review ? (
                        <ActionButton onClick={() => onReview(note)}>
                            <Check className="h-3.5 w-3.5" />
                            Mark reviewed
                        </ActionButton>
                    ) : null}
                    {canFlag && note.can_flag && (
                        <ActionButton
                            danger={!note.is_flagged}
                            onClick={() => onFlag(note)}
                        >
                            <Flag className="h-3.5 w-3.5" />
                            {note.is_flagged ? 'Unflag' : 'Flag'}
                        </ActionButton>
                    )}
                    <EntityKebab
                        label={`Actions for the note for ${clientName(note.client)}`}
                        actions={noteMenu(note, {
                            onOpen,
                            onFlag,
                            onReview,
                            canFlag,
                            canReview,
                        })}
                    />
                </div>
            </div>
        </div>
    );
}

function dayLabel(date: Date, timeZone?: string): string {
    if (!Number.isFinite(date.getTime())) return 'Date unavailable';
    const todayDate = noteCalendarDate(new Date().toISOString(), timeZone);
    const today = ymd(todayDate);
    const yesterday = new Date(todayDate);
    yesterday.setDate(yesterday.getDate() - 1);
    if (ymd(date) === today) return 'Today';
    if (ymd(date) === ymd(yesterday)) return 'Yesterday';
    return date.toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

export function CardsView({
    notes,
    ...handlers
}: { notes: ShiftNote[] } & NoteHandlers) {
    const ctx = useEntityContextMenu<ShiftNote>();
    const groups = useMemo(() => {
        const byDay = new Map<string, { date: Date; items: ShiftNote[] }>();
        for (const n of notes) {
            const date = noteDate(n, handlers.timeZone);
            const key = ymd(date);
            if (!byDay.has(key)) byDay.set(key, { date, items: [] });
            byDay.get(key)!.items.push(n);
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
    }, [notes, handlers.timeZone]);

    return (
        <div className="space-y-6">
            {groups.map((g) => (
                <div key={ymd(g.date)} className="space-y-3">
                    <div className="flex items-center gap-3">
                        <span className="text-sm font-bold">
                            {dayLabel(g.date, handlers.timeZone)}
                        </span>
                        <span className="h-px flex-1 bg-border" />
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {g.items.length} note
                            {g.items.length === 1 ? '' : 's'}
                        </span>
                    </div>
                    {g.items.map((n) => (
                        <NoteCard
                            key={n.id}
                            note={n}
                            {...handlers}
                            onContextMenu={ctx.open}
                        />
                    ))}
                </div>
            ))}
            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={NotebookPen}
                    title={clientName(ctx.ctx.record.client)}
                    items={noteMenu(ctx.ctx.record, handlers)}
                    onClose={ctx.close}
                />
            ) : null}
        </div>
    );
}
