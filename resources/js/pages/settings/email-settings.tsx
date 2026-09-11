import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import {
    EMAIL_PROVIDERS,
    REPLY_MODES,
    emailTestLabel,
    validEmailSettings,
    validEmailTest,
    type EmailSettingsState,
    type EmailTest,
} from '@/components/settings/email-configuration-contract';
import { EmailConfigurationDialog } from '@/components/settings/email-configuration-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    CheckCircle,
    Inbox,
    Mail,
    MessageSquare,
    Pencil,
    RefreshCw,
    Send,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Email', href: '/settings/email' },
];
type TestPointer = { uuid: string; version: number };

export default function EmailSettings() {
    const initial = usePage<EmailSettingsState>().props;
    const [state, setState] = useState<EmailSettingsState | null>(
        validEmailSettings(initial) ? initial : null,
    );
    const actor = useRef(initial.actor_id);
    const [tab, setTab] = useState<'delivery' | 'testing'>('delivery');
    const [editing, setEditing] = useState(false);
    const [pending, setPending] = useState<'read' | 'test' | 'result' | null>(
        null,
    );
    const [message, setMessage] = useState(
        validEmailSettings(initial)
            ? ''
            : 'Email settings could not be verified. Reload the current state.',
    );
    const [pointer, setPointer] = useState<TestPointer | null>(null);
    const [accessLost, setAccessLost] = useState(false);
    const [needsRead, setNeedsRead] = useState(!validEmailSettings(initial));
    const abort = useRef<AbortController | null>(null);
    const alive = useRef(true);
    const inFlight = useRef(false);
    const alert = useRef<HTMLDivElement>(null);
    const storageKey = `it.email.test.${actor.current}`;
    useEffect(() => {
        alive.current = true;
        try {
            const stored = sessionStorage.getItem(storageKey);
            const value = stored ? JSON.parse(stored) : null;
            if (
                value &&
                typeof value.uuid === 'string' &&
                /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
                    value.uuid,
                ) &&
                Number.isSafeInteger(value.version) &&
                value.version > 0
            )
                setPointer({ uuid: value.uuid, version: value.version });
        } catch {
            /* Recovery also remains available through the saved delivery record. */
        }
        return () => {
            alive.current = false;
            abort.current?.abort();
        };
    }, [storageKey]);
    useEffect(() => {
        if (message) alert.current?.focus();
    }, [message]);

    function retainPointer(next: TestPointer | null) {
        setPointer(next);
        try {
            if (next) sessionStorage.setItem(storageKey, JSON.stringify(next));
            else sessionStorage.removeItem(storageKey);
        } catch {
            /* Keep the in-memory request identity. */
        }
    }
    function loseAccess() {
        abort.current?.abort();
        setEditing(false);
        setState(null);
        setAccessLost(true);
        retainPointer(null);
        setMessage(
            'Your sign-in or permission changed. Email settings and unsaved entries are hidden. Sign in again and reopen this page.',
        );
    }
    function handleFailure(
        error: unknown,
        operation: 'read' | 'test' | 'result',
    ) {
        const status = axios.isAxiosError(error)
            ? error.response?.status
            : undefined;
        if ([401, 403, 419].includes(status ?? 0)) {
            loseAccess();
            return;
        }
        if (operation === 'read') setNeedsRead(true);
        // Existing requests replay before configuration validation on the server.
        // These POST rejections therefore confirm that this request was not submitted.
        if (operation === 'test' && [409, 422].includes(status ?? 0)) {
            retainPointer(null);
            setNeedsRead(true);
        }
        setMessage(
            operation === 'read'
                ? 'Saved settings could not be loaded. Retry the read before editing or testing.'
                : status === 404
                  ? 'No test record was found for this request. A request already sent may still finish; recover the same request before starting another.'
                  : status === 409
                    ? 'The configuration changed or an earlier test is still queued or uncertain. Read the current settings and recorded result before continuing.'
                    : status === 422
                      ? 'The saved support connection is not ready for this test. Review the delivery settings and support mailbox.'
                      : status === 429
                        ? 'Too many test requests. Wait a minute, then check the recorded result before retrying.'
                        : 'The test outcome could not be confirmed. Check its recorded result or recover the same request. Stopping the wait does not undo a send.',
        );
    }
    async function readSettings(openEditor = false) {
        if (inFlight.current || accessLost) return;
        inFlight.current = true;
        setPending('read');
        setMessage('');
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.get('/settings/email', {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!alive.current) return;
            const next: unknown = response.data?.data;
            if (!validEmailSettings(next))
                throw new Error('Unconfirmed settings');
            if (next.actor_id !== actor.current) {
                loseAccess();
                return;
            }
            setState(next);
            setNeedsRead(false);
            if (openEditor && next.can_manage) setEditing(true);
        } catch (error) {
            if (alive.current) handleFailure(error, 'read');
        } finally {
            inFlight.current = false;
            if (alive.current) setPending(null);
        }
    }
    function recordTest(test: EmailTest) {
        setState((current) =>
            current ? { ...current, last_test: test } : current,
        );
        if (!['queued', 'sending'].includes(test.status)) retainPointer(null);
        setMessage(
            test.status === 'accepted'
                ? test.capture_mode
                    ? 'The test was captured locally. No email was sent to an external provider.'
                    : 'The provider accepted the test. This does not confirm delivery; check your inbox and the recorded result.'
                : test.status === 'sending'
                  ? 'The sending outcome is uncertain. Check the recorded result before considering another test.'
                  : test.status === 'queued'
                    ? 'The test is queued. Recover the same request to check or continue it.'
                    : `Recorded test result: ${emailTestLabel(test).toLowerCase()}.`,
        );
    }
    async function runTest(reading = false) {
        if (inFlight.current || !state?.can_manage || accessLost) return;
        if (!reading && needsRead) return;
        const identity =
            pointer ??
            (reading && state.last_test
                ? {
                      uuid: state.last_test.request_uuid,
                      version: state.last_test.configuration_version,
                  }
                : {
                      uuid: crypto.randomUUID(),
                      version: state.settings.configuration_version,
                  });
        if (!reading && !state.settings.support_enabled) return;
        if (!reading) retainPointer(identity);
        inFlight.current = true;
        setPending(reading ? 'result' : 'test');
        setMessage('');
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = reading
                ? await axios.get(`/settings/email/test/${identity.uuid}`, {
                      signal: controller.signal,
                      headers: { Accept: 'application/json' },
                  })
                : await axios.post(
                      '/settings/email/test',
                      {
                          request_uuid: identity.uuid,
                          expected_version: identity.version,
                          expected_actor_id: state.actor_id,
                      },
                      {
                          signal: controller.signal,
                          headers: { Accept: 'application/json' },
                      },
                  );
            if (!alive.current) return;
            const test: unknown = response.data?.data;
            if (
                !validEmailTest(test) ||
                test.request_uuid !== identity.uuid ||
                test.configuration_version !== identity.version
            )
                throw new Error('Unconfirmed test result');
            recordTest(test);
        } catch (error) {
            if (alive.current)
                handleFailure(error, reading ? 'result' : 'test');
        } finally {
            inFlight.current = false;
            if (alive.current) setPending(null);
        }
    }
    const settings = state?.settings;
    const connection = state?.connections.find(
        (item) => item.id === settings?.support_connection_id,
    );
    const unsettled =
        state?.last_test &&
        ['queued', 'sending'].includes(state.last_test.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email settings" />
            <SettingsLayout>
                <div className="space-y-5">
                    <PageHeader
                        icon={Mail}
                        title="Email settings"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    accessLost || state?.delivery_issue
                                        ? 'warning'
                                        : 'info'
                                }
                            >
                                {accessLost
                                    ? 'Access required'
                                    : state?.capture_mode
                                      ? 'Local capture'
                                      : settings?.support_enabled
                                        ? 'Support delivery enabled'
                                        : 'Server mail settings'}
                            </PageHeaderStatusChip>
                        }
                        subline="IT support messages · Sender identity, public replies and delivery checks"
                        actions={
                            <>
                                <PageHeaderGlassButton
                                    icon={RefreshCw}
                                    disabled={pending !== null || accessLost}
                                    onClick={() => void readSettings()}
                                >
                                    Read saved settings
                                </PageHeaderGlassButton>
                                {state?.can_manage && (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        disabled={pending !== null}
                                        onClick={() => void readSettings(true)}
                                    >
                                        Edit email settings
                                    </PageHeaderGlassButton>
                                )}
                            </>
                        }
                        meters={
                            state && (
                                <>
                                    <PageHeaderMeterBlock
                                        label="Provider"
                                        onClick={() => setTab('delivery')}
                                    >
                                        <PageHeaderMeterBig>
                                            <span className="text-section-title">
                                                {settings?.support_enabled
                                                    ? EMAIL_PROVIDERS[
                                                          state.settings
                                                              .provider
                                                      ]
                                                    : 'Server default'}
                                            </span>
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Review delivery settings
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Public replies"
                                        onClick={() => setTab('delivery')}
                                    >
                                        <PageHeaderMeterBig>
                                            <span className="text-section-title">
                                                {settings?.support_enabled
                                                    ? REPLY_MODES[
                                                          state.settings
                                                              .public_reply_mode
                                                      ]
                                                    : 'Link only'}
                                            </span>
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Review privacy and content
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    {state.can_manage && (
                                        <PageHeaderMeterBlock
                                            label="Connected mailboxes"
                                            onClick={() =>
                                                router.visit(
                                                    '/settings/it-mailbox',
                                                )
                                            }
                                        >
                                            <PageHeaderMeterBig>
                                                {
                                                    state.connections.filter(
                                                        (item) =>
                                                            item.connected,
                                                    ).length
                                                }
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Manage support connections
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    )}
                                    {state.can_manage && (
                                        <PageHeaderMeterBlock
                                            label="Latest test attempts"
                                            onClick={() => setTab('testing')}
                                        >
                                            <PageHeaderMeterBig>
                                                {state.last_test
                                                    ?.attempt_count ?? '—'}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {emailTestLabel(
                                                    state.last_test,
                                                )}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    )}
                                </>
                            )
                        }
                        rail={
                            <PageHeaderRail
                                items={[
                                    {
                                        key: 'delivery',
                                        label: 'Delivery settings',
                                        icon: Mail,
                                    },
                                    {
                                        key: 'testing',
                                        label: 'Test and recovery',
                                        icon: ShieldCheck,
                                    },
                                ]}
                                value={tab}
                                onSelect={setTab}
                                ariaLabel="Email settings views"
                            />
                        }
                    />
                    {message && (
                        <Alert ref={alert} tabIndex={-1} role="alert">
                            <AlertCircle />
                            <AlertTitle>Email settings update</AlertTitle>
                            <AlertDescription>
                                <p>{message}</p>
                                {pending && (
                                    <Button
                                        variant="outline"
                                        onClick={() => abort.current?.abort()}
                                    >
                                        Stop waiting
                                    </Button>
                                )}
                                {accessLost && (
                                    <Button
                                        variant="outline"
                                        onClick={() => router.visit('/login')}
                                    >
                                        Sign in again
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {pending && !message && (
                        <Alert role="status">
                            <AlertTitle>
                                {pending === 'read'
                                    ? 'Reading saved settings…'
                                    : pending === 'test'
                                      ? 'Waiting for the test result…'
                                      : 'Checking the recorded result…'}
                            </AlertTitle>
                            <AlertDescription>
                                <Button
                                    variant="outline"
                                    onClick={() => abort.current?.abort()}
                                >
                                    Stop waiting
                                </Button>
                            </AlertDescription>
                        </Alert>
                    )}
                    {state && settings && (
                        <>
                            {state.capture_mode && (
                                <Alert>
                                    <ShieldCheck />
                                    <AlertTitle>
                                        Local mail capture is active
                                    </AlertTitle>
                                    <AlertDescription>
                                        Messages remain in the local capture or
                                        log. This environment does not send
                                        support emails to an external provider.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {state.delivery_issue && (
                                <Alert>
                                    <AlertCircle />
                                    <AlertTitle>
                                        Support delivery needs attention
                                    </AlertTitle>
                                    <AlertDescription>
                                        {state.delivery_issue}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {!state.can_manage && (
                                <Alert>
                                    <ShieldCheck />
                                    <AlertTitle>View access</AlertTitle>
                                    <AlertDescription>
                                        Changing or testing delivery requires
                                        both email-settings management and
                                        integration-credentials permission.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {tab === 'delivery' ? (
                                <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <Mail className="size-4" />
                                                Delivery and sender
                                            </CardTitle>
                                            <CardDescription>
                                                Saved configuration version{' '}
                                                {settings.configuration_version}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <ReviewRow
                                                label="IT support settings"
                                                value={
                                                    settings.support_enabled
                                                        ? 'Enabled'
                                                        : 'Disabled — server mailer applies'
                                                }
                                            />
                                            <ReviewRow
                                                label="Saved provider"
                                                value={
                                                    EMAIL_PROVIDERS[
                                                        settings.provider
                                                    ]
                                                }
                                            />
                                            <ReviewRow
                                                label="Sender name"
                                                value={settings.from_name}
                                            />
                                            <ReviewRow
                                                label="Support mailbox"
                                                value={
                                                    connection?.mailbox_email ??
                                                    (settings.support_connection_id
                                                        ? 'Connection details require review'
                                                        : 'Not selected')
                                                }
                                            />
                                            {settings.provider === 'smtp' && (
                                                <>
                                                    <ReviewRow
                                                        label="SMTP host / port"
                                                        value={`${settings.smtp_host || 'Not set'}:${settings.smtp_port}`}
                                                    />
                                                    <ReviewRow
                                                        label="Saved SMTP password"
                                                        value={
                                                            state.smtp_password_saved
                                                                ? 'Present — value hidden'
                                                                : 'No saved override'
                                                        }
                                                    />
                                                </>
                                            )}
                                            {state.can_manage && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.visit(
                                                            '/settings/it-mailbox',
                                                        )
                                                    }
                                                >
                                                    <Inbox className="size-4" />
                                                    Manage support mailbox
                                                </Button>
                                            )}
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <MessageSquare className="size-4" />
                                                Public replies and privacy
                                            </CardTitle>
                                            <CardDescription>
                                                Clear boundaries for what leaves
                                                a ticket.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <ReviewRow
                                                label="Active content mode"
                                                value={
                                                    settings.support_enabled
                                                        ? REPLY_MODES[
                                                              settings
                                                                  .public_reply_mode
                                                          ]
                                                        : 'Link only'
                                                }
                                            />
                                            <p className="text-subtle">
                                                {settings.support_enabled &&
                                                settings.public_reply_mode ===
                                                    'full_reply'
                                                    ? 'Public reply text is included in the email. Files are available by opening the ticket.'
                                                    : 'Recipients receive a notification with a link to read the reply in the application.'}
                                            </p>
                                            <p className="text-subtle">
                                                Internal notes are never
                                                external messages. Access to the
                                                ticket and its files is checked
                                                against the recipient’s current
                                                permissions.
                                            </p>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setTab('testing')
                                                }
                                            >
                                                <ShieldCheck className="size-4" />
                                                Review delivery checks
                                            </Button>
                                        </CardContent>
                                    </Card>
                                </div>
                            ) : (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <ShieldCheck className="size-4" />
                                            Test delivery and recover a request
                                        </CardTitle>
                                        <CardDescription>
                                            A test goes only to the signed-in
                                            settings owner. Accepted by a
                                            provider does not mean delivered.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {!state.can_manage ? (
                                            <Alert>
                                                <AlertTitle>
                                                    Test access is restricted
                                                </AlertTitle>
                                                <AlertDescription>
                                                    Your role can view settings
                                                    but cannot run delivery
                                                    tests or view their results.
                                                </AlertDescription>
                                            </Alert>
                                        ) : state.last_test ? (
                                            <>
                                                <StatusBadge
                                                    variant={
                                                        [
                                                            'failed',
                                                            'bounced',
                                                            'sending',
                                                        ].includes(
                                                            state.last_test
                                                                .status,
                                                        )
                                                            ? 'warning'
                                                            : state.last_test
                                                                    .status ===
                                                                'queued'
                                                              ? 'info'
                                                              : 'success'
                                                    }
                                                >
                                                    {[
                                                        'failed',
                                                        'bounced',
                                                        'sending',
                                                    ].includes(
                                                        state.last_test.status,
                                                    ) ? (
                                                        <AlertCircle className="mr-1 inline size-3.5" />
                                                    ) : state.last_test
                                                          .status ===
                                                      'queued' ? (
                                                        <Inbox className="mr-1 inline size-3.5" />
                                                    ) : (
                                                        <CheckCircle className="mr-1 inline size-3.5" />
                                                    )}
                                                    {emailTestLabel(
                                                        state.last_test,
                                                    )}
                                                </StatusBadge>
                                                <div className="grid grid-cols-2 gap-x-8">
                                                    <ReviewRow
                                                        label="Recipient"
                                                        value={
                                                            state.last_test
                                                                .recipient_email
                                                        }
                                                    />
                                                    <ReviewRow
                                                        label="Settings version"
                                                        value={
                                                            state.last_test
                                                                .configuration_version
                                                        }
                                                    />
                                                    <ReviewRow
                                                        label="Requested"
                                                        value={formatDateTime(
                                                            state.last_test
                                                                .created_at,
                                                        )}
                                                    />
                                                    <ReviewRow
                                                        label="Attempts"
                                                        value={
                                                            state.last_test
                                                                .attempt_count
                                                        }
                                                    />
                                                </div>
                                            </>
                                        ) : (
                                            <EmptyState
                                                variant="compact"
                                                icon={Inbox}
                                                title="No recorded test"
                                                description="Save and enable support delivery settings before testing."
                                            />
                                        )}
                                        {pointer && (
                                            <Alert>
                                                <AlertTitle>
                                                    A test request is retained
                                                    for recovery
                                                </AlertTitle>
                                                <AlertDescription>
                                                    Check the recorded result or
                                                    recover this same request. A
                                                    stopped wait does not cancel
                                                    mail already submitted.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        {state.can_manage && (
                                            <div className="flex flex-wrap gap-3">
                                                <Button
                                                    disabled={
                                                        pending !== null ||
                                                        needsRead ||
                                                        !settings.support_enabled ||
                                                        state.delivery_issue !==
                                                            null ||
                                                        Boolean(
                                                            unsettled &&
                                                            !pointer,
                                                        )
                                                    }
                                                    onClick={() =>
                                                        void runTest()
                                                    }
                                                    aria-busy={
                                                        pending === 'test'
                                                    }
                                                >
                                                    <Send className="size-4" />
                                                    {pointer
                                                        ? 'Recover same test request'
                                                        : 'Send test to me'}
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        pending !== null ||
                                                        (!pointer &&
                                                            !state.last_test)
                                                    }
                                                    onClick={() =>
                                                        void runTest(true)
                                                    }
                                                >
                                                    <RefreshCw className="size-4" />
                                                    Check recorded result
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={pending !== null}
                                                    onClick={() =>
                                                        void readSettings(true)
                                                    }
                                                >
                                                    <Pencil className="size-4" />
                                                    Review configuration
                                                </Button>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </>
                    )}
                    {editing && state?.can_manage && (
                        <EmailConfigurationDialog
                            initial={state}
                            onSaved={setState}
                            onAccessLost={loseAccess}
                            onClose={() => {
                                setEditing(false);
                                void readSettings();
                            }}
                        />
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
