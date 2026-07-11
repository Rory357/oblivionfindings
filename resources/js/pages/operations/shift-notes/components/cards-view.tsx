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
    Plus,
    User,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { cn } from '@/lib/utils';

import {
    HueAvatar,
    type ShiftNote,
    TypeBadge,
    clientName,
    fmtShiftChip,
    noteDate,
    relTime,
    shiftRole,
    typeMeta,
    ymd,
} from './shared';
import { Button as GuardrailButton } from '@/components/ui/button';

export type NoteHandlers = {
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
        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-card/50 py-16 text-center">
            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-accent text-primary">
                <FileText className="h-6 w-6" />
            </div>
            <h2 className="text-base font-semibold">
                {filtersActive ? 'No notes match' : 'No notes this week'}
            </h2>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                {filtersActive
                    ? 'Try adjusting your filters or clearing the day selection.'
                    : 'Notes will appear here as support workers document their shifts.'}
            </p>
            {filtersActive ? (
                <GuardrailButton unstyled
                    type="button"
                    onClick={onClearFilters}
                    className="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                >
                    <X className="h-3.5 w-3.5" />
                    Clear filters
                </GuardrailButton>
            ) : canCreate ? (
                <GuardrailButton unstyled
                    type="button"
                    onClick={onAddNote}
                    className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                >
                    <Plus className="h-3.5 w-3.5" />
                    Add shift note
                </GuardrailButton>
            ) : null}
        </div>
    );
}

function ExpandableBody({ text, max = 320 }: { text: string; max?: number }) {
    const [open, setOpen] = useState(false);
    if (!text) return null;
    if (text.length <= max)
        return (
            <p className="mt-3 text-[13.5px] leading-relaxed whitespace-pre-wrap text-foreground/90">
                {text}
            </p>
        );
    return (
        <div className="mt-3">
            <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap text-foreground/90">
                {open ? text : `${text.slice(0, max)}…`}
            </p>
            <GuardrailButton unstyled
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
        <GuardrailButton unstyled
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            className={cn(
                'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors',
                danger
                    ? 'border-status-critical/30 text-status-critical hover:bg-status-critical-bg'
                    : 'border-border bg-background hover:bg-accent',
            )}
        >
            {children}
        </GuardrailButton>
    );
}

function NoteCard({
    note,
    onOpen,
    onFlag,
    onReview,
}: { note: ShiftNote } & NoteHandlers) {
    const meta = typeMeta(note.type);
    const author = note.user?.name ?? 'Unknown';
    const house = note.site?.name ?? null;

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onOpen(note)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(note);
                }
            }}
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
                                <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                    <Lock className="h-3 w-3" />
                                    Private
                                </span>
                            ) : null}
                            {note.is_flagged ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-critical">
                                    <Flag className="h-3 w-3" />
                                    Flagged
                                </span>
                            ) : null}
                            {note.edited_at ? (
                                <span className="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                    Edited
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-0.5 truncate text-[11.5px] text-muted-foreground">
                            {shiftRole(note.shift)}
                            {house ? ` · ${house}` : ''}
                        </div>
                    </div>
                </div>
                <span className="shrink-0 text-[11.5px] text-muted-foreground">
                    {relTime(note.created_at)}
                </span>
            </div>

            {/* Chips */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {note.client ? (
                    <Link
                        href={`/operations/clients/${note.client.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="inline-flex items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/15"
                    >
                        <User className="h-3 w-3" />
                        {clientName(note.client)}
                    </Link>
                ) : null}
                {note.shift ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground">
                        <Clock className="h-3 w-3" />
                        {fmtShiftChip(note.shift)}
                    </span>
                ) : null}
                {house ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground">
                        <Home className="h-3 w-3" />
                        {house}
                    </span>
                ) : null}
            </div>

            {/* Body */}
            <ExpandableBody text={note.body} />

            {/* Footer */}
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-border pt-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px]">
                    {note.type === 'incident' ? (
                        <span className="inline-flex items-center gap-1 text-status-critical">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            <b>1</b> incident
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
                    {!note.reviewed_at ? (
                        <ActionButton onClick={() => onReview(note)}>
                            <Check className="h-3.5 w-3.5" />
                            Mark reviewed
                        </ActionButton>
                    ) : null}
                    <ActionButton
                        danger={!note.is_flagged}
                        onClick={() => onFlag(note)}
                    >
                        <Flag className="h-3.5 w-3.5" />
                        {note.is_flagged ? 'Unflag' : 'Flag'}
                    </ActionButton>
                    <ActionButton onClick={() => onOpen(note)}>
                        <Eye className="h-3.5 w-3.5" />
                        Open
                    </ActionButton>
                </div>
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
    const groups = useMemo(() => {
        const byDay = new Map<string, { date: Date; items: ShiftNote[] }>();
        for (const n of notes) {
            const date = noteDate(n);
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
    }, [notes]);

    return (
        <div className="space-y-6">
            {groups.map((g) => (
                <div key={ymd(g.date)} className="space-y-3">
                    <div className="flex items-center gap-3">
                        <span className="text-sm font-bold">
                            {dayLabel(g.date)}
                        </span>
                        <span className="h-px flex-1 bg-border" />
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {g.items.length} note
                            {g.items.length === 1 ? '' : 's'}
                        </span>
                    </div>
                    {g.items.map((n) => (
                        <NoteCard key={n.id} note={n} {...handlers} />
                    ))}
                </div>
            ))}
        </div>
    );
}
