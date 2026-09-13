import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import axios from 'axios';
import { type FormEvent, useEffect, useRef, useState } from 'react';

export type SsoProvider = 'microsoft' | 'google';
export type ProviderConfiguration = {
    version: number;
    source: 'deployment' | 'saved';
    saved_at: string | null;
    client_id: string;
    directory_id: string;
    domain: string;
    staff_enabled: boolean;
    portal_enabled: boolean;
    secret_source: 'deployment' | 'saved' | 'removed';
    secret_present: boolean;
    secret_readable: boolean;
    callback_urls: { staff: string; portal: string };
    checks: Record<string, string>;
    consent_status: 'unverified';
    sign_in_status: 'unverified';
};
export type ProvisioningConfiguration = {
    version: number;
    source: 'deployment' | 'saved';
    saved_at: string | null;
    auto_create_staff: boolean;
    auto_link_existing: boolean;
    portal_auto_create: boolean;
    require_admin_approval: true;
    default_role_name: 'support_worker';
    portal_role_name: 'next_of_kin';
};
type Configuration = ProviderConfiguration | ProvisioningConfiguration;
type Failure =
    | 'validation'
    | 'session'
    | 'access'
    | 'unknown'
    | 'conflict'
    | null;

function validConfiguration(
    value: unknown,
    provider: SsoProvider | 'provisioning',
): value is Configuration {
    if (!value || typeof value !== 'object') return false;
    const candidate = value as Record<string, unknown>;
    if (
        !Number.isSafeInteger(candidate.version) ||
        (candidate.version as number) < 0 ||
        !['saved', 'deployment'].includes(String(candidate.source))
    )
        return false;
    if ('client_secret' in candidate || 'secret_ciphertext' in candidate)
        return false;
    return provider === 'provisioning'
        ? typeof candidate.auto_create_staff === 'boolean' &&
              typeof candidate.auto_link_existing === 'boolean' &&
              typeof candidate.portal_auto_create === 'boolean' &&
              candidate.require_admin_approval === true &&
              candidate.default_role_name === 'support_worker' &&
              candidate.portal_role_name === 'next_of_kin'
        : typeof candidate.client_id === 'string' &&
              typeof candidate.domain === 'string' &&
              typeof candidate.directory_id === 'string' &&
              typeof candidate.staff_enabled === 'boolean' &&
              typeof candidate.portal_enabled === 'boolean' &&
              typeof candidate.secret_present === 'boolean' &&
              typeof candidate.secret_readable === 'boolean' &&
              candidate.callback_urls !== null &&
              typeof candidate.callback_urls === 'object' &&
              typeof (candidate.callback_urls as Record<string, unknown>)
                  .staff === 'string' &&
              typeof (candidate.callback_urls as Record<string, unknown>)
                  .portal === 'string' &&
              candidate.checks !== null &&
              typeof candidate.checks === 'object';
}

