import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    CheckCircle2,
    ClipboardList,
    FileText,
    MapPin,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState, type ComponentType } from 'react';

/**
 * Manual alert creation — the operator raises an alert from scratch (source
 * 'manual'). Guided WizardShell: what → who & where → details → review, with
 * a success pane linking straight into the new alert's workspace.
 */

const ALERT_TYPES = [
    { value: 'welfare_check', label: 'Welfare check' },
    { value: 'security', label: 'Security concern' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'medical', label: 'Medical concern' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'maintenance', label: 'Urgent maintenance' },
    { value: 'environment', label: 'Environment / weather' },
    { value: 'other', label: 'Other (describe below)' },
];

const SEVERITY_TILES: Array<{
    key: string;
    label: string;
    description: string;
    icon: ComponentType<{ className?: string }>;
}> = [
    {
        key: 'low',
        label: 'Low',
        description: 'Routine — deal with in shift',
        icon: CheckCircle2,
    },
    {
        key: 'medium',
        label: 'Medium',
        description: 'Needs attention today',
        icon: Activity,
    },
    {
        key: 'high',
        label: 'High',
        description: 'Urgent operator response',
        icon: AlertTriangle,
    },
    {
        key: 'critical',
        label: 'Critical',
        description: 'Drop everything',
        icon: ShieldAlert,
    },
];

const STEPS = [
    { key: 'what', label: 'What', blurb: 'Type & severity', icon: Bell },
    { key: 'who', label: 'Who & where', blurb: 'Client · site', icon: Users },
    {
        key: 'details',
        label: 'Details',
        blurb: 'Notes & priority',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check & raise',
        icon: CheckCircle2,
    },
] as const;

export function AlertClientSelect({
    value,
    onChange,
    clients,
}: {
    value: string;
    onChange: (value: string) => void;
    clients: Array<{ id: number; name: string }>;
}) {
    return (
        <SelectInput
            value={value}
            onChange={onChange}
            placeholder="No client linked"
            options={clients.map((client) => ({
                value: String(client.id),
                label: client.name,
            }))}
        />
    );
}

