import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ClipboardList,
    MessageSquareText,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    Choices,
    HARMS,
    labelFor,
    nzLocal,
    Picker,
    REACH,
    reportToken,
    TYPES,
    type Person,
} from '../errors/_shared';

export type ClientOption = Person & { site: string | null };
export function ReportErrorModal({
    open,
    onClose,
    clients,
    initialClientId,
    initialMedicationId,
    initialOccurredAt,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    initialClientId?: number | null;
    initialMedicationId?: number | null;
    initialOccurredAt?: string | null;
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [client, setClient] = useState<number | null>(
        initialClientId ?? null,
    );
    const [medicine, setMedicine] = useState<number | null>(
        initialMedicationId ?? null,
    );
    const [medicines, setMedicines] = useState<Person[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [retry, setRetry] = useState(0);
    const [kind, setKind] = useState('');
    const [initialOccurred] = useState(() =>
        nzLocal(initialOccurredAt ?? new Date().toISOString()),
    );
    const [occurred, setOccurred] = useState(initialOccurred);
    const [text, setText] = useState('');
    const [reach, setReach] = useState('');
    const [harm, setHarm] = useState('');
    const [immediate, setImmediate] = useState('');
    const [contributing, setContributing] = useState('');
    const [incident, setIncident] = useState(false);
    const [token, setToken] = useState(reportToken);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [duplicate, setDuplicate] = useState<number | null>(null);
    const [separateReason, setSeparateReason] = useState('');
    useEffect(() => {
        if (!open || !client) return;
        const controller = new AbortController();
        setLoading(true);
        setLoadError('');
        setMedicines([]);
        fetch(`/emar/errors/medicines/${client}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) throw new Error();
                return r.json();
            })
            .then((r: { medicines: Person[] }) => setMedicines(r.medicines))
            .catch(() => {
                if (!controller.signal.aborted)
                    setLoadError(
                        'The chart could not be loaded. Retry before reporting.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [client, open, retry]);
    const close = () => {
        if (!saving) onClose();
    };
    const submit = (asAccount = false) => {
        if (!navigator.onLine) {
            setErrors({
                connection:
                    'You’re offline. Reconnect to save; your report stays here.',
            });
            return;
        }
        setSaving(true);
        router.post(
            '/emar/errors',
            {
                client_id: client,
                client_medication_id: medicine,
                error_type: kind,
                occurred_at: occurred,
                reached_client: reach,
                harm_level: reach === 'no' ? 'none' : harm,
                description: text,
                immediate_action: immediate || null,
                contributing_factors: contributing || null,
                report_token: token,
                create_incident: incident,
                duplicate_id: asAccount ? duplicate : null,
                separate_reason: separateReason || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        asAccount
                            ? 'Account added'
                            : 'Medication error reported',
                    );
                    setStep(0);
                    setKind('');
                    setText('');
                    setReach('');
                    setHarm('');
                    setImmediate('');
                    setContributing('');
                    setIncident(false);
                    setErrors({});
                    setDuplicate(null);
                    setSeparateReason('');
                    setToken(reportToken());
                    onClose();
                },
                onError: (e) => {
                    setErrors(e);
                    setDuplicate(
                        e.duplicate_id ? Number(e.duplicate_id) : null,
                    );
                },
                onFinish: () => setSaving(false),
            },
        );
    };
    const steps = [
        {
            key: 'what',
            label: 'What happened',
            blurb: 'The person, medicine and time',
            icon: AlertTriangle,
        },
        {
            key: 'reach',
            label: 'Reach & harm',
            blurb: 'Two plain questions',
            icon: ClipboardList,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check, then send',
            icon: Check,
        },
    ];
    const firstOk =
        !!client &&
        !!kind &&
        !!text.trim() &&
        validLocalDateTime(occurred) &&
        !loading &&
        !loadError;
    const secondOk = !!reach && (reach === 'no' || !!harm);
    const requiredIncident =
        reach !== 'no' && ['moderate', 'severe', 'death'].includes(harm);
    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Report a medication error"
            description="Report what happened or add your account to an existing report."
            railIcon={AlertTriangle}
            railTitle="Report an error"
            railSubtitle="Medication errors"
            steps={steps}
            stepIndex={step}
            onStepClick={(i) => {
                if (!saving && i < step) setStep(i);
            }}
            completeness={{
                completed: [
                    client,
                    kind,
                    text.trim(),
                    validLocalDateTime(occurred),
                    reach,
                    reach === 'no' || harm,
                ].filter(Boolean).length,
                total: 6,
            }}
            formState={{
                isDirty:
                    client !== (initialClientId ?? null) ||
                    medicine !== (initialMedicationId ?? null) ||
                    occurred !== initialOccurred ||
                    Boolean(
                        kind ||
                        text ||
                        reach ||
                        harm ||
                        immediate ||
                        contributing ||
                        incident ||
                        separateReason,
                    ),
                processing: saving,
                errors: Object.fromEntries(
                    Object.entries(errors).filter(
                        ([key]) => key !== 'duplicate_id',
                    ),
                ),
            }}
            footer={
                <>
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={step ? () => setStep(step - 1) : close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                    {step < 2 ? (
                        <Button
                            disabled={
                                saving || (step === 0 ? !firstOk : !secondOk)
                            }
                            onClick={() => {
                                setErrors({});
                                setStep(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                saving ||
                                (!!duplicate && !separateReason.trim())
                            }
                            onClick={() => submit()}
                        >
                            {saving
                                ? 'Saving…'
                                : duplicate
                                  ? 'Send separate report'
                                  : 'Report error'}
                        </Button>
                    )}
                </>
            }
        >
            <div className="flex flex-col gap-5">
                {step === 0 ? (
                    <>
                        <Picker
                            label="Person"
                            value={client}
                            options={clients}
                            disabled={saving || !!initialClientId}
                            onChange={(id) => {
                                setClient(id);
                                setMedicine(null);
                                setDuplicate(null);
                            }}
                        />
                        <Picker
                            label="Medicine from the chart"
                            value={medicine}
                            options={medicines}
                            disabled={loading || !!loadError}
                            onChange={setMedicine}
                        />
                        <Button
                            variant="ghost"
                            onClick={() => setMedicine(null)}
                        >
                            Not about one chart medicine
                        </Button>
                        <p className="text-caption">
                            {loading
                                ? 'Loading chart…'
                                : 'Only medicines you may read are offered. Off-chart details can be recorded in your account.'}
                        </p>
                        {loadError && (
                            <div role="alert">
                                <p>{loadError}</p>
                                <Button
                                    variant="outline"
                                    onClick={() => setRetry(retry + 1)}
                                >
                                    Retry chart
                                </Button>
                            </div>
                        )}
                        <DateTimeField
                            id="error-occurred"
                            label="When it happened"
                            value={occurred}
                            onChange={setOccurred}
                            error={errors.occurred_at}
                        />
                        <Choices
                            label="What went wrong?"
                            value={kind}
                            options={TYPES}
                            onChange={setKind}
                        />
                        <div>
                            <Label htmlFor="error-account">
                                What happened?
                            </Label>
                            <Textarea
                                id="error-account"
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                rows={4}
                                maxLength={5000}
                            />
                            <InputError message={errors.description} />
                        </div>
                    </>
                ) : step === 1 ? (
                    <>
                        <Choices
                            label="Did it reach the person?"
                            value={reach}
                            options={REACH}
                            onChange={(v) => {
                                setReach(v);
                                if (v === 'no') setHarm('none');
                            }}
                        />
                        {reach && reach !== 'no' && (
                            <Choices
                                label="How much harm?"
                                value={harm}
                                options={HARMS}
                                onChange={setHarm}
                            />
                        )}
                        <div>
                            <Label htmlFor="error-immediate">
                                What was done straight away?
                            </Label>
                            <Textarea
                                id="error-immediate"
                                value={immediate}
                                onChange={(e) => setImmediate(e.target.value)}
                                maxLength={5000}
                            />
                        </div>
                        <div>
                            <Label htmlFor="error-contributing">
                                What might have contributed? (optional)
                            </Label>
                            <Textarea
                                id="error-contributing"
                                value={contributing}
                                onChange={(e) =>
                                    setContributing(e.target.value)
                                }
                                maxLength={5000}
                            />
                        </div>
                        {requiredIncident ? (
                            <p className="text-subtle">
                                One linked incident will be raised because of
                                the harm recorded.
                            </p>
                        ) : (
                            <Label className="frontline-tap flex items-center gap-2">
                                <Checkbox
                                    checked={incident}
                                    onCheckedChange={(v) =>
                                        setIncident(v === true)
                                    }
                                />
                                Also raise a linked incident
                            </Label>
                        )}
                    </>
                ) : (
                    <>
                        <ReviewCard
                            icon={MessageSquareText}
                            title="Your report"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Person"
                                value={
                                    clients.find((c) => c.id === client)?.name
                                }
                            />
                            <ReviewRow
                                label="Medicine"
                                value={
                                    medicines.find((m) => m.id === medicine)
                                        ?.name ?? 'Not about one chart medicine'
                                }
                            />
                            <ReviewRow
                                label="What went wrong"
                                value={labelFor(TYPES, kind)}
                            />
                            <ReviewRow
                                label="When"
                                value={localDateTimeLabel(occurred)}
                            />
                            <p className="mt-3 text-sm break-words whitespace-pre-wrap">
                                {text}
                            </p>
                        </ReviewCard>
                        <ReviewCard
                            icon={ClipboardList}
                            title="Reach & harm"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow
                                label="Reach"
                                value={labelFor(REACH, reach)}
                            />
                            <ReviewRow
                                label="Harm"
                                value={labelFor(HARMS, harm)}
                            />
                            <ReviewRow
                                label="Immediate action"
                                value={immediate}
                            />
                            <ReviewRow
                                label="Contributing factors"
                                value={contributing}
                            />
                            <ReviewRow
                                label="Linked incident"
                                value={
                                    incident || requiredIncident
                                        ? 'One incident'
                                        : 'Not requested'
                                }
                            />
                        </ReviewCard>
                        <p className="text-caption">
                            Your account stays in the permitted error record.
                            Incidents, Tasks and alerts receive a neutral
                            summary.
                        </p>
                        {duplicate && (
                            <ReviewCard
                                icon={AlertTriangle}
                                title="A report may already cover this event"
                            >
                                <p className="text-sm">{errors.duplicate}</p>
                                <Button
                                    variant="outline"
                                    disabled={saving}
                                    onClick={() => submit(true)}
                                >
                                    Add my account to that report
                                </Button>
                                <Label htmlFor="separate-reason">
                                    Or, why is this a separate event?
                                </Label>
                                <Textarea
                                    id="separate-reason"
                                    value={separateReason}
                                    onChange={(e) =>
                                        setSeparateReason(e.target.value)
                                    }
                                    maxLength={1000}
                                />
                            </ReviewCard>
                        )}
                    </>
                )}
            </div>
        </MedsWizardDialog>
    );
}
export default ReportErrorModal;
