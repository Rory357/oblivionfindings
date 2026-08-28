/* eslint-disable no-restricted-syntax -- Step bodies intentionally use styled
 * native controls (selects, list-builder rows, mood/CD toggles). Every colour
 * is a semantic design token, per docs/DESIGN_TOKENS.md. */
/* New / Edit handover wizard — 4 steps built around the outgoing-shift →
 * new-shift chain, on the shared WizardShell chrome (the Add Client modal
 * contract): 248px stepper rail, "Step x of y" header, 3px progress strip and
 * muted footer band. Flow, validation and submit payloads are unchanged. */
import { startOfWeek } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeftRight,
    ArrowRight,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    FileText,
    Home,
    ListChecks,
    Loader2,
    MapPin,
    Pill,
    Plus,
    Send,
    ShieldAlert,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    FieldErr as FieldError,
    StepHead,
} from '@/components/wizard/primitives';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';

import {
    type Catalogue,
    type CatalogueShift,
    type Handover,
    MOODS,
    clientName,
    fmtTime,
    incomingHandoverShifts,
    moodEmoji,
    nextShiftIdAfter,
    outgoingHandoverShifts,
    shiftOptionLabel,
} from './shared';
import { type ShiftMedSnapshot, ShiftMedSummary } from './shift-med-snapshot';

const SELECT_CLASS =
    'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground transition-colors focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-60';
const INPUT_CLASS = SELECT_CLASS;
const BAD_CLASS = 'border-status-critical focus:ring-status-critical/30';

type WizForm = {
    client_id: string;
    outgoing: string;
    outgoing_shift: string;
    incoming: string;
    incoming_shift: string;
    leave_open: boolean;
    narrative: string;
    mood: string;
    medications: string[];
    incidents: string[];
    followups: string[];
    tasks: string[];
    // eMAR lens: controlled-drug count reconciliation at handover.
    cd_result: '' | 'verified' | 'discrepancy';
    cd_witness: string;
    cd_witness_credential: string;
    cd_notes: string;
};

type EditLockState =
    | 'not_needed'
    | 'acquiring'
    | 'acquired'
    | 'blocked'
    | 'failed';

const WIZ_STEPS = [
    {
        key: 'shift',
        label: 'Shift & people',
        blurb: 'Who is handing over to whom',
        icon: ArrowLeftRight,
    },
    {
        key: 'narrative',
        label: 'Narrative & mood',
        blurb: 'How the shift went',
        icon: FileText,
    },
    {
        key: 'lists',
        label: 'Meds, follow-ups & tasks',
        blurb: 'What the next shift must action',
        icon: ListChecks,
    },
    {
        key: 'review',
        label: 'Review & submit',
        blurb: 'Confirm and send',
        icon: CheckCircle2,
    },
] as const;

function emptyForm(): WizForm {
    return {
        client_id: '',
        outgoing: '',
        outgoing_shift: '',
        incoming: '',
        incoming_shift: '',
        leave_open: false,
        narrative: '',
        mood: '',
        medications: [],
        incidents: [],
        followups: [],
        tasks: [],
        cd_result: '',
        cd_witness: '',
        cd_witness_credential: '',
        cd_notes: '',
    };
}

function initFromEditing(
    h: Handover,
    includeControlledEvidence: boolean,
): WizForm {
    const cdVerification = includeControlledEvidence ? h.cd_verification : null;

    return {
        client_id: h.client ? String(h.client.id) : '',
        outgoing: h.outgoing_staff ? String(h.outgoing_staff.id) : '',
        outgoing_shift: h.outgoing_shift ? String(h.outgoing_shift.id) : '',
        incoming: h.incoming_staff ? String(h.incoming_staff.id) : '',
        incoming_shift: h.incoming_shift ? String(h.incoming_shift.id) : '',
        leave_open: !h.incoming_staff && !h.incoming_shift,
        narrative: h.handover_notes ?? '',
        mood: h.client_mood ?? '',
        medications: [...(h.medications_due ?? [])],
        incidents: [...(h.incidents_to_note ?? [])],
        followups: [...(h.follow_up_items ?? [])],
        tasks: [...(h.tasks_pending ?? [])],
        cd_result: cdVerification?.result ?? '',
        cd_witness: cdVerification?.witness_id
            ? String(cdVerification.witness_id)
            : '',
        cd_witness_credential: '',
        cd_notes: cdVerification?.notes ?? '',
    };
}

function cdEvidenceChanged(f: WizForm, editing: Handover | null): boolean {
    const existing = editing?.cd_verification;
    if (!existing) {
        return Boolean(
            f.cd_result ||
            f.cd_witness ||
            f.cd_witness_credential ||
            f.cd_notes.trim(),
        );
    }

    return (
        f.cd_result !== existing.result ||
        f.cd_witness !== String(existing.witness_id ?? '') ||
        f.cd_notes.trim() !== (existing.notes ?? '').trim()
    );
}

function readiness(f: WizForm): number {
    let filled = 0;
    const total = 5;
    if (f.client_id) filled++;
    if (f.outgoing_shift) filled++;
    if (f.incoming_shift || f.leave_open) filled++;
    if (f.narrative.trim().length > 10) filled++;
    if (f.mood) filled++;
    return Math.round((filled / total) * 100);
}

