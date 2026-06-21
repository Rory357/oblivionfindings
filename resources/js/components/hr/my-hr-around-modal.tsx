/* eslint-disable no-restricted-syntax -- The "See all" modals reuse the
 * WizardShell (Add-Client) chrome but their list rows, type chips, legend dots
 * and Acknowledge/Congratulate pills are bespoke recognition surfaces sized to
 * the design handoff. Every colour is a semantic token / decorative identity
 * hue, as elsewhere in My HR. */
import { router } from '@inertiajs/react';
import { Megaphone, PartyPopper, Send, Users } from 'lucide-react';

import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { WizardShell } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';

import { hueFromId } from './my-hr-utils';

export type AroundView = 'celebrations' | 'whosOut' | 'announcements';

export type AroundCelebration = {
    id: string;
    user_id: number;
    name: string;
    initials: string;
    occasion: string;
    type: string;
    date: string;
};

export type AroundWhosOut = {
    user_id: number;
    name: string;
    initials: string;
    range: string;
    days_label: string;
    role: string;
    leave_type: string;
};

export type AroundAnnouncement = {
    id: number;
    title: string;
    priority: string;
    content: string;
    byline: string;
    acknowledged: boolean;
};

const VARIANT_TOKEN: Record<StatusVariant, string> = {
    success: '--status-success',
    warning: '--status-warning',
    critical: '--status-critical',
    info: '--status-info',
    neutral: '--muted-foreground',
};

const CELEB_TYPE: Record<string, { label: string; variant: StatusVariant }> = {
    birthday: { label: 'Birthday', variant: 'warning' },
    anniversary: { label: 'Work anniversary', variant: 'info' },
    new_starter: { label: 'New starter', variant: 'success' },
};

export function leaveBadge(type: string): { label: string; variant: StatusVariant } {
    const map: Record<string, { label: string; variant: StatusVariant }> = {
        annual: { label: 'Annual', variant: 'success' },
        sick: { label: 'Sick', variant: 'warning' },
        parental: { label: 'Parental', variant: 'info' },
        bereavement: { label: 'Bereavement', variant: 'critical' },
        public_holiday: { label: 'Public holiday', variant: 'neutral' },
        unpaid: { label: 'Unpaid', variant: 'neutral' },
    };
    return (
        map[type] ?? {
            label: type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
            variant: 'neutral',
        }
    );
}

export function announcementBadge(priority: string): {
    label: string;
    variant: StatusVariant;
} {
    const map: Record<string, { label: string; variant: StatusVariant }> = {
        urgent: { label: 'Urgent', variant: 'critical' },
        high: { label: 'High', variant: 'warning' },
        normal: { label: 'Notice', variant: 'info' },
        low: { label: 'FYI', variant: 'neutral' },
    };
    return map[priority] ?? { label: 'Notice', variant: 'info' };
}

function avatar(id: number, initials: string, size = 38) {
    return (
        <span
            className="grid shrink-0 place-items-center rounded-full font-bold text-white"
            style={{
                height: size,
                width: size,
                fontSize: size >= 36 ? 13 : 11,
                background: `oklch(0.62 0.17 ${hueFromId(id)})`,
            }}
        >
            {initials}
        </span>
    );
}

function legendRow(label: string, count: number, token: string) {
    return (
        <div key={label} className="flex items-center gap-2">
            <span
                className="h-2.5 w-2.5 shrink-0 rounded-full"
                style={{ background: `var(${token})` }}
            />
            <span className="text-[11.5px]">{label}</span>
            <span className="ml-auto text-[11px] font-bold text-muted-foreground">
                {count}
            </span>
        </div>
    );
}

const STEP_ICON = {
    celebrations: PartyPopper,
    whosOut: Users,
    announcements: Megaphone,
} as const;

