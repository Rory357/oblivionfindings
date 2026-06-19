/* eslint-disable no-restricted-syntax -- The rail section-nav and the footer
 * Acknowledge action are bespoke controls matching the wizard-shell chrome from
 * the design handoff; shadcn <Button> can't express the rail layout. */
import { CheckCircle2, FileText, ListChecks, MessagesSquare, Repeat, X } from 'lucide-react';
import { useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { WIZARD_FOOTER_CLASS, WIZARD_RAIL_CLASS } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';

export interface OneSession {
    id: number;
    session_date: string | null;
    session_type: string | null;
    supervisor: { id: number; name: string } | null;
    topics_discussed: string | null;
    actions_agreed: string[];
    employee_comments: string | null;
    employee_acknowledged: boolean;
    employee_acknowledged_at: string | null;
}

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * 1:1 review modal — built on the wizard-shell chrome (248px rail + header +
 * scroll body + footer band) per the design handoff. Phase 1 surfaces an
 * existing HrSupervisionNote: talking points (topics), action items, and your
 * comment; an unacknowledged session can be acknowledged with a comment.
 */
export function MyHrOneOnOneModal({
    session,
    cadence,
    onClose,
    onAcknowledge,
}: {
    session: OneSession | null;
    cadence: string;
    onClose: () => void;
    onAcknowledge: (id: number, comment: string) => void;
}) {
    const [comment, setComment] = useState('');
    const agendaRef = useRef<HTMLDivElement | null>(null);
    const actionsRef = useRef<HTMLDivElement | null>(null);
    const notesRef = useRef<HTMLDivElement | null>(null);

    if (!session) return null;

    const who = session.supervisor?.name ?? 'your manager';
    const topics = (session.topics_discussed ?? '')
        .split('\n')
        .map((t) => t.trim())
        .filter(Boolean);
    const acknowledged = session.employee_acknowledged;

    const nav = [
        { key: 'agenda' as const, label: 'Talking points', count: topics.length, icon: MessagesSquare },
        { key: 'actions' as const, label: 'Action items', count: session.actions_agreed.length, icon: ListChecks },
        { key: 'notes' as const, label: 'Notes', count: session.employee_comments ? 1 : 0, icon: FileText },
    ];

    function scrollTo(key: 'agenda' | 'actions' | 'notes') {
        const ref =
            key === 'agenda' ? agendaRef : key === 'actions' ? actionsRef : notesRef;
        ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    return (
        <Dialog open={!!session} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 1020px)', width: 'min(94vw, 1020px)' }}
            >
                <DialogTitle className="sr-only">1:1 review with {who}</DialogTitle>
                <DialogDescription className="sr-only">
                    Review the talking points, actions and notes from this 1:1.
                </DialogDescription>

                <div className="flex h-[min(90vh,820px)] min-h-0 overflow-hidden">
                    {/* Rail */}
                    <aside className={WIZARD_RAIL_CLASS}>
                        <div className="mb-2 flex items-center gap-2.5">
                            <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground">
                                <MessagesSquare className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <div className="text-sm leading-tight font-bold">
                                    1:1 review
                                </div>
                                <div className="truncate text-[11px] text-muted-foreground">
                                    with {who}
                                </div>
                            </div>
                        </div>

                        <div className="mb-1.5 rounded-xl border border-border bg-card p-3">
                            <div className="text-[13px] font-bold">
                                {fmtDate(session.session_date)}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10.5px] font-semibold text-muted-foreground">
                                    <Repeat className="h-2.5 w-2.5" /> {cadence}
                                </span>
                                <StatusBadge
                                    variant={acknowledged ? 'success' : 'info'}
                                    size="sm"
                                >
                                    {acknowledged ? 'Reviewed' : 'Completed'}
                                </StatusBadge>
                            </div>
                        </div>

                        {nav.map((n) => {
                            const Icon = n.icon;
                            return (
                                <button
                                    key={n.key}
                                    type="button"
                                    onClick={() => scrollTo(n.key)}
                                    className="flex w-full items-center gap-2.5 rounded-md p-2 text-left transition-colors hover:bg-sidebar-accent"
                                >
                                    <span className="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                    <span className="flex-1 text-[13px] font-semibold">
                                        {n.label}
                                    </span>
                                    {n.count > 0 ? (
                                        <span className="text-[11px] font-bold text-muted-foreground">
                                            {n.count}
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })}

                        <p className="mt-auto pt-3 text-[11px] leading-relaxed text-muted-foreground">
                            Acknowledging lets your manager know you’ve reviewed this 1:1.
                        </p>
                    </aside>

                    {/* Main */}
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                        <header className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5">
                            <div className="min-w-0 text-[14.5px] font-bold">
                                1:1 with {who}{' '}
                                <span className="text-[12px] font-normal text-muted-foreground">
                                    · {fmtDate(session.session_date)}
                                </span>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Close"
                                className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </header>

                        <div
                            
                            className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-6 py-5"
                        >
                            {/* Talking points */}
                            <section ref={agendaRef}>
                                <div className="mb-2.5 flex items-center gap-2">
                                    <MessagesSquare className="h-3.5 w-3.5 text-primary" />
                                    <h3 className="text-[13.5px] font-bold">
                                        Talking points
                                    </h3>
                                </div>
                                {topics.length === 0 ? (
                                    <p className="text-[12.5px] text-muted-foreground">
                                        No talking points recorded.
                                    </p>
                                ) : (
                                    <div className="flex flex-col gap-1.5">
                                        {topics.map((t, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center gap-2.5 rounded-[11px] border border-border px-3 py-2.5"
                                            >
                                                <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                                                <span className="text-[13.5px]">{t}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            {/* Action items */}
                            <section ref={actionsRef}>
                                <div className="mb-2.5 flex items-center gap-2">
                                    <ListChecks className="h-3.5 w-3.5 text-status-success" />
                                    <h3 className="text-[13.5px] font-bold">
                                        Action items
                                    </h3>
                                </div>
                                {session.actions_agreed.length === 0 ? (
                                    <p className="text-[12.5px] text-muted-foreground">
                                        No actions from this 1:1. ✨
                                    </p>
                                ) : (
                                    <div className="flex flex-col gap-1.5">
                                        {session.actions_agreed.map((a, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center gap-2.5 rounded-[11px] border border-border px-3 py-2.5"
                                            >
                                                <span className="h-4 w-4 shrink-0 rounded-md border-2 border-border" />
                                                <span className="text-[13px]">{a}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            {/* Notes */}
                            <section ref={notesRef}>
                                <div className="mb-2 flex items-center gap-2">
                                    <FileText className="h-3.5 w-3.5 text-status-info" />
                                    <h3 className="text-[13.5px] font-bold">Your notes</h3>
                                    <span className="text-[10.5px] text-muted-foreground">
                                        shared with {who}
                                    </span>
                                </div>
                                {session.employee_comments ? (
                                    <div className="rounded-[10px] bg-muted px-3.5 py-3 text-[13px] leading-relaxed">
                                        {session.employee_comments}
                                    </div>
                                ) : (
                                    <p className="text-[12.5px] text-muted-foreground">
                                        {acknowledged
                                            ? 'No comment added.'
                                            : 'Add a comment below when you acknowledge this 1:1.'}
                                    </p>
                                )}
                            </section>
                        </div>

                        <footer className={WIZARD_FOOTER_CLASS}>
                            {acknowledged ? (
                                <div className="flex items-center gap-1.5 text-[12.5px] font-semibold text-status-success">
                                    <CheckCircle2 className="h-4 w-4" />
                                    Acknowledged{' '}
                                    {session.employee_acknowledged_at
                                        ? `· ${fmtDate(session.employee_acknowledged_at)}`
                                        : ''}
                                </div>
                            ) : (
                                <>
                                    <input
                                        value={comment}
                                        onChange={(e) => setComment(e.target.value)}
                                        placeholder={`Add a comment for ${who.split(' ')[0]}…`}
                                        className="flex-1 rounded-[9px] border border-border bg-card px-3 py-2.5 text-[12.5px] outline-none focus:border-primary"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => onAcknowledge(session.id, comment)}
                                        className="inline-flex shrink-0 items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        <CheckCircle2 className="h-4 w-4" />
                                        Acknowledge 1:1
                                    </button>
                                </>
                            )}
                        </footer>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default MyHrOneOnOneModal;
