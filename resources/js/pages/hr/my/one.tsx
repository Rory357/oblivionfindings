/* eslint-disable no-restricted-syntax -- The "Acknowledge 1:1" pill is a bespoke
 * gradient/brand action sized to the card footer per the design handoff. */
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CheckCircle2,
    Eye,
    ListChecks,
    MessagesSquare,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { MyHrShell, type MyHrShellData } from '@/components/hr';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';

type Supervisor = { id: number; name: string } | null;

interface Session {
    id: number;
    session_date: string | null;
    session_type: string | null;
    duration_minutes: number | null;
    supervisor: Supervisor;
    topics_discussed: string | null;
    actions_agreed: string[];
    employee_comments: string | null;
    employee_acknowledged: boolean;
    employee_acknowledged_at: string | null;
    next_session_date: string | null;
}

interface OpenAction {
    note_id: number;
    label: string;
    from: string | null;
    session_date: string | null;
}

interface Props {
    myHr: MyHrShellData;
    sessions: Session[];
    openActions: OpenAction[];
    next: { date: string; who: string | null; days_until: number } | null;
}

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function MyOneOnOnes({ myHr, sessions, openActions, next }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const who = next?.who ?? sessions[0]?.supervisor?.name ?? 'your manager';

    function acknowledge(id: number) {
        router.post(
            `/hr/my/one/${id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('Acknowledged ✓', {
                        description:
                            'Your manager has been notified you’ve reviewed this 1:1.',
                    }),
                onError: () => toast.error('Could not acknowledge'),
            },
        );
    }

    function openCtx(e: React.MouseEvent, s: Session) {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: '1:1',
            tagBg: 'var(--status-info-bg)',
            tagColor: 'var(--status-info)',
            meta: `${fmtDate(s.session_date)} · ${s.supervisor?.name ?? ''}`,
            items: [
                {
                    icon: <Eye className="h-4 w-4" />,
                    label: 'View notes & actions',
                    onClick: () =>
                        document
                            .getElementById(`one-session-${s.id}`)
                            ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
                },
                ...(s.employee_acknowledged
                    ? []
                    : [
                          {
                              icon: <Check className="h-4 w-4" />,
                              label: 'Acknowledge',
                              onClick: () => acknowledge(s.id),
                          },
                      ]),
            ],
        });
    }

    return (
        <MyHrShell active="one" myHr={myHr} title="1:1s · My HR">
            <div className="flex flex-col gap-5">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <div>
                        <h2 className="text-[17px] font-bold">1:1s with {who}</h2>
                        <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Talking points, action items &amp; history
                        </p>
                    </div>
                </div>

                {/* Next 1:1 */}
                {next ? (
                    <div className="rounded-[18px] bg-gradient-to-br from-primary to-primary/70 p-5 text-primary-foreground shadow-[var(--shadow-float)]">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/20 px-2.5 py-1 text-[11.5px] font-bold">
                            <span className="h-1.5 w-1.5 rounded-full bg-primary-foreground" />
                            Next 1:1 · in {Math.max(0, next.days_until)} days
                        </span>
                        <div className="mt-3 flex items-center gap-3">
                            <CalendarClock className="h-6 w-6" />
                            <div>
                                <div className="text-lg font-bold">{fmtDate(next.date)}</div>
                                <div className="text-[12.5px] opacity-85">
                                    with {next.who ?? who}
                                </div>
                            </div>
                        </div>
                    </div>
                ) : null}

                {/* My open actions */}
                <Card className="p-4">
                    <div className="flex items-center gap-2">
                        <ListChecks className="h-4 w-4 text-primary" />
                        <h3 className="text-sm font-bold">My open actions</h3>
                        <span className="ml-auto text-[11px] text-muted-foreground">
                            carried across all 1:1s
                        </span>
                    </div>
                    <div className="mt-3 flex flex-col gap-2">
                        {openActions.length === 0 ? (
                            <p className="px-0.5 py-1 text-[13px] text-muted-foreground">
                                All actions complete — nice work. 💪
                            </p>
                        ) : (
                            openActions.map((a, i) => (
                                <div
                                    key={`${a.note_id}-${i}`}
                                    className="flex items-center gap-3 rounded-[11px] border border-border px-3 py-2.5"
                                >
                                    <span className="h-4 w-4 shrink-0 rounded-md border-2 border-border" />
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13px] font-semibold">
                                            {a.label}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            from 1:1 with {a.from ?? 'your manager'} ·{' '}
                                            {fmtDate(a.session_date)}
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </Card>

                {/* History */}
                <div>
                    <div className="mb-3 flex items-center gap-2">
                        <MessagesSquare className="h-4 w-4 text-muted-foreground" />
                        <h3 className="text-sm font-bold">History</h3>
                    </div>
                    {sessions.length === 0 ? (
                        <Card className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                            <MessagesSquare className="h-8 w-8 text-muted-foreground/40" />
                            <div className="text-sm font-semibold">No 1:1s yet</div>
                            <p className="max-w-sm text-[13px] text-muted-foreground">
                                When your manager records a supervision session and shares
                                it with you, it’ll appear here with its talking points and
                                actions.
                            </p>
                        </Card>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {sessions.map((s) => (
                                <Card
                                    key={s.id}
                                    id={`one-session-${s.id}`}
                                    onContextMenu={(e) => openCtx(e, s)}
                                    className="p-4"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-accent text-sm font-bold text-primary">
                                            {(s.supervisor?.name ?? '?')
                                                .split(' ')
                                                .map((n) => n[0])
                                                .join('')
                                                .slice(0, 2)
                                                .toUpperCase()}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-[13.5px] font-bold">
                                                {fmtDate(s.session_date)}
                                            </div>
                                            <div className="text-[11.5px] text-muted-foreground">
                                                with {s.supervisor?.name ?? 'your manager'}
                                                {s.actions_agreed.length > 0
                                                    ? ` · ${s.actions_agreed.length} action${s.actions_agreed.length === 1 ? '' : 's'}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <StatusBadge
                                            variant={
                                                s.employee_acknowledged
                                                    ? 'success'
                                                    : 'info'
                                            }
                                        >
                                            {s.employee_acknowledged
                                                ? 'Reviewed'
                                                : 'Completed'}
                                        </StatusBadge>
                                    </div>

                                    {s.topics_discussed ? (
                                        <p className="mt-3 text-[13px] leading-relaxed text-foreground">
                                            {s.topics_discussed}
                                        </p>
                                    ) : null}

                                    {s.actions_agreed.length > 0 ? (
                                        <ul className="mt-3 flex flex-col gap-1.5">
                                            {s.actions_agreed.map((a, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-center gap-2 text-[12.5px]"
                                                >
                                                    <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-status-success" />
                                                    {a}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}

                                    {!s.employee_acknowledged ? (
                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => acknowledge(s.id)}
                                                className="inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-primary-foreground transition-colors hover:bg-primary/90"
                                            >
                                                <Check className="h-3.5 w-3.5" />
                                                Acknowledge 1:1
                                            </button>
                                        </div>
                                    ) : null}
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
        </MyHrShell>
    );
}
