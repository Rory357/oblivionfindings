import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle2,
    Mail,
    Megaphone,
    MessageSquare,
    Send,
    Smartphone,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/**
 * Broadcast composer — guided steps so an urgent message can't go out
 * half-configured: message → audience → channels & delivery → review & send.
 */

const TEMPLATES: Record<string, string> = {
    fire_drill:
        'URGENT: Fire drill commencing now. All staff please follow evacuation procedures immediately. Assemble at designated muster points. Await further instructions from your shift lead.',
    missing_person:
        'ALERT: A resident has been reported missing. All available staff report to the control room immediately for a coordinated search. Do not leave your assigned area unattended.',
    severe_weather:
        'WEATHER ALERT: Severe weather warning in effect. Secure all outdoor areas, ensure all residents are indoors, and check emergency supplies. Monitor for further updates.',
    facility_lockdown:
        'LOCKDOWN: Facility lockdown is now in effect. Secure all entry and exit points. Keep all residents in their current locations. Await further instructions from the control room.',
    medication_recall:
        'MEDICATION RECALL: An urgent medication recall has been issued. All nursing staff: immediately check your medication stores and quarantine affected items. Contact pharmacy for guidance.',
    it_system_outage:
        'IT NOTICE: System outage affecting core applications. Switch to manual paper-based procedures. IT team is investigating. Expected resolution time will be communicated shortly.',
    custom: '',
};

const TEMPLATE_LABELS: Record<string, string> = {
    custom: 'Custom Message',
    fire_drill: 'Fire Drill',
    missing_person: 'Missing Person',
    severe_weather: 'Severe Weather',
    facility_lockdown: 'Facility Lockdown',
    medication_recall: 'Medication Recall',
    it_system_outage: 'IT System Outage',
};

const CHANNELS = [
    { key: 'in_app', label: 'In-App', icon: Bell },
    { key: 'push', label: 'Push Notification', icon: Smartphone },
    { key: 'sms', label: 'SMS', icon: MessageSquare },
    { key: 'email', label: 'Email', icon: Mail },
];

const ROLE_LABELS: Record<string, string> = {
    admin: 'Admin',
    coordinator: 'Coordinator',
    support_worker: 'Support Worker',
    shift_lead: 'Shift Lead',
    nurse: 'Nurse',
};

const STEPS = [
    {
        key: 'message',
        label: 'Message',
        blurb: 'Template & content',
        icon: Megaphone,
    },
    {
        key: 'audience',
        label: 'Audience',
        blurb: 'Who receives it',
        icon: Users,
    },
    {
        key: 'channels',
        label: 'Channels',
        blurb: 'How it reaches them',
        icon: Send,
    },
    {
        key: 'review',
        label: 'Review & send',
        blurb: 'Final check',
        icon: CheckCircle2,
    },
] as const;

