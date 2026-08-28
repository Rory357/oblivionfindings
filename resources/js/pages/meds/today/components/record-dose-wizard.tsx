/* eslint-disable no-restricted-syntax -- Mirrors the Add Client wizard: the
 * five-rights toggle tiles and summary panels are intentionally styled native
 * controls (selector cards), with every colour from semantic design tokens. */
/* Record Dose wizard — safety checks (five rights) → outcome → review & sign.
 * Chrome follows the Add Client dialog contract via MedsWizardDialog; every
 * write goes through POST /meds/today/record → EnhancedMarService, so witness
 * rules, CD register entries and the audit trail run exactly like the admin
 * recording path. */
import {
    CdBadge,
    ClientSummaryCard,
    StatusPill,
} from '@/components/meds/board-bits';
import {
    MedsWizardDialog,
    SummaryRow,
    type MedsWizardStep,
} from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    FieldErr,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
} from '@/components/wizard/primitives';
import {
    createMedicationMutationReplayState,
    prepareMedicationMutationReplayState,
} from '@/lib/emar-offline';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Hand,
    Info,
    Loader2,
    Pill,
    Shield,
    ShieldAlert,
    Stethoscope,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import type {
    ClientInfo,
    NotGivenReasonOption,
    ScheduleRow,
    WitnessOption,
} from '../types';

type Outcome = 'given' | 'refused' | 'withheld';

const STEPS: MedsWizardStep[] = [
    {
        key: 'verify',
        label: 'Safety checks',
        blurb: 'Right person, med, dose',
        icon: Shield,
    },
    {
        key: 'record',
        label: 'Record outcome',
        blurb: 'Given, refused, withheld',
        icon: ClipboardCheck,
    },
    {
        key: 'review',
        label: 'Review & sign',
        blurb: 'Confirm to the MAR',
        icon: FileText,
    },
];

const FIVE_RIGHTS = [
    { key: 'person', label: 'Right person', desc: 'Photo + NHI match' },
    {
        key: 'medication',
        label: 'Right medication',
        desc: 'Label matches the MAR',
    },
    { key: 'dose', label: 'Right dose', desc: 'Strength and quantity' },
    { key: 'route', label: 'Right route', desc: 'As prescribed' },
    { key: 'time', label: 'Right time', desc: 'Within the dosing window' },
] as const;

/** Wizard fields that live on the "Record outcome" step — used to jump back
 * to it when the server rejects one of them. */
const RECORD_STEP_FIELDS = [
    'status',
    'reason',
    'reason_code',
    'administered_at',
    'witnessed_by',
    'witness_credential',
    'quantity_administered',
    'cd_balance',
    'blood_glucose_level',
    'pulse_bpm',
    'blood_pressure_systolic',
    'blood_pressure_diastolic',
    'notes',
];

