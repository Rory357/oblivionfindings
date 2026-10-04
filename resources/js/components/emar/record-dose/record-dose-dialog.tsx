/* eMAR P01 — ONE recording dialog for every entry point ("Record a dose:
 * one pop-up everywhere", approved P01 v2 `record-dialog.tsx`). Meds today's
 * schedule and as-needed list, the guided round, the MAR, the client
 * profile, the shift card and Fleet transport all open THIS component; only
 * the locked "Opened from" context changes.
 *
 * Safety checks → Record outcome → Review & sign, on the shared WizardShell.
 * What the dose needs and allows comes from the server
 * (DoseRecordingRequirements); saving goes through the one recording
 * contract, which checks everything again under its locks. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { TilePicker } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    createMedicationMutationReplayState,
    prepareMedicationMutationReplayState,
    submitEmarMutation,
} from '@/lib/emar-offline';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { readServerSyncOutcome } from '@/lib/offline-queue';
import { cn } from '@/lib/utils';
import axios from 'axios';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    EyeOff,
    FileText,
    Link2,
    Loader2,
    LogIn,
    LogOut,
    MessageSquare,
    PauseCircle,
    Pill,
    RefreshCw,
    Repeat,
    Search,
    ShieldCheck,
    Truck,
    User,
    UserCheck,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { onCallText } from './copy';
import { BlockedWhy, ErrorCreatedDialog } from './dialogs';
import {
    AmountField,
    Field,
    NoteField,
    ReasonSelect,
    SecondPerson,
    formatAmount,
    type AmountState,
    type Errors,
    type SecondState,
} from './fields';
import {
    AllergyNotice,
    BlockedPanel,
    IdentityHeader,
    KV,
    MatchLine,
    NotConfigured,
    Notice,
    Rich,
    SupportChip,
    activeBlock,
} from './parts';
import {
    ENTRY_LABEL,
    isBlockedAnswer,
    type DoseRequirements,
    type DoseTarget,
    type EntryPoint,
    type RecordMode,
    type SecondPersonKind,
} from './types';
import { useDoseRequirements } from './use-dose-requirements';

type Outcome = 'given' | 'reoffered' | 'refused' | 'withheld' | 'away';
const GIVEN_LIKE: Outcome[] = ['given', 'reoffered'];

const STEPS = [
    {
        key: 'safety',
        label: 'Safety checks',
        blurb: 'Person, allergies, instructions',
        icon: ShieldCheck,
    },
    {
        key: 'outcome',
        label: 'Record outcome',
        blurb: 'What actually happened',
        icon: ClipboardCheck,
    },
    {
        key: 'review',
        label: 'Review & sign',
        blurb: 'Check, then save',
        icon: Check,
    },
] as const;

type Phase =
    | 'edit'
    | 'sending'
    | 'rejected'
    | 'uncertain'
    | 'duplicate'
    | 'done';

interface FormState {
    step: number;
    outcome: Outcome | null;
    reason: string;
    lateReason: string;
    note: string;
    when: string;
    followBy: string;
    prnReason: string;
    checkBy: string;
    amount: AmountState;
    obs: Record<string, string>;
    second: SecondState;
    stockQuantity: string;
    /** Controlled medicines: what's left in the stock after this dose (Q-C2a, counted). */
    cdBalance: string;
    packChecked: boolean;
}

/** Recording context an entry point locks (shown on step 1 and the review). */
export interface TransportContext {
    vehicle: string;
    trip: string;
    packed: string | null;
}

/** The Shift card preserves its canonical shift and existing pack scan. */
export interface ShiftRecordingContext {
    shiftId: number;
    scanVerification?: MedicationScanVerification | null;
}

export interface RecordedResult {
    status: 'recorded' | 'queued' | 'duplicate';
    administrationId: number | null;
}

/* ───────────── NZ wall-clock helpers (the approved DateTimeField speaks NZ) ───────────── */
const TZ = 'Pacific/Auckland';
function nzLocal(date: Date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: TZ,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);
    const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00';
    const hour = get('hour') === '24' ? '00' : get('hour');

    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
}
const timeLabel = (iso: string | null | undefined) =>
    iso
        ? new Date(iso).toLocaleTimeString('en-NZ', {
              hour: 'numeric',
              minute: '2-digit',
              timeZone: TZ,
          })
        : '—';
const localLabel = (local: string) => {
    if (!local) return '—';
    const [d, t] = local.split('T');
    const [h, m] = (t ?? '00:00').split(':').map(Number);
    const ampm = h >= 12 ? 'pm' : 'am';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    const date = new Date(`${d}T12:00:00Z`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });

    return `${date} · ${h12}:${String(m).padStart(2, '0')} ${ampm}`;
};

/** "NZDT" or "NZST" — the zone the times are in, as the approved review shows it. */
const nzZone = (date: Date = new Date()) =>
    new Intl.DateTimeFormat('en-NZ', { timeZone: TZ, timeZoneName: 'short' })
        .formatToParts(date)
        .find((p) => p.type === 'timeZoneName')?.value ?? 'NZ time';

/** The route in plain words ("by mouth"), as the approved dialog says it. */
const ROUTE_WORDS: Record<string, string> = {
    oral: 'by mouth',
    po: 'by mouth',
    sublingual: 'under the tongue',
    topical: 'on the skin',
};
const routeWords = (route: string) =>
    ROUTE_WORDS[route.trim().toLowerCase()] ?? route.trim().toLowerCase();

function outcomeTiles(
    req: DoseRequirements,
    mode: RecordMode,
    givenWhy: string | null,
) {
    const p = req.person.preferred_name;
    const isPrn = req.kind === 'prn';
    if (isPrn)
        return [
            {
                key: 'given',
                label: 'Given',
                description: 'You gave the medicine',
                icon: Check,
                disabled: givenWhy,
            },
        ];
    if (mode === 'reoffer') {
        return [
            {
                key: 'reoffered',
                label: 'Given after re-offer',
                description: 'Offered again and taken',
                icon: Repeat,
                disabled: givenWhy,
            },
            {
                key: 'refused',
                label: 'Refused again',
                description: `${p} still chose not to take it`,
                icon: X,
                disabled: null,
            },
        ];
    }
    const notGivenOnly =
        mode === 'notgiven' ? 'Can’t be recorded as given right now' : null;

    return [
        {
            key: 'given',
            label: 'Given',
            description: 'You gave the medicine',
            icon: Check,
            disabled: givenWhy ?? notGivenOnly,
        },
        {
            key: 'refused',
            label: 'Refused',
            description: `${p} chose not to take it`,
            icon: X,
            disabled: null,
        },
        {
            key: 'withheld',
            label: 'Withheld',
            description: 'Not given, with a reason',
            icon: PauseCircle,
            disabled: null,
        },
        {
            key: 'away',
            label: 'Away',
            description: `${p} isn’t here`,
            icon: LogOut,
            disabled: null,
        },
    ];
}

/* ───────────── the dialog ───────────── */
export function RecordDoseDialog({
    target,
    entry,
    mode = 'record',
    signedAs,
    transport,
    roundId,
    shiftContext,
    onClose,
    onRecorded,
    onEligibility,
    onNext,
    nextLabel,
    returnFocus,
}: {
    target: DoseTarget;
    entry: EntryPoint;
    mode?: RecordMode;
    signedAs: { name: string; role_label: string | null };
    transport?: TransportContext;
    roundId?: number | null;
    shiftContext?: ShiftRecordingContext;
    onClose: () => void;
    onRecorded?: (result: RecordedResult) => void;
    onEligibility?: () => void;
    onNext?: () => void;
    nextLabel?: string | null;
    returnFocus?: () => HTMLElement | null;
}) {
    const requirements = useDoseRequirements(target);

    if (requirements.status !== 'ready') {
        return (
            <LoadingOrMissing
                state={requirements.status}
                onClose={onClose}
                onRetry={requirements.reload}
            />
        );
    }

    if (isBlockedAnswer(requirements.data)) {
        // Nothing can be recorded: the approved "Why can't I record this?" answer, not the wizard.
        return (
            <BlockedWhy
                answer={requirements.data}
                target={target}
                onClose={onClose}
                onCloseAutoFocus={(e) => {
                    const el = returnFocus?.();
                    if (el) {
                        e.preventDefault();
                        el.focus();
                    }
                }}
            />
        );
    }

    return (
        <RecordDoseForm
            req={requirements.data}
            target={target}
            entry={entry}
            mode={mode}
            signedAs={signedAs}
            transport={transport}
            roundId={roundId ?? null}
            shiftContext={shiftContext}
            onClose={onClose}
            onRecorded={onRecorded}
            onEligibility={onEligibility}
            onNext={onNext}
            nextLabel={nextLabel}
            returnFocus={returnFocus}
        />
    );
}

