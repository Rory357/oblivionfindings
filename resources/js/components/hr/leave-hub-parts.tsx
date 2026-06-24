/* eslint-disable no-restricted-syntax -- Leave hub list/card primitives are
 * bespoke on-card layout surfaces: per-leave-type chips carry their own
 * brand-neutral category colours (mirrors the approved Leave Hub design and the
 * existing recharts palette), avatars are deterministic colour discs, and the
 * approval/request rows use raw <button>/<div> for the dense actionable layout
 * that shadcn <Button>/<Card> can't express. Status / SLA chips still use the
 * semantic status-* tokens. */
import { cn } from '@/lib/utils';
import { AlertTriangle, MoreHorizontal, Paperclip } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Leave-type metadata (label + category colour) — from the design    */
/* ------------------------------------------------------------------ */

export const LEAVE_TYPE_META: Record<string, { label: string; color: string }> =
    {
        annual: { label: 'Annual', color: '#7c3aed' },
        sick: { label: 'Sick', color: '#b42318' },
        bereavement: { label: 'Bereavement', color: '#8b5cf6' },
        family_violence: { label: 'Family violence', color: '#b42318' },
        parental: { label: 'Parental', color: '#8a6310' },
        public_holiday: { label: 'Public holiday', color: '#137a52' },
        alternative: { label: 'Alt / lieu', color: '#2563eb' },
        toil: { label: 'TOIL', color: '#0e7490' },
        unpaid: { label: 'Unpaid', color: '#6b6878' },
        other: { label: 'Other', color: '#6b6878' },
    };

export function leaveTypeMeta(type: string) {
    return LEAVE_TYPE_META[type] ?? LEAVE_TYPE_META.other;
}

/* ------------------------------------------------------------------ */
/*  Avatar — deterministic colour disc with initials                  */
/* ------------------------------------------------------------------ */

const AVATAR_COLORS = [
    '#7c3aed',
    '#2563eb',
    '#0e7490',
    '#137a52',
    '#b45309',
    '#be123c',
    '#9333ea',
    '#0891b2',
    '#4f46e5',
    '#c026d3',
];

export function leaveInitials(name: string): string {
    return name
        .split(/[ -]/)
        .filter(Boolean)
        .slice(0, 2)
        .map((x) => x[0]!.toUpperCase())
        .join('');
}

export function avatarColor(name: string): string {
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
    }
    return AVATAR_COLORS[hash % AVATAR_COLORS.length]!;
}

