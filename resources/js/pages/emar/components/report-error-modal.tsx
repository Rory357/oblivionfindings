/* Report medication error — BUILD-NEW Action-centre modal on the shared
 * Add-Client wizard chrome (MedsWizardDialog + wizard/primitives). Posts to
 * emar.errors.store (MedicationErrorController@store). */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, Info, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ClientOption = { id: number; name: string; site: string | null };

const STEPS = [
    {
        key: 'what',
        label: 'What happened',
        blurb: 'Client & type',
        icon: AlertTriangle,
    },
    {
        key: 'class',
        label: 'Classification',
        blurb: 'Severity & factors',
        icon: ClipboardList,
    },
    {
        key: 'sign',
        label: 'Actions & sign',
        blurb: 'Submit report',
        icon: ShieldCheck,
    },
];

const ERROR_TYPES = [
    { value: 'wrong_medication', label: 'Wrong medication' },
    { value: 'wrong_client', label: 'Wrong client' },
    { value: 'wrong_dose', label: 'Wrong dose' },
    { value: 'wrong_time', label: 'Wrong time' },
    { value: 'wrong_route', label: 'Wrong route' },
    { value: 'omission', label: 'Omission' },
    { value: 'unauthorised', label: 'Unauthorised' },
    { value: 'documentation', label: 'Documentation' },
    { value: 'other', label: 'Other' },
];

const SEVERITIES = [
    { value: 'near_miss', label: 'Near miss' },
    { value: 'minor', label: 'Minor' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'major', label: 'Major' },
    { value: 'critical', label: 'Critical' },
];

const labelOf = (opts: { value: string; label: string }[], v: string) =>
    opts.find((o) => o.value === v)?.label ?? '—';