function LoadingOrMissing({
    state,
    onClose,
    onRetry,
}: {
    state: 'loading' | 'not_found' | 'error';
    onClose: () => void;
    onRetry: () => void;
}) {
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="frontline-dialog flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
            >
                <div className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>
                        {state === 'not_found'
                            ? 'We can’t show this dose'
                            : 'Record dose'}
                    </DialogTitle>
                    <DialogDescription className="mt-2">
                        {state === 'loading'
                            ? 'Checking the latest safety information for this dose.'
                            : state === 'not_found'
                              ? 'It may not exist, or it may not be available to you.'
                              : 'Couldn’t load this dose. Some doses may already be recorded — don’t give anything from memory.'}
                    </DialogDescription>
                </div>
                <div className="min-h-0 overflow-y-auto p-5">
                    {state === 'loading' ? (
                        <LoadingState message="Checking the dose…" />
                    ) : null}
                    {state === 'error' ? (
                        <ErrorState
                            title="Couldn’t load this dose"
                            message="Try again, or use the printed MAR."
                            onRetry={onRetry}
                        />
                    ) : null}
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={onClose}
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RecordDoseForm({
    req,
    target,
    entry,
    mode,
    signedAs,
    transport,
    roundId,
    shiftContext,
    onClose,
    onRecorded,
    onEligibility,
    onNext,
    nextLabel,
    returnFocus,
}: {
    req: DoseRequirements;
    target: DoseTarget;
    entry: EntryPoint;
    mode: RecordMode;
    signedAs: { name: string; role_label: string | null };
    transport?: TransportContext;
    roundId: number | null;
    shiftContext?: ShiftRecordingContext;
    onClose: () => void;
    onRecorded?: (result: RecordedResult) => void;
    onEligibility?: () => void;
    onNext?: () => void;
    nextLabel?: string | null;
    returnFocus?: () => HTMLElement | null;
}) {
    const isPrn = req.kind === 'prn';
    const p = req.person.preferred_name;
    const med = req.order.name;
    const [scanCapture, setScanCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture,
    );
    const due = req.due;
    // The order's own dose words ("1 tablet (50 mg)"), else its amount.
    const orderedText =
        req.order.dosage ??
        (req.order.dose_amount != null
            ? formatAmount(req.order.dose_amount, req.order.dose_unit)
            : '—');

    const [f, setF] = useState<FormState>(() => ({
        step: mode === 'notgiven' || mode === 'reoffer' ? 1 : 0,
        outcome: null,
        reason: '',
        lateReason: '',
        note: '',
        when: nzLocal(),
        followBy: '',
        prnReason: '',
        checkBy: '',
        amount: {
            mode: 'asOrdered',
            amount: req.order.dose_amount,
            reason: '',
            severity: '',
            immediate: '',
        },
        obs: {},
        second: { id: null, pin: '' },
        stockQuantity: '',
        cdBalance: '',
        packChecked: false,
    }));
    const [errors, setErrors] = useState<Errors>({});
    const [phase, setPhase] = useState<Phase>('edit');
    const [serverMessage, setServerMessage] = useState<string | null>(null);
    const [duplicateOf, setDuplicateOf] = useState<{
        by: string | null;
        status: string;
        administered_at: string | null;
    } | null>(null);
    const [result, setResult] = useState<{
        tone: 'success' | 'warning';
        lines: string[];
    } | null>(null);
    const [discard, setDiscard] = useState(false);
    const [errorCreated, setErrorCreated] = useState<{
        reference: string | null;
        incidentId: number | null;
    } | null>(null);
    const dirty = useRef(false);
    const bodyRef = useRef<HTMLDivElement>(null);
    const replay = useRef(createMedicationMutationReplayState());

    const set = (patch: Partial<FormState>) => {
        dirty.current = true;
        setF((x) => ({ ...x, ...patch }));
        // Fixing a field clears its own message; other messages stay.
        const touched = new Set(Object.keys(patch));
        if (touched.has('second'))
            ['second', 'pin'].forEach((k) => touched.add(k));
        if (touched.has('amount'))
            ['amount', 'amountReason', 'severity', 'immediate'].forEach((k) =>
                touched.add(k),
            );
        if (touched.has('obs'))
            Object.keys(patch.obs ?? {}).forEach((k) =>
                touched.add(`obs-${k}`),
            );
        if (touched.has('outcome')) touched.add('reason');
        setErrors((e) => {
            const n = { ...e };
            touched.forEach((k) => delete n[k]);
            return n;
        });
    };

    /* ── blocks ── (nothing-can-be-recorded answers never reach this form) */
    const block = activeBlock(req);
    const competencyStops = [
        'expired',
        'not_current',
        'restricted',
        'area',
    ].includes(req.competency.state);
    // Co-signer mode: a restricted worker's "given" needs a colleague who can
    // confirm it, and one never goes unconfirmed.
    const noCosigner =
        req.second_person.kind === 'cosigner' &&
        !req.second_person.anyone_available;
    const givenWhy =
        block?.copy.title ??
        (competencyStops
            ? 'Your competency doesn’t cover “given”'
            : noCosigner
              ? 'Nobody on shift can co-sign this dose'
              : null);
    const notYetDue = !isPrn && due?.state === 'notdue';
    // A re-offer needs today's refusal with its follow-up still open (Q8).
    const reofferClosed = mode === 'reoffer' && !req.reoffer;
    const tiles = outcomeTiles(
        req,
        mode,
        givenWhy ??
            (notYetDue
                ? `Not yet due — the window opens at ${timeLabel(due?.window_opens_at)}`
                : null),
    ).map((t) =>
        reofferClosed
            ? { ...t, disabled: 'This refusal can’t be offered again' }
            : t,
    );
    const givenLike = !!f.outcome && GIVEN_LIKE.includes(f.outcome);

    /* ── second person ── */
    const secondKind: SecondPersonKind | null = !givenLike
        ? null
        : (req.second_person.kind ??
          (f.amount.mode === 'less' ? 'amount' : null));
    const nobodyToConfirm = !req.second_person.anyone_available;
    // Only a medication rule's second person or a smaller amount may go
    // unconfirmed (Q2); a witness or co-signer never can.
    const unconfirmed =
        !req.witness_override &&
        (secondKind === 'rule' || secondKind === 'amount') &&
        nobodyToConfirm;

    /* ── controlled stock (NF-18, P0-2) ── */
    const controlledGiven = givenLike && req.order.controlled;
    const trackedOrdinaryGiven =
        givenLike && !req.order.controlled && req.order.stock?.tracked === true;
    const stockGiven = controlledGiven || trackedOrdinaryGiven;
    const fromOrder = req.order.stock?.from_order ?? false;
    // Less or more is always counted out of the stock; as ordered only when
    // the order's amount isn't in the stock's unit.
    const needsStockQuantity =
        stockGiven && (!fromOrder || f.amount.mode !== 'asOrdered');
    const givenInStock = fromOrder
        ? f.amount.mode === 'asOrdered'
            ? req.order.dose_amount
            : f.amount.amount
        : null;
    const takenFromStock = needsStockQuantity
        ? Number(f.stockQuantity) || 0
        : (givenInStock ?? 0);
    const wasted =
        controlledGiven && givenInStock != null && takenFromStock > givenInStock
            ? Number((takenFromStock - givenInStock).toFixed(2))
            : 0;
    // Same unit either way when the order's amount is in the stock's unit;
    // the order's spelling reads better ("1 tablet", "2 tablets").
    const stockUnit = fromOrder
        ? req.order.dose_unit
        : (req.order.stock?.unit ?? req.order.dose_unit);

    /* ── time and window ── */
    const nowLocal = nzLocal();
    const windowOpensLocal = due
        ? nzLocal(new Date(due.window_opens_at))
        : null;
    const windowClosesLocal = due
        ? nzLocal(new Date(due.window_closes_at))
        : null;
    const outsideWindow = !!(
        due &&
        givenLike &&
        f.when &&
        windowClosesLocal &&
        f.when > windowClosesLocal
    );
    const beforeWindow = !!(
        due &&
        givenLike &&
        f.when &&
        windowOpensLocal &&
        f.when < windowOpensLocal
    );
    const windowLabel = due
        ? `${timeLabel(due.window_opens_at)}–${timeLabel(due.window_closes_at)}`
        : '';

    /* ── validation (keeps values; focuses the first error) ── */
    function validateOutcome(): Errors {
        const e: Errors = {};
        if (!f.outcome) e.outcome = 'Choose what happened.';
        if (f.outcome === 'withheld' && !f.reason)
            e.reason = 'Choose a reason for withholding.';
        if (f.outcome === 'withheld' && f.reason === 'other' && !f.note.trim())
            e.note = 'Say what happened — “Other” needs a note.';
        if (f.outcome === 'away' && !f.reason)
            e.reason = `Choose where ${p} is.`;
        if (f.outcome === 'refused' && !isPrn && !f.followBy)
            e.followBy =
                'Choose when to follow up — offer again or record why not.';
        if (f.outcome === 'refused' && f.followBy && f.followBy < nowLocal)
            e.followBy = 'Choose a follow-up time after now.';
        if (givenLike) {
            if (
                shiftContext?.scanVerification &&
                !hasVerifiedMedicationScan(scanCapture)
            )
                e.scan =
                    'Check the packed medicine code before recording a given dose.';
            if (!f.when) e.when = 'Choose the date and time it was given.';
            else if (f.when > nowLocal)
                e.when = 'The time can’t be later than now.';
            else if (beforeWindow)
                e.when = `Early doses aren’t recorded — the window opens at ${timeLabel(due?.window_opens_at)}.`;
            else if (outsideWindow && !f.lateReason)
                e.lateReason = `Say why it’s outside the dose window (${windowLabel}).`;
            if (isPrn) {
                if (!f.prnReason) e.prnReason = 'Choose what it was for.';
                if (!f.checkBy)
                    e.checkBy = 'Choose when to check whether it helped.';
                else if (f.when && f.checkBy < f.when)
                    e.checkBy = 'Choose a check time after the dose was given.';
            }
            const ordered = req.order.dose_amount;
            if (f.amount.mode === 'less') {
                if (!(f.amount.amount && f.amount.amount > 0))
                    e.amount =
                        'Nothing given? Go back and record it as refused or withheld.';
                else if (ordered != null && f.amount.amount >= ordered)
                    e.amount = `That isn’t less than the order (${formatAmount(ordered, req.order.dose_unit)}). If more was given, use “More than ordered was given”.`;
                if (!f.amount.reason)
                    e.amountReason = 'Choose why less was given.';
            }
            if (f.amount.mode === 'more') {
                if (
                    !(
                        f.amount.amount &&
                        (ordered == null || f.amount.amount > ordered)
                    )
                )
                    e.amount = `Enter the amount actually given — more than ${formatAmount(ordered, req.order.dose_unit)}.`;
                if (!f.amount.severity)
                    e.severity = 'Choose how serious it seems.';
                if (!f.amount.immediate.trim())
                    e.immediate = 'Say what you did straight away.';
                if (!f.note.trim())
                    e.note =
                        'Say what happened — it goes into the medication error report.';
            }
            for (const o of req.observations) {
                for (const field of o.fields) {
                    const v = (f.obs[field] ?? '').trim();
                    if (!v)
                        e[`obs-${field}`] =
                            `Enter the ${o.label.toLowerCase()} — the medication rule asks for it.`;
                    else if (!/^\d+(\.\d+)?$/.test(v))
                        e[`obs-${field}`] = 'Enter the reading as a number.';
                }
            }
            if (secondKind && !unconfirmed) {
                if (!f.second.id)
                    e.second = `Choose who is ${secondKind === 'witness' ? 'witnessing' : 'confirming'}.`;
                else if (!/^\d{6}$/.test(f.second.pin))
                    e.pin = 'Enter their 6-digit PIN.';
            }
            if (needsStockQuantity && !(Number(f.stockQuantity) > 0))
                e.stockQuantity = controlledGiven
                    ? 'Enter how many were taken from the controlled-drug stock.'
                    : 'Enter how many were given from stock, in its own unit.';
            else if (
                needsStockQuantity &&
                givenInStock != null &&
                Number(f.stockQuantity) < givenInStock
            )
                e.stockQuantity =
                    'More was given than was taken from the stock. Enter how many were taken from the controlled-drug stock.';
            if (
                trackedOrdinaryGiven &&
                needsStockQuantity &&
                givenInStock != null &&
                Number(f.stockQuantity) !== givenInStock
            )
                e.stockQuantity =
                    'Enter the stock quantity actually given. Record any damaged or wasted stock separately with the house lead.';
            if (
                controlledGiven &&
                !/^\d+(\.\d{1,2})?$/.test(f.cdBalance.trim())
            )
                e.cdBalance =
                    'Count what’s left in the stock after this dose and enter it.';
        }
        return e;
    }
    const ORDER = [
        'outcome',
        'reason',
        'followBy',
        'prnReason',
        'second',
        'pin',
        'when',
        'lateReason',
        'amount',
        'amountReason',
        'severity',
        'immediate',
        'stockQuantity',
        'cdBalance',
        'checkBy',
        'note',
    ];
    function focusFirst(e: Errors) {
        const first = ORDER.find((k) => e[k]) ?? Object.keys(e)[0];
        window.setTimeout(() => {
            const el = bodyRef.current?.querySelector<HTMLElement>(
                `[data-field="${first}"] button:not([aria-disabled="true"]), [data-field="${first}"] input, [data-field="${first}"] textarea, [data-field="${first}"] [role="combobox"]`,
            );
            el?.focus();
        }, 30);
    }
    function next() {
        if (f.step === 0) return setF((x) => ({ ...x, step: 1 }));
        const e = validateOutcome();
        setErrors(e);
        if (Object.keys(e).length) return focusFirst(e);
        setF((x) => ({ ...x, step: 2 }));
    }

    /* ── save: one request key, duplicate-safe, offline-aware ── */
    function payload(): Record<string, unknown> {
        const status = givenLike
            ? 'given'
            : f.outcome === 'refused'
              ? 'refused'
              : 'withheld';
        const reasonCode =
            f.outcome === 'refused'
                ? 'refused'
                : f.outcome === 'withheld' || f.outcome === 'away'
                  ? f.reason
                  : null;
        const base: Record<string, unknown> = {
            client_medication_id: req.order.id,
            administered_at: givenLike ? `${f.when}:00` : `${nowLocal}:00`,
            notes: f.note.trim() || null,
            medication_round_id: roundId,
            shift_id: shiftContext?.shiftId,
            ...(givenLike && shiftContext?.scanVerification
                ? toMedicationScanPayload(scanCapture)
                : {}),
            witness_override_id:
                givenLike && req.witness_override && !f.second.id
                    ? req.witness_override.id
                    : null,
        };
        const second =
            secondKind && !unconfirmed && f.second.id
                ? {
                      witnessed_by: f.second.id,
                      witness_credential: f.second.pin,
                  }
                : secondKind && unconfirmed
                  ? { second_person_unavailable: true }
                  : {};
        const amount =
            givenLike && req.order.dose_amount != null
                ? {
                      amount_mode:
                          f.amount.mode === 'asOrdered'
                              ? 'as_ordered'
                              : f.amount.mode,
                      quantity_given:
                          f.amount.mode === 'asOrdered'
                              ? null
                              : f.amount.amount,
                      amount_reason:
                          f.amount.mode === 'less' ? f.amount.reason : null,
                      more_severity:
                          f.amount.mode === 'more' ? f.amount.severity : null,
                      more_immediate_action:
                          f.amount.mode === 'more'
                              ? f.amount.immediate.trim()
                              : null,
                  }
                : {};
        const observations = givenLike
            ? Object.fromEntries(
                  Object.entries(f.obs).map(([k, v]) => [
                      k,
                      v.trim() === '' ? null : Number(v),
                  ]),
              )
            : {};
        if (isPrn) {
            return {
                ...base,
                reason:
                    f.prnReason === 'Other'
                        ? f.note.trim() || 'Other'
                        : f.prnReason,
                effect_check_due_at: f.checkBy || null,
                quantity_administered: needsStockQuantity
                    ? Number(f.stockQuantity)
                    : null,
                cd_balance: controlledGiven ? Number(f.cdBalance) : null,
                ...second,
                ...amount,
                ...observations,
            };
        }
        return {
            ...base,
            scheduled_for:
                target.kind === 'scheduled' ? target.scheduledFor : null,
            status,
            reason_code: reasonCode,
            late_reason: givenLike && outsideWindow ? f.lateReason : null,
            follow_up_due_at:
                f.outcome === 'refused' ? f.followBy || null : null,
            reoffer_of_id:
                mode === 'reoffer' ? (req.reoffer?.refusal_id ?? null) : null,
            quantity_administered: needsStockQuantity
                ? Number(f.stockQuantity)
                : null,
            cd_balance: controlledGiven ? Number(f.cdBalance) : null,
            ...second,
            ...amount,
            ...observations,
        };
    }

    async function save() {
        const body = Object.fromEntries(
            Object.entries(payload()).filter(
                ([, v]) => v !== null && v !== undefined,
            ),
        );
        // The PIN is never part of the replay identity: re-typing it must not
        // create a new request (or a duplicate record).
        const material = { ...body };
        delete material.witness_credential;
        replay.current = prepareMedicationMutationReplayState(
            replay.current,
            material,
        );
        const needsPin = typeof body.witnessed_by === 'number';
        setPhase('sending');
        setServerMessage(null);
        try {
            const outcome = await submitEmarMutation<Record<string, unknown>>(
                isPrn ? '/meds/today/prn' : '/meds/today/record',
                { ...body, client_request_uuid: replay.current.uuid },
                {
                    action: isPrn ? 'prn' : 'administration',
                    // A witness PIN is checked live; it is never stored on the device.
                    allowQueueWhenOffline:
                        !needsPin && !body.witness_override_id,
                    queuedMessage: `Saved on this device — ${med} for ${p} isn’t on the chart yet. It will send when you reconnect. Don’t record it again.`,
                },
            );
            handleOutcome(outcome.status, outcome.data);
        } catch (error) {
            handleError(error);
        }
    }

    function handleOutcome(
        status: string,
        data: Record<string, unknown> | undefined,
    ) {
        if (status === 'queued') {
            onRecorded?.({ status: 'queued', administrationId: null });
            onClose();
            return;
        }
        if (
            status === 'requires_connection' ||
            status === 'storage_unavailable' ||
            status === 'requires_authentication'
        ) {
            setPhase('uncertain');
            return;
        }
        if (
            status === 'conflict' ||
            (status === 'duplicate' && data && data.replayed === false)
        ) {
            setDuplicateOf((data?.duplicate_of as typeof duplicateOf) ?? null);
            setPhase('duplicate');
            onRecorded?.({ status: 'duplicate', administrationId: null });
            return;
        }
        if (
            status === 'processed' ||
            status === 'synced' ||
            status === 'duplicate'
        ) {
            const administration = data?.administration as
                | { id?: number }
                | undefined;
            onRecorded?.({
                status: 'recorded',
                administrationId: administration?.id ?? null,
            });
            const error = data?.medication_error as
                | {
                      reference_number?: string | null;
                      client_incident_id?: number | null;
                  }
                | null
                | undefined;
            if (error) {
                // More than ordered: the error-and-incident confirmation, not a success pane.
                setErrorCreated({
                    reference: error.reference_number ?? null,
                    incidentId: error.client_incident_id ?? null,
                });
                return;
            }
            const lines = [
                `${med} for ${p}: ${(tiles.find((t) => t.key === f.outcome)?.label ?? 'recorded').toLowerCase()} at ${givenLike ? localLabel(f.when).split(' · ')[1] : localLabel(nowLocal).split(' · ')[1]}. It’s on ${p}’s chart.`,
            ];
            if (req.witness_override && !f.second.id && givenLike)
                lines.push(
                    'Recorded under an approved witness override · a house lead must take part in a witnessed count and sign off this dose.',
                );
            if (unconfirmed)
                lines.push(
                    secondKind === 'amount'
                        ? 'Different amount not confirmed by a second person · follow-up for the house lead'
                        : 'Not confirmed by a second person — nobody else on shift (from the roster) · follow-up for the house lead',
                );
            if (outsideWindow && f.lateReason)
                lines.push(
                    `Outside the dose window · ${req.options.late_reasons[f.lateReason] ?? f.lateReason}`,
                );
            if (f.outcome === 'refused' && f.followBy)
                lines.push(
                    `Follow-up · ${signedAs.name} · by ${localLabel(f.followBy).split(' · ')[1]}`,
                );
            if (isPrn && f.checkBy)
                lines.push(
                    `Check whether it helped · ${signedAs.name} · by ${localLabel(f.checkBy).split(' · ')[1]}`,
                );
            setResult({
                tone: lines.length > 1 ? 'warning' : 'success',
                lines,
            });
            setPhase('done');
            return;
        }
        setPhase('rejected');
        setServerMessage('The server didn’t confirm this was saved.');
    }

    const OUTCOME_FIELDS: Record<string, string> = {
        scan_code: 'scan',
        scan_verified: 'scan',
        witnessed_by: 'second',
        witness_credential: 'pin',
        late_reason: 'lateReason',
        reason: 'reason',
        reason_code: 'reason',
        quantity_given: 'amount',
        amount_reason: 'amountReason',
        more_severity: 'severity',
        more_immediate_action: 'immediate',
        notes: 'note',
        follow_up_due_at: 'followBy',
        effect_check_due_at: 'checkBy',
        administered_at: 'when',
        quantity_administered: 'stockQuantity',
        cd_balance: 'cdBalance',
        amount_mode: 'amount',
        blood_glucose_level: 'obs-blood_glucose_level',
        pulse_bpm: 'obs-pulse_bpm',
        blood_pressure_systolic: 'obs-blood_pressure_systolic',
        blood_pressure_diastolic: 'obs-blood_pressure_diastolic',
    };

    function handleError(error: unknown) {
        if (!axios.isAxiosError(error) || !error.response) {
            // No answer: it may or may not be saved. Trying again reuses the
            // same request key, so it can't create a duplicate.
            setPhase('uncertain');
            return;
        }
        if (error.response.status >= 500) {
            setPhase('uncertain');
            return;
        }
        const body = error.response.data as
            | {
                  error?: string;
                  error_field?: string;
                  message?: string;
                  errors?: Record<string, string[] | string>;
              }
            | undefined;
        const fieldErrors: Errors = {};
        if (
            readServerSyncOutcome(body).kind === 'rejected' &&
            body?.error_field
        ) {
            const key = OUTCOME_FIELDS[body.error_field];
            if (key) fieldErrors[key] = body.error ?? 'Check this field.';
        } else if (body?.errors) {
            for (const [field, messages] of Object.entries(body.errors)) {
                const key = OUTCOME_FIELDS[field];
                if (key)
                    fieldErrors[key] = Array.isArray(messages)
                        ? messages[0]
                        : messages;
            }
        }
        if (Object.keys(fieldErrors).length) {
            setPhase('edit');
            setErrors(fieldErrors);
            setF((x) => ({ ...x, step: fieldErrors.scan ? 0 : 1 }));
            focusFirst(fieldErrors);
            return;
        }
        setServerMessage(body?.error ?? body?.message ?? null);
        setPhase('rejected');
    }

    function requestClose() {
        if (phase === 'sending') return;
        if (phase === 'done' || phase === 'duplicate' || !dirty.current)
            return onClose();
        setDiscard(true);
    }

    const pct = useMemo(() => {
        const items = [
            f.step >= 1,
            !!f.outcome,
            givenLike ? !!f.when : !!(f.reason || f.outcome === 'refused'),
            f.step >= 2,
        ];
        return Math.round((items.filter(Boolean).length / items.length) * 100);
    }, [f, givenLike]);

    useEffect(() => {
        bodyRef.current
            ?.closest('[data-wizard-region="body"]')
            ?.scrollTo?.(0, 0);
    }, [f.step]);

    /* ── step 1: safety checks ── */
    const medCard = (
        <section
            aria-label="Medicine"
            className="space-y-3 rounded-xl border bg-card p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-section-title">{med}</p>
                    <p className="text-subtle">
                        {req.order.dosage ??
                            (req.order.dose_amount != null
                                ? formatAmount(
                                      req.order.dose_amount,
                                      req.order.dose_unit,
                                  )
                                : 'Dose not recorded')}
                        {req.order.route
                            ? ` · ${routeWords(req.order.route)}`
                            : ''}
                        {isPrn ? ' · as needed' : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {req.order.controlled ? (
                        <StatusBadge
                            variant="neutral"
                            className="rounded-[8px]"
                        >
                            <ShieldCheck className="size-3" /> Controlled
                        </StatusBadge>
                    ) : null}
                    {req.covert.state !== 'none' ? (
                        <StatusBadge
                            variant={
                                req.covert.state === 'missing'
                                    ? 'critical'
                                    : 'neutral'
                            }
                            className="rounded-[8px]"
                        >
                            <EyeOff className="size-3" /> Covert
                        </StatusBadge>
                    ) : null}
                    <SupportChip support={req.support} />
                </div>
            </div>
            <KV
                rows={[
                    [
                        'Instructions',
                        <span key="i">
                            {req.order.instructions ??
                                'No instructions recorded.'}{' '}
                            <span className="text-subtle">
                                (from the prescription)
                            </span>
                        </span>,
                    ],
                    ...(isPrn && req.prn
                        ? ([
                              [
                                  'Last 24 hours',
                                  <span key="l">
                                      {req.prn.count_24h} of{' '}
                                      {req.prn.max_24h ?? 'no limit'} doses
                                      {req.prn.last_at
                                          ? ` · last ${timeLabel(req.prn.last_at)}${req.prn.last_by ? ` by ${req.prn.last_by}` : ''}`
                                          : ''}{' '}
                                      · counted over the real last 24 hours
                                  </span>,
                              ],
                          ] as [ReactNode, ReactNode][])
                        : due
                          ? ([
                                [
                                    'Due',
                                    <span key="d">
                                        {timeLabel(due.due_at)} · today’s window{' '}
                                        {windowLabel}{' '}
                                        <span className="text-subtle">
                                            (organisation window:{' '}
                                            {due.before_minutes} min before to{' '}
                                            {due.after_minutes} min after)
                                        </span>
                                    </span>,
                                ],
                            ] as [ReactNode, ReactNode][])
                          : []),
                    [
                        'Order',
                        req.order.awaiting_check ? (
                            <StatusBadge
                                key="o"
                                variant="warning"
                                className="rounded-[8px]"
                            >
                                Waiting to be checked
                            </StatusBadge>
                        ) : (
                            <span key="o">
                                {req.order.verified?.by
                                    ? `Verified ${req.order.verified.at ? new Date(req.order.verified.at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ }) : ''} by ${req.order.verified.by}`
                                    : 'Verified'}
                                {req.order.prescriber
                                    ? ` · prescribed by ${req.order.prescriber}`
                                    : ''}
                            </span>
                        ),
                    ],
                    ...(req.covert.state !== 'none'
                        ? ([
                              [
                                  'Covert plan',
                                  req.covert.state === 'missing' ? (
                                      <span
                                          key="c"
                                          className="font-semibold text-status-critical"
                                      >
                                          No current authorisation on file
                                      </span>
                                  ) : (
                                      <span key="c">
                                          {req.covert.plan ??
                                              'Covert authorisation on file.'}
                                          {req.covert.review_date
                                              ? ` Review due ${new Date(`${req.covert.review_date}T12:00:00Z`).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })}.`
                                              : ''}
                                      </span>
                                  ),
                              ],
                          ] as [ReactNode, ReactNode][])
                        : []),
                    ...(req.order.witness_required
                        ? ([
                              [
                                  'Witness',
                                  <span key="w">
                                      Needed — a different person, clocked in on
                                      a shift covering this house, with witness
                                      competency and a witness PIN
                                  </span>,
                              ],
                          ] as [ReactNode, ReactNode][])
                        : []),
                    ...(req.observation_rule_sentences.length ||
                    req.second_person.rule_sentences.length
                        ? ([
                              [
                                  'Medication rules',
                                  <ul key="r" className="space-y-1">
                                      {[
                                          ...new Set([
                                              ...req.observation_rule_sentences,
                                              ...req.second_person
                                                  .rule_sentences,
                                          ]),
                                      ].map((s) => (
                                          <li key={s}>{s}</li>
                                      ))}
                                  </ul>,
                              ],
                          ] as [ReactNode, ReactNode][])
                        : []),
                ]}
            />
        </section>
    );

    const blockAction = (() => {
        if (!block) return null;
        if (block.copy.action === 'clock-in') {
            return (
                <Button asChild variant="outline" className="frontline-tap">
                    <a href="/attendance">
                        <LogIn className="size-4" /> Clock in
                    </a>
                </Button>
            );
        }
        if (block.copy.action === 'eligibility' && onEligibility) {
            return (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={onEligibility}
                >
                    <UserCheck className="size-4" /> View my eligibility
                </Button>
            );
        }
        if (block.copy.action === 'message-lead' && req.house_lead) {
            return (
                <Button asChild variant="outline" className="frontline-tap">
                    <a href="/operations/messages">
                        <MessageSquare className="size-4" /> Message{' '}
                        {req.house_lead.name}
                    </a>
                </Button>
            );
        }
        return null;
    })();

    const step0 = (
        <div className="space-y-4">
            {entry === 'transport' && transport ? (
                <Notice
                    tone="info"
                    icon={Truck}
                    title={`During transport · ${transport.vehicle}`}
                >
                    {transport.trip}
                    {transport.packed ? ` · packed ${transport.packed}` : ''}.
                    Locked from the transport you opened.
                </Notice>
            ) : null}
            <IdentityHeader req={req} />
            <AllergyNotice req={req} />
            {req.allergy.match && !req.block_given ? (
                <Notice
                    tone="critical"
                    live="alert"
                    title="Possible allergy match — check before giving"
                >
                    <MatchLine req={req} />
                    <p className="mt-1.5">
                        Your organisation warns on allergy matches. Check with
                        the prescriber before giving; you can still record it as
                        given.
                    </p>
                </Notice>
            ) : null}
            {medCard}
            {shiftContext?.scanVerification && (
                <div data-field="scan">
                    <MedicationScanVerificationPanel
                        clientId={req.person.id}
                        medicationId={req.order.id}
                        scanVerification={shiftContext.scanVerification}
                        onChange={setScanCapture}
                        title="Pack check"
                        requirementText="Check the code before recording a given dose. A refusal or withhold can still be recorded without a scan."
                    />
                    <InputError message={errors.scan} />
                </div>
            )}
            {!isPrn && due?.state === 'late' && !block ? (
                <Notice
                    tone="warning"
                    title={`This dose is late — ${due.late_minutes >= 60 ? `${Math.floor(due.late_minutes / 60)} h ${due.late_minutes % 60} min` : `${due.late_minutes} min`} after ${timeLabel(due.due_at)}`}
                >
                    It’s outside today’s window ({windowLabel}). Recording isn’t
                    blocked. No late-dose instruction is set for this medicine:{' '}
                    <NotConfigured /> Check with the on-call contact before
                    giving it: <Rich text={onCallText(req)} />
                </Notice>
            ) : null}
            {notYetDue ? (
                <Notice
                    tone="info"
                    title={`Not yet due — the window opens at ${timeLabel(due?.window_opens_at)}`}
                >
                    Early doses aren’t recorded from here. If {p} will be out
                    when it’s due, the dose can be packed for the trip and
                    recorded when it’s given.
                </Notice>
            ) : null}
            {entry === 'transport' ? (
                <div
                    data-field="packChecked"
                    className="flex items-center gap-2 rounded-lg border p-3"
                >
                    <Checkbox
                        id="rd-pack"
                        checked={f.packChecked}
                        onCheckedChange={(v) =>
                            set({ packChecked: v === true })
                        }
                    />
                    <Label htmlFor="rd-pack" className="text-sm font-normal">
                        I’ve checked the packed medicine against the label and
                        the person{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                </div>
            ) : null}
            {block ? (
                <BlockedPanel
                    copy={block.copy}
                    req={req}
                    action={blockAction}
                />
            ) : null}
        </div>
    );

    /* ── step 2: record outcome ── */
    const secondPerson = secondKind ? (
        <SecondPerson
            kind={secondKind}
            req={req}
            value={f.second}
            onChange={(v) => set({ second: v })}
            errors={errors}
            nobody={unconfirmed}
        />
    ) : null;
    const prnReasonTiles = [
        ...(req.prn?.reasons ?? []).map((r) => ({
            key: r,
            label: r,
            description: 'From the prescription',
            icon: Pill,
        })),
        {
            key: 'Other',
            label: 'Other',
            description: 'Say what in the note',
            icon: FileText,
        },
    ];

    const step1 = (
        <div className="space-y-4">
            <IdentityHeader req={req} compact />
            <AllergyNotice req={req} compact />
            {reofferClosed ? (
                <Notice
                    tone="warning"
                    icon={Link2}
                    title="This refusal can’t be offered again"
                >
                    Its follow-up is closed, or the refusal was on another day.
                    The refusal stays in the history; record anything new as a
                    separate note.
                </Notice>
            ) : null}
            {mode === 'reoffer' && req.reoffer ? (
                <Notice
                    tone="neutral"
                    icon={Link2}
                    title={`Linked to the refusal at ${timeLabel(req.reoffer.refused_at)}`}
                >
                    The refusal stays in the history. Re-offer rule:{' '}
                    <NotConfigured />
                </Notice>
            ) : null}
            {(block || noCosigner) && !isPrn ? (
                <Notice tone="critical" title={givenWhy}>
                    You can still record a refusal, a withhold or an absence.
                    Blocking checks apply to “given” only.
                </Notice>
            ) : null}
            <div data-field="outcome" className="space-y-2">
                <Label id="rd-oc-l" className="text-sm font-medium">
                    What happened?{' '}
                    <span className="text-status-critical">*</span>
                </Label>
                <TilePicker
                    frontline
                    labelledBy="rd-oc-l"
                    describedBy={errors.outcome ? 'rd-oc-e' : undefined}
                    invalid={!!errors.outcome}
                    value={f.outcome ?? ''}
                    options={tiles}
                    onChange={(k) =>
                        set({
                            outcome: k as Outcome,
                            reason: f.outcome === k ? f.reason : '',
                        })
                    }
                />
                <InputError id="rd-oc-e" message={errors.outcome} />
            </div>

            {f.outcome === 'withheld' || f.outcome === 'away' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <ReasonSelect
                        id="reason"
                        label={
                            f.outcome === 'withheld'
                                ? 'Reason for withholding'
                                : `Where is ${p}?`
                        }
                        value={f.reason}
                        onChange={(v) => set({ reason: v })}
                        options={
                            f.outcome === 'withheld'
                                ? req.options.withheld_reasons
                                : req.options.away_reasons
                        }
                        error={errors.reason}
                    />
                    <NoteField
                        value={f.note}
                        onChange={(v) => set({ note: v })}
                        error={errors.note}
                        placeholder="e.g. Felt sick after breakfast; will offer again at lunch if the plan allows."
                    />
                    {f.outcome === 'withheld' &&
                    (req.block_given || req.allergy.match) ? (
                        <p className="text-caption sm:col-span-2">
                            Saving a withhold is always allowed while a safety
                            check blocks “given”. The house lead and everyone
                            rostered at {req.person.house ?? 'the house'} see it
                            until it’s resolved.
                        </p>
                    ) : null}
                </div>
            ) : null}

            {f.outcome === 'refused' ? (
                <div className="space-y-3">
                    <NoteField
                        value={f.note}
                        onChange={(v) => set({ note: v })}
                        error={errors.note}
                        placeholder={`e.g. ${p} said they felt fine and didn’t want it today`}
                    />
                    {!isPrn ? (
                        <div
                            data-field="followBy"
                            className="space-y-2 rounded-xl border p-3"
                        >
                            <p className="text-sm font-medium">
                                Follow-up{' '}
                                <span className="text-subtle">
                                    — owner: you ({signedAs.name})
                                </span>
                            </p>
                            <DateTimeField
                                compact
                                id="rd-follow"
                                label="Follow up by"
                                value={f.followBy}
                                onChange={(v) => set({ followBy: v })}
                                error={errors.followBy}
                                clearable={false}
                                hint="Use the agreed re-offer time. If none is recorded, ask the medication lead; do not guess. Offer again, or record why not."
                            />
                            <p className="text-caption">
                                It shows in your follow-ups until you record a
                                result.
                            </p>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {givenLike ? (
                <div className="space-y-4">
                    {req.witness_override && !f.second.id ? (
                        <Notice
                            tone="warning"
                            title="Approved dose witness override"
                        >
                            Applies until{' '}
                            {formatDateTime(req.witness_override.expires_at)}. A
                            house lead must take part in a witnessed count after
                            this dose and sign it off by{' '}
                            {formatDateTime(
                                req.witness_override.followup_due_at,
                            )}
                            . This dose needs a connection to save.
                        </Notice>
                    ) : null}
                    {secondKind && secondKind !== 'amount'
                        ? secondPerson
                        : null}
                    {isPrn ? (
                        <div data-field="prnReason" className="space-y-2">
                            <Label
                                id="rd-prn-l"
                                className="text-sm font-medium"
                            >
                                What is it for?{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <TilePicker
                                frontline
                                labelledBy="rd-prn-l"
                                invalid={!!errors.prnReason}
                                value={f.prnReason}
                                onChange={(k) => set({ prnReason: k })}
                                options={prnReasonTiles}
                            />
                            <InputError message={errors.prnReason} />
                        </div>
                    ) : null}
                    <div data-field="when">
                        <DateTimeField
                            compact
                            id="rd-when"
                            label={
                                f.outcome === 'reoffered' ||
                                f.outcome === 'given'
                                    ? 'Given'
                                    : 'Taken'
                            }
                            value={f.when}
                            onChange={(v) => set({ when: v })}
                            error={errors.when}
                            clearable={false}
                            hint="Now by default. The exact minute is kept."
                        />
                    </div>
                    {outsideWindow ? (
                        <ReasonSelect
                            id="lateReason"
                            label={`Why is it outside the dose window (${windowLabel})?`}
                            value={f.lateReason}
                            onChange={(v) => set({ lateReason: v })}
                            options={req.options.late_reasons}
                            error={errors.lateReason}
                        />
                    ) : null}
                    <AmountField
                        req={req}
                        value={f.amount}
                        onChange={(patch) =>
                            set({ amount: { ...f.amount, ...patch } })
                        }
                        errors={errors}
                        secondKind={secondKind}
                        amountUnconfirmed={
                            secondKind === 'amount' && unconfirmed
                        }
                        secondPerson={
                            secondKind === 'amount' ? secondPerson : null
                        }
                    />
                    {stockGiven ? (
                        <section
                            role="group"
                            aria-labelledby="rd-cd-l"
                            className="space-y-3 rounded-xl border p-4"
                        >
                            <p
                                id="rd-cd-l"
                                className="flex items-center gap-2 text-sm font-semibold"
                            >
                                <ShieldCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />{' '}
                                {controlledGiven
                                    ? 'Controlled-drug stock'
                                    : 'Medicine stock'}
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {needsStockQuantity ? (
                                    <Field
                                        id="stockQuantity"
                                        label={
                                            controlledGiven
                                                ? 'Taken from the stock for this dose'
                                                : 'Given from stock for this dose'
                                        }
                                        required
                                        error={errors.stockQuantity}
                                        hint={
                                            stockUnit
                                                ? `In the stock’s unit: ${stockUnit}.`
                                                : 'The stock unit is unknown. Ask the house lead to reconcile it before recording.'
                                        }
                                    >
                                        <Input
                                            id="rd-stockQuantity"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            className="max-w-xs"
                                            value={f.stockQuantity}
                                            aria-invalid={
                                                !!errors.stockQuantity
                                            }
                                            onChange={(e) =>
                                                set({
                                                    stockQuantity:
                                                        e.target.value.replace(
                                                            /[^\d.]/g,
                                                            '',
                                                        ),
                                                })
                                            }
                                        />
                                    </Field>
                                ) : (
                                    <div className="space-y-1.5 text-sm">
                                        <p className="font-medium">
                                            {controlledGiven
                                                ? 'Taken from the stock for this dose'
                                                : 'Given from stock for this dose'}
                                        </p>
                                        <p>
                                            {formatAmount(
                                                takenFromStock,
                                                stockUnit,
                                            )}{' '}
                                            <span className="text-subtle">
                                                — the ordered amount
                                            </span>
                                        </p>
                                    </div>
                                )}
                                {controlledGiven ? (
                                    <Field
                                        id="cdBalance"
                                        label="Balance left after this dose"
                                        required
                                        error={errors.cdBalance}
                                        hint="Count what’s left in the stock. It’s checked against the register when you save."
                                    >
                                        <Input
                                            id="rd-cdBalance"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            className="max-w-xs"
                                            value={f.cdBalance}
                                            aria-invalid={!!errors.cdBalance}
                                            onChange={(e) =>
                                                set({
                                                    cdBalance:
                                                        e.target.value.replace(
                                                            /[^\d.]/g,
                                                            '',
                                                        ),
                                                })
                                            }
                                        />
                                    </Field>
                                ) : null}
                            </div>
                            {wasted > 0 ? (
                                <Notice
                                    tone="warning"
                                    title={`${formatAmount(wasted, stockUnit)} taken but not given`}
                                >
                                    It’s recorded in the controlled-drug
                                    register as wasted, witnessed by the person
                                    confirming this dose. The register comes
                                    down by everything taken.
                                </Notice>
                            ) : null}
                        </section>
                    ) : null}
                    {req.observations.flatMap((o) =>
                        o.fields.map((field, i) => (
                            <Field
                                key={field}
                                id={`obs-${field}`}
                                label={
                                    o.fields.length > 1
                                        ? `${o.label} (${i === 0 ? 'top number' : 'bottom number'})`
                                        : o.label
                                }
                                required
                                error={errors[`obs-${field}`]}
                                hint={`Required by the medication rule. No range is set here — follow ${p}’s plan for what the reading means.`}
                            >
                                <div className="flex max-w-xs items-center gap-2">
                                    <Input
                                        id={`rd-obs-${field}`}
                                        inputMode="decimal"
                                        autoComplete="off"
                                        value={f.obs[field] ?? ''}
                                        aria-invalid={!!errors[`obs-${field}`]}
                                        onChange={(e) =>
                                            set({
                                                obs: {
                                                    ...f.obs,
                                                    [field]: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        {o.unit}
                                    </span>
                                </div>
                            </Field>
                        )),
                    )}
                    {isPrn ? (
                        <div
                            data-field="checkBy"
                            className="space-y-2 rounded-xl border p-3"
                        >
                            <p className="text-sm font-medium">
                                Check whether it helped{' '}
                                <span className="text-subtle">
                                    — owner: you ({signedAs.name})
                                </span>
                            </p>
                            <DateTimeField
                                compact
                                id="rd-check"
                                label="Check by"
                                value={f.checkBy}
                                onChange={(v) => set({ checkBy: v })}
                                error={errors.checkBy}
                                clearable={false}
                                hint="Use the check time in the prescription or agreed support plan. If none is recorded, ask the medication lead; do not guess. The check stays open across shifts until someone records it."
                            />
                        </div>
                    ) : null}
                    <NoteField
                        value={f.note}
                        onChange={(v) => set({ note: v })}
                        error={errors.note}
                        required={f.amount.mode === 'more'}
                        placeholder={
                            f.amount.mode === 'more'
                                ? 'e.g. Gave 2 tablets instead of 1 — picked up the wrong pack. Noticed when signing.'
                                : 'e.g. Took it with yoghurt. Asked about side effects.'
                        }
                    />
                </div>
            ) : null}
        </div>
    );

    /* ── step 3: review & sign ── */
    const outcomeLabel = tiles.find((t) => t.key === f.outcome)?.label ?? '';
    const secondName =
        req.second_person.candidates.find((c) => c.id === f.second.id)?.name ??
        null;
    const reasonWordsFor = (code: string) =>
        req.options.withheld_reasons[code] ??
        req.options.away_reasons[code] ??
        code;
    const step2 = (
        <div className="space-y-4">
            <IdentityHeader req={req} compact />
            {phase === 'sending' ? (
                <Notice
                    tone="info"
                    icon={Loader2}
                    live="status"
                    title="Sending — not yet confirmed"
                >
                    Don’t close this window. Nothing shows as recorded until
                    it’s confirmed.
                </Notice>
            ) : null}
            {phase === 'rejected' ? (
                <Notice
                    tone="critical"
                    live="alert"
                    title="Not recorded — this dose was not saved."
                >
                    {serverMessage ? `${serverMessage} ` : ''}The chart doesn’t
                    show this dose. What you entered is kept. Ask a colleague
                    with current competency to give it, or record a refusal,
                    withhold or absence.
                </Notice>
            ) : null}
            {phase === 'uncertain' ? (
                <Notice
                    tone="warning"
                    live="alert"
                    title="Not confirmed — check before trying again"
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            className="frontline-tap"
                        >
                            <a href={`/emar/mar?client_id=${req.person.id}`}>
                                <Search className="size-4" /> Check the chart
                            </a>
                        </Button>
                    }
                >
                    We didn’t get confirmation that this was saved, so it isn’t
                    shown as recorded. Check {p}’s chart first. Trying again
                    won’t create a duplicate.
                </Notice>
            ) : null}
            {phase === 'duplicate' ? (
                <Notice
                    tone="warning"
                    live="alert"
                    title="Already recorded — nothing new was saved"
                >
                    {duplicateOf?.by
                        ? `${duplicateOf.by} recorded this dose${duplicateOf.status ? ` as ${duplicateOf.status}` : ''}${duplicateOf.administered_at ? ` at ${timeLabel(duplicateOf.administered_at)}` : ''}. `
                        : 'Someone else recorded this dose first. '}
                    Your entry wasn’t saved, so the dose isn’t on the chart
                    twice. If what you did differs, tell them and the house
                    lead.
                </Notice>
            ) : null}
            {req.allergy.match ? <MatchLine req={req} /> : null}
            <ReviewCard
                icon={Pill}
                title="Medicine"
                onEdit={
                    mode === 'record'
                        ? () => setF((x) => ({ ...x, step: 0 }))
                        : undefined
                }
            >
                <ReviewRow label="Medicine" value={med} />
                <ReviewRow
                    label="Ordered"
                    value={`${orderedText}${due ? ` · ${timeLabel(due.due_at)}` : ' · as needed'}`}
                />
                <ReviewRow label="Opened from" value={ENTRY_LABEL[entry]} />
            </ReviewCard>
            <ReviewCard
                icon={ClipboardCheck}
                title="Outcome"
                onEdit={() => setF((x) => ({ ...x, step: 1 }))}
            >
                <ReviewRow label="What happened" value={outcomeLabel} />
                {f.reason ? (
                    <ReviewRow
                        label="Reason"
                        value={reasonWordsFor(f.reason)}
                    />
                ) : null}
                {isPrn && f.prnReason ? (
                    <ReviewRow label="For" value={f.prnReason} />
                ) : null}
                {givenLike ? (
                    <ReviewRow
                        label="Amount given"
                        value={
                            <span className="inline-flex flex-wrap items-center gap-2">
                                {f.amount.mode === 'asOrdered'
                                    ? orderedText
                                    : formatAmount(
                                          f.amount.amount,
                                          req.order.dose_unit,
                                      )}{' '}
                                {f.amount.mode === 'less' ? (
                                    <StatusBadge variant="warning" size="sm">
                                        Less than ordered ·{' '}
                                        {req.options.amount_reasons[
                                            f.amount.reason
                                        ] ?? f.amount.reason}
                                    </StatusBadge>
                                ) : f.amount.mode === 'more' ? (
                                    <StatusBadge variant="critical" size="sm">
                                        More than ordered — medication error
                                    </StatusBadge>
                                ) : (
                                    <span className="text-subtle">
                                        as ordered
                                    </span>
                                )}
                            </span>
                        }
                    />
                ) : null}
                {givenLike && f.amount.mode === 'more' ? (
                    <ReviewRow
                        label="When you save"
                        value={`A medication error (severity: ${f.amount.severity}) and one linked incident are created. Immediate action: ${f.amount.immediate}`}
                    />
                ) : null}
                {stockGiven ? (
                    <ReviewRow
                        label="Taken from the stock"
                        value={formatAmount(takenFromStock, stockUnit)}
                    />
                ) : null}
                {controlledGiven && wasted > 0 ? (
                    <ReviewRow
                        label="Wasted (witnessed)"
                        value={formatAmount(wasted, stockUnit)}
                    />
                ) : null}
                {controlledGiven ? (
                    <ReviewRow
                        label="Balance left after this dose"
                        value={formatAmount(Number(f.cdBalance), stockUnit)}
                    />
                ) : null}
                <ReviewRow
                    label="Time"
                    value={`${localLabel(givenLike ? f.when : nowLocal)} ${nzZone()}`}
                />
                {outsideWindow ? (
                    <ReviewRow
                        label="Outside the window"
                        value={
                            req.options.late_reasons[f.lateReason] ??
                            f.lateReason
                        }
                    />
                ) : null}
                {req.observations.flatMap((o) =>
                    o.fields.map((field) => (
                        <ReviewRow
                            key={field}
                            label={o.label}
                            value={`${f.obs[field] ?? ''} ${o.unit}`}
                        />
                    )),
                )}
                {secondKind && !unconfirmed && secondName ? (
                    <ReviewRow
                        label={
                            secondKind === 'witness'
                                ? 'Witnessed by'
                                : secondKind === 'amount'
                                  ? 'Amount confirmed by'
                                  : secondKind === 'cosigner'
                                    ? 'Co-signed by'
                                    : 'Confirmed by'
                        }
                        value={
                            <span>
                                {secondName}{' '}
                                <StatusBadge variant="success" size="sm">
                                    PIN checked when saved
                                </StatusBadge>
                            </span>
                        }
                    />
                ) : null}
                {secondKind && unconfirmed ? (
                    <ReviewRow
                        label="Second person"
                        value={
                            <span>
                                <StatusBadge variant="warning" size="sm">
                                    Not confirmed by a second person
                                </StatusBadge>{' '}
                                nobody else on shift (from the roster) ·{' '}
                                {req.house_lead
                                    ? `${req.house_lead.name} (house lead)`
                                    : 'the house lead'}{' '}
                                gets a follow-up
                            </span>
                        }
                    />
                ) : null}
                {f.outcome === 'refused' && !isPrn ? (
                    <ReviewRow
                        label="Follow-up"
                        value={`Owner ${signedAs.name} · by ${localLabel(f.followBy)}`}
                    />
                ) : null}
                {isPrn && f.checkBy ? (
                    <ReviewRow
                        label="Check whether it helped"
                        value={`Owner ${signedAs.name} · by ${localLabel(f.checkBy)}`}
                    />
                ) : null}
                {f.note ? <ReviewRow label="Note" value={f.note} /> : null}
            </ReviewCard>
            <ReviewCard icon={User} title="Signed as">
                <ReviewRow
                    label="Name"
                    value={`${signedAs.name}${signedAs.role_label ? ` · ${signedAs.role_label}` : ''}`}
                />
            </ReviewCard>
        </div>
    );

    /* ── footer ── */
    // An as-needed dose is only ever "given", so a block on "given" stops it.
    const blockedContinue =
        f.step === 0 && isPrn && givenWhy
            ? `Can’t continue: ${givenWhy.charAt(0).toLowerCase()}${givenWhy.slice(1)}`
            : f.step === 0 && entry === 'transport' && !f.packChecked
              ? 'Tick the pack check to continue'
              : null;
    const lockedForward = mode !== 'record' && f.step === 1;
    const footerStart =
        phase === 'duplicate' ? null : f.step > 0 &&
          !lockedForward &&
          phase !== 'sending' ? (
            <Button
                className="frontline-tap"
                type="button"
                variant="ghost"
                onClick={() => setF((x) => ({ ...x, step: x.step - 1 }))}
            >
                <ChevronLeft className="size-4" /> Back
            </Button>
        ) : (
            <Button
                className="frontline-tap"
                type="button"
                variant="outline"
                onClick={requestClose}
                disabled={phase === 'sending'}
            >
                Cancel
            </Button>
        );
    const footerEnd = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {blockedContinue ? (
                <span id="rd-cant" className="text-caption">
                    {blockedContinue}
                </span>
            ) : null}
            {f.step > 0 &&
            phase !== 'sending' &&
            phase !== 'duplicate' &&
            !lockedForward ? (
                <Button
                    className="frontline-tap"
                    type="button"
                    variant="outline"
                    onClick={requestClose}
                >
                    Cancel
                </Button>
            ) : null}
            {f.step < 2 ? (
                <Button
                    className="frontline-tap"
                    type="button"
                    onClick={next}
                    disabled={!!blockedContinue}
                    aria-describedby={blockedContinue ? 'rd-cant' : undefined}
                >
                    Continue <ChevronRight className="size-4" />
                </Button>
            ) : phase === 'duplicate' ? (
                <Button
                    className="frontline-tap"
                    type="button"
                    onClick={onClose}
                >
                    Close
                </Button>
            ) : (
                <Button
                    className="frontline-tap"
                    type="button"
                    onClick={() => void save()}
                    disabled={phase === 'sending'}
                >
                    {phase === 'sending' ? (
                        <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                    ) : phase === 'uncertain' || phase === 'rejected' ? (
                        <RefreshCw className="size-4" />
                    ) : (
                        <Check className="size-4" />
                    )}
                    {phase === 'sending'
                        ? 'Sending…'
                        : phase === 'uncertain'
                          ? 'Try again'
                          : 'Record outcome'}
                </Button>
            )}
        </div>
    );

    const railTitle = isPrn
        ? 'Record as-needed dose'
        : mode === 'reoffer'
          ? 'Record re-offer'
          : mode === 'notgiven'
            ? 'Record not given'
            : 'Record dose';
    const success =
        phase === 'done' && result ? (
            <WizardSuccessPane
                title="Recorded"
                blurb={
                    <span className="block space-y-2">
                        {result.lines.map((l, i) => (
                            <span
                                key={i}
                                className={cn(
                                    'block',
                                    i > 0 && 'font-medium text-status-warning',
                                )}
                            >
                                {l}
                            </span>
                        ))}
                    </span>
                }
                actions={
                    <>
                        {onNext && nextLabel ? (
                            <Button
                                className="frontline-tap"
                                type="button"
                                variant="outline"
                                onClick={onNext}
                            >
                                {nextLabel} <ChevronRight className="size-4" />
                            </Button>
                        ) : null}
                        <Button
                            className="frontline-tap"
                            type="button"
                            onClick={onClose}
                            autoFocus
                        >
                            Done
                        </Button>
                    </>
                }
            />
        ) : undefined;

    if (errorCreated) {
        return (
            <ErrorCreatedDialog
                medicine={med}
                person={p}
                amount={formatAmount(f.amount.amount, req.order.dose_unit)}
                reference={errorCreated.reference}
                incidentId={errorCreated.incidentId}
                onClose={onClose}
            />
        );
    }

    return (
        <>
            <WizardShell
                frontline
                open
                onClose={requestClose}
                onCloseAutoFocus={(e) => {
                    const el = returnFocus?.();
                    if (el) {
                        e.preventDefault();
                        el.focus();
                    }
                }}
                title={`${railTitle} — ${p}, ${med}`}
                description="Safety checks, then what happened, then review and save."
                railIcon={Pill}
                railTitle={railTitle}
                railSub={`${p} · ${isPrn ? 'As needed' : timeLabel(due?.due_at)}`}
                steps={STEPS.map((x, i) => ({
                    ...x,
                    disabled:
                        i > f.step ||
                        (mode !== 'record' && i === 0) ||
                        phase === 'sending',
                }))}
                stepIndex={f.step}
                onStepClick={(i) => {
                    if (i <= f.step) setF((x) => ({ ...x, step: i }));
                }}
                pct={pct}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">
                                Opened from
                            </span>
                            <br />
                            {ENTRY_LABEL[entry]}
                        </p>
                        <p>
                            <span className="font-semibold text-foreground">
                                Signed as
                            </span>
                            <br />
                            {signedAs.name}
                            {signedAs.role_label ? (
                                <>
                                    <br />
                                    {signedAs.role_label}
                                </>
                            ) : null}
                        </p>
                    </div>
                }
                footerStart={footerStart}
                footerEnd={footerEnd}
                success={success}
                maxWidth="min(94vw, 1040px)"
                maxHeight="min(86vh, 780px)"
            >
                <WizardStepPane>
                    <div ref={bodyRef} className="space-y-4">
                        {f.step === 0 ? step0 : f.step === 1 ? step1 : step2}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this record?"
                description="Nothing has been saved. What you entered for this dose will be lost, and the dose stays as not yet recorded."
                confirmText="Discard"
                cancelText="Keep recording"
            />
        </>
    );
}
