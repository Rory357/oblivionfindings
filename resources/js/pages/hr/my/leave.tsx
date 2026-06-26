/* eslint-disable no-restricted-syntax -- The "Request leave" header action and
 * row CTA are bespoke pills sized to the design handoff. */
import { router } from '@inertiajs/react';
import {
    Baby,
    CalendarDays,
    CalendarRange,
    Copy,
    Eye,
    Flower2,
    Palmtree,
    Plus,
    Thermometer,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    MyHrShell,
    hueFromId,
    type MyHrShellData,
} from '@/components/hr';
import { LeaveRequestDialog } from '@/components/hr/leave-request-dialog';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Card } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

interface LeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason: string | null;
    created_at: string;
}

interface LeaveBalance {
    leave_type: string;
    entitlement_hours: number;
    taken_hours: number;
    remaining_hours: number;
}

interface WhosOutDay {
    day: string;
    date: string;
    today: boolean;
    people: { user_id: number; name: string; initials: string }[];
}

interface Props {
    myHr: MyHrShellData;
    whosOutWeek: WhosOutDay[];
    requests: {
        data: LeaveRequest[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    balances: LeaveBalance[];
    leaveTypes: string[];
    publicHolidays?: Record<string, string>;
}

const TYPE_ICON: Record<string, typeof Palmtree> = {
    annual: Palmtree,
    sick: Thermometer,
    bereavement: Flower2,
    parental: Baby,
};

const STATUS_VARIANT: Record<string, StatusVariant> = {
    pending: 'warning',
    approved: 'success',
    declined: 'critical',
    cancelled: 'neutral',
};

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmtRange(start: string, end: string): string {
    const opts: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    };
    if (start === end)
        return new Date(start).toLocaleDateString('en-NZ', opts);
    const s = new Date(start);
    const e = new Date(end);
    const sameMonth =
        s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear();
    if (sameMonth) {
        return `${s.getDate()}–${e.toLocaleDateString('en-NZ', opts)}`;
    }
    return `${s.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })} – ${e.toLocaleDateString('en-NZ', opts)}`;
}

function avatarStyle(id: number) {
    return { backgroundColor: `oklch(0.62 0.17 ${hueFromId(id)})` };
}

function BalanceRing({ balance }: { balance: LeaveBalance }) {
    const total = balance.entitlement_hours || 0;
    const remaining = balance.remaining_hours ?? Math.max(0, total - balance.taken_hours);
    const pct = total > 0 ? remaining / total : 0;
    const c = 2 * Math.PI * 34;
    return (
        <Card className="flex items-center gap-4 p-[18px]">
            <div className="relative h-[84px] w-[84px] shrink-0">
                <svg viewBox="0 0 84 84" className="h-[84px] w-[84px] -rotate-90">
                    <circle
                        cx="42"
                        cy="42"
                        r="34"
                        fill="none"
                        stroke="var(--muted)"
                        strokeWidth="8"
                    />
                    <circle
                        cx="42"
                        cy="42"
                        r="34"
                        fill="none"
                        stroke="var(--primary)"
                        strokeWidth="8"
                        strokeLinecap="round"
                        strokeDasharray={c}
                        strokeDashoffset={c * (1 - pct)}
                        className="transition-[stroke-dashoffset] duration-700"
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-lg font-bold leading-none">
                        {remaining.toFixed(0)}h
                    </span>
                    <span className="text-[9px] text-muted-foreground">left</span>
                </div>
            </div>
            <div>
                <div className="text-[13.5px] font-bold">
                    {titleCase(balance.leave_type)}
                </div>
                <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                    {balance.taken_hours.toFixed(0)}h used · {total.toFixed(0)}h total
                </div>
                <div className="mt-1.5 text-[11px] font-semibold text-primary">
                    {Math.round(pct * 100)}% left
                </div>
            </div>
        </Card>
    );
}

export default function MyLeave({
    myHr,
    whosOutWeek,
    requests,
    balances,
    leaveTypes,
    publicHolidays,
}: Props) {
    const [wizardOpen, setWizardOpen] = useState(false);
    const [wizardInitial, setWizardInitial] = useState<{
        leave_type?: string;
        starts_at?: string;
        ends_at?: string;
    }>();
    const leaveTypeOptions = leaveTypes.map((t) => ({
        value: t,
        label: titleCase(t),
    }));
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    function openNew() {
        setWizardInitial(undefined);
        setWizardOpen(true);
    }

    function duplicate(r: LeaveRequest) {
        setWizardInitial({
            leave_type: r.leave_type,
            starts_at: r.start_date,
            ends_at: r.end_date,
        });
        setWizardOpen(true);
    }

    function cancel(r: LeaveRequest) {
        if (!confirm('Cancel this leave request?')) return;
        router.delete(`/hr/my/leave/${r.id}`, {
            preserveScroll: true,
            onSuccess: () =>
                toast.success('Leave request cancelled', {
                    description: `${titleCase(r.leave_type)} · ${fmtRange(r.start_date, r.end_date)}`,
                }),
            onError: () => toast.error('Could not cancel'),
        });
    }

    function openCtx(e: React.MouseEvent, r: LeaveRequest) {
        e.preventDefault();
        const canCancel = r.status === 'pending' || r.status === 'approved';
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: r.status,
            tagBg: 'var(--muted)',
            tagColor: 'var(--muted-foreground)',
            meta: `${titleCase(r.leave_type)} · ${fmtRange(r.start_date, r.end_date)}`,
            items: [
                {
                    icon: <Eye className="h-4 w-4" />,
                    label: 'View request',
                    onClick: () =>
                        toast.info(titleCase(r.leave_type), {
                            description: `${fmtRange(r.start_date, r.end_date)} · ${r.hours}h · ${titleCase(r.status)}`,
                        }),
                },
                {
                    icon: <Copy className="h-4 w-4" />,
                    label: 'Duplicate to new request',
                    onClick: () => duplicate(r),
                },
                ...(canCancel
                    ? [
                          { sep: true as const },
                          {
                              icon: <XCircle className="h-4 w-4" />,
                              label: 'Cancel request',
                              tone: 'critical' as const,
                              onClick: () => cancel(r),
                          },
                      ]
                    : []),
            ],
        });
    }

    return (
        <MyHrShell active="leave" myHr={myHr} title="Leave · My HR">
            <div className="flex flex-col gap-5">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <div>
                        <h2 className="text-[17px] font-bold">Leave &amp; time off</h2>
                        <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Balances, who’s out, and your requests
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openNew}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-primary-foreground transition-colors hover:bg-primary/90"
                    >
                        <Plus className="h-4 w-4" />
                        Request leave
                    </button>
                </div>

                {/* Balance gauges */}
                {balances.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {balances.map((b) => (
                            <BalanceRing key={b.leave_type} balance={b} />
                        ))}
                    </div>
                ) : null}

                {/* Who's out this week */}
                <Card className="p-[18px]">
                    <div className="mb-3.5 flex items-center gap-2">
                        <Users className="h-4 w-4 text-status-info" />
                        <h3 className="text-sm font-bold">Who’s out — this week</h3>
                    </div>
                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
                        {whosOutWeek.map((d) => (
                            <div
                                key={d.day}
                                className={cn(
                                    'min-h-[120px] rounded-xl border border-border p-3',
                                    d.today && 'bg-accent',
                                )}
                            >
                                <div className="text-[11px] font-bold text-muted-foreground">
                                    {d.day}
                                </div>
                                <div className="text-lg font-bold leading-tight">
                                    {d.date}
                                </div>
                                <div className="mt-2.5 flex flex-col gap-1.5">
                                    {d.people.map((p) => (
                                        <span
                                            key={p.user_id}
                                            title={p.name}
                                            className="grid h-7 w-7 place-items-center rounded-full border-2 border-card text-[10px] font-bold text-white"
                                            style={avatarStyle(p.user_id)}
                                        >
                                            {p.initials}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </Card>

                {/* My requests */}
                <Card className="overflow-hidden p-0">
                    <div className="px-[18px] pb-2 pt-4 text-sm font-bold">
                        My requests
                    </div>
                    {requests.data.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                            <CalendarDays className="h-8 w-8 text-muted-foreground/40" />
                            <div className="text-sm font-semibold">
                                No leave requests yet
                            </div>
                            <p className="max-w-sm text-[13px] text-muted-foreground">
                                Tap “Request leave” to book time off — it goes to your
                                manager for approval.
                            </p>
                        </div>
                    ) : (
                        <div className="px-2 pb-2">
                            {requests.data.map((r) => {
                                const Icon = TYPE_ICON[r.leave_type] ?? CalendarDays;
                                const days = r.hours / 8;
                                return (
                                    <div
                                        key={r.id}
                                        onContextMenu={(e) => openCtx(e, r)}
                                        className="flex items-center gap-3 rounded-[11px] px-2.5 py-3 transition-colors hover:bg-muted"
                                    >
                                        <span className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[9px] bg-accent text-primary">
                                            <Icon className="h-4 w-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-[13px] font-semibold">
                                                {titleCase(r.leave_type)}
                                            </div>
                                            <div className="text-[11.5px] text-muted-foreground">
                                                {fmtRange(r.start_date, r.end_date)} ·{' '}
                                                {Number.isInteger(days)
                                                    ? `${days} day${days === 1 ? '' : 's'}`
                                                    : `${r.hours}h`}
                                            </div>
                                        </div>
                                        <StatusBadge
                                            variant={
                                                STATUS_VARIANT[r.status] ?? 'neutral'
                                            }
                                        >
                                            {titleCase(r.status)}
                                        </StatusBadge>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>

                {requests.last_page > 1 ? (
                    <div className="flex justify-end">
                        <LaravelPagination links={requests.links} />
                    </div>
                ) : null}
            </div>

            <LeaveRequestDialog
                mode="self"
                open={wizardOpen}
                onClose={() => setWizardOpen(false)}
                staff={[]}
                leaveTypes={leaveTypeOptions}
                currentUser={{ name: myHr.profile.name }}
                holidays={publicHolidays}
                initial={wizardInitial}
                onSubmitted={() => setWizardOpen(false)}
            />
            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </MyHrShell>
    );
}