function nowHm(): string {
    const d = new Date();
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function RecordDoseWizard({
    row,
    client,
    date,
    witnesses,
    notGivenReasons,
    signedAs,
    initialOutcome = 'given',
    onClose,
}: {
    row: ScheduleRow;
    client: ClientInfo | undefined;
    /** Board date (Y-m-d) — recorded times are anchored to this day. */
    date: string;
    witnesses: WitnessOption[];
    notGivenReasons: NotGivenReasonOption[];
    signedAs: { name: string; role_label: string | null };
    initialOutcome?: Outcome;
    onClose: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [rights, setRights] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const doseReplay = useRef(createMedicationMutationReplayState());

    const form = useForm({
        client_medication_id: row.medication_id,
        scheduled_for: row.scheduled_for,
        status: initialOutcome as Outcome,
        reason_code: '',
        reason: '',
        time: nowHm(),
        witnessed_by: '',
        witness_credential: '',
        quantity_administered: '',
        cd_balance: '',
        blood_glucose_level: '',
        pulse_bpm: '',
        blood_pressure_systolic: '',
        blood_pressure_diastolic: '',
        notes: '',
        client_request_uuid: doseReplay.current.uuid,
    });

    const outcome = form.data.status;
    const isGiven = outcome === 'given';
    const needsWitness = row.requires_witness && isGiven;
    const needsBalance = row.is_controlled && isGiven;
    const selectedReason = useMemo(
        () => notGivenReasons.find((r) => r.value === form.data.reason_code),
        [notGivenReasons, form.data.reason_code],
    );
    const reasonLabels = useMemo(
        () => notGivenReasons.map((r) => r.label),
        [notGivenReasons],
    );

    const err = (key: string): string | undefined =>
        errors[key] ?? (form.errors as Record<string, string>)[key];

    const toggleRight = (key: string) =>
        setRights((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );

    // Recording an overdue dose lands outside the MAR dosing window, and the
    // backend (rightly) refuses a late entry without a reason. Surface the
    // field up-front for overdue rows, and self-heal for any other
    // outside-window case the server flags.
    const needsLateReason =
        isGiven && (row.status === 'overdue' || !!form.errors.reason);

    const validateStep = (index: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (index === 0 && rights.length < FIVE_RIGHTS.length) {
            e.rights = 'Confirm all five rights before continuing';
        }
        if (index === 1) {
            if (!form.data.time) e.time = 'Enter the time';
            if (!isGiven && !form.data.reason_code)
                e.reason_code = 'Choose the reason this dose was not given';
            if (!isGiven && !form.data.reason.trim())
                e.reason = 'Describe what happened';
            if (needsLateReason && !form.data.reason.trim())
                e.reason = 'Explain why this dose is being recorded late';
            if (needsWitness && !form.data.witnessed_by)
                e.witnessed_by = 'A witness is required for this medication';
            if (
                needsWitness &&
                form.data.witnessed_by &&
                !form.data.witness_credential
            )
                e.witness_credential =
                    'The witness confirms by entering their password';
            if (needsBalance && form.data.quantity_administered === '')
                e.quantity_administered = 'Record how many units were given';
            if (needsBalance && form.data.cd_balance === '')
                e.cd_balance = 'Record the running balance';
        }
        return e;
    };

    const next = () => {
        const e = validateStep(stepIndex);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = () => {
        const initialPayload = {
            client_medication_id: form.data.client_medication_id,
            scheduled_for: form.data.scheduled_for,
            status: form.data.status,
            reason_code:
                form.data.status === 'given'
                    ? null
                    : form.data.reason_code || null,
            reason: form.data.reason.trim() || null,
            // No timezone suffix — the backend parses bare datetimes in the
            // worker timezone (Pacific/Auckland), matching the board's day.
            administered_at: `${date}T${form.data.time}:00`,
            witnessed_by: form.data.witnessed_by
                ? parseInt(form.data.witnessed_by, 10)
                : null,
            witness_credential: form.data.witness_credential || null,
            quantity_administered:
                form.data.quantity_administered === ''
                    ? null
                    : Number(form.data.quantity_administered),
            cd_balance:
                form.data.cd_balance === ''
                    ? null
                    : Number(form.data.cd_balance),
            blood_glucose_level:
                form.data.blood_glucose_level === ''
                    ? null
                    : Number(form.data.blood_glucose_level),
            pulse_bpm:
                form.data.pulse_bpm === '' ? null : Number(form.data.pulse_bpm),
            blood_pressure_systolic:
                form.data.blood_pressure_systolic === ''
                    ? null
                    : Number(form.data.blood_pressure_systolic),
            blood_pressure_diastolic:
                form.data.blood_pressure_diastolic === ''
                    ? null
                    : Number(form.data.blood_pressure_diastolic),
            notes: form.data.notes.trim() || null,
            client_request_uuid: doseReplay.current.uuid,
        };
        const {
            witness_credential: _witnessCredential,
            client_request_uuid: _clientRequestUuid,
            ...materialPayload
        } = initialPayload;
        doseReplay.current = prepareMedicationMutationReplayState(
            doseReplay.current,
            materialPayload,
        );
        const payload = {
            ...initialPayload,
            client_request_uuid: doseReplay.current.uuid,
        };
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            setErrors({
                connection:
                    'Reconnect before signing this dose. Witness credentials are never saved on this device.',
            });
            return;
        }
        form.transform(() => payload);
        form.post('/meds/today/record', {
            preserveScroll: true,
            onSuccess: () => {
                doseReplay.current = createMedicationMutationReplayState();
                onClose();
            },
            onError: (serverErrors) => {
                const first = Object.keys(serverErrors)[0];
                if (first && RECORD_STEP_FIELDS.includes(first)) {
                    setStepIndex(1);
                }
            },
        });
    };

    const isReview = stepIndex === 2;
    const outcomeLabel =
        outcome === 'given'
            ? 'Given'
            : outcome === 'refused'
              ? 'Refused'
              : 'Withheld';
    const witnessName = witnesses.find(
        (w) => String(w.id) === form.data.witnessed_by,
    )?.name;
    const unmappedError = Object.entries(form.errors).find(
        ([key]) => !RECORD_STEP_FIELDS.includes(key) && key !== 'rights',
    )?.[1];

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Record dose"
            description="A guided, audited walk-through for recording a scheduled medication dose to the MAR."
            railIcon={Pill}
            railTitle="Record dose"
            railSubtitle={`${client?.preferred ?? row.client_name} · ${row.time}`}
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                if (i < stepIndex) setStepIndex(i);
            }}
            railFooter={
                <div className="rounded-lg border border-border bg-card p-3 text-[11px] leading-relaxed text-muted-foreground">
                    <span className="font-bold text-foreground">Signed as</span>
                    <br />
                    {signedAs.name}
                    {signedAs.role_label ? (
                        <>
                            <br />
                            {signedAs.role_label}
                        </>
                    ) : null}
                </div>
            }
            footer={
                <>
                    <div>
                        {stepIndex > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={back}
                            >
                                <ChevronLeft className="h-4 w-4" /> Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2.5">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={form.processing}
                                data-test="meds-dose-submit"
                            >
                                {form.processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Recording…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" />
                                        Sign & record to MAR
                                    </>
                                )}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </>
            }
        >
            {stepIndex === 0 ? (
                <div
                    key="verify"
                    className="animate-in duration-300 fade-in slide-in-from-right-2"
                >
                    <StepHead
                        icon={Shield}
                        title="Safety checks"
                        blurb="Verify the five rights before anything is given."
                    />
                    <div className="grid gap-4">
                        <ClientSummaryCard
                            client={client}
                            fallbackName={row.client_name}
                        />

                        <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3.5">
                            <div className="flex items-center gap-3">
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <Pill className="h-5 w-5" />
                                </span>
                                <div>
                                    <div className="flex items-center gap-2 text-sm font-bold">
                                        {row.medication_name}
                                        {row.is_controlled ? <CdBadge /> : null}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {[row.dose, row.route]
                                            .filter(Boolean)
                                            .join(' · ')}
                                        {' · scheduled '}
                                        {row.time}
                                    </div>
                                </div>
                            </div>
                            <StatusPill status={row.status} />
                        </div>

                        {row.status === 'overdue' || row.status === 'missed' ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                <strong>This dose is overdue.</strong> It was
                                due at {row.time} and the dosing window has
                                passed. Record what actually happened — give it
                                now (you’ll be asked why it’s late), or mark it
                                refused/withheld with a reason. Don’t leave it
                                unrecorded.
                            </InfoCard>
                        ) : null}

                        {client && client.allergies.length > 0 ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                <strong>Allergies:</strong>{' '}
                                {client.allergies.join(', ')}. Check the label
                                against the allergy list before giving.
                            </InfoCard>
                        ) : (
                            <InfoCard icon={Info}>
                                No known medication allergies on file for{' '}
                                {client?.preferred ?? row.client_name}.
                            </InfoCard>
                        )}

                        <div>
                            <SubHead icon={ClipboardCheck}>
                                The five rights
                            </SubHead>
                            <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {FIVE_RIGHTS.map((r) => {
                                    const active = rights.includes(r.key);
                                    return (
                                        <button
                                            key={r.key}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => toggleRight(r.key)}
                                            className={cn(
                                                'flex items-center gap-2.5 rounded-lg border p-3 text-left transition-all hover:border-primary/50',
                                                active
                                                    ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                                    : 'border-border bg-card/50',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'grid h-6 w-6 shrink-0 place-items-center rounded-full border-2 transition-colors',
                                                    active
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-muted-foreground/30 text-transparent',
                                                )}
                                            >
                                                <Check className="h-3.5 w-3.5" />
                                            </span>
                                            <span>
                                                <span className="block text-sm font-semibold">
                                                    {r.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {r.desc}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <FieldErr>{err('rights')}</FieldErr>
                        </div>
                    </div>
                </div>
            ) : null}

            {stepIndex === 1 ? (
                <div
                    key="record"
                    className="animate-in duration-300 fade-in slide-in-from-right-2"
                >
                    <StepHead
                        icon={ClipboardCheck}
                        title="Record the outcome"
                        blurb="What happened with this dose?"
                    />
                    <div className="grid gap-4">
                        <Field label="Outcome" required>
                            <Segmented<Outcome>
                                value={outcome}
                                onChange={(v) => form.setData('status', v)}
                                options={[
                                    {
                                        value: 'given',
                                        label: 'Given',
                                        icon: Check,
                                    },
                                    {
                                        value: 'refused',
                                        label: 'Refused',
                                        icon: Hand,
                                    },
                                    {
                                        value: 'withheld',
                                        label: 'Withheld',
                                        icon: Ban,
                                    },
                                ]}
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={isGiven ? 'Time given' : 'Time recorded'}
                                required
                                error={err('time') ?? err('administered_at')}
                            >
                                <Input
                                    type="time"
                                    value={form.data.time}
                                    onChange={(e) =>
                                        form.setData('time', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Dose" hint="as prescribed">
                                <Input
                                    readOnly
                                    value={[row.dose, row.route]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    className="bg-muted/50 text-muted-foreground"
                                />
                            </Field>
                        </div>

                        {!isGiven ? (
                            <Field
                                label={
                                    outcome === 'refused'
                                        ? 'Refusal reason'
                                        : 'Withhold reason'
                                }
                                required
                                error={err('reason_code')}
                            >
                                <ChipMulti
                                    values={
                                        selectedReason
                                            ? [selectedReason.label]
                                            : []
                                    }
                                    onChange={(labels) => {
                                        const lastLabel =
                                            labels[labels.length - 1];
                                        const match = notGivenReasons.find(
                                            (r) => r.label === lastLabel,
                                        );
                                        form.setData(
                                            'reason_code',
                                            match?.value ?? '',
                                        );
                                    }}
                                    options={reasonLabels}
                                />
                            </Field>
                        ) : null}

                        {needsWitness ? (
                            <>
                                <InfoCard icon={ShieldAlert} tone="warn">
                                    <strong>
                                        {row.is_controlled
                                            ? 'Controlled drug.'
                                            : 'Witness required.'}
                                    </strong>{' '}
                                    A second med-competent staff member must
                                    witness this administration
                                    {row.is_controlled
                                        ? ' and the register balance'
                                        : ''}
                                    . They confirm by entering their own
                                    password.
                                </InfoCard>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Witnessed by"
                                        required
                                        error={err('witnessed_by')}
                                    >
                                        <SelectInput
                                            value={form.data.witnessed_by}
                                            onChange={(v) =>
                                                form.setData('witnessed_by', v)
                                            }
                                            placeholder="Choose a witness…"
                                            options={witnesses.map((w) => ({
                                                value: String(w.id),
                                                label: w.name,
                                            }))}
                                        />
                                    </Field>
                                    <Field
                                        label="Witness password"
                                        required
                                        hint="entered by the witness"
                                        error={err('witness_credential')}
                                    >
                                        <Input
                                            type="password"
                                            autoComplete="off"
                                            value={form.data.witness_credential}
                                            onChange={(e) =>
                                                form.setData(
                                                    'witness_credential',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {needsBalance ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Units given"
                                    required
                                    hint="removed from stock"
                                    error={err('quantity_administered')}
                                >
                                    <Input
                                        type="number"
                                        min={0.01}
                                        step="0.01"
                                        placeholder="e.g. 2"
                                        value={form.data.quantity_administered}
                                        onChange={(e) =>
                                            form.setData(
                                                'quantity_administered',
                                                e.target.value,
                                            )
                                        }
                                        className="max-w-[220px]"
                                    />
                                </Field>
                                <Field
                                    label="Running balance after dose"
                                    required
                                    hint="CD register"
                                    error={err('cd_balance')}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        placeholder="e.g. 26"
                                        value={form.data.cd_balance}
                                        onChange={(e) =>
                                            form.setData(
                                                'cd_balance',
                                                e.target.value,
                                            )
                                        }
                                        className="max-w-[220px]"
                                    />
                                </Field>
                            </div>
                        ) : null}

                        {!isGiven ? (
                            <InfoCard
                                icon={Info}
                                tone={outcome === 'refused' ? 'warn' : 'info'}
                            >
                                {outcome === 'refused'
                                    ? 'A refused dose is flagged on the MAR and reviewed by the team leader. Don’t re-offer past the dosing window without guidance.'
                                    : 'Withheld doses need a reason — they’re reviewed in the weekly medication audit.'}
                            </InfoCard>
                        ) : null}

                        {isGiven ? (
                            <div>
                                <SubHead icon={Stethoscope}>
                                    Clinical observations
                                    <span className="font-normal normal-case">
                                        — only if required for this medication
                                    </span>
                                </SubHead>
                                <div className="mt-2 grid gap-4 sm:grid-cols-3">
                                    <Field
                                        label="Blood glucose"
                                        hint="mmol/L"
                                        error={err('blood_glucose_level')}
                                    >
                                        <Input
                                            type="number"
                                            step="0.1"
                                            min={0}
                                            value={
                                                form.data.blood_glucose_level
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'blood_glucose_level',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Pulse"
                                        hint="bpm"
                                        error={err('pulse_bpm')}
                                    >
                                        <Input
                                            type="number"
                                            min={20}
                                            max={250}
                                            value={form.data.pulse_bpm}
                                            onChange={(e) =>
                                                form.setData(
                                                    'pulse_bpm',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Blood pressure"
                                        hint="sys / dia"
                                        error={
                                            err('blood_pressure_systolic') ??
                                            err('blood_pressure_diastolic')
                                        }
                                    >
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                type="number"
                                                min={40}
                                                max={300}
                                                placeholder="120"
                                                value={
                                                    form.data
                                                        .blood_pressure_systolic
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'blood_pressure_systolic',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <span className="text-muted-foreground">
                                                /
                                            </span>
                                            <Input
                                                type="number"
                                                min={20}
                                                max={200}
                                                placeholder="80"
                                                value={
                                                    form.data
                                                        .blood_pressure_diastolic
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'blood_pressure_diastolic',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </Field>
                                </div>
                            </div>
                        ) : null}

                        {needsLateReason ? (
                            <Field
                                label="Why is this being recorded outside the window?"
                                required
                                error={err('reason')}
                            >
                                <Textarea
                                    rows={2}
                                    placeholder="e.g. Client was out at an appointment — given as soon as they returned…"
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                />
                            </Field>
                        ) : null}

                        {!isGiven ? (
                            <Field
                                label="What happened?"
                                required
                                error={err('reason')}
                            >
                                <Textarea
                                    rows={3}
                                    placeholder="What happened, who was told, and any follow-up…"
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                />
                            </Field>
                        ) : (
                            <Field
                                label="Notes"
                                hint="optional"
                                error={err('notes')}
                            >
                                <Textarea
                                    rows={3}
                                    placeholder="Anything worth noting — taken with food, crushed per plan…"
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        )}
                    </div>
                </div>
            ) : null}

            {isReview ? (
                <div
                    key="review"
                    className="animate-in duration-300 fade-in slide-in-from-right-2"
                >
                    <StepHead
                        icon={FileText}
                        title="Review & sign"
                        blurb="This entry is written to the MAR and the audit trail."
                    />
                    <div className="grid gap-4">
                        <ClientSummaryCard
                            client={client}
                            fallbackName={row.client_name}
                        />
                        <div className="rounded-lg border border-border p-4">
                            <SummaryRow
                                label="Medication"
                                value={`${row.medication_name}${row.dose ? ` — ${row.dose}` : ''}`}
                            />
                            <SummaryRow
                                label="Route / scheduled"
                                value={`${row.route ?? '—'} · ${row.time}`}
                            />
                            <SummaryRow
                                label="Outcome"
                                value={outcomeLabel}
                                tone={
                                    outcome === 'given'
                                        ? 'success'
                                        : outcome === 'refused'
                                          ? 'crit'
                                          : undefined
                                }
                            />
                            <SummaryRow label="Time" value={form.data.time} />
                            {!isGiven && selectedReason ? (
                                <SummaryRow
                                    label="Reason"
                                    value={selectedReason.label}
                                />
                            ) : null}
                            {form.data.reason.trim() ? (
                                <SummaryRow
                                    label={isGiven ? 'Late reason' : 'Detail'}
                                    value={form.data.reason.trim()}
                                />
                            ) : null}
                            {needsWitness ? (
                                <SummaryRow
                                    label="Witness"
                                    value={witnessName ?? '—'}
                                />
                            ) : null}
                            {needsBalance && form.data.cd_balance !== '' ? (
                                <SummaryRow
                                    label="CD balance after dose"
                                    value={form.data.cd_balance}
                                />
                            ) : null}
                            {form.data.notes.trim() ? (
                                <SummaryRow
                                    label="Notes"
                                    value={form.data.notes.trim()}
                                />
                            ) : null}
                            <SummaryRow
                                label="Recorded by"
                                value={signedAs.name}
                            />
                        </div>
                        {unmappedError ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                {unmappedError}
                            </InfoCard>
                        ) : null}
                        <InfoCard icon={Shield}>
                            Signing records this against your account with a
                            timestamp. Entries can be amended but never deleted
                            — corrections are kept in the audit trail.
                        </InfoCard>
                    </div>
                </div>
            ) : null}
        </MedsWizardDialog>
    );
}

export default RecordDoseWizard;