export function BroadcastWizard({
    open,
    onClose,
    roles,
    roleCounts,
    totalStaff,
}: {
    open: boolean;
    onClose: () => void;
    roles: string[];
    roleCounts: Record<string, number>;
    totalStaff: number;
}) {
    const [step, setStep] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const [template, setTemplate] = useState('custom');
    const [content, setContent] = useState('');
    const [channels, setChannels] = useState<string[]>(['in_app']);
    const [targetRoles, setTargetRoles] = useState<string[]>([]);
    const [sendToAll, setSendToAll] = useState(false);
    const [forceDelivery, setForceDelivery] = useState(false);

    const reset = () => {
        setStep(0);
        setSubmitted(false);
        setTemplate('custom');
        setContent('');
        setChannels(['in_app']);
        setTargetRoles([]);
        setSendToAll(false);
        setForceDelivery(false);
    };

    const close = () => {
        reset();
        onClose();
    };

    const handleTemplateChange = (value: string) => {
        setTemplate(value);
        if (value !== 'custom') setContent(TEMPLATES[value] ?? '');
    };

    const toggle = (
        list: string[],
        set: (v: string[]) => void,
        value: string,
    ) =>
        set(
            list.includes(value)
                ? list.filter((v) => v !== value)
                : [...list, value],
        );

    const estimatedRecipients = useMemo(() => {
        if (sendToAll) return totalStaff;
        if (targetRoles.length === 0) return 0;
        return targetRoles.reduce(
            (sum, role) => sum + (roleCounts[role] ?? 0),
            0,
        );
    }, [sendToAll, targetRoles, roleCounts, totalStaff]);

    const stepValid =
        step === 0
            ? content.trim().length > 0
            : step === 1
              ? sendToAll || targetRoles.length > 0
              : step === 2
                ? channels.length > 0
                : true;

    const submit = () => {
        if (submitting) return;
        setSubmitting(true);
        router.post(
            '/control-room/broadcast',
            {
                content,
                channels,
                target_roles: sendToAll ? [] : targetRoles,
                send_to_all: sendToAll,
                template: template !== 'custom' ? template : null,
                force_delivery: forceDelivery,
            },
            {
                preserveScroll: true,
                onSuccess: (pg) => {
                    if (
                        !(pg.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        setSubmitted(true);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New broadcast"
            description="Send an urgent message to staff across multiple channels"
            railIcon={Megaphone}
            railTitle="New broadcast"
            railSub="Urgent staff comms"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => (i <= step ? setStep(i) : undefined)}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title="Broadcast sending"
                        blurb={`Delivering to ${estimatedRecipients} recipient${estimatedRecipients === 1 ? '' : 's'} via ${channels.map((c) => CHANNELS.find((ch) => ch.key === c)?.label ?? c).join(', ')}. Delivery status appears in the history below.`}
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    Send another
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Users className="h-3.5 w-3.5" />
                    {estimatedRecipients} recipient
                    {estimatedRecipients === 1 ? '' : 's'}
                    {channels.length > 1
                        ? ` × ${channels.length} channels`
                        : ''}
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
                            disabled={
                                submitting ||
                                !content.trim() ||
                                channels.length === 0 ||
                                (!sendToAll && targetRoles.length === 0)
                            }
                        >
                            <Send className="mr-1.5 h-4 w-4" />
                            {submitting ? 'Sending…' : 'Send broadcast'}
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Megaphone}
                            title="What's the message?"
                            blurb="Start from an emergency template or write your own — templates stay editable."
                        />
                        <Field label="Template">
                            <SelectInput
                                value={template}
                                onChange={handleTemplateChange}
                                placeholder="Select a template"
                                options={Object.entries(TEMPLATE_LABELS).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        </Field>
                        <Field
                            label="Message"
                            required
                            hint={`${content.length}/2000 characters`}
                        >
                            <Textarea
                                value={content}
                                onChange={(e) => setContent(e.target.value)}
                                rows={6}
                                maxLength={2000}
                                placeholder="Type your broadcast message here…"
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
                            title="Who should receive it?"
                            blurb="Everyone, or specific roles."
                        />
                        <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                            <Switch
                                checked={sendToAll}
                                onCheckedChange={setSendToAll}
                            />
                            <span className="text-sm font-medium text-foreground">
                                Send to all staff{' '}
                                <span className="text-muted-foreground">
                                    ({totalStaff} members)
                                </span>
                            </span>
                        </label>
                        {!sendToAll ? (
                            <Field label="Target roles" required>
                                <div className="flex flex-wrap gap-3">
                                    {roles.map((role) => (
                                        <label
                                            key={role}
                                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2"
                                        >
                                            <Checkbox
                                                checked={targetRoles.includes(
                                                    role,
                                                )}
                                                onCheckedChange={() =>
                                                    toggle(
                                                        targetRoles,
                                                        setTargetRoles,
                                                        role,
                                                    )
                                                }
                                            />
                                            <span className="text-sm">
                                                {ROLE_LABELS[role] ?? role} (
                                                {roleCounts[role] ?? 0})
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Send}
                            title="How should it reach them?"
                            blurb="Pick every channel that should carry this message."
                        />
                        <Field label="Channels" required>
                            <div className="flex flex-wrap gap-3">
                                {CHANNELS.map(({ key, label, icon: Icon }) => (
                                    <label
                                        key={key}
                                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2"
                                    >
                                        <Checkbox
                                            checked={channels.includes(key)}
                                            onCheckedChange={() =>
                                                toggle(
                                                    channels,
                                                    setChannels,
                                                    key,
                                                )
                                            }
                                        />
                                        <span className="flex items-center gap-1.5 text-sm">
                                            <Icon className="h-3.5 w-3.5" />
                                            {label}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </Field>
                        <label className="flex items-start gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/30 px-4 py-3">
                            <Switch
                                checked={forceDelivery}
                                onCheckedChange={setForceDelivery}
                            />
                            <span className="flex-1">
                                <span className="block text-sm font-medium text-status-critical">
                                    Force delivery (emergency)
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    Overrides recipients' Do Not Disturb and
                                    channel preferences. Use only for genuine
                                    emergencies (fire, lockdown, evacuation).
                                </span>
                            </span>
                        </label>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={CheckCircle2}
                            title="Review & send"
                            blurb="This goes out immediately and can't be recalled."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={Megaphone}
                                title="Message"
                                onEdit={() => setStep(0)}
                                span
                            >
                                <p className="text-sm whitespace-pre-wrap text-foreground">
                                    {content}
                                </p>
                            </ReviewCard>
                            <ReviewCard
                                icon={Users}
                                title="Audience"
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="Recipients"
                                    value={`${estimatedRecipients}`}
                                />
                                <ReviewRow
                                    label="Scope"
                                    value={
                                        sendToAll
                                            ? 'All staff'
                                            : targetRoles
                                                  .map(
                                                      (r) =>
                                                          ROLE_LABELS[r] ?? r,
                                                  )
                                                  .join(', ')
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Send}
                                title="Delivery"
                                onEdit={() => setStep(2)}
                            >
                                <ReviewRow
                                    label="Channels"
                                    value={channels
                                        .map(
                                            (c) =>
                                                CHANNELS.find(
                                                    (ch) => ch.key === c,
                                                )?.label ?? c,
                                        )
                                        .join(', ')}
                                />
                                <ReviewRow
                                    label="Force delivery"
                                    value={
                                        forceDelivery
                                            ? 'YES — overrides DND'
                                            : 'No'
                                    }
                                />
                            </ReviewCard>
                        </div>
                        {forceDelivery ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                Force delivery is ON — this bypasses every
                                recipient's Do Not Disturb and channel
                                preferences.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
