import HandoverPersonNotes from '@/components/handover-person-notes';
/* Handover detail pop-up — full record with flow, lists, audit trail + actions. */
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    ArrowRight,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    FileText,
    ListChecks,
    Pill,
    Send,
    ShieldAlert,
    User,
    UserCheck,
    Users,
} from 'lucide-react';
import { type ComponentType, type ReactNode, useEffect, useState } from 'react';

import { formatDate } from '@/lib/datetime';
import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
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
} from './shared';
import { type ShiftMedSnapshot, ShiftMedSummary } from './shift-med-snapshot';

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
        <GuardrailCard
            unstyled
            className="rounded-xl border border-border bg-card"
        >
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
        </GuardrailCard>
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
    if (h.status === 'draft')
        return 'Saved draft. It has not been sent to the next worker.';
    if (h.status === 'acknowledged')
        return 'The incoming worker has acknowledged this handover.';
    return 'Sent to the incoming shift. The submitted record is kept unchanged.';
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
            className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
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
    medicationSnapshotUrl,
    emarLens = false,
}: {
    handover: Handover | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (h: Handover) => void;
    onSubmit: (h: Handover) => void;
    onAcknowledge: (h: Handover) => void;
    /** Endpoint for the live "Medications this shift" snapshot (GET ?shift_id=…).
     *  Both the eMAR and Operations handover views can surface it. */
    medicationSnapshotUrl?: string;
    /** True only in the eMAR handover surface — shows the "same record as
     *  Operations handovers" cross-reference banner (the copy is written from
     *  the eMAR side). Operations leaves it false. */
    emarLens?: boolean;
}) {
    const [section, setSection] = useState(0);
    useEffect(() => {
        setSection(0);
    }, [open, handover?.id]);
    const [snapshot, setSnapshot] = useState<ShiftMedSnapshot | null>(null);
    const [snapLoading, setSnapLoading] = useState(false);
    const shiftId = handover?.outgoing_shift?.id ?? null;

    useEffect(() => {
        if (!open || !medicationSnapshotUrl || !shiftId) {
            setSnapshot(null);
            return;
        }
        let cancelled = false;
        setSnapLoading(true);
        axios
            .get(medicationSnapshotUrl, { params: { shift_id: shiftId } })
            .then((res) => {
                if (!cancelled) setSnapshot(res.data?.snapshot ?? null);
            })
            .catch(() => {
                if (!cancelled) setSnapshot(null);
            })
            .finally(() => {
                if (!cancelled) setSnapLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, medicationSnapshotUrl, shiftId]);

    if (!handover) return null;
    const h = handover;
    const out = h.outgoing_staff;
    const submittedRecipient = h.submitted_incoming_staff ?? null;
    const currentAcknowledgementAssignee =
        h.current_incoming_staff ??
        (h.status === 'draft' ? h.incoming_staff : null);
    const inc = currentAcknowledgementAssignee;
    const immutableRecipientEvidence = Boolean(
        h.status !== 'draft' && submittedRecipient,
    );
    const note = lockNote(h);

    const needsIncoming = h.status === 'draft' && !h.incoming_shift;
    const editable = h.can_edit && !h.edit_lock;
    const sections = [
        {
            key: 'notes',
            label: 'Shift notes',
            blurb: 'People, site and follow-up',
            icon: FileText,
        },
        {
            key: 'next',
            label: 'Next worker',
            blurb: 'Who this handover goes to',
            icon: Users,
        },
        {
            key: 'history',
            label: 'History and links',
            blurb: 'Saved, sent and read',
            icon: Activity,
        },
    ];
    return (
        <WizardShell
            open={open}
            onClose={() => onOpenChange(false)}
            title="Shift handover"
            description="Read each person's notes, check who receives the handover, and review its history."
            railIcon={FileText}
            railTitle="Shift handover"
            railSub={h.site?.name ?? 'Your shift'}
            steps={sections}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={sections[section].label}
            pct={null}
            railExtra={
                <div className="space-y-3 text-sm">
                    <StatusPill status={h.status} />
                    <p className="text-muted-foreground">{note}</p>
                    {needsIncoming && (
                        <p className="text-status-warning">
                            Choose an incoming shift before sending.
                        </p>
                    )}
                </div>
            }
            footerStart={
                <GuardrailButton
                    variant="outline"
                    className="min-h-11"
                    onClick={() => onOpenChange(false)}
                >
                    Close
                </GuardrailButton>
            }
            footerEnd={
                <>
                    {editable && (
                        <GuardrailButton
                            variant={needsIncoming ? 'default' : 'outline'}
                            className="min-h-11"
                            onClick={() => onEdit(h)}
                        >
                            <FileText />
                            {needsIncoming
                                ? 'Choose incoming shift'
                                : 'Edit handover'}
                        </GuardrailButton>
                    )}
                    {h.status === 'draft' &&
                        h.can_submit &&
                        !needsIncoming &&
                        !h.edit_lock && (
                            <GuardrailButton
                                className="min-h-11"
                                onClick={() => onSubmit(h)}
                            >
                                <Send />
                                Send handover
                            </GuardrailButton>
                        )}
                    {h.status === 'submitted' && h.can_acknowledge && (
                        <GuardrailButton
                            className="min-h-11"
                            onClick={() => onAcknowledge(h)}
                        >
                            <Check />
                            I've read this handover
                        </GuardrailButton>
                    )}
                </>
            }
        >
            <WizardStepPane key={`${h.id}-${section}`}>
                <div className="mb-4 border-b border-border pb-4 text-sm text-muted-foreground">
                    {handoverDate(h).toLocaleDateString('en-NZ', {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                    })}
                    {h.outgoing_shift && (
                        <span> · {fmtShiftRange(h.outgoing_shift)}</span>
                    )}
                </div>
                {section === 0 && (
                    <div className="space-y-4">
                        {/* Narrative */}
                        <HandoverPersonNotes notes={h.worker_notes} />
                        {!h.worker_notes && (
                            <div>
                                <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-semibold text-muted-foreground">
                                    <FileText className="h-3.5 w-3.5" />
                                    Notes for {clientName(h.client)}
                                </div>
                                <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap">
                                    {h.handover_notes || 'No notes recorded.'}
                                </p>
                            </div>
                        )}

                        {medicationSnapshotUrl ? (
                            <ShiftMedSummary
                                snapshot={snapshot}
                                loading={snapLoading}
                                hasShift={!!h.outgoing_shift}
                            />
                        ) : null}

                        {h.cd_verification ? (
                            <div
                                className={cn(
                                    'rounded-xl border p-3.5',
                                    h.cd_verification.result === 'discrepancy'
                                        ? 'border-status-critical/30 bg-status-critical-bg/50'
                                        : 'border-status-success/30 bg-status-success-bg/50',
                                )}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span
                                        className={cn(
                                            'flex h-6 w-6 items-center justify-center rounded-md',
                                            h.cd_verification.result ===
                                                'discrepancy'
                                                ? 'bg-status-critical-bg text-status-critical'
                                                : 'bg-status-success-bg text-status-success',
                                        )}
                                    >
                                        {h.cd_verification.result ===
                                        'discrepancy' ? (
                                            <ShieldAlert className="h-3.5 w-3.5" />
                                        ) : (
                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                        )}
                                    </span>
                                    <span className="text-[13px] font-semibold">
                                        Controlled-drug count{' '}
                                        {h.cd_verification.result ===
                                        'discrepancy'
                                            ? '— discrepancy found'
                                            : 'verified'}
                                    </span>
                                    <Link
                                        href="/emar/controlled"
                                        className="ml-auto text-[12px] font-semibold text-primary hover:underline"
                                    >
                                        CD register
                                    </Link>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-muted-foreground">
                                    {h.cd_verification.witness_name ? (
                                        <span>
                                            Witness:{' '}
                                            <b className="text-foreground">
                                                {h.cd_verification.witness_name}
                                            </b>
                                        </span>
                                    ) : null}
                                    {h.cd_verification.verified_by_name ? (
                                        <span>
                                            Checked by{' '}
                                            {h.cd_verification.verified_by_name}
                                        </span>
                                    ) : null}
                                    {h.cd_verification.verified_at ? (
                                        <span>
                                            {formatDate(
                                                h.cd_verification.verified_at,
                                            )}{' '}
                                            {fmtTime(
                                                h.cd_verification.verified_at,
                                            )}
                                        </span>
                                    ) : null}
                                </div>
                                {h.cd_verification.notes ? (
                                    <p className="mt-1.5 text-[12.5px] leading-snug">
                                        {h.cd_verification.notes}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

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
                    </div>
                )}
                {section === 1 && (
                    <div className="space-y-4">
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

                        {immutableRecipientEvidence ? (
                            <div className="grid gap-2 rounded-xl border border-border bg-muted/20 px-4 py-3 text-[12px] sm:grid-cols-2">
                                <div>
                                    <div className="font-semibold text-muted-foreground">
                                        Submitted recipient
                                    </div>
                                    <div className="mt-0.5 font-medium">
                                        {submittedRecipient?.name ??
                                            'Not recorded'}
                                    </div>
                                </div>
                                <div>
                                    <div className="font-semibold text-muted-foreground">
                                        Current acknowledgement assignee
                                    </div>
                                    <div className="mt-0.5 font-medium">
                                        {currentAcknowledgementAssignee?.name ??
                                            'No worker currently assigned'}
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {emarLens ? (
                            <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-dashed border-border bg-muted/30 px-3 py-2 text-[11.5px] text-muted-foreground">
                                <ArrowRight className="h-3.5 w-3.5 shrink-0" />
                                Same shift-handover record as{' '}
                                <Link
                                    href="/operations/handovers"
                                    className="font-semibold text-primary hover:underline"
                                >
                                    Operations handovers
                                </Link>{' '}
                                — the eMAR view focuses on the medication slice;
                                concurrent edits are version-locked.
                            </div>
                        ) : null}

                        {h.edit_lock ? (
                            <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-status-warning/30 bg-status-warning-bg/60 px-3 py-2 text-[12px] font-medium text-status-warning">
                                <ShieldAlert className="h-3.5 w-3.5 shrink-0" />
                                Being edited by {h.edit_lock.held_by_name} —
                                editing is disabled here to avoid a conflict.
                            </div>
                        ) : null}
                    </div>
                )}
                {section === 2 && (
                    <div className="space-y-5">
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
                        <div className="flex flex-wrap items-center gap-1.5">
                            {h.client ? (
                                <OptionLink
                                    href={`/operations/clients/${h.client.id}`}
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
                                <OptionLink
                                    href={`/staff/${out.id}`}
                                    icon={UserCheck}
                                >
                                    {out.name.split(' ')[0]} · outgoing
                                </OptionLink>
                            ) : null}
                            {inc ? (
                                <OptionLink
                                    href={`/staff/${inc.id}`}
                                    icon={Users}
                                >
                                    {inc.name.split(' ')[0]} · incoming
                                </OptionLink>
                            ) : null}
                            {h.client && medicationSnapshotUrl ? (
                                <OptionLink
                                    href={`/emar/mar?client_id=${h.client.id}`}
                                    icon={Pill}
                                >
                                    Open on MAR chart
                                </OptionLink>
                            ) : null}
                        </div>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
