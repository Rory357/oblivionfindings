/* eslint-disable no-restricted-syntax -- Mirrors the Add Client wizard: the
 * med radio-tiles and summary panels are intentionally styled native controls
 * (selector cards), with every colour from semantic design tokens. */
/* PRN wizard — choose med → reason & severity → dose & time → review & sign.
 * Chrome follows the Add Client dialog contract via MedsWizardDialog. Submits
 * to the existing POST /meds/today/prn endpoint (EnhancedMarService), with the
 * same offline queue behaviour the original quick sheet had. */
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
} from '@/components/wizard/primitives';
import {
    CdBadge,
    ClientAvatar,
    ClientSummaryCard,
} from '@/components/meds/board-bits';
import {
    MedsWizardDialog,
    SummaryRow,
    type MedsWizardStep,
} from '@/components/meds/wizard-shell';
import { submitOffline } from '@/lib/offline-queue';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    Info,
    Loader2,
    Pill,
    Shield,
    Stethoscope,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import type { ClientInfo, PrnMedication, WitnessOption } from '../types';

type Severity = 'mild' | 'moderate' | 'severe';

const STEPS: MedsWizardStep[] = [
    {
        key: 'choose',
        label: 'Choose med',
        blurb: 'Who needs it, and what',
        icon: Pill,
    },
    {
        key: 'reason',
        label: 'Reason',
        blurb: 'Symptoms and severity',
        icon: Stethoscope,
    },
    { key: 'dose', label: 'Dose & time', blurb: 'What was given', icon: Clock },
    {
        key: 'review',
        label: 'Review & sign',
        blurb: 'Confirm the record',
        icon: FileText,
    },
];

const DEFAULT_REASONS = [
    'Pain',
    'Fever',
    'Anxiety / agitation',
    'Breathing difficulty',
    'Headache',
    'Seizure warning signs',
];

/** Reason chips from the med's prescriber template (comma / pipe / newline
 * separated), falling back to the standard symptom list. */
function reasonChipsFor(med: PrnMedication | null): string[] {
    const templated = (med?.prn_reason ?? '')
        .split(/[\n,|]+/)
        .map((r) => r.trim())
        .filter((r) => r.length > 0)
        .slice(0, 6);
    return templated.length > 0 ? templated : DEFAULT_REASONS;
}

