import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import {
    MAILBOX_LABELS,
    validMailboxConnections,
    type MailboxConnection,
    type MailboxConnections,
    type MailboxProvider,
} from '@/components/settings/it-mailbox-contract';
import { MailboxProviderPanel } from '@/components/settings/it-mailbox-provider';
import { MailboxQuarantinePanel } from '@/components/settings/it-mailbox-quarantine';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Inbox } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const providers: MailboxProvider[] = ['microsoft', 'google'];
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Support mailbox', href: '/settings/it-mailbox' },
];

export default function ItMailboxSettings() {
    const initial = usePage<{ connections: MailboxConnections }>().props
        .connections;
    const [connections, setConnections] = useState<MailboxConnections | null>(
        initial,
    );
    const [provider, setProvider] = useState<MailboxProvider>('microsoft');
    const [filter, setFilter] = useState('all');
    const [dirty, setDirty] = useState(false);
    const [quarantineDirty, setQuarantineDirty] = useState(false);
    const [panelRevision, setPanelRevision] = useState(0);
    const [reloading, setReloading] = useState(false);
    const [recoveryMessage, setRecoveryMessage] = useState(
        'Your session or access changed. Mailbox details and unsaved entries are concealed. Sign in if needed, then reload using your current access.',
    );
    const recovery = useRef<HTMLDivElement>(null);
    const abort = useRef<AbortController | null>(null);
    const leave = useSettingsLeaveConfirmation(
        dirty || quarantineDirty,
        'Discard unsaved mailbox entries?',
    );
    const needsAttention = (connection: MailboxConnection) =>
        !!connection.last_error ||
        connection.counts.awaiting_processing +
            connection.counts.awaiting_acknowledgement +
            connection.counts.quarantined >
            0;
    const visible = providers.filter(
        (key) =>
            filter === 'all' ||
            (connections && needsAttention(connections[key])),
    );
    const active = visible.includes(provider) ? provider : (visible[0] ?? null);
    const onAccessLost = useCallback(() => {
        setConnections(null);
        setDirty(false);
        setQuarantineDirty(false);
    }, []);
    const onSaved = useCallback(
        (connection: MailboxConnection) => {
            if (active)
                setConnections((current) =>
                    current ? { ...current, [active]: connection } : null,
                );
        },
        [active],
    );
    useEffect(() => {
        if (!connections) recovery.current?.focus();
    }, [connections]);
    useEffect(() => () => abort.current?.abort(), []);
    function select(key: MailboxProvider) {
        leave.request(() => {
            setFilter('all');
            setProvider(key);
            setPanelRevision((current) => current + 1);
            setDirty(false);
            setQuarantineDirty(false);
        });
    }
    async function reloadAccess() {
        if (reloading) return;
        setReloading(true);
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.get('/settings/it-mailbox', {
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            if (!validMailboxConnections(response.data?.connections))
                throw new Error('Invalid mailbox state.');
            if (!controller.signal.aborted)
                setConnections(response.data.connections);
        } catch {
            if (!controller.signal.aborted)
                setRecoveryMessage(
                    'Mailbox settings are still unavailable with the current session. Sign in again if needed, then retry loading them.',
                );
        } finally {
            if (!controller.signal.aborted) setReloading(false);
        }
    }
    const sum = (field: keyof MailboxConnection['counts']) =>
        connections
            ? providers.reduce(
                  (total, key) => total + connections[key].counts[field],
                  0,
              )
            : 0;
    function openCount(field: keyof MailboxConnection['counts']) {
        select(
            providers.find(
                (key) => connections && connections[key].counts[field] > 0,
            ) ?? 'microsoft',
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Support mailbox" />
            <SettingsLayout>
                <div className="space-y-5">
                    <PageHeader
                        icon={Inbox}
                        title="Support mailbox"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={connections ? 'info' : 'warning'}
                            >
                                {connections
                                    ? 'One organisation'
                                    : 'Access required'}
                            </PageHeaderStatusChip>
                        }
                        subline="Microsoft and Gmail intake · Connection settings and recovery"
                        actions={
                            <PageHeaderGlassButton
                                onClick={() => router.visit('/settings/sso')}
                            >
                                SSO configuration
                            </PageHeaderGlassButton>
                        }
                        meters={
                            connections ? (
                                <>
                                    <PageHeaderMeterBlock
                                        label="Authorizations"
                                        onClick={() =>
                                            select(
                                                providers.find(
                                                    (key) =>
                                                        connections[key].id !==
                                                        null,
                                                ) ?? 'microsoft',
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {
                                                providers.filter(
                                                    (key) =>
                                                        connections[key].id !==
                                                        null,
                                                ).length
                                            }
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Review connected accounts
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="To process"
                                        onClick={() =>
                                            openCount('awaiting_processing')
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {sum('awaiting_processing')}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Discovered inbound records
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="To acknowledge"
                                        onClick={() =>
                                            openCount(
                                                'awaiting_acknowledgement',
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {sum('awaiting_acknowledgement')}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Read flag not confirmed
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Quarantined"
                                        onClick={() => openCount('quarantined')}
                                    >
                                        <PageHeaderMeterBig>
                                            {sum('quarantined')}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Excluded from automatic ticketing
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            ) : undefined
                        }
                        filters={
                            connections ? (
                                <PageHeaderFilterSelect
                                    label="Show"
                                    value={filter}
                                    onChange={(value) =>
                                        leave.request(() => {
                                            setFilter(value);
                                            setPanelRevision(
                                                (current) => current + 1,
                                            );
                                            setDirty(false);
                                            setQuarantineDirty(false);
                                        })
                                    }
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All providers',
                                        },
                                        {
                                            value: 'attention',
                                            label: 'Needs attention',
                                        },
                                    ]}
                                />
                            ) : undefined
                        }
                        rail={
                            connections ? (
                                <PageHeaderRail
                                    items={visible.map((key) => ({
                                        key,
                                        label: MAILBOX_LABELS[key],
                                    }))}
                                    value={active ?? ''}
                                    onSelect={(key) => {
                                        if (
                                            providers.includes(
                                                key as MailboxProvider,
                                            )
                                        )
                                            select(key as MailboxProvider);
                                    }}
                                    ariaLabel="Mailbox providers"
                                />
                            ) : undefined
                        }
                    />
                    {leave.confirmation}
                    {!connections ? (
                        <Alert ref={recovery} tabIndex={-1}>
                            <AlertTitle>Current access required</AlertTitle>
                            <AlertDescription>
                                <p>{recoveryMessage}</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button asChild variant="outline">
                                        <a
                                            href="/login"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Sign in again
                                        </a>
                                    </Button>
                                    <Button
                                        type="button"
                                        disabled={reloading}
                                        onClick={() => void reloadAccess()}
                                    >
                                        Reload with current access
                                    </Button>
                                    {reloading && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                abort.current?.abort();
                                                setReloading(false);
                                            }}
                                        >
                                            Stop waiting
                                        </Button>
                                    )}
                                </div>
                            </AlertDescription>
                        </Alert>
                    ) : active ? (
                        <div
                            key={`${active}:${panelRevision}`}
                            className="space-y-5"
                        >
                            <MailboxProviderPanel
                                key={`${active}:${panelRevision}`}
                                provider={active}
                                initial={connections[active]}
                                onSaved={onSaved}
                                onDirtyChange={setDirty}
                                onAccessLost={onAccessLost}
                            />
                            <MailboxQuarantinePanel
                                key={`${active}:${connections[active].id}:${connections[active].version}`}
                                provider={active}
                                connection={connections[active]}
                                onAccessLost={onAccessLost}
                                onDirtyChange={setQuarantineDirty}
                            />
                        </div>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    No providers need attention
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-subtle">
                                    No recorded failure, pending work or
                                    quarantine matches this filter.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="mt-3"
                                    onClick={() => setFilter('all')}
                                >
                                    Show all providers
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
