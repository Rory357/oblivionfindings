/**
 * Overview pane — the pipeline command centre: a clickable intake funnel, a
 * merged "needs your attention" action list, bed occupancy per home, and a
 * this-week arrivals/departures summary. Everything deep-links into a tab.
 */
import { cn } from '@/lib/utils';
import {
    ArrowRight,
    Banknote,
    Bell,
    CalendarCheck,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Home,
    Inbox,
    ListRestart,
    LogIn,
    LogOut,
    ShieldAlert,
    Zap,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { fmtRange, Pill, relTime, type Tone } from '../shared';
import type { RespiteTab, RespiteWorkspaceData } from '../types';

const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-primary/10 text-primary',
};

const withinDays = (iso: string | null, lo: number, hi: number): boolean => {
    if (!iso) return false;
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return false;
    const days = (t - Date.now()) / 8.64e7;
    return days >= lo && days <= hi;
};

export function OverviewPane({
    data,
    goTab,
}: {
    data: RespiteWorkspaceData;
    goTab: (tab: RespiteTab) => void;
}) {
    const { referrals, requests, bookings, stays, homes, stats } = data;

    const stages: {
        icon: ComponentType<{ className?: string }>;
        label: string;
        tone: Tone;
        total: number;
        sub: string;
        tab: RespiteTab;
    }[] = [
        {
            icon: Inbox,
            label: 'Referrals',
            tone: 'info',
            total: referrals.filter((r) => r.status !== 'declined').length,
            sub: `${stats.newReferrals} new`,
            tab: 'referrals',
        },
        {
            icon: ClipboardCheck,
            label: 'Requests',
            tone: 'warning',
            total: requests.filter((r) => r.status !== 'rejected').length,
            sub: `${stats.awaitingReview} to review`,
            tab: 'requests',
        },
        {
            icon: CalendarCheck,
            label: 'Bookings',
            tone: 'neutral',
            total: bookings.filter(
                (b) => b.status !== 'completed' && b.status !== 'cancelled',
            ).length,
            sub: `${stats.confirmedUpcoming} confirmed`,
            tab: 'bookings',
        },
        {
            icon: Home,
            label: 'Stays',
            tone: 'success',
            total: stats.inHouse,
            sub: 'in house now',
            tab: 'stays',
        },
    ];

    const actions: {
        tone: Tone;
        icon: ComponentType<{ className?: string }>;
        title: string;
        sub: string;
        tab: RespiteTab;
    }[] = [];
    referrals
        .filter((r) => r.urgency === 'crisis' && r.status === 'received')
        .forEach((r) =>
            actions.push({
                tone: 'critical',
                icon: Zap,
                title: `Crisis referral — ${r.client}`,
                sub: r.reason ?? '',
                tab: 'referrals',
            }),
        );
    referrals
        .filter((r) => r.status === 'received' && r.urgency !== 'crisis')
        .forEach((r) =>
            actions.push({
                tone: 'info',
                icon: Inbox,
                title: `New referral — ${r.client}`,
                sub: `${r.referrer ?? 'Referrer'} · ${relTime(r.received)}`,
                tab: 'referrals',
            }),
        );
    referrals
        .filter((r) => r.status === 'accepted' && !r.hasRequest)
        .forEach((r) =>
            actions.push({
                tone: 'info',
                icon: ClipboardCheck,
                title: `Ready to book — ${r.client}`,
                sub: 'Accepted referral — create a booking request',
                tab: 'referrals',
            }),
        );
    requests
        .filter((r) => r.status === 'submitted' || r.status === 'under_review')
        .forEach((r) =>
            actions.push({
                tone: 'warning',
                icon: Clock,
                title: `Request awaiting sign-off — ${r.client}`,
                sub: `${r.nights ?? '?'} nights · ${fmtRange(r.start, r.end)}`,
                tab: 'requests',
            }),
        );
    if (stats.carerCrisisAttention > 0)
        actions.push({
            tone: 'critical',
            icon: Zap,
            title: 'Carer breakdown support needed',
            sub: `${stats.carerCrisisAttention} referral${stats.carerCrisisAttention === 1 ? '' : 's'} flagged`,
            tab: 'referrals',
        });
    if (stats.fundingAttention > 0)
        actions.push({
            tone: 'warning',
            icon: Banknote,
            title: 'Funding needs attention',
            sub: `${stats.fundingAttention} booking${stats.fundingAttention === 1 ? '' : 's'} pending or expiring`,
            tab: 'bookings',
        });
    if (stats.complianceAttention > 0)
        actions.push({
            tone: 'critical',
            icon: ShieldAlert,
            title: 'Compliance attention',
            sub: [
                stats.compliance.notifiablePastDeadline
                    ? `${stats.compliance.notifiablePastDeadline} overdue notifiable`
                    : null,
                stats.compliance.notifiableDueSoon
                    ? `${stats.compliance.notifiableDueSoon} near deadline`
                    : null,
                stats.compliance.restraintsAwaitingReview
                    ? `${stats.compliance.restraintsAwaitingReview} restraint review`
                    : null,
                stats.compliance.bspAwaitingLink
                    ? `${stats.compliance.bspAwaitingLink} BSP link`
                    : null,
                stats.compliance.missingConsentRights
                    ? `${stats.compliance.missingConsentRights} consent / rights`
                    : null,
                stats.compliance.openComplaints
                    ? `${stats.compliance.openComplaints} complaint`
                    : null,
            ]
                .filter(Boolean)
                .join(' · '),
            tab: 'stays',
        });
    if (stats.waitlisted > 0)
        actions.push({
            tone: 'info',
            icon: ListRestart,
            title: 'Waitlist promotion candidates',
            sub: `${stats.waitlisted} waitlisted request${stats.waitlisted === 1 ? '' : 's'}`,
            tab: 'requests',
        });
    if (stats.fullHomes > 0)
        actions.push({
            tone: 'warning',
            icon: Home,
            title: 'Respite home at capacity',
            sub: `${stats.fullHomes} home${stats.fullHomes === 1 ? '' : 's'} currently full`,
            tab: 'calendar',
        });
    const order: Record<Tone, number> = {
        critical: 0,
        warning: 1,
        info: 2,
        success: 3,
        neutral: 4,
    };
    actions.sort((a, b) => order[a.tone] - order[b.tone]);

    const arrivals = bookings.filter(
        (b) => withinDays(b.start, 0, 7) && b.status !== 'cancelled',
    ).length;
    const departures = stays.filter(
        (s) => s.live && withinDays(s.plannedEnd, 0, 7),
    ).length;
    const networkPct = stats.bedsTotal
        ? Math.round((stats.bedsOccupied / stats.bedsTotal) * 100)
        : 0;

    return (
        <div className="grid gap-[18px]">
            {/* funnel */}
            <div className="rounded-[14px] border border-border bg-card p-5">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-[15px] font-bold">Intake pipeline</h2>
                    <span className="text-xs text-muted-foreground">
                        Referral → Request → Booking → Stay
                    </span>
                </div>
                <div className="flex items-stretch">
                    {stages.map((s, i) => (
                        <div
                            key={s.label}
                            className="flex flex-1 items-stretch"
                        >
                            <button
                                type="button"
                                onClick={() => goTab(s.tab)}
                                className="flex-1 rounded-xl border border-border bg-card p-4 text-left transition-colors hover:border-primary/40 hover:bg-muted/40"
                            >
                                <span
                                    className={cn(
                                        'mb-2.5 inline-flex h-8 w-8 items-center justify-center rounded-[9px]',
                                        TONE_BG[s.tone],
                                    )}
                                >
                                    <s.icon className="h-4 w-4" />
                                </span>
                                <div className="text-[30px] leading-none font-bold tracking-tight tabular-nums">
                                    {s.total}
                                </div>
                                <div className="mt-1.5 text-[13px] font-semibold">
                                    {s.label}
                                </div>
                                <div className="text-[11.5px] text-muted-foreground">
                                    {s.sub}
                                </div>
                            </button>
                            {i < stages.length - 1 ? (
                                <div className="flex items-center px-1 text-muted-foreground/50">
                                    <ArrowRight className="h-5 w-5" />
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            </div>

            <div className="grid gap-[18px] lg:grid-cols-[1.5fr_1fr]">
                {/* needs attention */}
                <div className="rounded-[14px] border border-border bg-card p-5">
                    <div className="mb-3.5 flex items-center gap-2">
                        <Bell className="h-4 w-4 text-primary" />
                        <h2 className="text-[15px] font-bold">
                            Needs your attention
                        </h2>
                        <Pill tone="warning">{actions.length}</Pill>
                    </div>
                    <div className="grid gap-2">
                        {actions.slice(0, 8).map((a, i) => (
                            <div
                                key={i}
                                className="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                            >
                                <span
                                    className={cn(
                                        'grid h-8 w-8 shrink-0 place-items-center rounded-lg',
                                        TONE_BG[a.tone],
                                    )}
                                >
                                    <a.icon className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">
                                        {a.title}
                                    </div>
                                    <div className="truncate text-[11.5px] text-muted-foreground">
                                        {a.sub}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => goTab(a.tab)}
                                    className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold hover:bg-muted"
                                >
                                    Open <ArrowRight className="h-3 w-3" />
                                </button>
                            </div>
                        ))}
                        {actions.length === 0 ? (
                            <div className="px-2 py-10 text-center">
                                <CheckCircle2 className="mx-auto mb-2 h-9 w-9 text-muted-foreground/40" />
                                <p className="font-medium text-muted-foreground">
                                    All clear
                                </p>
                                <p className="text-sm text-muted-foreground/70">
                                    Nothing waiting on you right now.
                                </p>
                            </div>
                        ) : null}
                    </div>
                </div>

                {/* occupancy + this week */}
                <div className="grid content-start gap-[18px]">
                    <div className="rounded-[14px] border border-border bg-card p-5">
                        <h2 className="mb-3.5 text-[15px] font-bold">
                            Bed occupancy
                        </h2>
                        {homes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No respite-capable homes configured yet.
                            </p>
                        ) : (
                            homes.map((h) => {
                                const used = h.occupied;
                                const pct = h.capacity
                                    ? Math.round((used / h.capacity) * 100)
                                    : 0;
                                return (
                                    <div key={h.id} className="mb-3">
                                        <div className="mb-1.5 flex justify-between text-[12.5px]">
                                            <span className="font-semibold">
                                                {h.name}
                                            </span>
                                            {h.full ? (
                                                <Pill tone="warning">Full</Pill>
                                            ) : null}
                                            <span className="text-muted-foreground tabular-nums">
                                                {used}/{h.capacity || '—'} beds
                                            </span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full',
                                                    pct >= 80
                                                        ? 'bg-status-warning'
                                                        : 'bg-primary',
                                                )}
                                                style={{
                                                    width: `${Math.min(100, pct)}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                );
                            })
                        )}
                        <div className="mt-3.5 flex justify-between border-t border-border pt-3.5 text-[13px]">
                            <span className="text-muted-foreground">
                                Network occupancy
                            </span>
                            <span className="font-bold tabular-nums">
                                {networkPct}%
                            </span>
                        </div>
                    </div>

                    <div className="rounded-[14px] border border-border bg-card p-5">
                        <h2 className="mb-3 text-[15px] font-bold">
                            This week
                        </h2>
                        <MiniRow
                            icon={LogIn}
                            tone="success"
                            label="Arrivals"
                            value={`${arrivals} guest${arrivals === 1 ? '' : 's'}`}
                        />
                        <MiniRow
                            icon={LogOut}
                            tone="info"
                            label="Departures"
                            value={`${departures} guest${departures === 1 ? '' : 's'}`}
                        />
                        <MiniRow
                            icon={CalendarCheck}
                            tone="warning"
                            label="Confirmed ahead"
                            value={`${stats.confirmedUpcoming}`}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

function MiniRow({
    icon: Icon,
    tone,
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    tone: Tone;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-2.5 py-1.5">
            <span
                className={cn(
                    'grid h-6 w-6 place-items-center rounded-md',
                    TONE_BG[tone],
                )}
            >
                <Icon className="h-3 w-3" />
            </span>
            <span className="flex-1 text-[13px] text-muted-foreground">
                {label}
            </span>
            <span className="text-[13px] font-semibold">{value}</span>
        </div>
    );
}