export function MyHrAroundModal({
    view,
    onClose,
    celebrations,
    whosOut,
    announcements,
    congrats,
    acked,
    canViewFeed = false,
    onCongratulate,
    onAcknowledge,
    onSendKudos,
    onRequestLeave,
}: {
    view: AroundView | null;
    onClose: () => void;
    celebrations: AroundCelebration[];
    whosOut: AroundWhosOut[];
    announcements: AroundAnnouncement[];
    congrats: Set<number>;
    acked: Set<number>;
    /** Whether the viewer may open the full HR announcements feed. */
    canViewFeed?: boolean;
    onCongratulate: (c: AroundCelebration) => void;
    onAcknowledge: (id: number) => void;
    onSendKudos: () => void;
    onRequestLeave: () => void;
}) {
    const v: AroundView = view ?? 'celebrations';
    const Icon = STEP_ICON[v];

    const meta: Record<
        AroundView,
        { railTitle: string; railSub: string; blurb: string; header: string; legendTitle: string }
    > = {
        celebrations: {
            railTitle: 'Celebrations',
            railSub: 'This week · your team',
            blurb: 'Birthdays, work anniversaries and new starters across your sites this week.',
            header: 'Celebrations this week',
            legendTitle: 'By type',
        },
        whosOut: {
            railTitle: "Who's out",
            railSub: 'This week · your team',
            blurb: 'Everyone with approved leave that overlaps this week, across your sites.',
            header: 'Away this week',
            legendTitle: 'By type',
        },
        announcements: {
            railTitle: 'Announcements',
            railSub: 'From your team leads',
            blurb: 'Notices for your site and role. Acknowledge the ones that ask for it.',
            header: 'All announcements',
            legendTitle: 'By priority',
        },
    };

    const legend = (() => {
        if (v === 'celebrations') {
            const counts = new Map<string, number>();
            celebrations.forEach((c) =>
                counts.set(c.type, (counts.get(c.type) ?? 0) + 1),
            );
            return [...counts.entries()].map(([type, count]) => {
                const t = CELEB_TYPE[type] ?? { label: type, variant: 'neutral' as StatusVariant };
                return legendRow(t.label, count, VARIANT_TOKEN[t.variant]);
            });
        }
        if (v === 'whosOut') {
            const counts = new Map<string, { count: number; variant: StatusVariant }>();
            whosOut.forEach((w) => {
                const b = leaveBadge(w.leave_type);
                const e = counts.get(b.label) ?? { count: 0, variant: b.variant };
                counts.set(b.label, { count: e.count + 1, variant: b.variant });
            });
            return [...counts.entries()].map(([label, { count, variant }]) =>
                legendRow(label, count, VARIANT_TOKEN[variant]),
            );
        }
        const counts = new Map<string, { count: number; variant: StatusVariant }>();
        announcements.forEach((a) => {
            const b = announcementBadge(a.priority);
            const e = counts.get(b.label) ?? { count: 0, variant: b.variant };
            counts.set(b.label, { count: e.count + 1, variant: b.variant });
        });
        return [...counts.entries()].map(([label, { count, variant }]) =>
            legendRow(label, count, VARIANT_TOKEN[variant]),
        );
    })();

    const footerNote =
        v === 'celebrations'
            ? `${celebrations.length} ${celebrations.length === 1 ? 'person' : 'people'} to celebrate`
            : v === 'whosOut'
              ? `${whosOut.length} ${whosOut.length === 1 ? 'person' : 'people'} away this week`
              : `Showing ${announcements.length} notice${announcements.length === 1 ? '' : 's'}`;

    const primary: { label: string; onClick: () => void } | null =
        v === 'celebrations'
            ? { label: 'Send a shout-out', onClick: onSendKudos }
            : v === 'whosOut'
              ? { label: 'Request leave', onClick: onRequestLeave }
              : canViewFeed
                ? {
                      label: 'Open HR feed',
                      onClick: () => {
                          onClose();
                          router.visit('/hr/announcements');
                      },
                  }
                : null;

    return (
        <WizardShell
            open={view !== null}
            onClose={onClose}
            title={meta[v].railTitle}
            description={meta[v].blurb}
            railIcon={Icon}
            railTitle={meta[v].railTitle}
            railSub={meta[v].railSub}
            steps={[
                {
                    key: v,
                    label: meta[v].header,
                    blurb: meta[v].railSub,
                    icon: Icon,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            pct={null}
            maxWidth="min(94vw, 840px)"
            maxHeight="min(86vh, 620px)"
            railExtra={
                <div>
                    <p className="mb-2.5 text-[12px] leading-relaxed text-muted-foreground">
                        {meta[v].blurb}
                    </p>
                    <div className="mb-2 text-[10px] font-bold uppercase tracking-[0.06em] text-muted-foreground">
                        {meta[v].legendTitle}
                    </div>
                    <div className="flex flex-col gap-2">{legend}</div>
                </div>
            }
            footerStart={
                <span className="text-[12px] text-muted-foreground">{footerNote}</span>
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg px-3 py-2 text-[13px] font-semibold text-muted-foreground transition-colors hover:bg-muted"
                    >
                        Close
                    </button>
                    {primary ? (
                        <button
                            type="button"
                            onClick={primary.onClick}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-[13px] font-bold text-primary-foreground"
                        >
                            {v === 'celebrations' ? <Send className="h-3.5 w-3.5" /> : null}
                            {primary.label}
                        </button>
                    ) : null}
                </>
            }
        >
            {v === 'celebrations' ? (
                <div className="flex flex-col">
                    {celebrations.map((c) => {
                        const t = CELEB_TYPE[c.type] ?? {
                            label: c.type,
                            variant: 'neutral' as StatusVariant,
                        };
                        const done = congrats.has(c.user_id);
                        return (
                            <div
                                key={c.id}
                                className="flex items-center gap-3 border-b border-border py-3 last:border-0"
                            >
                                {avatar(c.user_id, c.initials)}
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">{c.name}</div>
                                    <div className="text-[11.5px] text-muted-foreground">
                                        {c.occasion}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div className="text-[12px] font-semibold">{c.date}</div>
                                    <StatusBadge variant={t.variant} size="sm" className="mt-1">
                                        {t.label}
                                    </StatusBadge>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onCongratulate(c)}
                                    disabled={done}
                                    className={cn(
                                        'shrink-0 rounded-lg border px-2.5 py-1.5 text-[11.5px] font-bold transition-colors',
                                        done
                                            ? 'border-status-success bg-status-success-bg text-status-success'
                                            : 'border-primary bg-card text-primary hover:bg-accent',
                                    )}
                                >
                                    {done ? 'Sent ✓' : 'Congratulate'}
                                </button>
                            </div>
                        );
                    })}
                </div>
            ) : null}

            {v === 'whosOut' ? (
                <div className="flex flex-col">
                    {whosOut.map((w) => {
                        const b = leaveBadge(w.leave_type);
                        return (
                            <div
                                key={`${w.user_id}-${w.range}`}
                                className="flex items-center gap-3 border-b border-border py-3 last:border-0"
                            >
                                {avatar(w.user_id, w.initials)}
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">{w.name}</div>
                                    <div className="text-[11.5px] text-muted-foreground">
                                        {w.role}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right min-w-[92px]">
                                    <div className="text-[12px] font-semibold">{w.range}</div>
                                    <div className="text-[11px] text-muted-foreground">
                                        {w.days_label}
                                    </div>
                                </div>
                                <StatusBadge variant={b.variant} size="sm">
                                    {b.label}
                                </StatusBadge>
                            </div>
                        );
                    })}
                </div>
            ) : null}

            {v === 'announcements' ? (
                <div className="flex flex-col gap-2.5">
                    {announcements.map((a) => {
                        const b = announcementBadge(a.priority);
                        const seen = acked.has(a.id) || a.acknowledged;
                        return (
                            <div
                                key={a.id}
                                className="flex items-start gap-3 rounded-xl border border-border p-3.5"
                            >
                                <span
                                    className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                                    style={{ background: `var(${VARIANT_TOKEN[b.variant]})` }}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-[13px] font-bold">{a.title}</span>
                                        <StatusBadge variant={b.variant} size="sm">
                                            {b.label}
                                        </StatusBadge>
                                    </div>
                                    <div className="mt-px text-[11.5px] text-muted-foreground">
                                        {a.byline}
                                    </div>
                                    {a.content ? (
                                        <p className="mt-1.5 text-[12.5px] leading-relaxed">
                                            {a.content}
                                        </p>
                                    ) : null}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onAcknowledge(a.id)}
                                    disabled={seen}
                                    className={cn(
                                        'shrink-0 rounded-lg border px-2.5 py-1.5 text-[11.5px] font-semibold transition-colors',
                                        seen
                                            ? 'border-status-success bg-status-success-bg text-status-success'
                                            : 'border-border bg-card hover:bg-muted',
                                    )}
                                >
                                    {seen ? 'Seen ✓' : 'Acknowledge'}
                                </button>
                            </div>
                        );
                    })}
                </div>
            ) : null}
        </WizardShell>
    );
}

export default MyHrAroundModal;