function useConfiguration<T extends Configuration>(
    initial: T,
    section: SsoProvider | 'provisioning',
    onSaved: (value: T) => void,
    clearSecret: () => void,
) {
    const [saved, setSaved] = useState(initial);
    const [current, setCurrent] = useState<T | null>(null);
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<Failure>(null);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const abort = useRef<AbortController | null>(null);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            abort.current?.abort();
        };
    }, []);

    function failed(error: unknown) {
        const status = axios.isAxiosError(error)
            ? error.response?.status
            : undefined;
        if (status === 422) {
            const fields = axios.isAxiosError(error)
                ? error.response?.data?.errors
                : null;
            setErrors(
                fields && typeof fields === 'object'
                    ? Object.fromEntries(
                          Object.entries(fields).map(([key, value]) => [
                              key,
                              Array.isArray(value)
                                  ? String(value[0])
                                  : String(value),
                          ]),
                      )
                    : {},
            );
            setFailure('validation');
            setMessage(
                'Review the highlighted fields. Your other entries are retained; enter a replacement secret again if needed.',
            );
        } else if (status === 401 || status === 419) {
            setFailure('session');
            setMessage(
                'Your session expired. Sign in again, then review the current saved configuration.',
            );
        } else if (status === 403) {
            setCurrent(null);
            setFailure('access');
            setMessage(
                'Your current access does not allow SSO configuration. The form is concealed.',
            );
        } else {
            setFailure(status === 409 ? 'conflict' : 'unknown');
            setMessage(
                status === 409
                    ? 'Another administrator changed these settings. Review the current saved values before saving again.'
                    : 'The save outcome is unknown. Review the current saved configuration before retrying; cancelling the wait does not undo a save.',
            );
        }
    }
    async function submit(payload: Record<string, unknown>) {
        if (pending || (failure && failure !== 'validation')) return;
        const controller = new AbortController();
        abort.current = controller;
        setPending(true);
        setFailure(null);
        setMessage('');
        setErrors({});
        try {
            const endpoint =
                section === 'provisioning'
                    ? '/settings/sso/provisioning'
                    : `/settings/sso/providers/${section}`;
            const response = await axios.put(
                endpoint,
                { ...payload, expected_version: saved.version },
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            const value = response.data?.configuration;
            if (
                response.data?.status !== 'saved' ||
                !validConfiguration(value, section) ||
                value.version !== saved.version + 1
            )
                throw new Error('Unconfirmed save response.');
            if (!alive.current) return;
            setSaved(value as T);
            onSaved(value as T);
            setMessage(
                'Settings saved. Provider consent and successful sign-in remain unverified.',
            );
        } catch (error) {
            if (alive.current) failed(error);
        } finally {
            if (alive.current) {
                clearSecret();
                setPending(false);
            }
        }
    }
    async function recover() {
        if (pending) return;
        const controller = new AbortController();
        abort.current = controller;
        setPending(true);
        try {
            const response = await axios.get(
                `/settings/sso/configuration/${section}`,
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!validConfiguration(response.data?.configuration, section))
                throw new Error('Invalid configuration response.');
            if (!alive.current) return;
            const value = response.data.configuration as T;
            setCurrent(value);
            onSaved(value);
            setFailure('conflict');
            setMessage(
                'Current saved values loaded. Choose how to continue; nothing has been resubmitted.',
            );
        } catch (error) {
            if (alive.current) failed(error);
        } finally {
            if (alive.current) setPending(false);
        }
    }
    function acceptCurrent() {
        if (!current) return null;
        const value = current;
        setSaved(value);
        setCurrent(null);
        setFailure(null);
        setErrors({});
        setMessage(
            'Current version selected. Review your entries before saving.',
        );
        return value;
    }
    return {
        saved,
        reviewed: current,
        pending,
        failure,
        message,
        errors,

        submit,
        recover,
        acceptCurrent,
        accessFailed: (error: unknown) => {
            clearSecret();
            failed(error);
        },
        cancelWait: () => abort.current?.abort(),
    };
}