export function LeaveAvatar({
    name,
    size = 42,
}: {
    name: string;
    size?: number;
}) {
    const style: CSSProperties = {
        background: avatarColor(name),
        height: size,
        width: size,
        fontSize: size <= 28 ? 10.5 : size <= 32 ? 11.5 : 14,
    };
    return (
        <span
            style={style}
            className="grid flex-none place-items-center rounded-full font-bold text-primary-foreground"
        >
            {leaveInitials(name)}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Chips — leave type, status, SLA                                   */
/* ------------------------------------------------------------------ */

export function LeaveTypeChip({ type }: { type: string }) {
    const meta = leaveTypeMeta(type);
    return (
        <span
            style={{
                background: `color-mix(in oklab, ${meta.color} 12%, var(--card))`,
                color: meta.color,
                borderColor: `color-mix(in oklab, ${meta.color} 28%, var(--card))`,
            }}
            className="inline-flex w-max items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-bold"
        >
            <span
                style={{ background: meta.color }}
                className="h-[7px] w-[7px] rounded-[2px]"
            />
            {meta.label}
        </span>
    );
}

const STATUS_META: Record<string, { label: string; className: string }> = {
    approved: {
        label: 'Approved',
        className:
            'border-status-success/30 bg-status-success-bg text-status-success',
    },
    declined: {
        label: 'Declined',
        className:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-border bg-muted text-muted-foreground',
    },
    pending: {
        label: 'Pending',
        className:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
};

export function LeaveStatusChip({ status }: { status: string }) {
    const meta = STATUS_META[status] ?? STATUS_META.pending;
    return (
        <span
            className={cn(
                'inline-flex w-max items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-bold',
                meta.className,
            )}
        >
            {meta.label}
        </span>
    );
}

export type LeaveSlaState = {
    is_overdue?: boolean;
    due_within_24h?: boolean;
    status?: string;
};

/** SLA pill for pending rows; falls back to the status chip once decided. */
export function LeaveSlaChip({ request }: { request: LeaveSlaState }) {
    if (request.status && request.status !== 'pending') {
        return <LeaveStatusChip status={request.status} />;
    }
    if (request.is_overdue) {
        return (
            <span className="inline-flex w-max items-center gap-1 rounded-full border border-status-critical/30 bg-status-critical-bg px-2 py-0.5 text-[10.5px] font-extrabold text-status-critical">
                Overdue
            </span>
        );
    }
    if (request.due_within_24h) {
        return (
            <span className="inline-flex w-max items-center gap-1 rounded-full border border-status-warning/30 bg-status-warning-bg px-2 py-0.5 text-[10.5px] font-extrabold text-status-warning">
                Due in 24h
            </span>
        );
    }
    return (
        <span className="inline-flex w-max items-center gap-1 rounded-full border border-status-success/30 bg-status-success-bg px-2 py-0.5 text-[10.5px] font-extrabold text-status-success">
            On track
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Info pill — small bordered fact (dates / hours / balance)         */
/* ------------------------------------------------------------------ */

export function InfoPill({
    children,
    tone,
}: {
    children: ReactNode;
    tone?: 'critical';
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold',
                tone === 'critical'
                    ? 'border-status-critical/40 bg-status-critical-bg text-status-critical'
                    : 'border-border text-foreground',
            )}
        >
            {children}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Approval card — the rich actionable row on the Approvals tab       */
/* ------------------------------------------------------------------ */

export type ApprovalCardRequest = {
    id: number;
    staff_name: string;
    leave_type: string;
    period?: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: string;
    reason?: string | null;
    has_doc?: boolean;
    hours_waiting?: number;
    is_overdue?: boolean;
    due_within_24h?: boolean;
    escalated?: boolean;
    escalated_from?: string | null;
    reviewed_by?: string | null;
    roster_conflict?: {
        has_conflict: boolean;
        shifts: Array<{
            site_name: string | null;
            date: string | null;
            am_pm: string;
        }>;
    };
    balance_impact?: {
        remaining_before: number;
        projected_after: number;
        insufficient: boolean;
    } | null;
};

function waitedLabel(hours?: number): string {
    if (!hours || hours <= 0) return 'just now';
    if (hours < 24) return `${Math.round(hours)}h ago`;
    const d = Math.floor(hours / 24);
    const h = Math.round(hours % 24);
    return h > 0 ? `${d}d ${h}h ago` : `${d}d ago`;
}

export function ApprovalCard({
    request: r,
    selectable,
    checked,
    onToggle,
    onApprove,
    onDecline,
    onMore,
    processing,
}: {
    request: ApprovalCardRequest;
    selectable: boolean;
    checked: boolean;
    onToggle: (checked: boolean) => void;
    onApprove: () => void;
    onDecline: () => void;
    onMore: (e: React.MouseEvent) => void;
    processing: boolean;
}) {
    const decided = r.status !== 'pending';
    const conflict = r.roster_conflict?.has_conflict
        ? r.roster_conflict.shifts[0]
        : null;
    const conflictText = conflict
        ? `${conflict.am_pm} ${conflict.site_name ?? ''} ${conflict.date ?? ''}`.trim()
        : '';

    return (
        <div
            onContextMenu={onMore}
            className={cn(
                'rounded-[15px] border bg-card p-4 shadow-[0_1px_2px_rgba(20,10,40,0.04)] transition-colors',
                r.is_overdue
                    ? 'border-status-critical/30'
                    : 'border-border hover:border-border/70',
            )}
        >
            <div className="flex items-start gap-3">
                {selectable && !decided ? (
                    <input
                        type="checkbox"
                        checked={checked}
                        onChange={(e) => onToggle(e.target.checked)}
                        aria-label={`Select leave request for ${r.staff_name}`}
                        className="mt-1 h-[17px] w-[17px] flex-none accent-[var(--primary)]"
                    />
                ) : null}
                <LeaveAvatar name={r.staff_name} />

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[14.5px] font-bold whitespace-nowrap">
                            {r.staff_name}
                        </span>
                        <LeaveTypeChip type={r.leave_type} />
                        {r.leave_type === 'family_violence' ||
                        r.leave_type === 'sick' ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10.5px] font-bold text-muted-foreground">
                                🔒 Private
                            </span>
                        ) : null}
                    </div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        submitted {waitedLabel(r.hours_waiting)}
                    </div>

                    <div className="mt-2.5 flex flex-wrap gap-2">
                        <InfoPill>
                            {r.start_date} – {r.end_date}
                        </InfoPill>
                        <InfoPill>{r.hours}h</InfoPill>
                        {r.balance_impact ? (
                            <InfoPill
                                tone={
                                    r.balance_impact.insufficient
                                        ? 'critical'
                                        : undefined
                                }
                            >
                                <span className="text-muted-foreground">
                                    Balance
                                </span>{' '}
                                {r.balance_impact.remaining_before}h →{' '}
                                {r.balance_impact.projected_after}h
                                {r.balance_impact.insufficient ? (
                                    <span className="font-extrabold">
                                        {' '}
                                        ⚠ short
                                    </span>
                                ) : null}
                            </InfoPill>
                        ) : null}
                    </div>

                    {conflict ? (
                        <div className="mt-2.5 inline-flex items-center gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-2.5 py-1.5 text-xs font-semibold text-status-warning">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            Roster conflict — {conflictText}
                        </div>
                    ) : null}

                    {r.reason ? (
                        <div className="mt-2.5 text-[12.5px] text-foreground/[0.78] italic">
                            “{r.reason}”
                            {r.has_doc ? (
                                <span className="ml-2 inline-flex items-center gap-1 rounded-md border border-border bg-accent px-2 py-0.5 text-[11px] font-bold text-accent-foreground not-italic">
                                    <Paperclip className="h-3 w-3" /> document
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                {/* right: SLA + actions */}
                <div className="flex flex-none flex-col items-end gap-2.5">
                    <LeaveSlaChip request={r} />
                    {!decided ? (
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                onClick={onApprove}
                                disabled={processing}
                                title="Approve"
                                className="inline-flex items-center gap-1.5 rounded-[9px] bg-status-success px-3 py-2 text-[12.5px] font-bold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                onClick={onDecline}
                                disabled={processing}
                                title="Decline"
                                className="inline-flex items-center gap-1.5 rounded-[9px] border border-status-critical/30 bg-card px-3 py-2 text-[12.5px] font-bold text-status-critical transition-colors hover:bg-status-critical-bg disabled:opacity-50"
                            >
                                Decline
                            </button>
                            <button
                                type="button"
                                onClick={onMore}
                                title="More actions"
                                className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-muted-foreground transition-colors hover:bg-muted"
                            >
                                <MoreHorizontal className="h-4 w-4" />
                            </button>
                        </div>
                    ) : null}
                    {r.escalated && r.escalated_from ? (
                        <span className="text-[11px] font-semibold text-muted-foreground">
                            Escalated from {r.escalated_from}
                        </span>
                    ) : null}
                    {decided && r.reviewed_by ? (
                        <span className="text-[11px] font-semibold text-muted-foreground">
                            {r.status === 'approved' ? 'Approved' : 'Declined'}{' '}
                            by {r.reviewed_by}
                        </span>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