export function NewAlertWizard({
    open,
    onClose,
    clients,
    sites,
    onOpenAlert,
}: {
    open: boolean;
    onClose: () => void;
    clients: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
    onOpenAlert?: (id: number) => void;
}) {
    const [step, setStep] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const flash = (
        usePage().props as {
            flash?: { created_alert_id?: number; error?: string };
        }
    ).flash;

    const form = useForm({
        source: 'manual',
        alert_type: '',
        custom_type: '',
        severity: 'medium',
        client_id: '',
        site_id: '',
        priority: '',
        notes: '',
    });

    const reset = () => {
        form.reset();
        form.clearErrors();
        setStep(0);
        setSubmitted(false);
    };

    const close = () => {
        reset();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            source: 'manual',
            alert_type:
                d.alert_type === 'other' && d.custom_type.trim()
                    ? d.custom_type.trim()
                    : d.alert_type,
            severity: d.severity,
            client_id: d.client_id ? Number(d.client_id) : null,
            site_id: d.site_id ? Number(d.site_id) : null,
            priority: d.priority || null,
            notes: d.notes || null,
        }));
        form.post('/control-room/alerts', {
            preserveScroll: true,
            onSuccess: (pg) => {
                if (!(pg.props as { flash?: { error?: string } }).flash?.error)
                    setSubmitted(true);
            },
        });
    };

    const typeLabel = ALERT_TYPES.find(
        (t) => t.value === form.data.alert_type,
    )?.label;
    const clientName = clients.find(
        (c) => String(c.id) === form.data.client_id,
    )?.name;
    const siteName = sites.find(
        (s) => String(s.id) === form.data.site_id,
    )?.name;
    const newId = flash?.created_alert_id;

    const stepValid =
        step === 0
            ? Boolean(
                  form.data.alert_type &&
                  form.data.severity &&
                  (form.data.alert_type !== 'other' ||
                      form.data.custom_type.trim()),
              )
            : true;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New alert"
            description="Raise a manual Control Room alert"
            railIcon={Bell}
            railTitle="New alert"
            railSub="Manual · operator desk"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => (i <= step ? setStep(i) : undefined)}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title="Alert raised"
                        blurb={`The alert is live on the operator desk${newId ? ` as CR-${newId}` : ''} and follows the normal triage lifecycle from here.`}
                        actions={
                            <>
                                {newId && onOpenAlert ? (
                                    <Button
                                        onClick={() => {
                                            const id = newId;
                                            close();
                                            onOpenAlert(id);
                                        }}
                                    >
                                        Open alert
                                    </Button>
                                ) : null}
                                <Button variant="outline" onClick={reset}>
                                    Raise another
                                </Button>
                                <Button variant="outline" onClick={close}>
                                    Done
                                </Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                <span className="text-xs text-muted-foreground">
                    Manual alerts join the same queues and SLAs as automatic
                    ones.
                </span>
            }
            footerEnd={
                <>
                    {step > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                    ) : null}
                    {step < STEPS.length - 1 ? (
                        <Button
                            size="sm"
                            onClick={() => setStep(step + 1)}
                            disabled={!stepValid}
                        >
                            Next
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={submit}
                            disabled={form.processing || !form.data.alert_type}
                        >
                            Raise alert
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Bell}
                            title="What's the alert?"
                            blurb="Pick the closest type and how urgent it is — severity drives queues and SLA clocks."
                        />
                        <Field
                            label="Alert type"
                            required
                            error={form.errors.alert_type}
                        >
                            <SelectInput
                                value={form.data.alert_type}
                                onChange={(v) => form.setData('alert_type', v)}
                                placeholder="Select a type"
                                options={ALERT_TYPES}
                            />
                        </Field>
                        {form.data.alert_type === 'other' ? (
                            <Field label="Describe the type" required>
                                <Input
                                    value={form.data.custom_type}
                                    onChange={(e) =>
                                        form.setData(
                                            'custom_type',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. power_outage"
                                />
                            </Field>
                        ) : null}
                        <Field
                            label="Severity"
                            required
                            error={form.errors.severity}
                        >
                            <TilePicker
                                value={form.data.severity}
                                onChange={(v) => form.setData('severity', v)}
                                options={SEVERITY_TILES}
                                cols={2}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Users}
                            title="Who and where?"
                            blurb="Link the client and site so the alert lands with the right context — both optional."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Client" error={form.errors.client_id}>
                                <AlertClientSelect
                                    value={form.data.client_id}
                                    onChange={(v) =>
                                        form.setData('client_id', v)
                                    }
                                    clients={clients}
                                />
                            </Field>
                            <Field label="Site" error={form.errors.site_id}>
                                <SelectInput
                                    value={form.data.site_id}
                                    onChange={(v) => form.setData('site_id', v)}
                                    placeholder="No site linked"
                                    options={sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    }))}
                                />
                            </Field>
                        </div>
                        <InfoCard icon={MapPin} tone="info">
                            If this is about a specific person, linking the
                            client keeps their record and the operator desk in
                            sync.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={FileText}
                            title="Details"
                            blurb="What should the operator know? A clear note saves a phone call."
                        />
                        <Field
                            label="Notes"
                            hint="What's happening, what's been done so far"
                            error={form.errors.notes}
                        >
                            <Textarea
                                rows={5}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="e.g. Neighbour reported the front door open since 6am…"
                            />
                        </Field>
                        <Field
                            label="Desk priority"
                            hint="Optional — orders the worklist"
                            error={form.errors.priority}
                        >
                            <SelectInput
                                value={form.data.priority}
                                onChange={(v) => form.setData('priority', v)}
                                placeholder="No priority"
                                options={[
                                    { value: 'critical', label: 'Critical' },
                                    { value: 'high', label: 'High' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'low', label: 'Low' },
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard
                            icon={Bell}
                            title="Alert"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Type"
                                value={
                                    form.data.alert_type === 'other'
                                        ? form.data.custom_type
                                        : typeLabel
                                }
                            />
                            <ReviewRow
                                label="Severity"
                                value={
                                    SEVERITY_TILES.find(
                                        (s) => s.key === form.data.severity,
                                    )?.label
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="Who & where"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow label="Client" value={clientName} />
                            <ReviewRow label="Site" value={siteName} />
                        </ReviewCard>
                        <ReviewCard
                            icon={ClipboardList}
                            title="Details"
                            onEdit={() => setStep(2)}
                            span
                        >
                            <ReviewRow
                                label="Priority"
                                value={
                                    form.data.priority
                                        ? form.data.priority
                                              .charAt(0)
                                              .toUpperCase() +
                                          form.data.priority.slice(1)
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Notes"
                                value={form.data.notes || undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
