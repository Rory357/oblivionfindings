import { DiscardDraftConfirm, usePopupClose } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { StatusVariant } from '@/components/ui/status-badge';
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
import { useMemo, useRef, useState } from 'react';

/**
 * New broadcast — guided steps so an urgent message can't go out
 * half-configured: message → audience → channels → review and send. The
 * review step stays even though there are few fields: a broadcast reaches
 * many people and can't be recalled.
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

/** Template names, shared with the broadcast list and record pages. */
export const BROADCAST_TEMPLATE_LABELS: Record<string, string> = {
    custom: 'Custom message',
    fire_drill: 'Fire drill',
    missing_person: 'Missing person',
    severe_weather: 'Severe weather',
    facility_lockdown: 'Facility lockdown',
    medication_recall: 'Medication recall',
    it_system_outage: 'IT system outage',
};

/** Channel names, shared with the broadcast list and record pages. */
export const BROADCAST_CHANNEL_LABELS: Record<string, string> = {
    in_app: 'In-app',
    push: 'Push notification',
    sms: 'SMS',
    email: 'Email',
};

export function channelList(channels: string[]): string {
    return channels.map((c) => BROADCAST_CHANNEL_LABELS[c] ?? c).join(', ');
}

/**
 * A broadcast's delivery as recorded. "Delivered" means the channel confirmed
 * it — never that it was read or acknowledged, which are separate outcomes.
 */
export function broadcastDeliveryState(
    total: number,
    delivered: number,
    failed: number,
): { label: string; tone: StatusVariant } {
    if (total > 0 && delivered === total)
        return { label: 'Delivered', tone: 'success' };
    if (failed > 0 && delivered === 0)
        return { label: 'Not delivered', tone: 'critical' };
    if (failed > 0) return { label: 'Some not delivered', tone: 'warning' };
    return { label: 'Not confirmed yet', tone: 'info' };
}

const CHANNELS = [
    { key: 'in_app', label: BROADCAST_CHANNEL_LABELS.in_app, icon: Bell },
    { key: 'push', label: BROADCAST_CHANNEL_LABELS.push, icon: Smartphone },
    { key: 'sms', label: BROADCAST_CHANNEL_LABELS.sms, icon: MessageSquare },
    { key: 'email', label: BROADCAST_CHANNEL_LABELS.email, icon: Mail },
];

const ROLE_LABELS: Record<string, string> = {
    admin: 'Admin',
    coordinator: 'Coordinator',
    support_worker: 'Support worker',
    shift_lead: 'Shift lead',
    nurse: 'Nurse',
};

const STEPS = [
    {
        key: 'message',
        label: 'Message',
        blurb: 'What staff need to know',
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
        label: 'Review and send',
        blurb: 'Check before sending',
        icon: CheckCircle2,
    },
] as const;

