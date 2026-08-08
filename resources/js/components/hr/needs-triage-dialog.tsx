/* eslint-disable no-restricted-syntax -- The triage modal is a bespoke
 * master-detail surface (a left rail of category buttons + a scrollable people
 * list with compact per-row actions + status pills) rather than shadcn Card
 * primitives. All colours are semantic design tokens. */
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    ExternalLink,
    MailWarning,
    Send,
    ShieldAlert,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export type TriageRail = 'compliance' | 'probation' | 'invites';

export type TriageRow = {
    id: string;
    profile_id: number | null;
    name: string;
    detail: string | null;
    status: string; // expired | expiring_soon | probation | pending
    date: string | null;
};

export type TriageData = {
    compliance: TriageRow[];
    probation: TriageRow[];
    invites: TriageRow[];
};

export type TriageSummary = {
    compliance_alerts: number;
    on_probation: number;
    pending_invites: number;
};

const RAILS: {
    key: TriageRail;
    label: string;
    icon: LucideIcon;
    blurb: string;
}[] = [
    {
        key: 'compliance',
        label: 'Compliance',
        icon: ShieldAlert,
        blurb: 'Expired or expiring staff requirements',
    },
    {
        key: 'probation',
        label: 'Probation',
        icon: CalendarClock,
        blurb: 'Employees still within their probation period',
    },
    {
        key: 'invites',
        label: 'Invites',
        icon: MailWarning,
        blurb: 'Active staff who have never signed in',
    },
];

const STATUS_PILL: Record<string, { label: string; cls: string }> = {
    expired: {
        label: 'Expired',
        cls: 'bg-status-critical-bg text-status-critical',
    },
    expiring_soon: {
        label: 'Expiring soon',
        cls: 'bg-status-warning-bg text-status-warning',
    },
    probation: {
        label: 'On probation',
        cls: 'bg-status-info-bg text-status-info',
    },
    pending: { label: 'Not signed in', cls: 'bg-muted text-muted-foreground' },
};

