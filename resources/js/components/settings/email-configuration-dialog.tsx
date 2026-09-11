import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Field,
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
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import axios from 'axios';
import {
    ClipboardCheck,
    Inbox,
    Mail,
    MessageSquare,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    EMAIL_PROVIDERS,
    REPLY_MODES,
    validEmailSettings,
    type EmailConfiguration,
    type EmailProvider,
    type EmailSettingsState,
    type PublicReplyMode,
} from './email-configuration-contract';

const steps = [
    {
        key: 'provider',
        label: 'Delivery provider',
        blurb: 'Provider and SMTP settings',
        icon: Mail,
    },
    {
        key: 'identity',
        label: 'Support identity',
        blurb: 'Sender and reply mailbox',
        icon: Inbox,
    },
    {
        key: 'content',
        label: 'Public replies',
        blurb: 'Content and activation',
        icon: MessageSquare,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
] as const;
type Draft = Omit<EmailConfiguration, 'configuration_version'> & {
    smtp_password: string;
    clear_smtp_password: boolean;
};
function draftFor(settings: EmailConfiguration): Draft {
    const { configuration_version: _version, ...fields } = settings;
    return { ...fields, smtp_password: '', clear_smtp_password: false };
}

export function EmailConfigurationDialog({
    initial,
    onSaved,
    onClose,
    onAccessLost,
}: {
    initial: EmailSettingsState;
    onSaved: (state: EmailSettingsState) => void;
    onClose: () => void;
    onAccessLost: () => void;
}) {
    const [base, setBase] = useState(initial);
    const [draft, setDraft] = useState(() => draftFor(initial.settings));
    const [step, setStep] = useState(0);
    const [pending, setPending] = useState<'save' | 'read' | null>(null);
    const [failure, setFailure] = useState<'conflict' | 'unknown' | null>(null);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [review, setReview] = useState<EmailSettingsState | null>(null);
    const [saved, setSaved] = useState(false);
    const abort = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const inFlight = useRef(false);
    const alert = useRef<HTMLDivElement>(null);
    const dirty =
        !saved &&
        (JSON.stringify(draft) !== JSON.stringify(draftFor(base.settings)) ||
            pending !== null ||
            failure === 'unknown');
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Discard unsaved email settings?',
    );
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            abort.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (message) alert.current?.focus();
    }, [message]);
    const selected = base.connections.find(
        (connection) => connection.id === draft.support_connection_id,
    );
    const reviewedConnection = review
        ? review.connections.find(
              (connection) =>
                  connection.id === review.settings.support_connection_id,
          )
        : undefined;
    const locked = pending !== null || failure !== null;
    const completion = (() => {
        const fields = [
            draft.provider,
            draft.from_name.trim(),
            draft.public_reply_mode,
        ];
        if (draft.support_enabled) {
            fields.push(
                draft.from_address.trim(),
                draft.support_connection_id === null
                    ? ''
                    : String(draft.support_connection_id),
                draft.support_connection_version === null
                    ? ''
                    : String(draft.support_connection_version),
            );
            if (draft.provider === 'smtp') {
                fields.push(
                    draft.smtp_host.trim(),
                    draft.smtp_port >= 1 && draft.smtp_port <= 65535
                        ? String(draft.smtp_port)
                        : '',
                );
            }
        }
        return Math.round(
            (fields.filter(Boolean).length / fields.length) * 100,
        );
    })();

    function change<K extends keyof Draft>(key: K, value: Draft[K]) {
        setDraft((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
    }
    function validate(stepIndex?: number): Record<string, string> {
        const next: Record<string, string> = {};
        const includes = (index: number) =>
            stepIndex === undefined || stepIndex === index;

        if (includes(0)) {
            if (
                draft.support_enabled &&
                draft.provider === 'smtp' &&
                !draft.smtp_host.trim()
            )
                next.smtp_host = 'Enter an SMTP host.';
            if (
                !Number.isInteger(draft.smtp_port) ||
                draft.smtp_port < 1 ||
                draft.smtp_port > 65535
            )
                next.smtp_port = 'Enter an SMTP port from 1 to 65535.';
        }
        if (includes(1)) {
            if (!draft.from_name.trim())
                next.from_name = 'Enter a sender name.';
            if (draft.support_enabled && !draft.from_address.trim())
                next.from_address = 'Enter a sender and reply address.';
            if (
                draft.support_enabled &&
                (draft.support_connection_id === null ||
                    draft.support_connection_version === null)
            )
                next.support_connection_id =
                    'Choose the approved support mailbox.';
        }
        return next;
    }
    function continueStep() {
        const next = validate(step);
        if (Object.keys(next).length > 0) {
            setErrors(next);
            setMessage('Complete the required fields before continuing.');
            return;
        }
        setErrors({});
        setMessage('');
        setStep(step + 1);
    }
    function save() {
        const next = validate();
        if (Object.keys(next).length > 0) {
            setErrors(next);
            setMessage('Complete the required fields before saving.');
            setStep(
                Object.keys(next).some((key) => key.startsWith('smtp_'))
                    ? 0
                    : 1,
            );
            return;
        }
        void request();
    }
    function close() {
        leave.request(onClose);
    }
    async function request(reading = false) {
        if (inFlight.current || (!reading && failure !== null)) return;
        inFlight.current = true;
        const controller = new AbortController();
        abort.current = controller;
        setPending(reading ? 'read' : 'save');
        setMessage('');
        setErrors({});
        try {
            const response = reading
                ? await axios.get('/settings/email', {
                      signal: controller.signal,
                      headers: { Accept: 'application/json' },
                  })
                : await axios.put(
                      '/settings/email',
                      {
                          ...draft,
                          expected_version: base.settings.configuration_version,
                          expected_actor_id: base.actor_id,
                      },
                      {
                          signal: controller.signal,
                          headers: { Accept: 'application/json' },
                      },
                  );
            if (!alive.current) return;
            const state: unknown = response.data?.data;
            if (
                !validEmailSettings(state) ||
                (!reading &&
                    state.settings.configuration_version !==
                        base.settings.configuration_version + 1)
            ) {
                throw new Error('Unconfirmed settings response');
            }
            if (!state.can_manage || state.actor_id !== base.actor_id) {
                onAccessLost();
                return;
            }
            if (reading) {
                setReview(state);
                setMessage(
                    'Review the current saved settings before deciding whether to keep your entries.',
                );
            } else {
                setSaved(true);
                setDraft(draftFor(state.settings));
                setBase(state);
                setFailure(null);
                onSaved(state);
            }
        } catch (error) {
            if (!alive.current) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if ([401, 403, 419].includes(status ?? 0)) {
                onAccessLost();
                return;
            }
            if (status === 422 && !reading) {
                const details = axios.isAxiosError(error)
                    ? error.response?.data?.errors
                    : null;
                const fields: Record<string, string> = {};
                if (details && typeof details === 'object')
                    for (const [key, values] of Object.entries(details)) {
                        if (
                            Array.isArray(values) &&
                            typeof values[0] === 'string'
                        )
                            fields[key] = values[0];
                    }
                setErrors(fields);
                setMessage(
                    'The settings were not saved. Review the highlighted fields; your entries are retained.',
                );
                setStep(
                    Object.keys(fields).some((key) => key.startsWith('smtp_'))
                        ? 0
                        : Object.keys(fields).some(
                                (key) =>
                                    key.startsWith('support_connection') ||
                                    key.startsWith('from_'),
                            )
                          ? 1
                          : 2,
                );
            } else {
                setFailure(status === 409 ? 'conflict' : 'unknown');
                setMessage(
                    reading
                        ? 'Saved settings could not be loaded. Retry the read before making another change.'
                        : status === 409
                          ? 'Another edit changed the saved settings. Review the current version before applying your entries.'
                          : 'The save outcome is unknown. Read the saved settings before retrying. Stopping the wait does not cancel a save already sent.',
                );
            }
        } finally {
            inFlight.current = false;
            if (alive.current) setPending(null);
        }
    }

    function keepEntries() {
        if (!review) return;
        const connection = review.connections.find(
            (item) => item.id === draft.support_connection_id,
        );
        if (
            !connection ||
            connection.configuration_version !==
                draft.support_connection_version
        ) {
            setDraft((current) => ({
                ...current,
                support_connection_id: null,
                support_connection_version: null,
            }));
        }
        setBase(review);
        onSaved(review);
        setReview(null);
        setFailure(null);
        setMessage(
            'Your entries are retained against the reviewed version. Check the support mailbox and review step before saving.',
        );
    }

    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Edit email settings"
                description="Choose the outgoing provider, approved support identity and public-reply content, then review the changes."
                railIcon={Mail}
                railTitle="Email settings"
                railSub="IT support delivery"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!pending) setStep(index);
                }}
                pct={completion}
                pctLabel="Settings completeness"
                footerStart={
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {pending ? (
                            <Button
                                variant="outline"
                                onClick={() => abort.current?.abort()}
                            >
                                Stop waiting
                            </Button>
                        ) : null}
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={pending !== null}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {step < 3 ? (
                            <Button
                                disabled={pending !== null}
                                onClick={continueStep}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                disabled={locked}
                                aria-busy={pending === 'save'}
                                onClick={save}
                            >
                                {pending === 'save'
                                    ? 'Saving…'
                                    : 'Save email settings'}
                            </Button>
                        )}
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Email settings saved"
                            blurb="The saved configuration is available for IT support delivery. Provider acceptance still needs its own test."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    {message && (
                        <Alert ref={alert} tabIndex={-1} role="alert">
                            <AlertTitle>Review email settings</AlertTitle>
                            <AlertDescription className="space-y-3">
                                <p>{message}</p>
                                {failure && (
                                    <Button
                                        variant="outline"
                                        disabled={pending !== null}
                                        onClick={() => void request(true)}
                                    >
                                        {pending === 'read'
                                            ? 'Loading saved settings…'
                                            : 'Read saved settings'}
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {review && (
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Current saved version"
                        >
                            <ReviewRow
                                label="Version"
                                value={review.settings.configuration_version}
                            />
                            <ReviewRow
                                label="Provider"
                                value={
                                    EMAIL_PROVIDERS[review.settings.provider]
                                }
                            />
                            <ReviewRow
                                label="Sender name"
                                value={review.settings.from_name || 'Not set'}
                            />
                            <ReviewRow
                                label="Sender and reply address"
                                value={
                                    review.settings.from_address || 'Not set'
                                }
                            />
                            <ReviewRow
                                label="Support mailbox"
                                value={
                                    reviewedConnection
                                        ? `${EMAIL_PROVIDERS[reviewedConnection.provider]} · ${reviewedConnection.mailbox_email ?? 'Address unavailable'} · Version ${reviewedConnection.configuration_version} · ${reviewedConnection.connected ? 'Connected' : 'Disconnected'}`
                                        : review.settings
                                                .support_connection_id !== null
                                          ? 'Saved connection details are unavailable'
                                          : 'Not selected'
                                }
                            />
                            <ReviewRow
                                label="Public replies"
                                value={
                                    REPLY_MODES[
                                        review.settings.public_reply_mode
                                    ]
                                }
                            />
                            <ReviewRow
                                label="Support settings"
                                value={
                                    review.settings.support_enabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                }
                            />
                            {review.settings.provider === 'smtp' && (
                                <>
                                    <ReviewRow
                                        label="SMTP host / port"
                                        value={`${review.settings.smtp_host || 'Not set'}:${review.settings.smtp_port}`}
                                    />
                                    <ReviewRow
                                        label="SMTP encryption"
                                        value={review.settings.smtp_encryption}
                                    />
                                    <ReviewRow
                                        label="SMTP username"
                                        value={
                                            review.settings.smtp_username ||
                                            'Not set'
                                        }
                                    />
                                    <ReviewRow
                                        label="Saved SMTP password"
                                        value={
                                            review.smtp_password_saved
                                                ? 'Present — value hidden'
                                                : 'No saved override'
                                        }
                                    />
                                </>
                            )}
                            <p className="text-subtle mt-3">
                                Passwords are never returned. A saved-password
                                indicator cannot confirm the value you entered.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button variant="outline" onClick={keepEntries}>
                                    Keep my entries with this version
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        onSaved(review);
                                        onClose();
                                    }}
                                >
                                    Use saved settings and close
                                </Button>
                            </div>
                        </ReviewCard>
                    )}
                    <fieldset disabled={locked} className="space-y-5">
                        {step === 0 && (
                            <WizardStepPane key="provider">
                                <StepHead
                                    icon={Mail}
                                    title="Choose the delivery provider"
                                    blurb="These settings control IT messages when support delivery is enabled. Mail capture remains active in an isolated environment."
                                />
                                <TilePicker
                                    value={draft.provider}
                                    cols={3}
                                    onChange={(value) => {
                                        const provider = value as EmailProvider;
                                        setErrors((current) => ({
                                            ...current,
                                            smtp_host: '',
                                            smtp_port: '',
                                        }));
                                        setDraft((current) => ({
                                            ...current,
                                            provider,
                                            // The server always requires a
                                            // valid port, including when SMTP
                                            // fields are hidden for API mail.
                                            smtp_port:
                                                provider === 'smtp'
                                                    ? current.smtp_port
                                                    : 587,
                                            ...(provider !== 'smtp' &&
                                            selected?.provider !== provider
                                                ? {
                                                      support_connection_id:
                                                          null,
                                                      support_connection_version:
                                                          null,
                                                  }
                                                : {}),
                                        }));
                                    }}
                                    options={Object.entries(
                                        EMAIL_PROVIDERS,
                                    ).map(([key, label]) => ({
                                        key,
                                        label,
                                        icon: Mail,
                                    }))}
                                />
                                {draft.provider === 'smtp' ? (
                                    <div className="grid grid-cols-2 gap-4">
                                        <Field
                                            label="SMTP host"
                                            required={draft.support_enabled}
                                            error={errors.smtp_host}
                                        >
                                            <Input
                                                value={draft.smtp_host}
                                                onChange={(event) =>
                                                    change(
                                                        'smtp_host',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Port"
                                            required
                                            error={errors.smtp_port}
                                        >
                                            <Input
                                                type="number"
                                                min={1}
                                                max={65535}
                                                value={draft.smtp_port}
                                                onChange={(event) =>
                                                    change(
                                                        'smtp_port',
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Encryption"
                                            error={errors.smtp_encryption}
                                        >
                                            <SelectInput
                                                value={draft.smtp_encryption}
                                                onChange={(value) =>
                                                    change(
                                                        'smtp_encryption',
                                                        value as Draft['smtp_encryption'],
                                                    )
                                                }
                                                placeholder="Choose encryption"
                                                options={[
                                                    {
                                                        value: 'tls',
                                                        label: 'Required STARTTLS',
                                                    },
                                                    {
                                                        value: 'ssl',
                                                        label: 'TLS connection (SMTPS)',
                                                    },
                                                    {
                                                        value: 'none',
                                                        label: 'No TLS',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        <Field
                                            label="Username"
                                            error={errors.smtp_username}
                                        >
                                            <Input
                                                autoComplete="off"
                                                value={draft.smtp_username}
                                                onChange={(event) =>
                                                    change(
                                                        'smtp_username',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Replacement password"
                                            error={errors.smtp_password}
                                            hint={
                                                base.smtp_password_saved
                                                    ? 'A password is saved. Leave blank to retain it.'
                                                    : 'Leave blank to use the server SMTP credential, if configured.'
                                            }
                                        >
                                            <Input
                                                type="password"
                                                autoComplete="new-password"
                                                value={draft.smtp_password}
                                                onChange={(event) =>
                                                    change(
                                                        'smtp_password',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Remove saved SMTP password"
                                            hint="After removal, the server SMTP credential applies if one is configured."
                                        >
                                            <Checkbox
                                                checked={
                                                    draft.clear_smtp_password
                                                }
                                                onCheckedChange={(checked) =>
                                                    change(
                                                        'clear_smtp_password',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                ) : (
                                    <p className="text-subtle">
                                        Use an existing support-mailbox
                                        connection. Account sign-in alone does
                                        not establish permission to send mail.
                                    </p>
                                )}
                            </WizardStepPane>
                        )}
                        {step === 1 && (
                            <WizardStepPane key="identity">
                                <StepHead
                                    icon={Inbox}
                                    title="Choose the support identity"
                                    blurb="The selected mailbox is the sender and receives replies. Manage account connections from Support mailbox settings."
                                />
                                <Field
                                    label="Support mailbox"
                                    required={draft.support_enabled}
                                    error={
                                        errors.support_connection_id ??
                                        errors.support_connection_version
                                    }
                                >
                                    <SelectInput
                                        value={
                                            draft.support_connection_id === null
                                                ? 'none'
                                                : String(
                                                      draft.support_connection_id,
                                                  )
                                        }
                                        placeholder="Choose a support mailbox"
                                        onChange={(value) => {
                                            const connection =
                                                base.connections.find(
                                                    (item) =>
                                                        String(item.id) ===
                                                        value,
                                                );
                                            setDraft((current) => ({
                                                ...current,
                                                support_connection_id:
                                                    connection?.id ?? null,
                                                support_connection_version:
                                                    connection?.configuration_version ??
                                                    null,
                                                from_address:
                                                    connection?.mailbox_email ??
                                                    current.from_address,
                                            }));
                                        }}
                                        options={[
                                            {
                                                value: 'none',
                                                label: 'No mailbox selected',
                                            },
                                            ...base.connections.map(
                                                (connection) => ({
                                                    value: String(
                                                        connection.id,
                                                    ),
                                                    label: `${EMAIL_PROVIDERS[connection.provider]} · ${connection.mailbox_email ?? 'Address unavailable'}${connection.connected ? '' : ' · Disconnected'}`,
                                                }),
                                            ),
                                        ]}
                                    />
                                </Field>
                                {draft.support_connection_id !== null &&
                                    !selected && (
                                        <p className="text-subtle">
                                            The previously selected connection
                                            is unavailable. Choose a current
                                            connection or disable these support
                                            settings.
                                        </p>
                                    )}
                                {selected?.sending_issue &&
                                    draft.provider !== 'smtp' && (
                                        <Alert>
                                            <AlertTitle>
                                                Sending permission needs review
                                            </AlertTitle>
                                            <AlertDescription>
                                                {selected.sending_issue}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                <Field
                                    label="Sender name"
                                    required
                                    error={errors.from_name}
                                >
                                    <Input
                                        value={draft.from_name}
                                        onChange={(event) =>
                                            change(
                                                'from_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Sender and reply address"
                                    required
                                    error={errors.from_address}
                                    hint="Must match the selected support mailbox."
                                >
                                    <Input
                                        readOnly
                                        value={
                                            selected?.mailbox_email ??
                                            draft.from_address
                                        }
                                    />
                                </Field>
                            </WizardStepPane>
                        )}
                        {step === 2 && (
                            <WizardStepPane key="content">
                                <StepHead
                                    icon={MessageSquare}
                                    title="Choose what public replies contain"
                                    blurb="Internal notes remain private in both modes. Files stay behind the ticket’s existing sign-in and permission checks."
                                />
                                <Field
                                    label="Use these settings for IT messages"
                                    hint="When disabled, IT messages retain the application's configured mailer and link-only content."
                                >
                                    <Checkbox
                                        checked={draft.support_enabled}
                                        onCheckedChange={(checked) =>
                                            change(
                                                'support_enabled',
                                                checked === true,
                                            )
                                        }
                                    />
                                </Field>
                                <TilePicker
                                    value={draft.public_reply_mode}
                                    onChange={(value) =>
                                        change(
                                            'public_reply_mode',
                                            value as PublicReplyMode,
                                        )
                                    }
                                    options={[
                                        {
                                            key: 'link_only',
                                            label: 'Link only',
                                            description:
                                                'A notification with a link to read the reply in the app.',
                                            icon: ShieldCheck,
                                        },
                                        {
                                            key: 'full_reply',
                                            label: 'Full public reply',
                                            description:
                                                'The public reply text in the email, with a link to the ticket and its files.',
                                            icon: MessageSquare,
                                        },
                                    ]}
                                />
                            </WizardStepPane>
                        )}
                        {step === 3 && (
                            <WizardStepPane key="review">
                                <StepHead
                                    icon={ClipboardCheck}
                                    title="Review email settings"
                                    blurb="Saving changes configuration. It does not send a test message or prove delivery."
                                />
                                <ReviewCard
                                    icon={Mail}
                                    title="Provider"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Provider"
                                        value={EMAIL_PROVIDERS[draft.provider]}
                                    />
                                    {draft.provider === 'smtp' && (
                                        <>
                                            <ReviewRow
                                                label="Host / port"
                                                value={`${draft.smtp_host || 'Not set'}:${draft.smtp_port}`}
                                            />
                                            <ReviewRow
                                                label="Encryption"
                                                value={draft.smtp_encryption}
                                            />
                                            <ReviewRow
                                                label="Password"
                                                value={
                                                    draft.clear_smtp_password
                                                        ? 'Remove saved password'
                                                        : draft.smtp_password
                                                          ? 'Replace saved password'
                                                          : 'Retain existing credential'
                                                }
                                            />
                                        </>
                                    )}
                                </ReviewCard>
                                <ReviewCard
                                    icon={Inbox}
                                    title="Support identity"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Sender name"
                                        value={draft.from_name}
                                    />
                                    <ReviewRow
                                        label="Mailbox"
                                        value={
                                            selected?.mailbox_email ??
                                            'Not selected'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={MessageSquare}
                                    title="Public replies"
                                    onEdit={() => setStep(2)}
                                >
                                    <ReviewRow
                                        label="Support settings"
                                        value={
                                            draft.support_enabled
                                                ? 'Enabled'
                                                : 'Disabled'
                                        }
                                    />
                                    <ReviewRow
                                        label="Content"
                                        value={
                                            REPLY_MODES[draft.public_reply_mode]
                                        }
                                    />
                                    <ReviewRow
                                        label="Files"
                                        value="Open the ticket"
                                    />
                                </ReviewCard>
                            </WizardStepPane>
                        )}
                    </fieldset>
                </div>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