function ListBuilder({
    icon: Icon,
    tone,
    title,
    placeholder,
    items,
    onChange,
    readOnly = false,
}: {
    icon: typeof Pill;
    tone: 'critical' | 'warning' | 'primary';
    title: string;
    placeholder: string;
    items: string[];
    onChange: (items: string[]) => void;
    readOnly?: boolean;
}) {
    const [val, setVal] = useState('');
    const add = () => {
        const t = val.trim();
        if (!t) return;
        onChange([...items, t]);
        setVal('');
    };
    const tile =
        tone === 'critical'
            ? 'bg-status-critical-bg text-status-critical'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-accent text-primary';
    const dot =
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
                        tile,
                    )}
                >
                    <Icon className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-semibold">{title}</span>
                <span className="ml-auto rounded-full bg-muted px-1.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                    {items.length}
                </span>
            </div>
            <div className="space-y-2 px-3.5 py-3">
                {items.length > 0 ? (
                    <div className="space-y-1.5">
                        {items.map((it, i) => (
                            <div
                                key={i}
                                className="flex items-start gap-2 text-[13px]"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full',
                                        dot,
                                    )}
                                />
                                <span className="flex-1 leading-snug">
                                    {it}
                                </span>
                                {!readOnly ? (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onChange(
                                                items.filter((_, j) => j !== i),
                                            )
                                        }
                                        aria-label="Remove item"
                                        className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-[12.5px] text-muted-foreground">
                        None added yet.
                    </div>
                )}
                {!readOnly ? (
                    <div className="flex items-center gap-2">
                        <input
                            className={cn(INPUT_CLASS, 'h-9')}
                            placeholder={placeholder}
                            value={val}
                            onChange={(e) => setVal(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add();
                                }
                            }}
                        />
                        <button
                            type="button"
                            onClick={add}
                            className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border bg-background px-2.5 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                        >
                            <Plus className="h-3.5 w-3.5" />
                            Add
                        </button>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export function HandoverWizard({
    open,
    onOpenChange,
    editing,
    catalogue,
    currentUser,
    preselectClientId,
    onAddClient,
    onSubmitted,
    basePath = '/operations/handovers',
    medicationFocus = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editing: Handover | null;
    catalogue: Catalogue;
    currentUser: { id: number; name: string };
    preselectClientId: number | null;
    onAddClient: () => void;
    onSubmitted: (weekStart: Date) => void;
    // eMAR reuse: post to the medication-handover endpoints and bind the meds
    // step to the client's active medication orders. Defaults preserve the
    // Operations behaviour.
    basePath?: string;
    medicationFocus?: boolean;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [f, setF] = useState<WizForm>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    // eMAR lens: live medication picture for the selected outgoing shift's window.
    const [snapshot, setSnapshot] = useState<ShiftMedSnapshot | null>(null);
    const [snapLoading, setSnapLoading] = useState(false);
    const [editLockState, setEditLockState] =
        useState<EditLockState>('not_needed');
    const [editLockHolder, setEditLockHolder] = useState<string | null>(null);
    const credentialInputRef = useRef<HTMLInputElement | null>(null);

    const clearWitnessCredential = () => {
        if (credentialInputRef.current) credentialInputRef.current.value = '';
        setF((current) =>
            current.cd_witness_credential
                ? { ...current, cd_witness_credential: '' }
                : current,
        );
    };

    const closeWizard = () => {
        clearWitnessCredential();
        onOpenChange(false);
    };

    // Re-seed the form whenever the dialog (re)opens or the edited record changes.
    useEffect(() => {
        if (!open) {
            if (credentialInputRef.current)
                credentialInputRef.current.value = '';
            setF((current) =>
                current.cd_witness_credential
                    ? { ...current, cd_witness_credential: '' }
                    : current,
            );
            return;
        }
        setStepIndex(0);
        setErrors({});
        setF(
            editing
                ? initFromEditing(
                      editing,
                      medicationFocus && catalogue.capabilities.view_controlled,
                  )
                : emptyForm(),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        open,
        editing?.id,
        medicationFocus,
        catalogue.capabilities.view_controlled,
    ]);

    // A client added via the inline Add Client dialog selects itself here.
    useEffect(() => {
        if (preselectClientId == null) return;
        setF((p) => ({
            ...p,
            client_id: String(preselectClientId),
            outgoing_shift: '',
            incoming_shift: '',
            cd_result: '',
            cd_witness: '',
            cd_witness_credential: '',
            cd_notes: '',
        }));
    }, [preselectClientId]);

    // eMAR lens only: when the outgoing shift is chosen, fetch its live medication
    // snapshot and pre-fill the meds list once (only when empty — never clobbering
    // an edit). medicationFocus is false on Operations, so this stays inert there.
    useEffect(() => {
        if (!open || !medicationFocus || !f.outgoing_shift) {
            setSnapshot(null);
            return;
        }
        let cancelled = false;
        setSnapLoading(true);
        axios
            .get(`${basePath}/shift-medications`, {
                params: { shift_id: Number(f.outgoing_shift) },
            })
            .then((res) => {
                if (cancelled) return;
                const snap: ShiftMedSnapshot | null =
                    res.data?.snapshot ?? null;
                setSnapshot(snap);
                if (snap && snap.due.length > 0) {
                    setF((p) =>
                        p.medications.length === 0
                            ? {
                                  ...p,
                                  medications: snap.due.map(
                                      (d) =>
                                          `${d.name} — due ${d.time}${d.controlled ? ' (CD)' : ''}`,
                                  ),
                              }
                            : p,
                    );
                }
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
    }, [open, medicationFocus, f.outgoing_shift, basePath]);

    // eMAR lens: take a presence edit-lock while editing an existing draft. Only
    // release it if this wizard instance actually acquired it; a failed/blocked
    // acquisition must never unlock another worker's edit session.
    useEffect(() => {
        const id = editing?.id;
        if (
            !open ||
            !medicationFocus ||
            !id ||
            editing.status !== 'draft' ||
            !editing.can_edit
        ) {
            setEditLockState('not_needed');
            setEditLockHolder(null);
            return;
        }

        let disposed = false;
        let acquired = false;
        const release = () =>
            axios.post(`${basePath}/${id}/unlock`).catch(() => {});

        setEditLockState('acquiring');
        setEditLockHolder(null);
        axios
            .post(`${basePath}/${id}/lock`)
            .then((res) => {
                if (res.data?.locked === true) {
                    acquired = true;
                    if (disposed) release();
                    else setEditLockState('acquired');
                    return;
                }

                if (!disposed) {
                    const heldBy =
                        typeof res.data?.held_by === 'string'
                            ? res.data.held_by
                            : 'Another worker';
                    setEditLockHolder(heldBy);
                    setEditLockState('blocked');
                    toast.warning(
                        `${heldBy} is editing this handover. Close it and try again after they finish.`,
                    );
                }
            })
            .catch(() => {
                if (!disposed) {
                    setEditLockState('failed');
                    toast.error(
                        'Could not secure this handover for editing. Close it and try again.',
                    );
                }
            });

        return () => {
            disposed = true;
            if (acquired) release();
        };
    }, [
        open,
        medicationFocus,
        editing?.id,
        editing?.status,
        editing?.can_edit,
        basePath,
    ]);

    const cur = WIZ_STEPS[stepIndex];
    const pct = readiness(f);
    const set = <K extends keyof WizForm>(k: K, v: WizForm[K]) =>
        setF((p) => ({ ...p, [k]: v }));
    const canViewControlled =
        medicationFocus && catalogue.capabilities.view_controlled;
    const canGovernControlled =
        canViewControlled && catalogue.capabilities.record_controlled;
    const immutable = Boolean(
        editing && (editing.status !== 'draft' || !editing.can_edit),
    );
    const editLockUnavailable = Boolean(
        editing && medicationFocus && editLockState !== 'acquired',
    );
    const mutationDisabled = immutable || editLockUnavailable;

    const siteName = (siteId: number | null) =>
        catalogue.sites.find((s) => s.id === siteId)?.name ?? '';
    const client = f.client_id
        ? catalogue.clients.find((c) => String(c.id) === f.client_id)
        : null;
    const controlledWitnesses = client?.site_id
        ? (
              catalogue.controlledWitnessesBySite[String(client.site_id)] ?? []
          ).filter((staff) => staff.id !== currentUser.id)
        : [];

    const outgoingShifts = useMemo(
        () =>
            outgoingHandoverShifts(
                catalogue.shifts,
                f.client_id,
                currentUser.id,
                catalogue.capabilities.manage_any_shifts,
            ),
        [
            catalogue.shifts,
            catalogue.capabilities.manage_any_shifts,
            f.client_id,
            currentUser.id,
        ],
    );
    const incomingShifts = useMemo(
        () =>
            incomingHandoverShifts(
                catalogue.shifts,
                f.client_id,
                f.outgoing_shift,
            ),
        [catalogue.shifts, f.client_id, f.outgoing_shift],
    );
    const suggestNextId = incomingShifts[0] ? String(incomingShifts[0].id) : '';

    const oSh = f.outgoing_shift
        ? catalogue.shifts.find((s) => String(s.id) === f.outgoing_shift)
        : null;
    const nSh = f.incoming_shift
        ? catalogue.shifts.find((s) => String(s.id) === f.incoming_shift)
        : null;

    // Workers are derived from the roster: a handover's outgoing/incoming worker
    // IS the shift's assignee (an open outgoing shift is recorded against the
    // person logging it). These read-only displays mirror what the server saves.
    const staffName = (id: string) =>
        catalogue.staff.find((s) => String(s.id) === id)?.name ?? null;
    const outgoingShiftOpen = !!oSh && !oSh.user_id;
    const outgoingWorkerName = f.outgoing ? staffName(f.outgoing) : null;
    const incomingWorkerName = f.incoming ? staffName(f.incoming) : null;

    // Resolve who a shift hands to: its assignee, or the current user when the
    // outgoing shift is open (matching the server's `shift.user_id ?: actor`).
    const incomingWorkerFor = (shiftId: string) => {
        const shift = catalogue.shifts.find((s) => String(s.id) === shiftId);
        return shift?.user_id ? String(shift.user_id) : '';
    };

    const pickOutgoingShift = (v: string) => {
        const shift = catalogue.shifts.find((s) => String(s.id) === v);
        const nextId = nextShiftIdAfter(catalogue.shifts, f.client_id, v) || '';
        setF((p) => ({
            ...p,
            outgoing_shift: v,
            // Outgoing worker = shift assignee, else the current user (you).
            outgoing: shift?.user_id
                ? String(shift.user_id)
                : String(currentUser.id),
            incoming_shift: p.leave_open ? '' : nextId,
            incoming: p.leave_open ? '' : incomingWorkerFor(nextId),
            cd_result: '',
            cd_witness: '',
            cd_witness_credential: '',
            cd_notes: '',
        }));
    };

    const pickIncomingShift = (v: string) => {
        setF((p) => ({
            ...p,
            incoming_shift: v,
            incoming: incomingWorkerFor(v),
        }));
    };

    function validate(key: string): Record<string, string> {
        const e: Record<string, string> = {};
        if (key === 'shift') {
            if (!f.client_id)
                e.client_id = 'Choose the client this handover is about';
            if (!f.outgoing_shift)
                e.outgoing_shift = 'Select the outgoing shift';
            if (!f.incoming_shift && !f.leave_open)
                e.incoming_shift =
                    'Pick the new shift, or mark it open (needs cover)';
        }
        if (key === 'narrative') {
            if (f.narrative.trim().length < 10)
                e.narrative = 'Add a short narrative of how the shift went';
        }
        if (
            key === 'lists' &&
            canGovernControlled &&
            cdEvidenceChanged(f, editing)
        ) {
            if (!f.cd_result) {
                e.cd_result = editing?.cd_verification
                    ? 'Recorded controlled-drug evidence cannot be removed; record a replacement result'
                    : 'Choose the controlled-drug count result';
            }
            if (f.cd_result && !f.cd_witness)
                e.cd_witness_id = 'Select the witnessing worker';
            if (f.cd_result && !f.cd_witness_credential.trim())
                e.cd_witness_credential =
                    'The witness must enter their password or PIN';
            if (f.cd_result === 'discrepancy' && !f.cd_notes.trim())
                e.cd_notes = 'Describe the discrepancy before continuing';
        }
        return e;
    }

    const goNext = () => {
        const e = validate(cur.key);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, WIZ_STEPS.length - 1));
    };
    const goBack = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = (asDraft: boolean) => {
        if (mutationDisabled) {
            toast.error(
                immutable
                    ? 'This handover is read-only and cannot be changed.'
                    : 'This handover is not secured for editing. Close it and try again.',
            );
            return;
        }

        const all = {
            ...validate('shift'),
            ...validate('narrative'),
            ...validate('lists'),
        };
        if (!asDraft && f.leave_open) {
            all.incoming_shift =
                'Assign the bounded incoming shift before submitting. You can keep this as a draft while cover is arranged.';
        }
        if (Object.keys(all).length) {
            setErrors(all);
            setStepIndex(
                all.client_id || all.outgoing_shift || all.incoming_shift
                    ? 0
                    : all.narrative
                      ? 1
                      : 2,
            );
            return;
        }

        const payload = {
            handover_notes: f.narrative,
            client_mood: f.mood || null,
            incoming_shift_id:
                f.leave_open || !f.incoming_shift
                    ? null
                    : Number(f.incoming_shift),
            incoming_staff_id:
                f.leave_open || !f.incoming ? null : Number(f.incoming),
            ...(canGovernControlled
                ? { medications_due_text: f.medications.join('\n') }
                : {}),
            incidents_to_note_text: f.incidents.join('\n'),
            follow_up_items_text: f.followups.join('\n'),
            tasks_pending_text: f.tasks.join('\n'),
            // eMAR lens only: controlled-drug count reconciliation.
            ...(canGovernControlled && cdEvidenceChanged(f, editing)
                ? {
                      cd_result: f.cd_result || null,
                      cd_witness_id: f.cd_witness ? Number(f.cd_witness) : null,
                      cd_witness_credential: f.cd_witness_credential || null,
                      cd_notes: f.cd_notes || null,
                  }
                : {}),
            submit: !asDraft,
        };

        const targetShift = catalogue.shifts.find(
            (s) => String(s.id) === f.outgoing_shift,
        );
        const targetWeek = startOfWeek(
            targetShift?.starts_at
                ? new Date(targetShift.starts_at)
                : new Date(),
        );

        // Credentials are one-shot proof for this request. Clear the controlled
        // input/state before the network round-trip so retries require the witness
        // to authenticate again and no secret lingers in the open wizard.
        clearWitnessCredential();
        setSubmitting(true);
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                clearWitnessCredential();
                onOpenChange(false);
                toast.success(
                    asDraft
                        ? 'Handover saved as draft'
                        : `Handover submitted for ${client ? clientName(client) : 'client'}`,
                );
                onSubmitted(targetWeek);
            },
            onError: (serverErrors: Record<string, string>) => {
                setErrors(serverErrors);
                clearWitnessCredential();
                if (
                    serverErrors.cd_result ||
                    serverErrors.cd_witness_id ||
                    serverErrors.cd_witness_credential ||
                    serverErrors.cd_notes
                ) {
                    setStepIndex(2);
                }

                // Surface concurrency and governed witness failures directly;
                // the matching field message remains beside the affected input.
                toast.error(
                    serverErrors.handover ??
                        serverErrors.cd_witness_credential ??
                        serverErrors.cd_witness_id ??
                        serverErrors.cd_notes ??
                        'Could not save the handover. Please review and retry.',
                );
            },
            onFinish: () => {
                clearWitnessCredential();
                setSubmitting(false);
            },
        };

        if (editing) {
            router.put(
                `${basePath}/${editing.id}`,
                { ...payload, version: editing.version },
                opts,
            );
        } else {
            router.post(
                basePath,
                { shift_id: Number(f.outgoing_shift), ...payload },
                opts,
            );
        }
    };

    const footerStart =
        !mutationDisabled && stepIndex > 0 ? (
            <Button variant="ghost" onClick={goBack}>
                <ChevronLeft className="mr-1 h-4 w-4" />
                Back
            </Button>
        ) : null;

    const footerEnd = (
        <>
            <Button variant="outline" onClick={closeWizard}>
                {immutable ? 'Close' : 'Cancel'}
            </Button>
            {!mutationDisabled && cur.key === 'review' ? (
                <>
                    {!editing || editing.status === 'draft' ? (
                        <Button
                            variant="secondary"
                            onClick={() => submit(true)}
                            disabled={submitting || mutationDisabled}
                        >
                            Save as draft
                        </Button>
                    ) : null}
                    <Button
                        onClick={() => submit(false)}
                        disabled={submitting || mutationDisabled}
                    >
                        {submitting ? (
                            <>
                                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                Submitting…
                            </>
                        ) : (
                            <>
                                <Send className="mr-1.5 h-4 w-4" />
                                Submit handover
                            </>
                        )}
                    </Button>
                </>
            ) : !mutationDisabled ? (
                <Button onClick={goNext}>
                    Continue
                    <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            ) : null}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={closeWizard}
            title={editing ? 'Edit handover' : 'New handover'}
            description="A guided wizard to record a shift-to-shift handover."
            railIcon={editing ? FileText : ArrowLeftRight}
            railTitle={editing ? 'Edit handover' : 'New handover'}
            railSub={
                editing
                    ? `${clientName(editing.client)} · update`
                    : 'Shift → shift'
            }
            steps={WIZ_STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Handover readiness"
            maxWidth="min(96vw, 1000px)"
            maxHeight="min(92vh, 820px)"
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {errors.handover ? (
                <div
                    role="alert"
                    className="mb-4 rounded-xl border border-status-critical/30 bg-status-critical-bg px-3.5 py-3 text-[13px] text-status-critical"
                >
                    <div className="flex items-start gap-2">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <div className="font-semibold">
                                This handover was not saved
                            </div>
                            <div className="mt-0.5">{errors.handover}</div>
                            <div className="mt-1 text-[12px]">
                                Close this window and reopen the handover to
                                load the current version before applying your
                                changes.
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
            {immutable ? (
                <div className="mb-4 rounded-xl border border-border bg-muted/50 px-3.5 py-3 text-[13px] text-muted-foreground">
                    This handover is read-only. Submitted and acknowledged
                    records cannot be edited.
                </div>
            ) : editLockState === 'acquiring' ? (
                <div className="mb-4 rounded-xl border border-border bg-muted/50 px-3.5 py-3 text-[13px] text-muted-foreground">
                    Securing this draft for editing…
                </div>
            ) : editLockState === 'blocked' ? (
                <div
                    role="alert"
                    className="mb-4 rounded-xl border border-status-warning/30 bg-status-warning-bg px-3.5 py-3 text-[13px] text-status-warning"
                >
                    {editLockHolder ?? 'Another worker'} is editing this
                    handover. It is read-only here until they finish.
                </div>
            ) : editLockState === 'failed' ? (
                <div
                    role="alert"
                    className="mb-4 rounded-xl border border-status-critical/30 bg-status-critical-bg px-3.5 py-3 text-[13px] text-status-critical"
                >
                    The edit lock could not be confirmed. Close this handover
                    and try again before making changes.
                </div>
            ) : null}
            {mutationDisabled ? (
                <WizardStepPane>
                    <ReviewBody
                        f={f}
                        catalogue={catalogue}
                        goTo={setStepIndex}
                        canViewControlled={canViewControlled}
                        canGovernControlled={canGovernControlled}
                        editing={editing}
                        editable={false}
                    />
                </WizardStepPane>
            ) : null}
            {!mutationDisabled && cur.key === 'shift' ? (
                <WizardStepPane>
                    <div className="space-y-4">
                        <StepHead
                            icon={ArrowLeftRight}
                            title="Set up the shift handover"
                            blurb="Pick the client, the outgoing shift being handed over, then the new shift that takes over."
                        />
                        <div className="space-y-1.5">
                            <label className="text-[13px] font-semibold">
                                Client
                                <span className="text-status-critical"> *</span>
                            </label>
                            <select
                                className={cn(
                                    SELECT_CLASS,
                                    errors.client_id && BAD_CLASS,
                                )}
                                value={f.client_id}
                                disabled={!!editing}
                                onChange={(e) =>
                                    setF((p) => ({
                                        ...p,
                                        client_id: e.target.value,
                                        outgoing_shift: '',
                                        incoming_shift: '',
                                        cd_result: '',
                                        cd_witness: '',
                                        cd_witness_credential: '',
                                        cd_notes: '',
                                    }))
                                }
                            >
                                <option value="">Select a client…</option>
                                {catalogue.clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {clientName(c)}
                                        {siteName(c.site_id)
                                            ? ` · ${siteName(c.site_id)}`
                                            : ''}
                                    </option>
                                ))}
                            </select>
                            {errors.client_id ? (
                                <FieldError>{errors.client_id}</FieldError>
                            ) : null}
                        </div>

                        {!editing ? (
                            <div className="flex items-start gap-2.5 rounded-xl border border-primary/20 bg-accent/60 px-3.5 py-3 text-[12.5px]">
                                <UserPlus className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                <div>
                                    Can't find the person?{' '}
                                    <button
                                        type="button"
                                        onClick={onAddClient}
                                        className="font-semibold text-primary underline underline-offset-2"
                                    >
                                        Add a client
                                    </button>{' '}
                                    — they'll be created and selected here
                                    without leaving this handover.
                                </div>
                            </div>
                        ) : null}

                        {client ? (
                            <div className="flex flex-wrap gap-2">
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                    <Home className="h-3 w-3" />
                                    {siteName(client.site_id) || 'No house'}
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                    <MapPin className="h-3 w-3" />
                                    Supported living
                                </span>
                            </div>
                        ) : null}

                        <SubHead
                            n={1}
                            text="Outgoing shift — being handed over"
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    Outgoing shift
                                    <span className="text-status-critical">
                                        {' '}
                                        *
                                    </span>
                                </label>
                                <ShiftSelect
                                    shifts={outgoingShifts}
                                    value={f.outgoing_shift}
                                    onChange={pickOutgoingShift}
                                    disabled={!f.client_id || !!editing}
                                    bad={!!errors.outgoing_shift}
                                    placeholder={
                                        f.client_id
                                            ? 'Select the shift ending…'
                                            : 'Choose a client first'
                                    }
                                />
                                {errors.outgoing_shift ? (
                                    <FieldError>
                                        {errors.outgoing_shift}
                                    </FieldError>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    Outgoing support worker
                                </label>
                                <DerivedWorker muted={!outgoingWorkerName}>
                                    {!f.outgoing_shift
                                        ? 'Select the outgoing shift first'
                                        : outgoingWorkerName
                                          ? `${outgoingWorkerName}${outgoingShiftOpen ? ' · you (open shift)' : ''}`
                                          : '—'}
                                </DerivedWorker>
                            </div>
                        </div>

                        {oSh ? (
                            <ShiftChain
                                oSh={oSh}
                                nSh={nSh ?? null}
                                leaveOpen={f.leave_open}
                            />
                        ) : null}

                        <SubHead n={2} text="New shift — taking over" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    New (incoming) shift
                                </label>
                                <ShiftSelect
                                    shifts={incomingShifts}
                                    value={f.incoming_shift}
                                    onChange={pickIncomingShift}
                                    disabled={f.leave_open || !f.outgoing_shift}
                                    bad={!!errors.incoming_shift}
                                    suggestId={suggestNextId}
                                    placeholder={
                                        f.leave_open
                                            ? 'Open — no incoming shift'
                                            : !f.outgoing_shift
                                              ? 'Pick the outgoing shift first'
                                              : 'Next shift (auto-suggested)'
                                    }
                                />
                                <label className="mt-1 flex cursor-pointer items-center gap-2 text-[12.5px] font-medium">
                                    <input
                                        type="checkbox"
                                        checked={f.leave_open}
                                        onChange={(e) => {
                                            const open = e.target.checked;
                                            const nextId = open
                                                ? ''
                                                : nextShiftIdAfter(
                                                      catalogue.shifts,
                                                      f.client_id,
                                                      f.outgoing_shift,
                                                  ) || '';
                                            setF((p) => ({
                                                ...p,
                                                leave_open: open,
                                                incoming_shift: nextId,
                                                incoming: open
                                                    ? ''
                                                    : incomingWorkerFor(nextId),
                                            }));
                                        }}
                                        className="h-4 w-4 accent-primary"
                                    />
                                    Leave the new shift open (needs cover)
                                </label>
                                {errors.incoming_shift ? (
                                    <FieldError>
                                        {errors.incoming_shift}
                                    </FieldError>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-[13px] font-semibold">
                                    Incoming support worker
                                </label>
                                <DerivedWorker
                                    muted={!incomingWorkerName || f.leave_open}
                                >
                                    {f.leave_open
                                        ? 'Open — needs cover'
                                        : !f.incoming_shift
                                          ? 'Pick the new shift first'
                                          : incomingWorkerName
                                            ? incomingWorkerName
                                            : 'Unassigned — set on the roster'}
                                </DerivedWorker>
                            </div>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {!mutationDisabled && cur.key === 'narrative' ? (
                <WizardStepPane>
                    <div className="space-y-4">
                        <StepHead
                            icon={FileText}
                            title="How did the shift go?"
                            blurb="A clear narrative the incoming worker can read in under a minute."
                        />
                        <div className="space-y-1.5">
                            <label className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                Handover narrative
                                <span className="text-status-critical">*</span>
                                <span className="text-[11.5px] font-normal text-muted-foreground">
                                    mood, sleep, meals, activities, anything to
                                    watch
                                </span>
                            </label>
                            <textarea
                                className={cn(
                                    'min-h-[180px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed focus:border-ring focus:ring-2 focus:ring-ring/30 focus:outline-none',
                                    errors.narrative && BAD_CLASS,
                                )}
                                placeholder="e.g. Settled day overall. Good appetite, joined the afternoon activity…"
                                value={f.narrative}
                                onChange={(e) =>
                                    set('narrative', e.target.value)
                                }
                            />
                            <div className="flex items-center justify-between">
                                {errors.narrative ? (
                                    <FieldError>{errors.narrative}</FieldError>
                                ) : (
                                    <span className="text-[12px] text-muted-foreground">
                                        Be specific and factual — this is a
                                        clinical record.
                                    </span>
                                )}
                                <span className="text-[12px] text-muted-foreground tabular-nums">
                                    {f.narrative.length} chars
                                </span>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-[13px] font-semibold">
                                Client mood at end of shift
                            </label>
                            <div className="flex flex-wrap gap-2">
                                {MOODS.map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() =>
                                            set('mood', f.mood === m ? '' : m)
                                        }
                                        className={cn(
                                            'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-[13px] font-medium transition-colors',
                                            f.mood === m
                                                ? 'border-primary bg-accent text-foreground'
                                                : 'border-border bg-background text-muted-foreground hover:bg-accent',
                                        )}
                                    >
                                        <span className="text-[15px] leading-none">
                                            {moodEmoji(m)}
                                        </span>
                                        {m}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {!mutationDisabled && cur.key === 'lists' ? (
                <WizardStepPane>
                    <div className="space-y-4">
                        <StepHead
                            icon={ListChecks}
                            title="What must the next shift action?"
                            blurb="Add discrete items — they appear as checklists for the incoming worker."
                        />
                        <div className="grid gap-4 lg:grid-cols-2">
                            {canViewControlled ? (
                                <div className="space-y-2">
                                    {medicationFocus && (
                                        <ShiftMedSummary
                                            snapshot={snapshot}
                                            loading={snapLoading}
                                            hasShift={!!f.outgoing_shift}
                                            noShiftHint="Select the outgoing shift to load its live medication picture."
                                            note={
                                                snapshot &&
                                                snapshot.due.length > 0
                                                    ? 'Due meds were pre-filled into the list below — edit or remove as needed.'
                                                    : undefined
                                            }
                                        />
                                    )}
                                    {medicationFocus && canGovernControlled && (
                                        <div className="rounded-xl border border-border bg-card p-3">
                                            <div className="mb-1.5 flex items-center gap-2 text-[13px] font-semibold">
                                                <span className="flex h-6 w-6 items-center justify-center rounded-md bg-status-critical-bg text-status-critical">
                                                    <Pill className="h-3.5 w-3.5" />
                                                </span>
                                                Add from medication orders
                                            </div>
                                            <select
                                                className={SELECT_CLASS}
                                                value=""
                                                disabled={!client}
                                                onChange={(e) => {
                                                    const name = e.target.value;
                                                    if (
                                                        name &&
                                                        !f.medications.includes(
                                                            name,
                                                        )
                                                    )
                                                        set('medications', [
                                                            ...f.medications,
                                                            name,
                                                        ]);
                                                }}
                                            >
                                                <option value="">
                                                    {client
                                                        ? 'Pulled from active medication orders…'
                                                        : 'Select a client first'}
                                                </option>
                                                {(client?.medications ?? [])
                                                    .filter(
                                                        (m) =>
                                                            !f.medications.includes(
                                                                m.name,
                                                            ),
                                                    )
                                                    .map((m) => (
                                                        <option
                                                            key={m.id}
                                                            value={m.name}
                                                        >
                                                            {m.name}
                                                        </option>
                                                    ))}
                                            </select>
                                        </div>
                                    )}
                                    <ListBuilder
                                        icon={Pill}
                                        tone="critical"
                                        title="Medications due"
                                        placeholder={
                                            medicationFocus
                                                ? 'Other / unscheduled medicine…'
                                                : 'e.g. Quetiapine 25mg — due 20:00'
                                        }
                                        items={f.medications}
                                        onChange={(v) => set('medications', v)}
                                        readOnly={!canGovernControlled}
                                    />
                                </div>
                            ) : null}
                            <ListBuilder
                                icon={ShieldAlert}
                                tone="critical"
                                title="Incidents to note"
                                placeholder="e.g. 11:20 — brief escalation, resolved"
                                items={f.incidents}
                                onChange={(v) => set('incidents', v)}
                            />
                            <ListBuilder
                                icon={ListChecks}
                                tone="primary"
                                title="Follow-up items"
                                placeholder="e.g. Rebook physio for Thursday"
                                items={f.followups}
                                onChange={(v) => set('followups', v)}
                            />
                            <ListBuilder
                                icon={ClipboardCheck}
                                tone="warning"
                                title="Tasks pending"
                                placeholder="e.g. Restock bathroom consumables"
                                items={f.tasks}
                                onChange={(v) => set('tasks', v)}
                            />
                        </div>
                        {canGovernControlled ? (
                            <CdVerificationSection
                                result={f.cd_result}
                                witness={f.cd_witness}
                                witnessCredential={f.cd_witness_credential}
                                witnessCredentialRef={credentialInputRef}
                                notes={f.cd_notes}
                                cdDue={snapshot?.counts.cd_due ?? 0}
                                witnesses={controlledWitnesses}
                                onResult={(v) =>
                                    setF((current) => ({
                                        ...current,
                                        cd_result: v,
                                        cd_witness: v ? current.cd_witness : '',
                                        cd_witness_credential: '',
                                    }))
                                }
                                onWitness={(v) =>
                                    setF((current) => ({
                                        ...current,
                                        cd_witness: v,
                                        cd_witness_credential: '',
                                    }))
                                }
                                onWitnessCredential={(v) =>
                                    set('cd_witness_credential', v)
                                }
                                onNotes={(v) => set('cd_notes', v)}
                                errors={{
                                    result: errors.cd_result,
                                    witness: errors.cd_witness_id,
                                    credential: errors.cd_witness_credential,
                                    notes: errors.cd_notes,
                                }}
                            />
                        ) : canViewControlled && editing?.cd_verification ? (
                            <CdEvidenceSummary
                                evidence={editing.cd_verification}
                            />
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {!mutationDisabled && cur.key === 'review' ? (
                <WizardStepPane>
                    <div className="space-y-4">
                        <StepHead
                            icon={CheckCircle2}
                            title="Review the handover"
                            blurb="Confirm everything reads well, then submit to the incoming worker."
                        />
                        <ReviewBody
                            f={f}
                            catalogue={catalogue}
                            goTo={setStepIndex}
                            canViewControlled={canViewControlled}
                            canGovernControlled={canGovernControlled}
                            editing={editing}
                            editable={!mutationDisabled}
                        />
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/** Read-only display for a derived worker — the field is taken from the roster
 *  (the shift's assignee), so it's shown rather than chosen. */
function DerivedWorker({
    children,
    muted,
}: {
    children: React.ReactNode;
    muted?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex h-10 w-full items-center rounded-lg border border-input bg-muted/40 px-3 text-sm',
                muted ? 'text-muted-foreground' : 'text-foreground',
            )}
        >
            {children}
        </div>
    );
}

function SubHead({ n, text }: { n: number; text: string }) {
    return (
        <div className="flex items-center gap-2 pt-1 text-[13px] font-bold">
            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] text-primary-foreground">
                {n}
            </span>
            {text}
        </div>
    );
}

/** eMAR lens: two-person controlled-drug count reconciliation at handover —
 *  result + witness + notes, with a deep-link to the CD register. */
function CdVerificationSection({
    result,
    witness,
    witnessCredential,
    witnessCredentialRef,
    notes,
    cdDue,
    witnesses,
    onResult,
    onWitness,
    onWitnessCredential,
    onNotes,
    errors,
}: {
    result: '' | 'verified' | 'discrepancy';
    witness: string;
    witnessCredential: string;
    witnessCredentialRef: React.RefObject<HTMLInputElement | null>;
    notes: string;
    cdDue: number;
    witnesses: { id: number; name: string }[];
    onResult: (v: '' | 'verified' | 'discrepancy') => void;
    onWitness: (v: string) => void;
    onWitnessCredential: (v: string) => void;
    onNotes: (v: string) => void;
    errors: {
        result?: string;
        witness?: string;
        credential?: string;
        notes?: string;
    };
}) {
    const options = [
        ['verified', 'Counts verified', Check],
        ['discrepancy', 'Discrepancy found', AlertTriangle],
    ] as const;
    return (
        <div className="rounded-xl border border-border bg-card p-3.5">
            <div className="mb-2 flex flex-wrap items-center gap-2">
                <span className="flex h-6 w-6 items-center justify-center rounded-md bg-status-critical-bg text-status-critical">
                    <Pill className="h-3.5 w-3.5" />
                </span>
                <span className="text-[13px] font-semibold">
                    Controlled-drug count · two-person check
                </span>
                {cdDue > 0 ? (
                    <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-semibold text-status-warning">
                        {cdDue} CD{cdDue === 1 ? '' : 's'} due this shift
                    </span>
                ) : null}
                <a
                    href="/emar/controlled"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="ml-auto text-[12px] font-semibold text-primary hover:underline"
                >
                    Open CD register →
                </a>
            </div>
            <p className="mb-2.5 text-[12px] text-muted-foreground">
                Reconcile the controlled-drug register with the incoming worker
                at shift change.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <label className="text-[12.5px] font-semibold">
                        Count result
                    </label>
                    <div className="flex flex-wrap gap-2">
                        {options.map(([val, label, Icon]) => (
                            <button
                                key={val}
                                type="button"
                                onClick={() =>
                                    onResult(result === val ? '' : val)
                                }
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-[12.5px] font-medium transition-colors',
                                    result === val
                                        ? val === 'discrepancy'
                                            ? 'border-status-critical bg-status-critical-bg text-status-critical'
                                            : 'border-status-success bg-status-success-bg text-status-success'
                                        : 'border-border bg-background text-muted-foreground hover:bg-accent',
                                )}
                            >
                                <Icon className="h-3.5 w-3.5" />
                                {label}
                            </button>
                        ))}
                    </div>
                    {errors.result ? (
                        <FieldError>{errors.result}</FieldError>
                    ) : null}
                </div>
                <div className="space-y-1.5">
                    <label
                        htmlFor="handover-cd-witness"
                        className="text-[12.5px] font-semibold"
                    >
                        Witness (second checker)
                    </label>
                    <select
                        id="handover-cd-witness"
                        className={SELECT_CLASS}
                        value={witness}
                        disabled={!result}
                        onChange={(e) => onWitness(e.target.value)}
                    >
                        <option value="">
                            {result
                                ? 'Select the witnessing worker…'
                                : 'Record a result first'}
                        </option>
                        {witnesses.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                    {errors.witness ? (
                        <FieldError>{errors.witness}</FieldError>
                    ) : null}
                </div>
            </div>
            {result ? (
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <label
                            htmlFor="handover-cd-witness-credential"
                            className="text-[12.5px] font-semibold"
                        >
                            Witness password or PIN
                        </label>
                        <input
                            ref={witnessCredentialRef}
                            id="handover-cd-witness-credential"
                            type="password"
                            autoComplete="new-password"
                            spellCheck={false}
                            data-lpignore="true"
                            className={cn(INPUT_CLASS, 'h-9')}
                            value={witnessCredential}
                            disabled={!witness}
                            aria-invalid={Boolean(errors.credential)}
                            onChange={(event) =>
                                onWitnessCredential(event.target.value)
                            }
                        />
                        {errors.credential ? (
                            <FieldError>{errors.credential}</FieldError>
                        ) : null}
                    </div>
                    <div className="space-y-1.5">
                        <label
                            htmlFor="handover-cd-notes"
                            className="text-[12.5px] font-semibold"
                        >
                            Notes{' '}
                            {result === 'discrepancy' ? (
                                <span className="text-status-critical">
                                    — describe the discrepancy
                                </span>
                            ) : (
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            )}
                        </label>
                        <input
                            id="handover-cd-notes"
                            className={cn(INPUT_CLASS, 'h-9')}
                            placeholder={
                                result === 'discrepancy'
                                    ? 'e.g. Diazepam register shows 1 fewer than counted — escalated'
                                    : 'e.g. All CD counts matched the register'
                            }
                            value={notes}
                            onChange={(e) => onNotes(e.target.value)}
                        />
                        {errors.notes ? (
                            <FieldError>{errors.notes}</FieldError>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function CdEvidenceSummary({
    evidence,
    pending = false,
}: {
    evidence: NonNullable<Handover['cd_verification']>;
    pending?: boolean;
}) {
    const discrepancy = evidence.result === 'discrepancy';

    return (
        <div className="rounded-xl border border-border bg-card p-3.5">
            <div className="flex items-center gap-2">
                {discrepancy ? (
                    <AlertTriangle className="h-4 w-4 text-status-critical" />
                ) : (
                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                )}
                <span className="text-[13px] font-semibold">
                    {pending
                        ? 'Controlled-drug count to record'
                        : 'Controlled-drug count evidence'}
                </span>
                <span
                    className={cn(
                        'ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        discrepancy
                            ? 'bg-status-critical-bg text-status-critical'
                            : 'bg-status-success-bg text-status-success',
                    )}
                >
                    {discrepancy ? 'Discrepancy recorded' : 'Counts verified'}
                </span>
            </div>
            <div className="mt-2 grid gap-1 text-[12.5px] sm:grid-cols-2">
                <span className="text-muted-foreground">Second checker</span>
                <span className="font-medium">
                    {evidence.witness_name ?? 'Recorded witness'}
                </span>
                {!pending ? (
                    <>
                        <span className="text-muted-foreground">
                            Recorded by
                        </span>
                        <span className="font-medium">
                            {evidence.verified_by_name ?? 'Authorised worker'}
                        </span>
                    </>
                ) : null}
                {evidence.notes ? (
                    <>
                        <span className="text-muted-foreground">Notes</span>
                        <span className="font-medium">{evidence.notes}</span>
                    </>
                ) : null}
            </div>
        </div>
    );
}

function ShiftSelect({
    shifts,
    value,
    onChange,
    disabled,
    bad,
    suggestId,
    placeholder,
}: {
    shifts: CatalogueShift[];
    value: string;
    onChange: (v: string) => void;
    disabled?: boolean;
    bad?: boolean;
    suggestId?: string;
    placeholder: string;
}) {
    return (
        <select
            className={cn(SELECT_CLASS, bad && BAD_CLASS)}
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
        >
            <option value="">{placeholder}</option>
            {shifts.map((s) => (
                <option key={s.id} value={s.id}>
                    {shiftOptionLabel(s)}
                    {suggestId && String(s.id) === String(suggestId)
                        ? ' · next'
                        : ''}
                </option>
            ))}
        </select>
    );
}

function ShiftChain({
    oSh,
    nSh,
    leaveOpen,
}: {
    oSh: CatalogueShift;
    nSh: CatalogueShift | null;
    leaveOpen: boolean;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-muted/30 px-3.5 py-2.5 text-[12px]">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-semibold">
                <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                Outgoing · {shiftOptionLabel(oSh)}
            </span>
            <ArrowRight className="h-4 w-4 text-muted-foreground" />
            {leaveOpen ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-status-warning-bg px-2.5 py-1 font-semibold text-status-warning">
                    <AlertTriangle className="h-3.5 w-3.5" />
                    New shift left open
                </span>
            ) : nSh ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-semibold">
                    <Clock className="h-3.5 w-3.5 text-primary" />
                    New · {shiftOptionLabel(nSh)}
                </span>
            ) : (
                <span className="text-muted-foreground">
                    Pick the new shift below
                </span>
            )}
        </div>
    );
}

function ReviewBody({
    f,
    catalogue,
    goTo,
    canViewControlled,
    canGovernControlled,
    editing,
    editable,
}: {
    f: WizForm;
    catalogue: Catalogue;
    goTo: (i: number) => void;
    canViewControlled: boolean;
    canGovernControlled: boolean;
    editing: Handover | null;
    editable: boolean;
}) {
    const client =
        catalogue.clients.find((c) => String(c.id) === f.client_id) ??
        editing?.client;
    const out = catalogue.staff.find((s) => String(s.id) === f.outgoing);
    const inc = catalogue.staff.find((s) => String(s.id) === f.incoming);
    const submittedRecipient = editing?.submitted_incoming_staff ?? null;
    const currentAcknowledgementAssignee =
        editing?.current_incoming_staff ??
        (editing?.status === 'draft' ? editing.incoming_staff : null);
    const immutableRecipientEvidence = Boolean(
        editing && editing.status !== 'draft' && submittedRecipient,
    );
    const oSh =
        catalogue.shifts.find((s) => String(s.id) === f.outgoing_shift) ??
        editing?.outgoing_shift;
    const nSh =
        catalogue.shifts.find((s) => String(s.id) === f.incoming_shift) ??
        editing?.incoming_shift;
    const siteName = (siteId: number | null) =>
        catalogue.sites.find((s) => s.id === siteId)?.name ??
        (editing?.site?.id === siteId ? editing.site.name : '');

    const lists: [string, string[], 'critical' | 'warning' | 'primary'][] = [
        ...(canViewControlled
            ? ([['Medications due', f.medications, 'critical']] as [
                  string,
                  string[],
                  'critical',
              ][])
            : []),
        ['Incidents', f.incidents, 'critical'],
        ['Follow-ups', f.followups, 'primary'],
        ['Tasks pending', f.tasks, 'warning'],
    ];
    const cdChanged = canGovernControlled && cdEvidenceChanged(f, editing);
    const cdWitness = catalogue.staff.find(
        (staff) => String(staff.id) === f.cd_witness,
    );
    const reviewCdEvidence =
        canViewControlled && cdChanged && f.cd_result
            ? {
                  result: f.cd_result,
                  witness_id: f.cd_witness ? Number(f.cd_witness) : null,
                  witness_name: cdWitness?.name ?? null,
                  notes: f.cd_notes.trim() || null,
                  verified_at: null,
                  verified_by: null,
                  verified_by_name: null,
              }
            : canViewControlled
              ? (editing?.cd_verification ?? null)
              : null;

    return (
        <div className="space-y-3">
            <ReviewCard
                icon={ArrowLeftRight}
                title="Shift & people"
                onEdit={editable ? () => goTo(0) : undefined}
            >
                <ReviewRow
                    k="Client"
                    v={
                        client
                            ? `${clientName(client)}${siteName(client.site_id) ? ` · ${siteName(client.site_id)}` : ''}`
                            : '—'
                    }
                />
                <ReviewRow
                    k="Outgoing"
                    v={out?.name ?? editing?.outgoing_staff?.name ?? '—'}
                />
                <ReviewRow
                    k="Outgoing shift"
                    v={
                        oSh
                            ? `${oSh.label} · ${fmtTime(oSh.starts_at)}–${fmtTime(oSh.ends_at)}`
                            : '—'
                    }
                />
                {immutableRecipientEvidence ? (
                    <>
                        <ReviewRow
                            k="Submitted recipient"
                            v={submittedRecipient?.name ?? '—'}
                        />
                        <ReviewRow
                            k="Current acknowledgement assignee"
                            v={
                                currentAcknowledgementAssignee?.name ?? (
                                    <span className="text-status-warning">
                                        No worker currently assigned
                                    </span>
                                )
                            }
                        />
                    </>
                ) : (
                    <ReviewRow
                        k="Incoming"
                        v={
                            f.leave_open ? (
                                <span className="text-status-warning">
                                    Open — needs cover
                                </span>
                            ) : inc || editing?.incoming_staff ? (
                                (inc?.name ?? editing?.incoming_staff?.name)
                            ) : (
                                '—'
                            )
                        }
                    />
                )}
                <ReviewRow
                    k="New shift"
                    v={
                        f.leave_open ? (
                            <span className="text-status-warning">
                                Left open
                            </span>
                        ) : nSh ? (
                            `${nSh.label} · ${fmtTime(nSh.starts_at)}–${fmtTime(nSh.ends_at)}`
                        ) : (
                            '—'
                        )
                    }
                />
            </ReviewCard>

            <ReviewCard
                icon={FileText}
                title="Narrative & mood"
                onEdit={editable ? () => goTo(1) : undefined}
            >
                <ReviewRow
                    k="Mood"
                    v={f.mood ? `${moodEmoji(f.mood)} ${f.mood}` : '—'}
                />
                <p className="mt-1.5 text-[13.5px] leading-relaxed">
                    {f.narrative || (
                        <span className="text-muted-foreground">
                            No narrative yet.
                        </span>
                    )}
                </p>
            </ReviewCard>

            {lists.some(([, arr]) => arr.length > 0) ? (
                <ReviewCard
                    icon={ListChecks}
                    title="Action items"
                    onEdit={editable ? () => goTo(2) : undefined}
                >
                    {lists
                        .filter(([, arr]) => arr.length > 0)
                        .map(([label, arr]) => (
                            <div
                                key={label}
                                className="flex flex-wrap items-start gap-2 py-1"
                            >
                                <span className="w-28 shrink-0 text-[12.5px] font-semibold text-muted-foreground">
                                    {label}
                                </span>
                                <div className="flex flex-1 flex-wrap gap-1.5">
                                    {arr.map((it, i) => (
                                        <span
                                            key={i}
                                            className="rounded-md bg-accent px-2 py-0.5 text-[12px]"
                                        >
                                            {it}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                </ReviewCard>
            ) : null}
            {reviewCdEvidence ? (
                <CdEvidenceSummary
                    evidence={reviewCdEvidence}
                    pending={cdChanged}
                />
            ) : null}
        </div>
    );
}

function ReviewCard({
    icon: Icon,
    title,
    onEdit,
    children,
}: {
    icon: typeof ArrowLeftRight;
    title: string;
    onEdit?: () => void;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-3.5">
            <div className="mb-2 flex items-center gap-2">
                <Icon className="h-4 w-4 text-primary" />
                <span className="text-[13px] font-bold">{title}</span>
                {onEdit ? (
                    <button
                        type="button"
                        onClick={onEdit}
                        className="ml-auto text-[12px] font-semibold text-primary hover:underline"
                    >
                        Edit
                    </button>
                ) : null}
            </div>
            {children}
        </div>
    );
}

function ReviewRow({ k, v }: { k: string; v: React.ReactNode }) {
    return (
        <div className="flex items-start gap-2 py-0.5 text-[13px]">
            <span className="w-28 shrink-0 text-muted-foreground">{k}</span>
            <span className="min-w-0 flex-1 font-medium">{v}</span>
        </div>
    );
}
