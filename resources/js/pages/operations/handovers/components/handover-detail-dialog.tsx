/* Handover detail pop-up — full record with flow, lists, audit trail + actions. */
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Check,
    CheckCircle2,
    Clock,
    ClipboardCheck,
    FileText,
    Home,
    ListChecks,
    Pill,
    Send,
    ShieldAlert,
    User,
    UserCheck,
    Users,
} from 'lucide-react';
import { type ComponentType, type ReactNode } from 'react';

import { formatDate } from '@/lib/datetime';
import { cn } from '@/lib/utils';

import {
    type Handover,
    HueAvatar,
    MoodChip,
    StatusPill,
    clientName,
    fmtShiftRange,
    fmtTime,
    handoverDate,
    humanizeRole,
    initials,
} from './shared';

function DetailList({
    icon: Icon,
    tone,
    title,
    items,
}: {
    icon: ComponentType<{ className?: string }>;
    tone: 'critical' | 'warning' | 'primary';
    title: string;
    items: string[];
}) {
    if (!items || items.length === 0) return null;
    const tileClass =
        tone === 'critical'
            ? 'bg-status-critical-bg text-status-critical'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-accent text-primary';
    const dotClass =
        tone === 'critical'
            ? 'bg-status-critical'
            : tone === 'warning'
              ? 'bg-status-warning'
              : 'bg-primary';
    return (
        <div className="rounded-xl border border-border bg-card">
            <div className="flex items-center gap-2 border-b border-border px-3.5 py-2.5">
                <span
                    className={cn(
                        'flex h-6 w-6 items-center justify-center rounded-md',
                        tileClass,
                    )}
                >
                    <Icon className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-semibold">{title}</span>
                <span className="ml-auto rounded-full bg-muted px-1.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                    {items.length}
                </span>
            </div>
            <div className="space-y-1.5 px-3.5 py-3">
                {items.map((it, i) => (
                    <div key={i} className="flex items-start gap-2 text-[13px]">
                        <span
                            className={cn(
                                'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full',
                                dotClass,
                            )}
                        />
                        <span className="leading-snug">{it}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function TimelineRow({
    icon: Icon,
    tone,
    label,
    who,
    iso,
}: {
    icon: ComponentType<{ className?: string }>;
    tone: 'muted' | 'warning' | 'success';
    label: string;
    who: string;
    iso: string | null;
}) {
    const tileClass =
        tone === 'success'
            ? 'bg-status-success-bg text-status-success'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-muted text-muted-foreground';
    return (
        <div className="flex items-center gap-3 py-2">
            <span
                className={cn(
                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg',
                    tileClass,
                )}
            >
                <Icon className="h-3.5 w-3.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-[13px] font-semibold">{label}</div>
                <div className="truncate text-[11.5px] text-muted-foreground">
                    {who}
                </div>
            </div>
            <div className="text-right text-[11.5px] text-muted-foreground">
                {iso ? (
                    <>
                        <div>{formatDate(iso)}</div>
                        <div>{fmtTime(iso)}</div>
                    </>
                ) : (
                    '—'
                )}
            </div>
        </div>
    );
}

function lockNote(h: Handover) {
    const { reason, days_left, age_days } = h.lock;
    if (reason === 'window_closed')
        return {
            icon: ShieldAlert,
            critical: true,
            text: `Edit window closed${age_days != null ? ` (${age_days} days old)` : ''} — only managers can edit.`,
        };
    if (h.status === 'draft')
        return {
            icon: FileText,
            critical: false,
            text: 'Draft — visible only to the outgoing worker until submitted.',
        };
    if (reason === 'within_window' && days_left != null)
        return {
            icon: Clock,
            critical: false,
            text: `Staff can edit for ${days_left} more day${days_left === 1 ? '' : 's'}.`,
        };
    if (reason === 'manager')
        return {
            icon: UserCheck,
            critical: false,
            text: 'Manager access — editable anytime.',
        };
    return null;
}

/** Inline Inertia link for an entity name/label inside the detail body. Renders
 *  an <a> (so no raw-button lint) that client-side-navigates on click. */
function EntityLink({
    href,
    className,
    children,
}: {
    href: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'rounded transition-colors hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
        >
            {children}
        </Link>
    );
}

/** A jump action in the detail dialog's footer Options bar — mirrors the
 *  prn-detail-dialog ghost-button idiom. Navigates via Inertia. */
function OptionLink({
    href,
    icon: Icon,
    children,
}: {
    href: string;
    icon: ComponentType<{ className?: string }>;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent"
        >
            <Icon className="h-3.5 w-3.5" />
            {children}
        </Link>
    );
}

export function HandoverDetailDialog({
    handover,
    open,
    onOpenChange,
    onEdit,
    onSubmit,
    onAcknowledge,
}: {
    handover: Handover | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (h: Handover) => void;
    onSubmit: (h: Handover) => void;
    onAcknowledge: (h: Handover) => void;
}) {
    if (!handover) return null;
    const h = handover;
    const out = h.outgoing_staff;
    const inc = h.incoming_staff;
    const note = lockNote(h);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[720px]">
                {/* Header */}
                <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                    <HueAvatar name={clientName(h.client)} size={44} />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <DialogTitle className="text-base font-bold">
                                {clientName(h.client)}
                            </DialogTitle>
                            <StatusPill status={h.status} />
                            {h.lock.locked ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                                    <ShieldAlert className="h-3 w-3" />
                                    Locked · manager only
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-muted-foreground">
                            {h.site ? (
                                <span className="inline-flex items-center gap-1">
                                    <Home className="h-3 w-3" />
                                    {h.site.name}
                                </span>
                            ) : null}
                            {h.outgoing_shift ? (
                                <EntityLink
                                    href={`/operations/shifts/${h.outgoing_shift.id}`}
                                    className="inline-flex items-center gap-1"
                                >
                                    <Clock className="h-3 w-3" />
                                    {h.outgoing_shift.label} ·{' '}
                                    {fmtShiftRange(h.outgoing_shift)}
                                </EntityLink>
                            ) : null}
                            <span className="inline-flex items-center gap-1">
                                <Activity className="h-3 w-3" />
                                {handoverDate(h).toLocaleDateString('en-NZ', {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'long',
                                })}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Body */}
                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    {/* Flow */}
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3">
                        {out ? (
                            <div className="flex items-center gap-2">
                                <HueAvatar name={out.name} size={38} />
                                <div className="leading-tight">
                                    <EntityLink
                                        href={`/staff/${out.id}`}
                                        className="block text-[13px] font-bold"
                                    >
                                        {out.name}
                                    </EntityLink>
                                    <div className="text-[11px] text-muted-foreground">
                                        Outgoing
                                        {humanizeRole(out.role)
                                            ? ` · ${humanizeRole(out.role)}`
                                            : ''}
                                    </div>
                                </div>
                            </div>
                        ) : null}
                        <ArrowRight className="h-5 w-5 text-muted-foreground" />
                        {inc ? (
                            <div className="flex items-center gap-2">
                                <HueAvatar name={inc.name} size={38} />
                                <div className="leading-tight">
                                    <EntityLink
                                        href={`/staff/${inc.id}`}
                                        className="block text-[13px] font-bold"
                                    >
                                        {inc.name}
                                    </EntityLink>
                                    <div className="text-[11px] text-muted-foreground">
                                        Incoming
                                        {humanizeRole(inc.role)
                                            ? ` · ${humanizeRole(inc.role)}`
                                            : ''}
                                        {h.incoming_shift
                                            ? ` · ${h.incoming_shift.label} ${fmtShiftRange(h.incoming_shift)}`
                                            : ''}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-dashed border-status-warning/50 bg-status-warning-bg px-2.5 py-1 text-[11px] font-semibold text-status-warning">
                                <ShieldAlert className="h-3.5 w-3.5" />
                                Incoming shift open — needs cover
                            </span>
                        )}
                        <span className="ml-auto">
                            <MoodChip mood={h.client_mood} />
                        </span>
                    </div>

                    {/* Narrative */}
                    <div>
                        <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-semibold text-muted-foreground">
                            <FileText className="h-3.5 w-3.5" />
                            Handover narrative
                        </div>
                        <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap">
                            {h.handover_notes || 'No narrative recorded.'}
                        </p>
                    </div>

                    <DetailList
                        icon={Pill}
                        tone="critical"
                        title="Medications due"
                        items={h.medications_due}
                    />
                    <DetailList
                        icon={ShieldAlert}
                        tone="critical"
                        title="Incidents to note"
                        items={h.incidents_to_note}
                    />
                    <DetailList
                        icon={ListChecks}
                        tone="primary"
                        title="Follow-up items"
                        items={h.follow_up_items}
                    />
                    <DetailList
                        icon={ClipboardCheck}
                        tone="warning"
                        title="Tasks pending"
                        items={h.tasks_pending}
                    />

                    {/* Audit trail */}
                    <div>
                        <div className="mb-1 flex items-center gap-1.5 text-[12px] font-semibold text-muted-foreground">
                            <Activity className="h-3.5 w-3.5" />
                            Audit trail
                        </div>
                        <div className="divide-y divide-border">
                            <TimelineRow
                                icon={FileText}
                                tone="muted"
                                label="Created"
                                who={out?.name ?? 'Unknown'}
                                iso={h.created_at}
                            />
                            <TimelineRow
                                icon={Send}
                                tone="warning"
                                label="Submitted"
                                who={
                                    h.submitted_at
                                        ? (out?.name ?? 'Outgoing worker')
                                        : 'Not yet submitted'
                                }
                                iso={h.submitted_at}
                            />
                            <TimelineRow
                                icon={CheckCircle2}
                                tone="success"
                                label="Acknowledged"
                                who={
                                    h.acknowledger?.name ??
                                    'Awaiting acknowledgement'
                                }
                                iso={h.acknowledged_at}
                            />
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex flex-col gap-2.5 border-t border-border px-5 py-3.5">
                    {/* Options bar — cross-entity jumps (client / shift / staff / MAR) */}
                    <div className="flex flex-wrap items-center gap-1.5">
                        {h.client ? (
                            <OptionLink
                                href={`/operations/clients/${h.client.id}/care`}
                                icon={User}
                            >
                                View client
                            </OptionLink>
                        ) : null}
                        {h.outgoing_shift ? (
                            <OptionLink
                                href={`/operations/shifts/${h.outgoing_shift.id}`}
                                icon={Clock}
                            >
                                View shift
                            </OptionLink>
                        ) : null}
                        {out ? (
                            <OptionLink href={`/staff/${out.id}`} icon={UserCheck}>
                                {out.name.split(' ')[0]} · outgoing
                            </OptionLink>
                        ) : null}
                        {inc ? (
                            <OptionLink href={`/staff/${inc.id}`} icon={Users}>
                                {inc.name.split(' ')[0]} · incoming
                            </OptionLink>
                        ) : null}
                        {h.client ? (
                            <OptionLink
                                href={`/emar/mar?client_id=${h.client.id}`}
                                icon={Pill}
                            >
                                Open on MAR chart
                            </OptionLink>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                        {note ? (
                            <>
                                <note.icon
                                    className={cn(
                                        'h-3.5 w-3.5',
                                        note.critical && 'text-status-critical',
                                    )}
                                />
                                {note.text}
                            </>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => onOpenChange(false)}
                            className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                        >
                            Close
                        </button>
                        {h.status === 'draft' && h.can_submit ? (
                            <button
                                type="button"
                                onClick={() => onSubmit(h)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                            >
                                <Send className="h-3.5 w-3.5" />
                                Submit
                            </button>
                        ) : null}
                        {h.status === 'submitted' && h.can_acknowledge ? (
                            <button
                                type="button"
                                onClick={() => onAcknowledge(h)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                            >
                                <Check className="h-4 w-4" />
                                Acknowledge
                            </button>
                        ) : null}
                        {h.can_edit ? (
                            <button
                                type="button"
                                onClick={() => onEdit(h)}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                            >
                                <FileText className="h-3.5 w-3.5" />
                                Edit handover
                            </button>
                        ) : (
                            <button
                                type="button"
                                disabled
                                title="Only managers can edit after the 7-day window"
                                className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-muted px-3 py-2 text-xs font-semibold text-muted-foreground"
                            >
                                <ShieldAlert className="h-3.5 w-3.5" />
                                Edit locked
                            </button>
                        )}
                    </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
