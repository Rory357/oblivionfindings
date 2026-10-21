/* "Send a message" from a referral's Communications section: who and how
 * (the enquiry's contact or a reviewed contact in People involved, limited
 * by their own saved preferences, with the reason when blocked), an approved
 * template with a short edit and locked merge fields, then a review. When
 * the recipient has a safe contact time, staff choose the send time next to
 * it and the message is held until then. The message is queued in the
 * referral outbox and sent by the delivery worker; nothing is sent from this
 * request. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    SelectInput,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTimeLong } from '@/lib/datetime';
import axios from 'axios';
import {
    ClipboardCheck,
    Eye,
    FileText,
    Mail,
    MessageSquareText,
    Send,
    UserCheck,
    UserRound,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ReferralDateTime, validReferralDateTime } from './date-time';
import {
    audienceLabels,
    channelLabels,
    renderTemplate,
    sameFields,
    smsParts,
    smsWide,
    strayBraces,
    type MessageAudience,
    type MessageChannel,
    type MessageTemplate,
} from './message-templates';
import { Nested, NestedRow } from './settings-ui';
import { referralHref, type ReferralDetail } from './types';

export type ComposeRecipient = {
    /** `person` or `referrer` (the enquiry's contact) or `contact:{id}` (People involved). */
    key: string;
    kind: 'enquiry' | 'contact';
    /** Which templates fit them. */
    audience: MessageAudience;
    role_label: string | null;
    name: string | null;
    first_name: string | null;
    relationship: string | null;
    reason: string | null;
    preferences: {
        channel: string | null;
        leave_message: boolean;
        safe_time: string | null;
        communication: string | null;
    } | null;
    channels: Record<
        MessageChannel,
        { address: string | null; reason: string | null }
    >;
};
export type ComposeTemplate = MessageTemplate & {
    fields: string[];
    reason: string | null;
};
export type ComposeContext = {
    version: number;
    blocked: string | null;
    recipients: ComposeRecipient[];
    templates: ComposeTemplate[];
    values: {
        reference: string;
        provider_name: string;
        coordinator_name: string;
        contact_phone: string;
        response_hours: string;
    } | null;
    senders: Record<MessageChannel, string | null>;
    document_requests: {
        id: number;
        reference: string;
        title: string;
        recipient: string;
        expires_at: string;
    }[];
    link_length: number;
    limits: {
        sms_parts: number;
        edit_allowance: number;
        email_body: number;
        /** A held message is sent within this many days. */
        schedule_days: number;
    };
};

/** Why the chosen send time can't be used; null when it can. */
export function sendTimeProblem(
    value: string,
    days: number,
    now = Date.now(),
): string | null {
    if (!value || !validReferralDateTime(value))
        return 'Choose when to send it, in NZ time.';
    const at = new Date(value).getTime();
    if (Number.isNaN(at)) return 'Choose when to send it, in NZ time.';
    if (at < now - 60_000) return 'Choose a time that hasn’t passed yet.';
    if (at > now + days * 86_400_000)
        return `Choose a time within the next ${days} days.`;
    return null;
}

const recipientIcon = (item: Pick<ComposeRecipient, 'audience'>) =>
    item.audience === 'person'
        ? UserRound
        : item.audience === 'referrer'
          ? UserCheck
          : Users;
const recipientLabel = (
    item: Pick<ComposeRecipient, 'kind' | 'name' | 'audience'>,
) =>
    item.kind === 'contact'
        ? (item.name ?? audienceLabels.contact)
        : audienceLabels[item.audience];

const preferredLabels: Record<string, string> = {
    email: 'Email',
    phone: 'Phone',
    none: 'No direct contact',
};

/** Why the edited wording can't be sent, in plain words; null when it can. */
export function editProblem(
    template: ComposeTemplate,
    body: string,
    context: Pick<ComposeContext, 'limits' | 'link_length'>,
    values: Record<string, string>,
): string | null {
    if (!body.trim()) return 'Write the message.';
    if (strayBraces(body))
        return 'Use braces only around a merge field, like {first_name}.';
    if (!sameFields(template.body, body))
        return 'Keep the merge fields exactly as they are. They are filled in for you.';
    if (body.length > template.body.length + context.limits.edit_allowance)
        return `Keep edits short: add no more than ${context.limits.edit_allowance} characters.`;
    if (body.length > context.limits.email_body) return 'Shorten the message.';
    if (template.channel === 'sms') {
        const parts = smsParts(
            renderTemplate(body, {
                ...values,
                document_link: 'x'.repeat(context.link_length),
            }),
        );
        if (parts > context.limits.sms_parts)
            return `This text would take ${parts} parts. Shorten it to ${context.limits.sms_parts} or fewer.`;
    }
    return null;
}

