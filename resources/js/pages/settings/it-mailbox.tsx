import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Inbox, Plug, RefreshCw } from 'lucide-react';
import { useState } from 'react';

type ProviderKey = 'microsoft' | 'google';

type Connection = {
    configured: boolean;
    status: string | null; // connected | disconnected | error | null (never connected)
    account_email: string | null;
    account_name: string | null;
    mailbox_email: string | null;
    effective_mailbox: string | null;
    last_polled_at: string | null;
    last_error: string | null;
};

type PageProps = {
    connections: Record<ProviderKey, Connection>;
};

const PROVIDER_LABELS: Record<ProviderKey, string> = {
    microsoft: 'Microsoft 365 (Exchange)',
    google: 'Google Workspace (Gmail)',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Support Mailbox' },
];

function fmtDate(iso: string | null): string {
    if (!iso) return 'never';
    try {
        return new Date(iso).toLocaleString('en-NZ', { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

export default function ItMailboxSettings() {
    const { connections } = usePage<PageProps>().props;
    const page = usePage<{ flash?: { success?: string; error?: string }; errors?: Record<string, string> }>().props;
    const flash = page.flash;
    const errorList = Object.values(page.errors ?? {});
    const anyConnected = (['microsoft', 'google'] as ProviderKey[]).some(
        (key) => connections[key]?.status === 'connected',
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Support Mailbox" />
            <SettingsLayout>
                <div className="space-y-6">
                    <header className="space-y-1">
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <Inbox className="h-6 w-6 text-primary" />
                            Support Mailbox
                        </h1>
                        <p className="text-muted-foreground max-w-3xl text-sm">
                            Connect the mailbox staff email for IT help. Unread messages are polled hourly and
                            become helpdesk tickets (or thread onto an existing ticket when the subject carries
                            its IT-… reference). Only mail from staff accounts is ticketed.
                        </p>
                    </header>

                    {flash?.success && (
                        <div className="flex items-center gap-2 rounded-lg border border-status-success/30 bg-status-success/10 px-4 py-2.5 text-sm text-status-success">
                            <Check className="h-4 w-4 shrink-0" />
                            {flash.success}
                        </div>
                    )}
                    {(flash?.error || errorList.length > 0) && (
                        <div className="flex items-start gap-2 rounded-lg border border-status-critical/30 bg-status-critical/10 px-4 py-2.5 text-sm text-status-critical">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                {flash?.error}
                                {errorList.map((e, i) => (
                                    <div key={i}>{e}</div>
                                ))}
                            </div>
                        </div>
                    )}

                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                Providers
                            </h2>
                            {anyConnected && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post('/settings/it-mailbox/poll-now', {}, { preserveScroll: true })}
                                >
                                    <RefreshCw className="mr-1.5 h-4 w-4" /> Poll now
                                </Button>
                            )}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['microsoft', 'google'] as ProviderKey[]).map((key) => (
                                <MailboxProviderCard key={key} providerKey={key} connection={connections[key]} />
                            ))}
                        </div>
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function MailboxProviderCard({ providerKey, connection }: { providerKey: ProviderKey; connection: Connection }) {
    const connected = connection.status === 'connected';
    const errored = connection.status === 'error';
    const [mailboxDraft, setMailboxDraft] = useState(connection.mailbox_email ?? '');

    const saveMailbox = () =>
        router.put(
            `/settings/it-mailbox/mailbox/${providerKey}`,
            { mailbox_email: mailboxDraft.trim() === '' ? null : mailboxDraft.trim() },
            { preserveScroll: true },
        );

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base">{PROVIDER_LABELS[providerKey]}</CardTitle>
                    {connected ? (
                        <Badge className="bg-status-success/15 text-status-success hover:bg-status-success/15">
                            Connected
                        </Badge>
                    ) : errored ? (
                        <Badge className="bg-status-critical/15 text-status-critical hover:bg-status-critical/15">
                            Error
                        </Badge>
                    ) : (
                        <Badge variant="outline">Not connected</Badge>
                    )}
                </div>
                <CardDescription>
                    {connected || errored
                        ? `${connection.account_name ?? connection.account_email ?? 'Connected account'} · reads ${connection.effective_mailbox ?? 'its own inbox'} · last poll ${fmtDate(connection.last_polled_at)}`
                        : connection.configured
                          ? providerKey === 'microsoft'
                            ? 'Authorise an account with delegated access to the support mailbox.'
                            : 'Authorise the support account itself — Gmail reads its own inbox.'
                          : 'OAuth client credentials are not configured for this provider yet.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {errored && connection.last_error ? (
                    <p className="rounded-md border border-status-critical/30 bg-status-critical/10 px-3 py-2 text-xs text-status-critical">
                        {connection.last_error}
                    </p>
                ) : null}

                {connected && providerKey === 'microsoft' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="it-mailbox-delegated" className="text-xs">
                            Support mailbox (optional)
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="it-mailbox-delegated"
                                type="email"
                                placeholder={connection.account_email ?? 'support@yourorg.co.nz'}
                                value={mailboxDraft}
                                onChange={(e) => setMailboxDraft(e.target.value)}
                            />
                            <Button type="button" variant="outline" size="sm" onClick={saveMailbox}>
                                Save
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Leave blank to read the connected account’s own inbox. A shared support@ mailbox
                            needs delegated access for the connected account.
                        </p>
                    </div>
                ) : null}

                {connected || errored ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => router.delete(`/settings/it-mailbox/connect/${providerKey}`, { preserveScroll: true })}
                    >
                        Disconnect
                    </Button>
                ) : connection.configured ? (
                    <Button size="sm" asChild>
                        <a href={`/settings/it-mailbox/connect/${providerKey}`}>
                            <Plug className="mr-1.5 h-4 w-4" /> Connect
                        </a>
                    </Button>
                ) : (
                    <Button type="button" size="sm" disabled title="Set OAuth client credentials in the environment first">
                        Not configured
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}