export function ReportErrorModal({
    open,
    onClose,
    clients,
    initialClientId,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    initialClientId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [clientId, setClientId] = useState(
        initialClientId ? String(initialClientId) : '',
    );
    const [errorType, setErrorType] = useState('');
    const [description, setDescription] = useState('');
    const [severity, setSeverity] = useState('');
    const [contributing, setContributing] = useState('');
    const [immediate, setImmediate] = useState('');
    const [createIncident, setCreateIncident] = useState('no');
    const [reachedClient, setReachedClient] = useState('');
    const [openDisclosure, setOpenDisclosure] = useState('na');

    const reset = () => {
        setStep(0);
        setClientId(initialClientId ? String(initialClientId) : '');
        setErrorType('');
        setDescription('');
        setSeverity('');
        setContributing('');
        setImmediate('');
        setCreateIncident('no');
        setReachedClient('');
        setOpenDisclosure('na');
    };

    const close = () => {
        reset();
        onClose();
    };

    const step1Ok = clientId && errorType && description.trim().length > 0;
    const step2Ok = severity.length > 0;
    const seriousIncidentNeedsAction =
        createIncident === 'yes' && ['major', 'critical'].includes(severity);

    const submit = () => {
        setSaving(true);
        router.post(
            '/emar/errors',
            {
                client_id: Number(clientId),
                error_type: errorType,
                severity,
                reached_client: reachedClient || null,
                open_disclosure: openDisclosure || null,
                description,
                immediate_action: immediate || null,
                contributing_factors: contributing || null,
                create_incident: createIncident === 'yes',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Medication error reported');
                    close();
                },
                onError: () => toast.error('Could not submit the error report'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const clientName =
        clients.find((c) => String(c.id) === clientId)?.name ?? '—';

    const footer = (
        <>
            <Button
                variant="ghost"
                onClick={step === 0 ? close : () => setStep((s) => s - 1)}
                disabled={saving}
            >
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step < 2 ? (
                <Button
                    onClick={() => setStep((s) => s + 1)}
                    disabled={
                        (step === 0 && !step1Ok) || (step === 1 && !step2Ok)
                    }
                >
                    Continue
                </Button>
            ) : (
                <Button
                    onClick={submit}
                    disabled={
                        saving ||
                        (seriousIncidentNeedsAction && !immediate.trim())
                    }
                >
                    <ShieldCheck className="h-4 w-4" />
                    {saving ? 'Submitting…' : 'Submit error report'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Report a medication error"
            description="Record a medication error or near miss for review."
            railIcon={AlertTriangle}
            railTitle="Report med error"
            railSubtitle="Error & near-miss log"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={AlertTriangle}
                        title="What happened"
                        blurb="Identify the client and the kind of error."
                    />
                    <Field label="Client" required>
                        <SelectInput
                            value={clientId}
                            onChange={setClientId}
                            placeholder="Select client"
                            options={clients.map((c) => ({
                                value: String(c.id),
                                label: c.site
                                    ? `${c.name} · ${c.site}`
                                    : c.name,
                            }))}
                        />
                    </Field>
                    <Field label="Error type" required>
                        <SelectInput
                            value={errorType}
                            onChange={setErrorType}
                            placeholder="Select type"
                            options={ERROR_TYPES}
                        />
                    </Field>
                    <Field label="What happened" required span>
                        <Textarea
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            rows={4}
                            placeholder="Describe the error factually — what, when, who was involved."
                        />
                    </Field>
                    <InfoCard icon={Info}>
                        An undocumented omission is itself a medication error in
                        NZ practice. Reporting near misses helps prevent harm —
                        this is a no-blame record.
                    </InfoCard>
                </div>
            ) : step === 1 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={ClipboardList}
                        title="Classification"
                        blurb="Rate the severity and note contributing factors."
                    />
                    <Field label="Severity" required span>
                        <SelectInput
                            value={severity}
                            onChange={setSeverity}
                            placeholder="Select severity"
                            options={SEVERITIES}
                        />
                    </Field>
                    <Field label="Did the error reach the client?" span>
                        <SelectInput
                            value={reachedClient}
                            onChange={setReachedClient}
                            placeholder="Choose"
                            options={[
                                {
                                    value: 'no',
                                    label: 'No — intercepted (near miss territory)',
                                },
                                {
                                    value: 'yes',
                                    label: 'Yes — reached the client',
                                },
                                { value: 'unknown', label: 'Unknown' },
                            ]}
                        />
                    </Field>
                    <Field label="Contributing factors" span>
                        <Textarea
                            value={contributing}
                            onChange={(e) => setContributing(e.target.value)}
                            rows={3}
                            placeholder="Workload, similar packaging, interruptions, etc. (optional)"
                        />
                    </Field>
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={ShieldCheck}
                        title="Actions & sign"
                        blurb="Record what was done and submit."
                    />
                    <Field
                        label="Immediate action taken"
                        required={seriousIncidentNeedsAction}
                        span
                    >
                        <Textarea
                            value={immediate}
                            onChange={(e) => setImmediate(e.target.value)}
                            rows={3}
                            placeholder={
                                seriousIncidentNeedsAction
                                    ? 'Required: record the clinical or safety action actually taken.'
                                    : 'Clinical response, who was notified, observations (optional)'
                            }
                        />
                    </Field>
                    <Field label="Raise an incident?">
                        <SelectInput
                            value={createIncident}
                            onChange={setCreateIncident}
                            placeholder="Choose"
                            options={[
                                { value: 'no', label: 'No — error log only' },
                                {
                                    value: 'yes',
                                    label: 'Yes — also create an incident',
                                },
                            ]}
                        />
                    </Field>
                    <Field label="Family / open disclosure">
                        <SelectInput
                            value={openDisclosure}
                            onChange={setOpenDisclosure}
                            placeholder="Choose"
                            options={[
                                { value: 'na', label: 'Not required' },
                                { value: 'pending', label: 'Pending' },
                                { value: 'done', label: 'Completed' },
                            ]}
                        />
                    </Field>
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Client" value={clientName} />
                            <SummaryRow
                                label="Error type"
                                value={labelOf(ERROR_TYPES, errorType)}
                            />
                            <SummaryRow
                                label="Severity"
                                value={labelOf(SEVERITIES, severity)}
                                tone={
                                    ['major', 'critical'].includes(severity)
                                        ? 'crit'
                                        : undefined
                                }
                            />
                            <SummaryRow
                                label="Incident"
                                value={
                                    createIncident === 'yes'
                                        ? 'Will be raised'
                                        : 'No'
                                }
                            />
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        Submitting records this to the medication error register
                        with an audit entry.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default ReportErrorModal;