export function SendMessageDialog({
    referral,
    onClose,
    onQueued,
    onDenied,
}: {
    referral: ReferralDetail;
    onClose: () => void;
    /** The message is queued: refresh the referral and its communications. */
    onQueued: () => void;
    onDenied: () => void;
}) {
    const [context, setContext] = useState<ComposeContext | null>(null);
    const [loadError, setLoadError] = useState('');
    const [loads, setLoads] = useState(0);
    const [step, setStep] = useState(0);
    const [recipient, setRecipient] = useState('');
    const [channel, setChannel] = useState<MessageChannel | ''>('');
    const [templateId, setTemplateId] = useState('');
    const [body, setBody] = useState('');
    const [documentId, setDocumentId] = useState('');
    const [sendAt, setSendAt] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [queued, setQueued] = useState<{
        scheduled_for: string | null;
    } | null>(null);
    const [discard, setDiscard] = useState(false);
    const submitted = useRef<Record<string, unknown> | null>(null);
    const pending = useRef(false);
    const bodyRef = useRef<HTMLDivElement>(null);
    // A reload (after a 409) keeps the recipient and channel chosen, while they're still allowed.
    const selection = useRef({ recipient, channel });
    selection.current = { recipient, channel };

    useEffect(() => {
        const controller = new AbortController();
        setLoadError('');
        axios
            .get<ComposeContext>(
                `${referralHref(referral.reference)}/communications/compose`,
                { signal: controller.signal },
            )
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setContext(data);
                if (data.blocked) return;
                const sendable = (item: ComposeRecipient) =>
                    !item.reason &&
                    Object.values(item.channels).some(
                        (option) => !option.reason,
                    );
                const current = selection.current;
                const kept = data.recipients.find(
                    (item) => item.key === current.recipient && sendable(item),
                );
                const chosen = kept ?? data.recipients.find(sendable);
                if (!chosen) return;
                setRecipient(chosen.key);
                setChannel(
                    kept &&
                        current.channel &&
                        !kept.channels[current.channel].reason
                        ? current.channel
                        : ((['email', 'sms'] as const).find(
                              (key) => !chosen.channels[key].reason,
                          ) ?? ''),
                );
            })
            .catch((failure) => {
                if (controller.signal.aborted) return;
                if (
                    axios.isAxiosError(failure) &&
                    [401, 403, 404].includes(failure.response?.status ?? 0)
                ) {
                    onDenied();
                    return;
                }
                setLoadError(
                    'The message options could not be loaded. Check your connection and try again.',
                );
            });
        return () => controller.abort();
        // onDenied is stable for the dialog's life; reload only on request.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [referral.reference, loads]);

    const person = context?.recipients.find((item) => item.key === recipient);
    const templates =
        context?.templates.filter(
            (item) =>
                item.channel === channel && item.audience === person?.audience,
        ) ?? [];
    const template = templates.find((item) => item.id === templateId);
    const values: Record<string, string> = {
        ...(context?.values ?? {}),
        first_name: person?.first_name ?? '',
    };
    const needsLink = !!template?.fields.includes('document_link');
    const problem =
        template && context
            ? editProblem(template, body, context, values)
            : null;
    const safeTime = person?.preferences?.safe_time ?? null;
    const address = channel && person ? person.channels[channel].address : null;
    const edited = !!template && body !== template.body;
    const dirty = step > 0 || edited || busy || uncertain;
    const leave = useSettingsLeaveConfirmation(
        dirty && !queued,
        'Leave without sending this message?',
        'Your message is not queued yet. If sending was interrupted, check Communications before trying again.',
        undefined,
        busy,
    );

    const stepReady = useCallback(
        (index: number): string | null => {
            if (!context) return 'Loading message options.';
            if (context.blocked) return context.blocked;
            if (index >= 1) {
                if (!person || person.reason)
                    return 'Choose who the message is for.';
                if (!channel || person.channels[channel].reason)
                    return 'Choose an allowed way to send it.';
            }
            if (index >= 2) {
                if (!template) return 'Choose a template.';
                if (template.reason) return template.reason;
                if (needsLink && !documentId)
                    return 'Choose the document request whose link to send.';
                if (problem) return problem;
            }
            return null;
        },
        [context, person, channel, template, needsLink, documentId, problem],
    );

    const go = (index: number) => {
        if (busy || submitted.current) return;
        const reason = index > step ? stepReady(index) : null;
        if (reason) {
            setError(reason);
            return;
        }
        setError('');
        setStep(index);
        bodyRef.current?.scrollTo({ top: 0 });
    };

    const chooseTemplate = (id: string) => {
        const next = templates.find((item) => item.id === id);
        if (!next || next.reason) return;
        setTemplateId(id);
        setBody(next.body);
        setDocumentId(
            next.fields.includes('document_link') &&
                context?.document_requests.length === 1
                ? String(context.document_requests[0].id)
                : '',
        );
        setError('');
    };

    async function send() {
        if (pending.current || !context || !template) return;
        const reason = stepReady(2);
        if (reason) {
            setError(reason);
            return;
        }
        // A safe contact time is free text: staff choose when to send, and it is held until then.
        const timing =
            safeTime && !submitted.current
                ? sendTimeProblem(sendAt, context.limits.schedule_days)
                : null;
        if (timing) {
            setError(timing);
            return;
        }
        pending.current = true;
        setBusy(true);
        setError('');
        submitted.current ??= {
            version: context.version,
            request_key: crypto.randomUUID(),
            recipient,
            channel,
            template_id: template.id,
            body,
            document_request_id: needsLink ? Number(documentId) : null,
            send_at: safeTime ? sendAt : null,
        };
        try {
            const { data } = await axios.post<{
                scheduled_for?: string | null;
            }>(
                `${referralHref(referral.reference)}/communications`,
                submitted.current,
            );
            setQueued({ scheduled_for: data?.scheduled_for ?? null });
            setUncertain(false);
            onQueued();
        } catch (failure) {
            const status = axios.isAxiosError(failure)
                ? (failure.response?.status ?? 0)
                : 0;
            const data = axios.isAxiosError(failure)
                ? (failure.response?.data as
                      | { message?: string; errors?: Record<string, string[]> }
                      | undefined)
                : undefined;
            if ([401, 403, 404].includes(status)) {
                onDenied();
                return;
            }
            if (status >= 400 && status < 500) {
                // Nothing was queued: the details can be changed and sent again.
                submitted.current = null;
                setUncertain(false);
                if (status === 409) {
                    // Someone changed the referral: load its current version and preferences.
                    setLoads((value) => value + 1);
                    setError(
                        'This referral changed while you were writing. Its latest details are loaded: check the message and queue it again.',
                    );
                    return;
                }
                setError(
                    data?.errors
                        ? Object.values(data.errors).flat().join(' ')
                        : (data?.message ??
                              'The message could not be queued. Review it and try again.'),
                );
            } else {
                setUncertain(true);
                setError(
                    'We couldn’t confirm the message was queued. Send the exact same request again to check; it is never queued twice.',
                );
            }
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }

    const close = () => {
        if (busy) return;
        if (queued || !dirty) onClose();
        else setDiscard(true);
    };

    const steps = [
        {
            key: 'who',
            label: 'Who and how',
            blurb: 'Recipient and safe channel',
            icon: Users,
        },
        {
            key: 'message',
            label: 'Message',
            blurb: 'Approved template',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review and send',
            blurb: 'Check before queueing',
            icon: ClipboardCheck,
        },
    ];

    return (
        <>
            {leave.confirmation}
            <WizardShell
                open
                title="Send a message"
                description={`Message about ${referral.name}`}
                railTitle="Send a message"
                railIcon={Send}
                railSub="Approved wording only. Messages go out only when their saved contact preferences allow it."
                steps={steps}
                stepIndex={step}
                onStepClick={go}
                bodyRef={bodyRef}
                maxWidth="min(92vw, 1100px)"
                onClose={close}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy || uncertain}
                        onClick={() => (step ? go(step - 1) : close())}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={busy || !context || !!context.blocked}
                        onClick={() => (step < 2 ? go(step + 1) : void send())}
                    >
                        {busy
                            ? 'Queueing…'
                            : step < 2
                              ? 'Continue'
                              : uncertain
                                ? 'Send the same request again'
                                : 'Queue message'}
                    </Button>
                }
                success={
                    queued ? (
                        <WizardSuccessPane
                            title={
                                queued.scheduled_for
                                    ? 'Message scheduled'
                                    : 'Message queued'
                            }
                            blurb={
                                queued.scheduled_for
                                    ? `It is held until ${formatDateTimeLong(queued.scheduled_for)} NZ time, then sent automatically. You can cancel it from Communications until then.`
                                    : 'It is sent within about a minute. Communications shows when it is sent, or why it could not be.'
                            }
                            actions={
                                <Button onClick={onClose}>
                                    View communications
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2 className="text-section-title">{steps[step].label}</h2>
                    {!context && !loadError && (
                        <div role="status">
                            <LoadingState message="Checking contact preferences…" />
                        </div>
                    )}
                    {loadError && (
                        <ErrorState
                            title="Message options unavailable"
                            message={loadError}
                            onRetry={() => setLoads((value) => value + 1)}
                        />
                    )}
                    {context?.blocked && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {context.blocked}
                            </AlertDescription>
                        </Alert>
                    )}
                    {error && (
                        <Alert variant={uncertain ? 'destructive' : 'warning'}>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    {context && step === 0 && (
                        <WhoAndHow
                            context={context}
                            recipient={recipient}
                            channel={channel}
                            onRecipient={(key) => {
                                setRecipient(key);
                                const next = context.recipients.find(
                                    (item) => item.key === key,
                                );
                                const allowed = (
                                    ['email', 'sms'] as const
                                ).find(
                                    (option) => !next?.channels[option].reason,
                                );
                                setChannel(allowed ?? '');
                                setTemplateId('');
                                setBody('');
                                setError('');
                            }}
                            onChannel={(key) => {
                                setChannel(key);
                                setTemplateId('');
                                setBody('');
                                setError('');
                            }}
                        />
                    )}
                    {context && step === 1 && person && channel && (
                        <>
                            {templates.length ? (
                                <div className="space-y-1.5">
                                    <p
                                        id="message-template-label"
                                        className="text-sm font-medium"
                                    >
                                        Template
                                    </p>
                                    <TilePicker
                                        cols={2}
                                        labelledBy="message-template-label"
                                        value={templateId}
                                        onChange={chooseTemplate}
                                        options={templates.map((item) => ({
                                            key: item.id,
                                            label: item.label,
                                            icon:
                                                item.channel === 'sms'
                                                    ? MessageSquareText
                                                    : Mail,
                                            description:
                                                item.channel === 'email'
                                                    ? (item.subject ?? '')
                                                    : `${channelLabels.sms} to ${audienceLabels[item.audience].toLowerCase()}`,
                                            disabled: item.reason,
                                        }))}
                                    />
                                </div>
                            ) : (
                                <EmptyState
                                    icon={FileText}
                                    title="No templates for this"
                                    description={`There is no ${channelLabels[channel].toLowerCase()} template switched on for ${audienceLabels[person.audience].toLowerCase()} yet. Someone who manages referral settings can review one and switch it on in Settings › Referrals, under Workflow › Delivery & channels.`}
                                />
                            )}
                            {template && (
                                <MessageEditor
                                    context={context}
                                    template={template}
                                    person={person}
                                    channel={channel}
                                    address={address}
                                    body={body}
                                    onBody={setBody}
                                    problem={problem}
                                    values={values}
                                    documentId={documentId}
                                    onDocument={setDocumentId}
                                    needsLink={needsLink}
                                />
                            )}
                        </>
                    )}
                    {context && step === 2 && person && channel && template && (
                        <>
                            <ReviewCard
                                title="Message"
                                icon={
                                    channel === 'sms' ? MessageSquareText : Mail
                                }
                                onEdit={() => go(1)}
                            >
                                <ReviewRow
                                    label="To"
                                    value={`${person.name} (${person.role_label}) · ${address ?? ''}`}
                                />
                                <ReviewRow
                                    label="Sent by"
                                    value={channelLabels[channel]}
                                />
                                <ReviewRow
                                    label="From"
                                    value={context.senders[channel] ?? '—'}
                                />
                                <ReviewRow
                                    label="Template"
                                    value={template.label}
                                />
                                <ReviewRow
                                    label="Wording"
                                    value={
                                        edited
                                            ? 'Edited for this message'
                                            : 'As approved'
                                    }
                                />
                                {needsLink && (
                                    <ReviewRow
                                        label="Secure upload link"
                                        value={
                                            context.document_requests.find(
                                                (item) =>
                                                    String(item.id) ===
                                                    documentId,
                                            )?.title
                                        }
                                    />
                                )}
                            </ReviewCard>
                            <Nested icon={Eye} title="What they receive">
                                {channel === 'email' && (
                                    <NestedRow
                                        label="Subject"
                                        value={renderTemplate(
                                            template.subject,
                                            values,
                                        )}
                                    />
                                )}
                                <p className="mt-3 rounded-md bg-card p-3 text-sm break-words whitespace-pre-wrap">
                                    {renderTemplate(body, {
                                        ...values,
                                        document_link: '[secure upload link]',
                                    })}
                                </p>
                            </Nested>
                            <Nested icon={Send} title="When to send">
                                {safeTime ? (
                                    <div className="space-y-3">
                                        <NestedRow
                                            label="Their safe contact time"
                                            value={`“${safeTime}”`}
                                        />
                                        <fieldset disabled={busy || uncertain}>
                                            <ReferralDateTime
                                                id="message-send-at"
                                                label="Send at (NZ time)"
                                                value={sendAt}
                                                onChange={setSendAt}
                                            />
                                        </fieldset>
                                        <p className="text-caption">
                                            Choose a time that suits their safe
                                            contact time. The message is held
                                            and sent automatically then, and can
                                            be cancelled until it sends.
                                        </p>
                                    </div>
                                ) : (
                                    <p className="text-sm">
                                        Sent within about a minute of queueing.
                                        No safe contact time was given.
                                    </p>
                                )}
                            </Nested>
                            <p className="text-subtle">
                                Until it is sent you can cancel it from
                                Communications. A sent message counts as a
                                contact attempt, not a conversation: log the
                                contact when they reply.
                            </p>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                title="Discard this message?"
                description="Nothing has been queued. Your wording for this message is removed."
                confirmText="Discard message"
                cancelText="Keep writing"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
            />
        </>
    );
}

function WhoAndHow({
    context,
    recipient,
    channel,
    onRecipient,
    onChannel,
}: {
    context: ComposeContext;
    recipient: string;
    channel: MessageChannel | '';
    onRecipient: (key: string) => void;
    onChannel: (key: MessageChannel) => void;
}) {
    const person = context.recipients.find((item) => item.key === recipient);
    const people = person?.kind === 'contact';
    return (
        <>
            <div className="space-y-1.5">
                <p id="message-recipient-label" className="text-sm font-medium">
                    Send to{' '}
                    <span className="text-caption">
                        The enquiry’s contact, or someone reviewed in People
                        involved
                    </span>
                </p>
                <TilePicker
                    cols={2}
                    labelledBy="message-recipient-label"
                    value={recipient}
                    onChange={onRecipient}
                    options={context.recipients.map((item) => ({
                        key: item.key,
                        label: recipientLabel(item),
                        icon: recipientIcon(item),
                        description:
                            (item.kind === 'contact'
                                ? [item.role_label, item.relationship]
                                : [item.name, item.relationship]
                            )
                                .filter(Boolean)
                                .join(' · ') || undefined,
                        disabled: context.blocked ?? item.reason,
                    }))}
                />
            </div>
            {person?.preferences && (
                <Nested icon={UserRound} title="Their contact preferences">
                    <NestedRow
                        label="Safe way to contact them"
                        value={
                            preferredLabels[person.preferences.channel ?? ''] ??
                            'Not recorded'
                        }
                    />
                    <NestedRow
                        label="Messages"
                        value={
                            people
                                ? person.preferences.leave_message
                                    ? 'Contact permitted · OK to leave a phone message'
                                    : 'Contact permitted · no phone messages'
                                : person.preferences.leave_message
                                  ? 'OK to send or leave a message'
                                  : 'No messages'
                        }
                    />
                    <NestedRow
                        label="Safe time"
                        value={
                            person.preferences.safe_time ??
                            'No time limit given'
                        }
                    />
                    <NestedRow
                        label="Communication needs"
                        value={
                            person.preferences.communication ?? 'None recorded'
                        }
                    />
                </Nested>
            )}
            {person && !person.reason && (
                <div className="space-y-1.5">
                    <p
                        id="message-channel-label"
                        className="text-sm font-medium"
                    >
                        Send by
                    </p>
                    <TilePicker
                        cols={2}
                        labelledBy="message-channel-label"
                        value={channel}
                        onChange={(key) => onChannel(key as MessageChannel)}
                        options={(['email', 'sms'] as const).map((key) => ({
                            key,
                            label: channelLabels[key],
                            icon: key === 'email' ? Mail : MessageSquareText,
                            description:
                                person.channels[key].address ??
                                (key === 'email'
                                    ? 'No email address'
                                    : 'No phone number'),
                            disabled:
                                context.blocked ?? person.channels[key].reason,
                        }))}
                    />
                </div>
            )}
        </>
    );
}

function MessageEditor({
    context,
    template,
    person,
    channel,
    address,
    body,
    onBody,
    problem,
    values,
    documentId,
    onDocument,
    needsLink,
}: {
    context: ComposeContext;
    template: ComposeTemplate;
    person: ComposeRecipient;
    channel: MessageChannel;
    address: string | null;
    body: string;
    onBody: (body: string) => void;
    problem: string | null;
    values: Record<string, string>;
    documentId: string;
    onDocument: (id: string) => void;
    needsLink: boolean;
}) {
    const rendered = renderTemplate(body, {
        ...values,
        document_link: '[secure upload link]',
    });
    const counted = renderTemplate(body, {
        ...values,
        document_link: 'x'.repeat(context.link_length),
    });
    const parts = smsParts(counted);
    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="min-w-0 space-y-4">
                {needsLink && (
                    <Field
                        label="Secure document request"
                        required
                        hint="Its link is added. The access code is never included: give it to them another way."
                    >
                        <SelectInput
                            placeholder="Choose a document request"
                            value={documentId}
                            onChange={onDocument}
                            options={context.document_requests.map((item) => ({
                                value: String(item.id),
                                label: `${item.title} · for ${item.recipient} · until ${formatDateTimeLong(item.expires_at)}`,
                            }))}
                        />
                    </Field>
                )}
                <div>
                    <Field
                        label="Message"
                        required
                        hint="Reword it a little if you need to"
                    >
                        <Textarea
                            rows={10}
                            value={body}
                            maxLength={
                                template.body.length +
                                context.limits.edit_allowance
                            }
                            aria-invalid={problem ? true : undefined}
                            onChange={(event) => onBody(event.target.value)}
                        />
                    </Field>
                    <FieldErr>{problem}</FieldErr>
                    <p className="text-caption mt-1.5">
                        Keep merge fields like {'{first_name}'} exactly as they
                        are: they are filled in for you.
                        {channel === 'sms'
                            ? ` ${counted.length} characters · ${parts} of ${context.limits.sms_parts} text parts${smsWide(counted) ? ' · macrons or curly quotes make each part shorter' : ''}.`
                            : ''}
                    </p>
                    {body !== template.body && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mt-1 px-0"
                            onClick={() => onBody(template.body)}
                        >
                            Go back to the approved wording
                        </Button>
                    )}
                </div>
            </div>
            <Nested icon={Eye} title="Preview">
                <NestedRow
                    label="From"
                    value={context.senders[channel] ?? 'Not set up'}
                />
                <NestedRow
                    label="To"
                    value={`${person.name} (${person.role_label}) · ${address ?? ''}`}
                />
                {channel === 'email' && (
                    <NestedRow
                        label="Subject"
                        value={renderTemplate(template.subject, values)}
                    />
                )}
                <p className="mt-3 rounded-md bg-card p-3 text-sm break-words whitespace-pre-wrap">
                    {rendered}
                </p>
            </Nested>
        </div>
    );
}