function nowHm(): string {
    const d = new Date();
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function PrnWizard({
    medications,
    clients,
    date,
    witnesses,
    signedAs,
    initialMedId = null,
    onClose,
}: {
    medications: PrnMedication[];
    clients: Map<number, ClientInfo>;
    /** Board date (Y-m-d) — recorded times are anchored to this day. */
    date: string;
    witnesses: WitnessOption[];
    signedAs: { name: string; role_label: string | null };
    initialMedId?: number | null;
    onClose: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [medId, setMedId] = useState<number | null>(initialMedId);
    const [reasons, setReasons] = useState<string[]>([]);
    const [otherReason, setOtherReason] = useState('');
    const [severity, setSeverity] = useState<Severity>('moderate');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const med = useMemo(
        () => medications.find((m) => m.id === medId) ?? null,
        [medications, medId],
    );
    const client = med ? clients.get(med.client_id) : undefined;
    const reasonChips = useMemo(() => reasonChipsFor(med), [med]);

    const form = useForm({
        client_medication_id: initialMedId,
        dose_given: '',
        time: nowHm(),
        witnessed_by: '',
        witness_credential: '',
        notes: '',
    });

    const err = (key: string): string | undefined =>
        errors[key] ?? (form.errors as Record<string, string>)[key];

    const reasonText = useMemo(() => {
        const picked = [...reasons];
        if (reasons.includes('Other') && otherReason.trim()) {
            picked.splice(picked.indexOf('Other'), 1, otherReason.trim());
        }
        const base = picked.join(', ');
        return base ? `${base} (${severity})` : '';
    }, [reasons, otherReason, severity]);

    const validateStep = (index: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (index === 0 && !medId) e.med = 'Choose a PRN medication';
        if (index === 1) {
            if (reasons.length === 0) e.reasons = 'Pick at least one reason';
            if (reasons.includes('Other') && !otherReason.trim())
                e.other = 'Describe the reason';
        }
        if (index === 2) {
            if (!form.data.dose_given.trim())
                e.dose_given = 'Enter the dose given';
            if (!form.data.time) e.time = 'Enter the time';
            if (med?.requires_witness && !form.data.witnessed_by)
                e.witnessed_by =
                    'A witness is required for this medication';
            if (
                med?.requires_witness &&
                form.data.witnessed_by &&
                !form.data.witness_credential
            )
                e.witness_credential =
                    'The witness confirms by entering their password';
        }
        return e;
    };

    const next = () => {
        const e = validateStep(stepIndex);
        setErrors(e);
        if (Object.keys(e).length) return;
        if (stepIndex === 0 && med) {
            form.setData('client_medication_id', med.id);
            if (!form.data.dose_given) {
                form.setData('dose_given', med.dose ?? '');
            }
        }
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = () => {
        if (!med) return;

        const payload = {
            client_medication_id: med.id,
            reason: reasonText,
            dose_given: form.data.dose_given,
            administered_at: `${date}T${form.data.time}:00`,
            witnessed_by: form.data.witnessed_by
                ? parseInt(form.data.witnessed_by, 10)
                : null,
            witness_credential: form.data.witness_credential || null,
            notes: form.data.notes.trim() || null,
        };

        // Offline: queue locally and replay on reconnect — the server dedupes
        // on client_request_uuid so a lost ACK doesn't double-record.
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            void submitOffline({
                action: 'prn',
                url: '/meds/today/prn',
                payload,
                queuedMessage:
                    'PRN saved on this device — we’ll send it when you’re back online.',
            }).then(() => {
                router.reload({ preserveScroll: true });
                onClose();
            });
            return;
        }

        form.transform(() => payload);
        form.post('/meds/today/prn', {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (serverErrors) => {
                const first = Object.keys(serverErrors)[0];
                if (
                    first &&
                    [
                        'witnessed_by',
                        'witness_credential',
                        'dose_given',
                        'administered_at',
                    ].includes(first)
                ) {
                    setStepIndex(2);
                } else if (first === 'reason') {
                    setStepIndex(1);
                } else if (first === 'client_medication_id') {
                    setStepIndex(0);
                }
            },
        });
    };

    const isReview = stepIndex === 3;
    const witnessName = witnesses.find(
        (w) => String(w.id) === form.data.witnessed_by,
    )?.name;

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Give as-needed med"
            description="A guided, audited walk-through for recording an as-needed (PRN) dose."
            railIcon={Zap}
            railTitle="Give as-needed med"
            railSubtitle="PRN record"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                if (i < stepIndex) setStepIndex(i);
            }}
            railFooter={
                <div className="rounded-lg border border-border bg-card p-3 text-[11px] leading-relaxed text-muted-foreground">
                    <span className="font-bold text-foreground">
                        PRN protocol
                    </span>
                    <br />
                    Check the reason matches the prescriber&rsquo;s indication,
                    respect minimum intervals, and record the effect within the
                    hour.
                </div>
            }
            footer={
                <>
                    <div>
                        {stepIndex > 0 ? (
                            <Button type="button" variant="ghost" onClick={back}>
                                <ChevronLeft className="h-4 w-4" /> Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2.5">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={form.processing}
                                data-test="meds-prn-submit"
                            >
                                {form.processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Recording…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" />
                                        Sign & record PRN
                                    </>
                                )}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                onClick={next}
                                data-test="meds-prn-continue"
                            >
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </>
            }
        >
            {stepIndex === 0 ? (
                <div
                    key="choose"
                    className="animate-in fade-in slide-in-from-right-2 duration-300"
                >
                    <StepHead
                        icon={Zap}
                        title="Which as-needed med?"
                        blurb="Only PRN meds for the clients on your shift are shown."
                    />
                    <div className="grid gap-2">
                        {medications.map((m) => {
                            const active = medId === m.id;
                            const limitText =
                                m.max_per_day !== null
                                    ? `${m.given_last_24h}/${m.max_per_day} today`
                                    : null;
                            return (
                                <button
                                    key={m.id}
                                    type="button"
                                    aria-pressed={active}
                                    aria-label={`Record as-needed dose of ${m.name} for ${m.client_name}`}
                                    onClick={() => setMedId(m.id)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border p-3.5 text-left transition-all hover:border-primary/50',
                                        active
                                            ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                            : 'border-border bg-card/50',
                                    )}
                                >
                                    <ClientAvatar
                                        name={m.client_name}
                                        clientId={m.client_id}
                                        className="h-10 w-10 text-xs"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                            {m.client_name} — {m.name}{' '}
                                            {m.dose ?? ''}
                                            {m.is_controlled ? <CdBadge /> : null}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {[
                                                m.prn_reason,
                                                limitText,
                                                m.min_hours_between
                                                    ? `min ${m.min_hours_between} h apart`
                                                    : null,
                                                m.last_given_label
                                                    ? `last given ${m.last_given_label}`
                                                    : 'not given before',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
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
                                </button>
                            );
                        })}
                    </div>
                    <FieldErr>{err('med')}</FieldErr>
                    {med?.interval_blocked ? (
                        <div className="mt-4">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                <strong>Minimum interval not yet reached.</strong>{' '}
                                {med.name} was last given {med.last_given_label};
                                the {med.min_hours_between} hour minimum means
                                the next dose is from{' '}
                                <strong>{med.next_allowed_label}</strong>.
                                Continue only with team-leader approval.
                            </InfoCard>
                        </div>
                    ) : null}
                    {med?.over_limit ? (
                        <div className="mt-4">
                            <InfoCard icon={AlertTriangle} tone="crit">
                                Already given {med.given_last_24h} of{' '}
                                {med.max_per_day} in the last 24 hours. Don&rsquo;t
                                give another dose without checking with your
                                supervisor first.
                            </InfoCard>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {stepIndex === 1 && med ? (
                <div
                    key="reason"
                    className="animate-in fade-in slide-in-from-right-2 duration-300"
                >
                    <StepHead
                        icon={Stethoscope}
                        title={`Why does ${client?.preferred ?? med.client_name} need it?`}
                        blurb={
                            med.prn_reason
                                ? `Prescriber's indication: ${med.prn_reason}.`
                                : 'Record the symptoms you observed.'
                        }
                    />
                    <div className="grid gap-4">
                        <Field label="Reason" required error={err('reasons') ?? err('reason')}>
                            <ChipMulti
                                values={reasons}
                                onChange={setReasons}
                                options={[...reasonChips, 'Other']}
                            />
                        </Field>
                        {reasons.includes('Other') ? (
                            <Field
                                label="Something else"
                                required
                                error={err('other')}
                            >
                                <Input
                                    value={otherReason}
                                    onChange={(e) =>
                                        setOtherReason(e.target.value)
                                    }
                                    placeholder="Describe the reason"
                                />
                            </Field>
                        ) : null}
                        <Field label="Severity">
                            <Segmented<Severity>
                                value={severity}
                                onChange={setSeverity}
                                options={[
                                    { value: 'mild', label: 'Mild' },
                                    { value: 'moderate', label: 'Moderate' },
                                    { value: 'severe', label: 'Severe' },
                                ]}
                            />
                        </Field>
                        {severity === 'severe' ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                Severe symptoms: consider escalating to the
                                on-call nurse or 111 first — a PRN isn&rsquo;t a
                                substitute for urgent care.
                            </InfoCard>
                        ) : null}
                        <Field label="What did you observe?" hint="optional">
                            <Textarea
                                rows={3}
                                placeholder="e.g. Holding jaw and asking for pain relief after lunch…"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </div>
            ) : null}

            {stepIndex === 2 && med ? (
                <div
                    key="dose"
                    className="animate-in fade-in slide-in-from-right-2 duration-300"
                >
                    <StepHead
                        icon={Clock}
                        title="Dose & time"
                        blurb="Record exactly what was given, and when."
                    />
                    <div className="grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Dose given"
                                required
                                hint={med.dose ? `prescribed ${med.dose}` : undefined}
                                error={err('dose_given')}
                            >
                                <Input
                                    value={form.data.dose_given}
                                    onChange={(e) =>
                                        form.setData(
                                            'dose_given',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Time given"
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
                        </div>
                        {med.requires_witness ? (
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
                        ) : null}
                        {med.max_per_day !== null || med.min_hours_between ? (
                            <InfoCard icon={Info}>
                                Limits for this PRN:
                                {med.max_per_day !== null ? (
                                    <>
                                        {' '}
                                        max <strong>
                                            {med.max_per_day}×
                                        </strong>{' '}
                                        in 24 hours
                                    </>
                                ) : null}
                                {med.max_per_day !== null &&
                                med.min_hours_between
                                    ? ','
                                    : null}
                                {med.min_hours_between ? (
                                    <>
                                        {' '}
                                        minimum{' '}
                                        <strong>
                                            {med.min_hours_between} hours
                                        </strong>{' '}
                                        between doses
                                    </>
                                ) : null}
                                .
                            </InfoCard>
                        ) : null}
                        <InfoCard icon={Info}>
                            A follow-up reminder appears on your board until the
                            effect of this dose is recorded — aim to check within
                            the hour.
                        </InfoCard>
                    </div>
                </div>
            ) : null}

            {isReview && med ? (
                <div
                    key="review"
                    className="animate-in fade-in slide-in-from-right-2 duration-300"
                >
                    <StepHead
                        icon={FileText}
                        title="Review & sign"
                        blurb="This is written to the PRN register and the MAR."
                    />
                    <div className="grid gap-4">
                        <ClientSummaryCard
                            client={client}
                            fallbackName={med.client_name}
                        />
                        <div className="rounded-lg border border-border p-4">
                            <SummaryRow
                                label="Medication"
                                value={`${med.name} — ${form.data.dose_given || (med.dose ?? '')}`}
                            />
                            <SummaryRow label="Route" value={med.route ?? '—'} />
                            <SummaryRow
                                label="Reason"
                                value={reasonText || '—'}
                                tone={severity === 'severe' ? 'crit' : undefined}
                            />
                            <SummaryRow
                                label="Time given"
                                value={form.data.time}
                            />
                            {med.requires_witness ? (
                                <SummaryRow
                                    label="Witness"
                                    value={witnessName ?? '—'}
                                />
                            ) : null}
                            {form.data.notes.trim() ? (
                                <SummaryRow
                                    label="Observations"
                                    value={form.data.notes.trim()}
                                />
                            ) : null}
                            <SummaryRow
                                label="Recorded by"
                                value={signedAs.name}
                            />
                        </div>
                        {err('reason') ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                {err('reason')}
                            </InfoCard>
                        ) : null}
                        <InfoCard icon={Shield}>
                            PRN entries appear on the MAR and the PRN register,
                            and a follow-up effect check is queued on your board.
                        </InfoCard>
                    </div>
                </div>
            ) : null}
        </MedsWizardDialog>
    );
}

export default PrnWizard;