const UNCONFIRMED =
    "We couldn't confirm the broadcast was sent — the connection may have dropped. Check the broadcast list before sending again, so staff don't get it twice.";

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
    const [error, setError] = useState<string | null>(null);
    const [unconfirmed, setUnconfirmed] = useState(false);
    const settled = useRef(false);

    const [template, setTemplate] = useState('custom');
    const [content, setContent] = useState('');
    const [channels, setChannels] = useState<string[]>(['in_app']);
    const [targetRoles, setTargetRoles] = useState<string[]>([]);
    const [sendToAll, setSendToAll] = useState(false);
    const [forceDelivery, setForceDelivery] = useState(false);

    const reset = () => {
        setStep(0);
        setSubmitted(false);
        setError(null);
        setUnconfirmed(false);
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

    // Closing with a message typed asks the one "Discard this draft?".
    const dirty = !submitted && content.trim().length > 0;
    const guard = usePopupClose({ dirty, processing: submitting, onClose: close });

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

    const channelNames = channels
        .map((c) => CHANNELS.find((ch) => ch.key === c)?.label ?? c)
        .join(', ');

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
                onStart: () => {
                    settled.current = false;
                    setSubmitting(true);
                    setError(null);
                    setUnconfirmed(false);
                },
                onSuccess: (pg) => {
                    settled.current = true;
                    const flashError = (
                        pg.props as { flash?: { error?: string } }
                    ).flash?.error;
                    if (flashError) setError(flashError);
                    else setSubmitted(true);
                },
                onError: (errors) => {
                    settled.current = true;
                    setError(
                        Object.values(errors)[0] ??
                            "The broadcast wasn't sent. Check the message and try again.",
                    );
                },
                onCancel: () => {
                    settled.current = true;
                },
                onFinish: () => {
                    setSubmitting(false);
                    if (!settled.current) setUnconfirmed(true);
                },
            },
        );
    };

    const checkBroadcasts = () => {
        setUnconfirmed(false);
        close();
        router.reload({ only: ['broadcasts'], preserveScroll: true });
    };

    return (
        <>
            <WizardShell
                open={open && !guard.asking}
                onClose={guard.requestClose}
                title="New broadcast"
                description="Prepare, review and send an urgent message to staff"
                railIcon={Megaphone}
                railTitle="New broadcast"
                railSub="Urgent message to staff"
                steps={STEPS}
                stepIndex={step}
                onStepClick={(i) => (i <= step ? setStep(i) : undefined)}
                success={
                    submitted ? (
                        <WizardSuccessPane
                            title="Broadcast is being sent"
                            blurb={`Going to ${estimatedRecipients} ${estimatedRecipients === 1 ? 'person' : 'people'} by ${channelNames}. It's now in the broadcast list, with what was delivered to each person.`}
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
                        {estimatedRecipients}{' '}
                        {estimatedRecipients === 1 ? 'person' : 'people'}
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
                                disabled={submitting}
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
                        ) : unconfirmed ? (
                            <Button size="sm" onClick={checkBroadcasts}>
                                Check broadcasts
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
                                title="What do staff need to know?"
                                blurb="Start from an emergency template or write your own. Templates stay editable."
                            />
                            <Field label="Template">
                                <SelectInput
                                    value={template}
                                    onChange={handleTemplateChange}
                                    placeholder="Choose a template"
                                    options={Object.entries(
                                        BROADCAST_TEMPLATE_LABELS,
                                    ).map(([value, label]) => ({ value, label }))}
                                />
                            </Field>
                            <Field
                                label="Message"
                                required
                                hint={`${content.length} of 2,000 characters`}
                            >
                                <Textarea
                                    value={content}
                                    onChange={(e) => setContent(e.target.value)}
                                    rows={6}
                                    maxLength={2000}
                                    placeholder="Type the message staff will receive…"
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
                                title="Who needs this message?"
                                blurb="Everyone you can reach, or only some roles. Your Site access still applies."
                            />
                            <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                                <Switch
                                    checked={sendToAll}
                                    onCheckedChange={setSendToAll}
                                />
                                <span className="text-sm font-medium text-foreground">
                                    Send to all staff{' '}
                                    <span className="text-muted-foreground">
                                        ({totalStaff}{' '}
                                        {totalStaff === 1 ? 'person' : 'people'})
                                    </span>
                                </span>
                            </label>
                            {!sendToAll ? (
                                <Field label="Roles" required>
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
                                blurb="Choose every channel that should carry this message."
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
                            <label className="flex items-start gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg px-4 py-3">
                                <Switch
                                    checked={forceDelivery}
                                    onCheckedChange={setForceDelivery}
                                />
                                <span className="flex-1">
                                    <span className="block text-sm font-medium text-status-critical">
                                        Force delivery (emergency)
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Ignores each person&apos;s Do Not Disturb and
                                        channel choices. Use only for real
                                        emergencies such as a fire, lockdown or
                                        evacuation.
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
                                title="Check before sending"
                                blurb="It goes out straight away and can't be recalled."
                            />
                            {unconfirmed ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    {UNCONFIRMED}
                                </InfoCard>
                            ) : null}
                            {error ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {error}
                                </InfoCard>
                            ) : null}
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
                                        label="People"
                                        value={`${estimatedRecipients}`}
                                    />
                                    <ReviewRow
                                        label="Who"
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
                                    <ReviewRow label="Channels" value={channelNames} />
                                    <ReviewRow
                                        label="Force delivery"
                                        value={
                                            forceDelivery
                                                ? 'Yes — ignores Do Not Disturb'
                                                : 'No'
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Permissions and channels are checked again when you
                                send. Sending and delivery are reported separately
                                for each person on the broadcast&apos;s page.
                            </InfoCard>
                            {forceDelivery ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    Force delivery is on — this ignores every
                                    person&apos;s Do Not Disturb and channel choices.
                                </InfoCard>
                            ) : null}
                        </div>
                    </WizardStepPane>
                ) : null}
            </WizardShell>
            <DiscardDraftConfirm
                open={open && guard.asking}
                what="New broadcast"
                onKeepEditing={guard.keepEditing}
                onDiscard={guard.discard}
            />
        </>
    );
}