function Recovery({
    command,
    onUseSaved,
    onKeepFields,
}: {
    command: ReturnType<typeof useConfiguration>;
    onUseSaved: () => void;
    onKeepFields: () => void;
}) {
    const summary = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (command.failure) summary.current?.focus();
    }, [command.failure, command.message]);

    return (
        <>
            {command.message && (
                <Alert
                    variant={command.failure ? 'destructive' : 'default'}
                    role={command.failure ? 'alert' : 'status'}
                    ref={summary}
                    tabIndex={-1}
                >
                    <AlertTitle>
                        {command.failure
                            ? 'SSO settings need attention'
                            : 'SSO settings status'}
                    </AlertTitle>
                    <AlertDescription>
                        <p>{command.message}</p>
                        {Object.entries(command.errors).length > 0 && (
                            <ul className="mt-2 list-inside list-disc">
                                {Object.entries(command.errors).map(
                                    ([key, value]) => (
                                        <li key={key}>{value}</li>
                                    ),
                                )}
                            </ul>
                        )}
                        {command.failure === 'session' && (
                            <Button asChild variant="outline">
                                <a
                                    href="/login"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Sign in again
                                </a>
                            </Button>
                        )}
                        {command.failure &&
                            command.failure !== 'validation' &&
                            !command.reviewed && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void command.recover()}
                                    disabled={command.pending}
                                >
                                    {command.failure === 'access'
                                        ? 'Check access again'
                                        : 'Review current saved settings'}
                                </Button>
                            )}
                    </AlertDescription>
                </Alert>
            )}
            {command.reviewed && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Current saved version {command.reviewed.version}
                        </CardTitle>
                        <CardDescription>
                            {'client_id' in command.reviewed
                                ? 'The saved secret stays concealed. A replacement secret must be entered again.'
                                : 'Review the saved account-creation settings before choosing how to continue.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {'client_id' in command.reviewed ? (
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <dt>Client ID</dt>
                                <dd className="break-all">
                                    {command.reviewed.client_id ||
                                        'Not configured'}
                                </dd>
                                <dt>Staff domain</dt>
                                <dd>
                                    {command.reviewed.domain ||
                                        'Not configured'}
                                </dd>
                                <dt>Staff sign-in</dt>
                                <dd>
                                    {command.reviewed.staff_enabled
                                        ? 'Enabled'
                                        : 'Disabled'}
                                </dd>
                                <dt>Portal sign-in</dt>
                                <dd>
                                    {command.reviewed.portal_enabled
                                        ? 'Enabled'
                                        : 'Disabled'}
                                </dd>
                            </dl>
                        ) : (
                            <p className="text-sm">
                                Staff creation:{' '}
                                {command.reviewed.auto_create_staff
                                    ? 'enabled'
                                    : 'disabled'}{' '}
                                · Pending account linking:{' '}
                                {command.reviewed.auto_link_existing
                                    ? 'enabled'
                                    : 'disabled'}{' '}
                                · Portal creation:{' '}
                                {command.reviewed.portal_auto_create
                                    ? 'enabled'
                                    : 'disabled'}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onUseSaved}
                            >
                                Use saved values
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onKeepFields}
                            >
                                Keep my field changes
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}
        </>
    );
}

