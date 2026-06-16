/* eslint-disable no-restricted-syntax -- The handover wizard mirrors the bespoke
 * Add-client modal surface (stepper rail + scroll-contained body + custom footer)
 * and intentionally uses styled native controls. Every colour is a semantic
 * design token, per docs/DESIGN_TOKENS.md. */
/* New / Edit handover wizard — 4 steps modelled on the Add Client wizard, built
 * around the outgoing-shift → new-shift chain. */
import { startOfWeek } from '@/components/rostering';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { FieldErr as FieldError, StepHead } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';

import { type ShiftMedSnapshot, ShiftMedSummary } from './shift-med-snapshot';
import {
    type Catalogue,
    type CatalogueShift,
    type Handover,
    MOODS,
    clientName,
    clientShiftsSorted,
    fmtTime,
    moodEmoji,
    nextShiftIdAfter,
    shiftOptionLabel,
} from './shared';

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
};

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
    };
}

function initFromEditing(h: Handover): WizForm {
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
    };
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
}: {
    icon: typeof Pill;
    tone: 'critical' | 'warning' | 'primary';
    title: string;
    placeholder: string;
    items: string[];
    onChange: (items: string[]) => void;
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
                                <span className="flex-1 leading-snug">{it}</span>
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
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-[12.5px] text-muted-foreground">
                        None added yet.
                    </div>
                )}
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

    // Re-seed the form whenever the dialog (re)opens or the edited record changes.
    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setErrors({});
        setF(editing ? initFromEditing(editing) : emptyForm());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    // A client added via the inline Add Client dialog selects itself here.
    useEffect(() => {
        if (preselectClientId == null) return;
        setF((p) => ({
            ...p,
            client_id: String(preselectClientId),
            outgoing_shift: '',
            incoming_shift: '',
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
            .get(`${basePath}/shift-medications`, { params: { shift_id: Number(f.outgoing_shift) } })
            .then((res) => {
                if (cancelled) return;
                const snap: ShiftMedSnapshot | null = res.data?.snapshot ?? null;
                setSnapshot(snap);
                if (snap && snap.due.length > 0) {
                    setF((p) =>
                        p.medications.length === 0
                            ? { ...p, medications: snap.due.map((d) => `${d.name} — due ${d.time}${d.controlled ? ' (CD)' : ''}`) }
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

    const cur = WIZ_STEPS[stepIndex];
    const pct = readiness(f);
    const set = <K extends keyof WizForm>(k: K, v: WizForm[K]) =>
        setF((p) => ({ ...p, [k]: v }));

    const siteName = (siteId: number | null) =>
        catalogue.sites.find((s) => s.id === siteId)?.name ?? '';
    const client = f.client_id
        ? catalogue.clients.find((c) => String(c.id) === f.client_id)
        : null;

    const outgoingShifts = useMemo(
        () => clientShiftsSorted(catalogue.shifts, f.client_id),
        [catalogue.shifts, f.client_id],
    );
    const suggestNextId = nextShiftIdAfter(
        catalogue.shifts,
        f.client_id,
        f.outgoing_shift,
    );
    const incomingShifts = useMemo(() => {
        const outId = f.outgoing_shift;
        const out = catalogue.shifts.find((s) => String(s.id) === outId);
        const outEnd = out?.ends_at ? new Date(out.ends_at).getTime() : null;
        return outgoingShifts.filter(
            (s) =>
                String(s.id) !== outId &&
                (outEnd == null ||
                    (s.starts_at && new Date(s.starts_at).getTime() >= outEnd)),
        );
    }, [outgoingShifts, catalogue.shifts, f.outgoing_shift]);

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
        const all = { ...validate('shift'), ...validate('narrative') };
        if (Object.keys(all).length) {
            setErrors(all);
            setStepIndex(
                all.client_id || all.outgoing_shift || all.incoming_shift
                    ? 0
                    : 1,
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
            medications_due_text: f.medications.join('\n'),
            incidents_to_note_text: f.incidents.join('\n'),
            follow_up_items_text: f.followups.join('\n'),
            tasks_pending_text: f.tasks.join('\n'),
            submit: !asDraft,
        };

        const targetShift = catalogue.shifts.find(
            (s) => String(s.id) === f.outgoing_shift,
        );
        const targetWeek = startOfWeek(
            targetShift?.starts_at ? new Date(targetShift.starts_at) : new Date(),
        );

        setSubmitting(true);
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                toast.success(
                    editing
                        ? 'Handover updated'
                        : asDraft
                          ? 'Handover saved as draft'
                          : `Handover submitted for ${client ? clientName(client) : 'client'}`,
                );
                onSubmitted(targetWeek);
            },
            onError: () =>
                toast.error('Could not save the handover. Please review and retry.'),
            onFinish: () => setSubmitting(false),
        };

        if (editing) {
            router.put(`${basePath}/${editing.id}`, payload, opts);
        } else {
            router.post(
                basePath,
                { shift_id: Number(f.outgoing_shift), ...payload },
                opts,
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[min(820px,92vh)] max-w-[min(96vw,1000px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-[min(96vw,1000px)] md:flex-row [&>button]:hidden">
                <DialogTitle className="sr-only">
                    {editing ? 'Edit handover' : 'New handover'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    A guided wizard to record a shift-to-shift handover.
                </DialogDescription>

                {/* Stepper rail */}
                <aside className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar p-4 md:flex">
                    <div className="mb-4 flex items-center gap-2.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/15 text-primary">
                            {editing ? (
                                <FileText className="h-4.5 w-4.5" />
                            ) : (
                                <ArrowLeftRight className="h-4.5 w-4.5" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <div className="text-sm font-bold">
                                {editing ? 'Edit handover' : 'New handover'}
                            </div>
                            <div className="truncate text-[11.5px] text-muted-foreground">
                                {editing
                                    ? `${clientName(editing.client)} · update`
                                    : 'Shift → shift'}
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-1 flex-col gap-1">
                        {WIZ_STEPS.map((s, i) => {
                            const Icon = s.icon;
                            const active = i === stepIndex;
                            const done = i < stepIndex;
                            return (
                                <button
                                    key={s.key}
                                    type="button"
                                    onClick={() => setStepIndex(i)}
                                    className={cn(
                                        'flex items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors',
                                        active
                                            ? 'bg-primary/10'
                                            : 'hover:bg-accent',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : done
                                                  ? 'bg-status-success-bg text-status-success'
                                                  : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {done ? (
                                            <Check className="h-3.5 w-3.5" />
                                        ) : (
                                            <Icon className="h-3.5 w-3.5" />
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-[13px] font-semibold leading-tight">
                                            {s.label}
                                        </span>
                                        <span className="block text-[11px] text-muted-foreground">
                                            {s.blurb}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-3 rounded-lg border border-border bg-card p-3">
                        <div className="flex items-center justify-between text-[11.5px] font-semibold">
                            <span>Handover readiness</span>
                            <span className="tabular-nums">{pct}%</span>
                        </div>
                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary transition-all"
                                style={{ width: `${pct}%` }}
                            />
                        </div>
                    </div>
                </aside>

                {/* Main panel */}
                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center justify-between border-b border-border px-5 py-3">
                        <div className="text-[12.5px] text-muted-foreground">
                            Step {stepIndex + 1} of {WIZ_STEPS.length} ·{' '}
                            <b className="text-foreground">{cur.label}</b>
                        </div>
                        <button
                            type="button"
                            onClick={() => onOpenChange(false)}
                            aria-label="Close"
                            className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                            <X className="h-4.5 w-4.5" />
                        </button>
                    </header>
                    <div className="h-[3px] shrink-0 bg-muted">
                        <div
                            className="h-full bg-primary transition-all"
                            style={{
                                width: `${((stepIndex + 1) / WIZ_STEPS.length) * 100}%`,
                            }}
                        />
                    </div>

                    <div className="flex-1 overflow-y-auto px-5 py-5">
                        {cur.key === 'shift' ? (
                            <div className="space-y-4">
                                <StepHead
                                    icon={ArrowLeftRight}
                                    title="Set up the shift handover"
                                    blurb="Pick the client, the outgoing shift being handed over, then the new shift that takes over."
                                />
                                <div className="space-y-1.5">
                                    <label className="text-[13px] font-semibold">
                                        Client
                                        <span className="text-status-critical">
                                            {' '}
                                            *
                                        </span>
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
                                            }))
                                        }
                                    >
                                        <option value="">
                                            Select a client…
                                        </option>
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
                                        <FieldError>
                                            {errors.client_id}
                                        </FieldError>
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
                                            — they'll be created and selected
                                            here without leaving this handover.
                                        </div>
                                    </div>
                                ) : null}

                                {client ? (
                                    <div className="flex flex-wrap gap-2">
                                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                            <Home className="h-3 w-3" />
                                            {siteName(client.site_id) ||
                                                'No house'}
                                        </span>
                                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-medium">
                                            <MapPin className="h-3 w-3" />
                                            Supported living
                                        </span>
                                    </div>
                                ) : null}

                                <SubHead n={1} text="Outgoing shift — being handed over" />
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
                                        <DerivedWorker
                                            muted={!outgoingWorkerName}
                                        >
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
                                            disabled={
                                                f.leave_open ||
                                                !f.outgoing_shift
                                            }
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
                                                    const open =
                                                        e.target.checked;
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
                                                            : incomingWorkerFor(
                                                                  nextId,
                                                              ),
                                                    }));
                                                }}
                                                className="h-4 w-4 accent-primary"
                                            />
                                            Leave the new shift open (needs
                                            cover)
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
                                            muted={
                                                !incomingWorkerName ||
                                                f.leave_open
                                            }
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
                        ) : null}

                        {cur.key === 'narrative' ? (
                            <div className="space-y-4">
                                <StepHead
                                    icon={FileText}
                                    title="How did the shift go?"
                                    blurb="A clear narrative the incoming worker can read in under a minute."
                                />
                                <div className="space-y-1.5">
                                    <label className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                        Handover narrative
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                        <span className="text-[11.5px] font-normal text-muted-foreground">
                                            mood, sleep, meals, activities,
                                            anything to watch
                                        </span>
                                    </label>
                                    <textarea
                                        className={cn(
                                            'min-h-[180px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30',
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
                                            <FieldError>
                                                {errors.narrative}
                                            </FieldError>
                                        ) : (
                                            <span className="text-[12px] text-muted-foreground">
                                                Be specific and factual — this
                                                is a clinical record.
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
                                                    set(
                                                        'mood',
                                                        f.mood === m ? '' : m,
                                                    )
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
                        ) : null}

                        {cur.key === 'lists' ? (
                            <div className="space-y-4">
                                <StepHead
                                    icon={ListChecks}
                                    title="What must the next shift action?"
                                    blurb="Add discrete items — they appear as checklists for the incoming worker."
                                />
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <div className="space-y-2">
                                        {medicationFocus && (
                                            <ShiftMedSummary
                                                snapshot={snapshot}
                                                loading={snapLoading}
                                                hasShift={!!f.outgoing_shift}
                                                noShiftHint="Select the outgoing shift to load its live medication picture."
                                                note={snapshot && snapshot.due.length > 0 ? 'Due meds were pre-filled into the list below — edit or remove as needed.' : undefined}
                                            />
                                        )}
                                        {medicationFocus && (
                                            <div className="rounded-xl border border-border bg-card p-3">
                                                <div className="mb-1.5 flex items-center gap-2 text-[13px] font-semibold">
                                                    <span className="flex h-6 w-6 items-center justify-center rounded-md bg-status-critical-bg text-status-critical"><Pill className="h-3.5 w-3.5" /></span>
                                                    Add from medication orders
                                                </div>
                                                <select
                                                    className={SELECT_CLASS}
                                                    value=""
                                                    disabled={!client}
                                                    onChange={(e) => {
                                                        const name = e.target.value;
                                                        if (name && !f.medications.includes(name)) set('medications', [...f.medications, name]);
                                                    }}
                                                >
                                                    <option value="">{client ? 'Pulled from active medication orders…' : 'Select a client first'}</option>
                                                    {(client?.medications ?? [])
                                                        .filter((m) => !f.medications.includes(m.name))
                                                        .map((m) => (
                                                            <option key={m.id} value={m.name}>{m.name}</option>
                                                        ))}
                                                </select>
                                            </div>
                                        )}
                                        <ListBuilder
                                            icon={Pill}
                                            tone="critical"
                                            title="Medications due"
                                            placeholder={medicationFocus ? 'Other / unscheduled medicine…' : 'e.g. Quetiapine 25mg — due 20:00'}
                                            items={f.medications}
                                            onChange={(v) => set('medications', v)}
                                        />
                                    </div>
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
                            </div>
                        ) : null}

                        {cur.key === 'review' ? (
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
                                />
                            </div>
                        ) : null}
                    </div>

                    {/* Footer */}
                    <footer className="flex items-center justify-between gap-2 border-t border-border bg-muted/30 px-5 py-3.5">
                        <div>
                            {stepIndex > 0 ? (
                                <button
                                    type="button"
                                    onClick={goBack}
                                    className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Back
                                </button>
                            ) : null}
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => onOpenChange(false)}
                                className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                            >
                                Cancel
                            </button>
                            {cur.key === 'review' ? (
                                <>
                                    {!editing ||
                                    editing.status === 'draft' ? (
                                        <button
                                            type="button"
                                            onClick={() => submit(true)}
                                            disabled={submitting}
                                            className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent disabled:opacity-60"
                                        >
                                            Save as draft
                                        </button>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => submit(false)}
                                        disabled={submitting}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                                    >
                                        {submitting ? (
                                            <>
                                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                Saving…
                                            </>
                                        ) : editing ? (
                                            <>
                                                <Check className="h-3.5 w-3.5" />
                                                Save changes
                                            </>
                                        ) : (
                                            <>
                                                <Send className="h-3.5 w-3.5" />
                                                Submit handover
                                            </>
                                        )}
                                    </button>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    onClick={goNext}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                >
                                    Continue
                                    <ChevronRight className="h-4 w-4" />
                                </button>
                            )}
                        </div>
                    </footer>
                </div>
            </DialogContent>
        </Dialog>
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
}: {
    f: WizForm;
    catalogue: Catalogue;
    goTo: (i: number) => void;
}) {
    const client = catalogue.clients.find((c) => String(c.id) === f.client_id);
    const out = catalogue.staff.find((s) => String(s.id) === f.outgoing);
    const inc = catalogue.staff.find((s) => String(s.id) === f.incoming);
    const oSh = catalogue.shifts.find((s) => String(s.id) === f.outgoing_shift);
    const nSh = catalogue.shifts.find((s) => String(s.id) === f.incoming_shift);
    const siteName = (siteId: number | null) =>
        catalogue.sites.find((s) => s.id === siteId)?.name ?? '';

    const lists: [string, string[], 'critical' | 'warning' | 'primary'][] = [
        ['Medications due', f.medications, 'critical'],
        ['Incidents', f.incidents, 'critical'],
        ['Follow-ups', f.followups, 'primary'],
        ['Tasks pending', f.tasks, 'warning'],
    ];

    return (
        <div className="space-y-3">
            <ReviewCard
                icon={ArrowLeftRight}
                title="Shift & people"
                onEdit={() => goTo(0)}
            >
                <ReviewRow
                    k="Client"
                    v={
                        client
                            ? `${clientName(client)}${siteName(client.site_id) ? ` · ${siteName(client.site_id)}` : ''}`
                            : '—'
                    }
                />
                <ReviewRow k="Outgoing" v={out ? out.name : '—'} />
                <ReviewRow
                    k="Outgoing shift"
                    v={
                        oSh
                            ? `${oSh.label} · ${fmtTime(oSh.starts_at)}–${fmtTime(oSh.ends_at)}`
                            : '—'
                    }
                />
                <ReviewRow
                    k="Incoming"
                    v={
                        f.leave_open ? (
                            <span className="text-status-warning">
                                Open — needs cover
                            </span>
                        ) : inc ? (
                            inc.name
                        ) : (
                            '—'
                        )
                    }
                />
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
                onEdit={() => goTo(1)}
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
                    onEdit={() => goTo(2)}
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
    onEdit: () => void;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-3.5">
            <div className="mb-2 flex items-center gap-2">
                <Icon className="h-4 w-4 text-primary" />
                <span className="text-[13px] font-bold">{title}</span>
                <button
                    type="button"
                    onClick={onEdit}
                    className="ml-auto text-[12px] font-semibold text-primary hover:underline"
                >
                    Edit
                </button>
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
