/* eslint-disable no-restricted-syntax -- The header + history row actions are
 * bespoke pills/cards sized to the design handoff. */
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    ChevronRight,
    Eye,
    ListChecks,
    MessagesSquare,
    Repeat,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    MyHrOneOnOneModal,
    MyHrShell,
    type MyHrShellData,
    type OneSession,
} from '@/components/hr';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

interface Session extends OneSession {
    duration_minutes: number | null;
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

function inferCadence(sessions: Session[]): string {
    const dates = sessions
        .map((s) => s.session_date)
        .filter(Boolean)
        .map((d) => new Date(d as string).getTime())
        .sort((a, b) => b - a);
    if (dates.length < 2) return 'Fortnightly';
    const gap = Math.round((dates[0] - dates[1]) / 86_400_000);
    if (gap <= 9) return 'Weekly';
    if (gap <= 21) return 'Fortnightly';
    return 'Monthly';
}

export default function MyOneOnOnes({
    myHr,
    sessions,
    openActions,
    next,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [openId, setOpenId] = useState<number | null>(null);

    const cadence = inferCadence(sessions);
    const who = next?.who ?? sessions[0]?.supervisor?.name ?? 'your manager';
    const latest = sessions[0] ?? null;
    const selected = sessions.find((s) => s.id === openId) ?? null;

    function acknowledge(id: number, comment?: string) {
        router.post(
            `/hr/my/one/${id}/acknowledge`,
            { employee_comments: comment || undefined },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Acknowledged ✓', {
                        description:
                            'Your manager has been notified you’ve reviewed this 1:1.',
                    });
                    setOpenId(null);
                },
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
                    label: 'Open notes & actions',
                    onClick: () => setOpenId(s.id),
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
                        <h2 className="text-[17px] font-bold">
                            1:1s with {who}
                        </h2>
                        <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Talking points, action items &amp; history
                        </p>
                    </div>
                    {latest ? (
                        <button
                            type="button"
                            onClick={() => setOpenId(latest.id)}
                            className="ml-auto inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            <MessagesSquare className="h-4 w-4" />
                            Review latest 1:1
                        </button>
                    ) : null}
                </div>

                {/* Next 1:1 */}
                {next ? (
                    <div className="rounded-[18px] bg-gradient-to-br from-primary to-primary/70 p-5 text-primary-foreground shadow-[var(--shadow-float)]">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/20 px-2.5 py-1 text-[11.5px] font-bold">
                                <span className="h-1.5 w-1.5 rounded-full bg-primary-foreground" />
                                Next 1:1 · in {Math.max(0, next.days_until)}{' '}
                                days
                            </span>
                            <span className="ml-auto inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold">
                                <Repeat className="h-3 w-3" /> {cadence}
                            </span>
                        </div>
                        <div className="mt-3.5 flex items-center gap-3.5">
                            <CalendarClock className="h-7 w-7" />
                            <div className="flex-1">
                                <div className="text-lg font-bold">
                                    {fmtDate(next.date)}
                                </div>
                                <div className="text-[12.5px] opacity-85">
                                    with {next.who ?? who}
                                </div>
                            </div>
                            {latest ? (
                                <button
                                    type="button"
                                    onClick={() => setOpenId(latest.id)}
                                    className="rounded-[11px] bg-primary-foreground px-4 py-2.5 text-[13px] font-bold text-primary shadow-md"
                                >
                                    Review last 1:1
                                </button>
                            ) : null}
                        </div>
                        {openActions.length > 0 ? (
                            <div className="mt-3.5 rounded-[12px] bg-primary-foreground/12 px-3.5 py-2.5 text-[12.5px]">
                                <span className="font-bold">
                                    {openActions.length}
                                </span>{' '}
                                open action{openActions.length === 1 ? '' : 's'}{' '}
                                to wrap up before then.
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {/* Progress strip */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        icon={MessagesSquare}
                        tone="info"
                        value={sessions.length}
                        label="1:1s logged"
                        sub={`${cadence} · on track`}
                    />
                    <StatCard
                        icon={ListChecks}
                        tone="success"
                        value={openActions.length}
                        label="Open actions"
                        sub={
                            openActions.length === 0 ? 'all clear' : 'to close'
                        }
                    />
                    <StatCard
                        icon={Sparkles}
                        tone="primary"
                        value={latest ? fmtDate(latest.session_date) : '—'}
                        label="Last 1:1"
                        sub={latest?.supervisor?.name ?? '—'}
                    />
                </div>

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
                                            from 1:1 with{' '}
                                            {a.from ?? 'your manager'} ·{' '}
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
                            <div className="text-sm font-semibold">
                                No 1:1s yet
                            </div>
                            <p className="max-w-sm text-[13px] text-muted-foreground">
                                When your manager records a supervision session
                                and shares it with you, it’ll appear here with
                                its talking points and actions.
                            </p>
                        </Card>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {sessions.map((s, idx) => (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => setOpenId(s.id)}
                                    onContextMenu={(e) => openCtx(e, s)}
                                    className="flex items-center gap-3.5 rounded-[14px] border border-border bg-card p-4 text-left shadow-sm transition-shadow hover:shadow-[var(--shadow-float)]"
                                >
                                    <div className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-accent text-sm font-bold text-primary">
                                        {(s.supervisor?.name ?? '?')
                                            .split(' ')
                                            .map((n) => n[0])
                                            .join('')
                                            .slice(0, 2)
                                            .toUpperCase()}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-[13.5px] font-bold">
                                                {fmtDate(s.session_date)}
                                            </span>
                                            {idx === 0 ? (
                                                <span className="rounded-full bg-accent px-2 py-0.5 text-[10px] font-bold text-primary">
                                                    Latest
                                                </span>
                                            ) : null}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            with{' '}
                                            {s.supervisor?.name ??
                                                'your manager'}
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
                                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <MyHrOneOnOneModal
                session={selected}
                cadence={cadence}
                onClose={() => setOpenId(null)}
                onAcknowledge={acknowledge}
            />
            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
        </MyHrShell>
    );
}

function StatCard({
    icon: Icon,
    tone,
    value,
    label,
    sub,
}: {
    icon: typeof MessagesSquare;
    tone: 'info' | 'success' | 'primary';
    value: string | number;
    label: string;
    sub: string;
}) {
    const toneClass = {
        info: 'bg-status-info-bg text-status-info',
        success: 'bg-status-success-bg text-status-success',
        primary: 'bg-accent text-primary',
    }[tone];
    return (
        <Card className="flex items-center gap-3.5 p-4">
            <span
                className={cn(
                    'grid h-[42px] w-[42px] shrink-0 place-items-center rounded-[11px]',
                    toneClass,
                )}
            >
                <Icon className="h-[18px] w-[18px]" />
            </span>
            <div className="min-w-0">
                <div className="truncate text-lg leading-none font-bold">
                    {value}
                </div>
                <div className="text-xs font-semibold">{label}</div>
                <div className="truncate text-[11px] text-muted-foreground">
                    {sub}
                </div>
            </div>
        </Card>
    );
}