function formatNZ(date: string | null): string | null {
    if (!date) return null;
    const d = new Date(date + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return date;
    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(d);
}

/**
 * "Needs attention" triage — a drill-down modal opened from the hero chips.
 * Left rail switches between Compliance / Probation / Invites (live counts);
 * the body lists the actual people with per-row actions; the footer deep-links
 * to the owning surface. This is a cross-cutting action queue, not a tab.
 */
export function NeedsTriageDialog({
    open,
    onClose,
    initialRail = 'compliance',
    summary,
    triage,
    canManage,
}: {
    open: boolean;
    onClose: () => void;
    initialRail?: TriageRail;
    summary: TriageSummary;
    triage: TriageData;
    canManage: boolean;
}) {
    const [rail, setRail] = useState<TriageRail>(initialRail);
    const [inviting, setInviting] = useState<number | null>(null);

    // Re-focus the rail the chip asked for whenever the modal (re)opens.
    useEffect(() => {
        if (open) setRail(initialRail);
    }, [open, initialRail]);

    const counts: Record<TriageRail, number> = {
        compliance: summary.compliance_alerts,
        probation: summary.on_probation,
        invites: summary.pending_invites,
    };

    const rows = triage[rail] ?? [];
    const total = counts[rail];
    const active = RAILS.find((r) => r.key === rail)!;

    const viewProfile = (profileId: number | null) => {
        if (!profileId) return;
        router.visit(`/hr/people/${profileId}`);
    };

    const sendInvite = (profileId: number | null) => {
        if (!profileId) return;
        setInviting(profileId);
        router.post(
            `/hr/people/${profileId}/invite`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setInviting(null),
            },
        );
    };

    const footer = (() => {
        switch (rail) {
            case 'compliance':
                return {
                    label: 'Open Compliance centre',
                    onClick: () => router.visit('/hr/compliance'),
                };
            case 'probation':
                return {
                    label: 'View everyone on probation',
                    onClick: () => router.visit('/hr/people?probation=1'),
                };
            case 'invites':
                return {
                    label: 'View the People directory',
                    onClick: () => router.visit('/hr/people'),
                };
        }
    })();

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="border-b border-border p-5">
                    <DialogTitle className="flex items-center gap-2">
                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-status-warning-bg text-status-warning">
                            <ShieldAlert className="h-4 w-4" />
                        </span>
                        Needs attention
                    </DialogTitle>
                    <DialogDescription>
                        Your cross-cutting action queue — work through
                        compliance, probation and pending invites without
                        leaving the page.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex min-h-0 flex-col sm:flex-row">
                    {/* ── left rail ── */}
                    <nav className="flex shrink-0 gap-1.5 overflow-x-auto border-b border-border p-3 sm:w-52 sm:flex-col sm:overflow-visible sm:border-r sm:border-b-0">
                        {RAILS.map((r) => {
                            const Icon = r.icon;
                            const isActive = r.key === rail;
                            return (
                                <button
                                    key={r.key}
                                    type="button"
                                    onClick={() => setRail(r.key)}
                                    className={cn(
                                        'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition-colors',
                                        isActive
                                            ? 'bg-primary/10 font-semibold text-primary'
                                            : 'text-foreground hover:bg-muted',
                                    )}
                                >
                                    <Icon className="h-4 w-4 shrink-0" />
                                    <span className="flex-1">{r.label}</span>
                                    <span
                                        className={cn(
                                            'min-w-[1.5rem] rounded-full px-1.5 py-0.5 text-center text-xs font-bold tabular-nums',
                                            counts[r.key] > 0
                                                ? 'bg-status-warning-bg text-status-warning'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {counts[r.key]}
                                    </span>
                                </button>
                            );
                        })}
                    </nav>

                    {/* ── list ── */}
                    <div className="flex min-w-0 flex-1 flex-col">
                        <div className="border-b border-border px-5 py-3">
                            <p className="text-sm font-semibold">
                                {active.label}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {active.blurb}
                            </p>
                        </div>

                        <div className="max-h-[46vh] min-h-[12rem] flex-1 overflow-y-auto p-3">
                            {rows.length === 0 ? (
                                <div className="flex h-full flex-col items-center justify-center py-10 text-center">
                                    <CheckCircle2 className="mb-3 h-10 w-10 text-status-success" />
                                    <p className="text-sm font-medium">
                                        Nothing needs attention here
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        This queue is clear.
                                    </p>
                                </div>
                            ) : (
                                <ul className="space-y-1.5">
                                    {rows.map((row) => {
                                        const pill =
                                            STATUS_PILL[row.status] ??
                                            STATUS_PILL.pending;
                                        const date = formatNZ(row.date);
                                        return (
                                            <li
                                                key={row.id}
                                                className="flex items-center gap-3 rounded-lg border border-border bg-card p-2.5"
                                            >
                                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                                                    <UserRound className="h-4 w-4" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {row.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.detail || '—'}
                                                        {date ? (
                                                            <span className="ml-1.5">
                                                                · {date}
                                                            </span>
                                                        ) : null}
                                                    </p>
                                                </div>
                                                <span
                                                    className={cn(
                                                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                        pill.cls,
                                                    )}
                                                >
                                                    {pill.label}
                                                </span>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    {rail === 'invites' &&
                                                    canManage ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                !row.profile_id ||
                                                                inviting ===
                                                                    row.profile_id
                                                            }
                                                            onClick={() =>
                                                                sendInvite(
                                                                    row.profile_id,
                                                                )
                                                            }
                                                            className="h-8 gap-1.5"
                                                        >
                                                            <Send className="h-3.5 w-3.5" />
                                                            {inviting ===
                                                            row.profile_id
                                                                ? 'Sending…'
                                                                : 'Invite'}
                                                        </Button>
                                                    ) : null}
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={
                                                            !row.profile_id
                                                        }
                                                        onClick={() =>
                                                            viewProfile(
                                                                row.profile_id,
                                                            )
                                                        }
                                                        className="h-8"
                                                    >
                                                        View
                                                    </Button>
                                                </div>
                                            </li>
                                        );
                                    })}
                                    {total > rows.length ? (
                                        <li className="px-1 py-2 text-center text-xs text-muted-foreground">
                                            Showing the first {rows.length} of{' '}
                                            {total}. Use the link below to see
                                            them all.
                                        </li>
                                    ) : null}
                                </ul>
                            )}
                        </div>

                        <div className="flex items-center justify-between gap-2 border-t border-border px-5 py-3">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={footer.onClick}
                                className="gap-1.5"
                            >
                                <ExternalLink className="h-3.5 w-3.5" />
                                {footer.label}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onClose}
                            >
                                Done
                            </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default NeedsTriageDialog;