function useDirtyGuard(
    dirty: boolean,
    onDirtyChange: (dirty: boolean) => void,
) {
    useEffect(() => {
        onDirtyChange(dirty);
        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);
}

function Toggle({
    id,
    label,
    description,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-5">
            <div>
                <Label htmlFor={id}>{label}</Label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

export function SsoProviderForm({
    provider,
    initial,
    onSaved,
    onDirtyChange,
    onAccessLost,
    audience = 'all',
}: {
    provider: SsoProvider;
    initial: ProviderConfiguration;
    onSaved: (value: ProviderConfiguration) => void;
    onDirtyChange: (dirty: boolean) => void;
    onAccessLost?: (reason: 'session' | 'access') => void;
    audience?: string;
}) {
    const [fields, setFields] = useState(initial);
    const [secret, setSecret] = useState('');
    const [secretAction, setSecretAction] = useState<
        'keep' | 'replace' | 'remove'
    >('keep');
    const [confirmRemoval, setConfirmRemoval] = useState(false);
    const [check, setCheck] = useState('');
    const [checking, setChecking] = useState(false);
    const alive = useRef(true);
    const checkAbort = useRef<AbortController | null>(null);
    const clearSecret = () => {
        setSecret('');
        setSecretAction('keep');
        setConfirmRemoval(false);
    };
    const command = useConfiguration(initial, provider, onSaved, clearSecret);
    useEffect(() => {
        if (command.failure === 'session' || command.failure === 'access')
            onAccessLost?.(command.failure);
    }, [command.failure, onAccessLost]);
    const dirty =
        [
            'client_id',
            'directory_id',
            'domain',
            'staff_enabled',
            'portal_enabled',
        ].some(
            (key) =>
                fields[key as keyof ProviderConfiguration] !==
                command.saved[key as keyof ProviderConfiguration],
        ) ||
        secretAction !== 'keep' ||
        command.pending ||
        !!command.failure;
    useDirtyGuard(dirty, onDirtyChange);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            checkAbort.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (command.failure === 'access') {
            setFields({
                ...initial,
                client_id: '',
                directory_id: '',
                domain: '',
            });
            clearSecret();
        }
    }, [command.failure, initial]);
    const concealed =
        command.failure === 'access' || command.failure === 'session';
    const blocked =
        checking ||
        command.pending ||
        (!!command.failure && command.failure !== 'validation');
    const label = provider === 'microsoft' ? 'Microsoft' : 'Google';
    function submit(event: FormEvent) {
        event.preventDefault();
        setCheck('');
        void command.submit({
            client_id: fields.client_id,
            ...(provider === 'microsoft'
                ? { directory_id: fields.directory_id }
                : {}),
            domain: fields.domain,
            staff_enabled: fields.staff_enabled,
            portal_enabled: fields.portal_enabled,
            secret_action: secretAction,
            client_secret: secretAction === 'replace' ? secret : '',
            ...(secretAction === 'remove'
                ? { confirm_secret_removal: confirmRemoval }
                : {}),
        });
    }
    async function checkSaved() {
        if (checking || blocked) return;
        setChecking(true);
        setCheck('');
        const controller = new AbortController();
        checkAbort.current = controller;
        try {
            const response = await axios.post(
                `/settings/sso/providers/${provider}/check`,
                {},
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (
                typeof response.data?.configuration_valid !== 'boolean' ||
                response.data?.provider !== provider ||
                typeof response.data?.checks !== 'object' ||
                response.data?.consent_status !== 'unverified'
            )
                throw new Error('Invalid check response.');
            if (alive.current)
                setCheck(
                    response.data.configuration_valid
                        ? 'Saved configuration passes local checks. Provider consent and sign-in remain unverified.'
                        : `Configuration needs attention: ${Object.values(response.data.checks).join(' ')}`,
                );
        } catch (error) {
            if (!alive.current) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 401 || status === 419 || status === 403) {
                command.accessFailed(error);
            } else {
                setCheck(
                    'The local check could not complete. Check your connection and try again; no provider result is available.',
                );
            }
        } finally {
            if (alive.current) setChecking(false);
        }
    }
    return (
        <form
            onSubmit={submit}
            className="space-y-5"
            aria-label={`${label} SSO settings`}
        >
            <Recovery
                command={command}
                onUseSaved={() => {
                    const value = command.acceptCurrent();
                    if (value) setFields(value);
                    clearSecret();
                }}
                onKeepFields={() => {
                    command.acceptCurrent();
                    clearSecret();
                }}
            />
            {!concealed && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            {label} sign-in{' '}
                            <StatusBadge variant="warning">
                                Provider unverified
                            </StatusBadge>
                        </CardTitle>
                        <CardDescription>
                            {command.saved.source === 'deployment'
                                ? 'Reading deployment configuration. Saving creates an application setting; deployment files are unchanged.'
                                : `Saved application configuration · version ${command.saved.version}.`}{' '}
                            This controls sign-in only. Support mailboxes and
                            outbound email have separate settings.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <fieldset disabled={blocked} className="space-y-5">
                            <div className="grid grid-cols-2 gap-5">
                                {provider === 'microsoft' && (
                                    <div className="space-y-2">
                                        <Label htmlFor="sso-directory">
                                            Microsoft directory ID
                                        </Label>
                                        <Input
                                            id="sso-directory"
                                            value={fields.directory_id}
                                            onChange={(event) =>
                                                setFields({
                                                    ...fields,
                                                    directory_id:
                                                        event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!command.errors.directory_id
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            The Entra directory UUID for this
                                            organisation; it applies to
                                            Microsoft sign-in.
                                        </p>
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="sso-client">
                                        Client ID
                                    </Label>
                                    <Input
                                        id="sso-client"
                                        value={fields.client_id}
                                        onChange={(event) =>
                                            setFields({
                                                ...fields,
                                                client_id: event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!command.errors.client_id
                                        }
                                    />
                                </div>
                                {audience !== 'portal' && (
                                    <div className="space-y-2">
                                        <Label htmlFor="sso-domain">
                                            Staff organisation domain
                                        </Label>
                                        <Input
                                            id="sso-domain"
                                            value={fields.domain}
                                            onChange={(event) =>
                                                setFields({
                                                    ...fields,
                                                    domain: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!command.errors.domain
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Exact domain only; Google also
                                            verifies Workspace membership.
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="space-y-3">
                                <Label htmlFor="sso-secret-action">
                                    Client secret
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    {!command.saved.secret_readable
                                        ? 'Saved secret cannot be read. Replace or remove it.'
                                        : command.saved.secret_present
                                          ? `A ${command.saved.secret_source === 'deployment' ? 'deployment-managed' : 'saved'} secret is present and concealed.`
                                          : 'No readable secret is configured.'}{' '}
                                    Leaving the secret unchanged keeps the
                                    current value.
                                </p>
                                <Select
                                    value={secretAction}
                                    onValueChange={(value) => {
                                        setSecretAction(
                                            value as typeof secretAction,
                                        );
                                        setSecret('');
                                        setConfirmRemoval(false);
                                    }}
                                >
                                    <SelectTrigger id="sso-secret-action">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="keep">
                                            Keep current secret
                                        </SelectItem>
                                        <SelectItem value="replace">
                                            Replace secret
                                        </SelectItem>
                                        <SelectItem value="remove">
                                            Remove secret
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {secretAction === 'replace' && (
                                    <div className="space-y-2">
                                        <Label htmlFor="sso-secret">
                                            Replacement secret
                                        </Label>
                                        <Input
                                            id="sso-secret"
                                            type="password"
                                            autoComplete="new-password"
                                            value={secret}
                                            onChange={(event) =>
                                                setSecret(event.target.value)
                                            }
                                            aria-invalid={
                                                !!command.errors.client_secret
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Entered secrets are cleared after
                                            every save attempt and never
                                            included in browser draft storage.
                                        </p>
                                    </div>
                                )}
                                {secretAction === 'remove' && (
                                    <label className="flex items-center gap-3">
                                        <Checkbox
                                            checked={confirmRemoval}
                                            onCheckedChange={(value) =>
                                                setConfirmRemoval(
                                                    value === true,
                                                )
                                            }
                                        />
                                        I understand removal requires disabling
                                        staff and portal sign-in.
                                    </label>
                                )}
                            </div>
                            {audience !== 'portal' && (
                                <Toggle
                                    id="sso-staff"
                                    label={`Enable ${label} staff sign-in`}
                                    description="Current account approval and existing access permissions still apply."
                                    checked={fields.staff_enabled}
                                    onChange={(value) =>
                                        setFields({
                                            ...fields,
                                            staff_enabled: value,
                                        })
                                    }
                                />
                            )}
                            {audience !== 'staff' && (
                                <Toggle
                                    id="sso-portal"
                                    label={`Enable ${label} portal sign-in`}
                                    description="Portal accounts retain their separate role and approval boundary."
                                    checked={fields.portal_enabled}
                                    onChange={(value) =>
                                        setFields({
                                            ...fields,
                                            portal_enabled: value,
                                        })
                                    }
                                />
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit">
                                    Save {label} settings
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={checking}
                                    onClick={() => void checkSaved()}
                                >
                                    Check saved configuration
                                </Button>
                            </div>
                        </fieldset>
                    </CardContent>
                </Card>
            )}
            {command.pending && (
                <div className="flex items-center gap-3">
                    <span role="status">Waiting for settings response…</span>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={command.cancelWait}
                    >
                        Cancel wait
                    </Button>
                </div>
            )}
            {check && (
                <Alert role="status">
                    <AlertDescription>{check}</AlertDescription>
                </Alert>
            )}
        </form>
    );
}

export function SsoProvisioningForm({
    initial,
    onSaved,
    onDirtyChange,
    onAccessLost,
    audience = 'all',
}: {
    initial: ProvisioningConfiguration;
    onSaved: (value: ProvisioningConfiguration) => void;
    onDirtyChange: (dirty: boolean) => void;
    onAccessLost?: (reason: 'session' | 'access') => void;
    audience?: string;
}) {
    const [fields, setFields] = useState(initial);
    const command = useConfiguration(
        initial,
        'provisioning',
        onSaved,
        () => {},
    );
    useEffect(() => {
        if (command.failure === 'session' || command.failure === 'access')
            onAccessLost?.(command.failure);
    }, [command.failure, onAccessLost]);
    const dirty =
        fields.auto_create_staff !== command.saved.auto_create_staff ||
        fields.auto_link_existing !== command.saved.auto_link_existing ||
        fields.portal_auto_create !== command.saved.portal_auto_create ||
        command.pending ||
        !!command.failure;
    useDirtyGuard(dirty, onDirtyChange);
    return (
        <form
            aria-label="SSO provisioning settings"
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                void command.submit({
                    auto_create_staff: fields.auto_create_staff,
                    auto_link_existing: fields.auto_link_existing,
                    portal_auto_create: fields.portal_auto_create,
                });
            }}
        >
            <Recovery
                command={command}
                onUseSaved={() => {
                    const value = command.acceptCurrent();
                    if (value) setFields(value);
                }}
                onKeepFields={() => {
                    command.acceptCurrent();
                }}
            />
            {command.failure !== 'access' && command.failure !== 'session' && (
                <Card>
                    <CardHeader>
                        <CardTitle>Account provisioning</CardTitle>
                        <CardDescription>
                            New accounts always require administrator approval.
                            Default staff access is Support worker; new portal
                            access is Next of kin. These governed rules cannot
                            be broadened here.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <fieldset
                            disabled={
                                command.pending ||
                                (!!command.failure &&
                                    command.failure !== 'validation')
                            }
                            className="space-y-5"
                        >
                            {audience !== 'portal' && (
                                <>
                                    <Toggle
                                        id="sso-create-staff"
                                        label="Create pending staff accounts"
                                        description="Permit first sign-in from the configured staff organisation to prepare an unapproved account."
                                        checked={fields.auto_create_staff}
                                        onChange={(value) =>
                                            setFields({
                                                ...fields,
                                                auto_create_staff: value,
                                            })
                                        }
                                    />
                                    <Toggle
                                        id="sso-link-pending"
                                        label="Link matching pending staff accounts"
                                        description="Email matching applies only before approval. Approved accounts must explicitly link a new provider from an authenticated profile."
                                        checked={fields.auto_link_existing}
                                        onChange={(value) =>
                                            setFields({
                                                ...fields,
                                                auto_link_existing: value,
                                            })
                                        }
                                    />
                                </>
                            )}
                            {audience !== 'staff' && (
                                <Toggle
                                    id="sso-create-portal"
                                    label="Create pending portal accounts"
                                    description="Prepare an unapproved portal account without granting client, site or staff access."
                                    checked={fields.portal_auto_create}
                                    onChange={(value) =>
                                        setFields({
                                            ...fields,
                                            portal_auto_create: value,
                                        })
                                    }
                                />
                            )}
                            <Button type="submit">
                                Save provisioning settings
                            </Button>
                        </fieldset>
                    </CardContent>
                </Card>
            )}
            {command.pending && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={command.cancelWait}
                >
                    Cancel wait
                </Button>
            )}
        </form>
    );
}
