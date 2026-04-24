import { Head, router, usePage } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle, Loader2, Mail, Send, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Email', href: '/settings/email' },
];

type EmailProvider = 'smtp' | 'microsoft' | 'google';

type EmailSettingsPageProps = {
    settings: {
        provider: EmailProvider;
        smtp_host: string;
        smtp_port: number;
        smtp_encryption: 'tls' | 'ssl' | 'none';
        smtp_username: string;
        from_address: string;
        from_name: string;
    };
    connections: {
        microsoft: { connected: boolean; email: string | null };
        google: { connected: boolean; email: string | null };
    };
    smtp_password_saved: boolean;
    flash?: {
        success?: string | null;
        error?: string | null;
        warning?: string | null;
    };
};

export default function EmailSettings() {
    const { settings, connections, smtp_password_saved, flash } = usePage<EmailSettingsPageProps>().props;
    const [submitAction, setSubmitAction] = useState<'save' | 'test' | null>(null);
    const [formData, setFormData] = useState({
        provider: settings.provider,
        smtp_host: settings.smtp_host ?? '',
        smtp_port: String(settings.smtp_port ?? 587),
        smtp_encryption: settings.smtp_encryption ?? 'tls',
        smtp_username: settings.smtp_username ?? '',
        smtp_password: '',
        from_address: settings.from_address ?? '',
        from_name: settings.from_name ?? '',
    });

    const settingsKey = useMemo(() => JSON.stringify(settings), [settings]);

    useEffect(() => {
        setFormData({
            provider: settings.provider,
            smtp_host: settings.smtp_host ?? '',
            smtp_port: String(settings.smtp_port ?? 587),
            smtp_encryption: settings.smtp_encryption ?? 'tls',
            smtp_username: settings.smtp_username ?? '',
            smtp_password: '',
            from_address: settings.from_address ?? '',
            from_name: settings.from_name ?? '',
        });
    }, [settingsKey]);

    const processing = submitAction !== null;
    const saving = submitAction === 'save';
    const testing = submitAction === 'test';

    const handleSave = () => {
        setSubmitAction('save');
        router.put('/settings/email', {
            ...formData,
            smtp_port: Number(formData.smtp_port || 0),
        }, {
            preserveScroll: true,
            onFinish: () => setSubmitAction(null),
        });
    };

    const handleTest = () => {
        setSubmitAction('test');
        router.post('/settings/email/test', {
            ...formData,
            smtp_port: Number(formData.smtp_port || 0),
        }, {
            preserveScroll: true,
            onFinish: () => setSubmitAction(null),
        });
    };

    const renderProviderBadge = (connected: boolean, dusk: string) =>
        connected ? (
            <Badge
                dusk={dusk}
                variant="outline"
                className="border-status-success/30 bg-status-success-bg text-status-success"
            >
                <CheckCircle className="mr-1 h-3 w-3" />
                Connected
            </Badge>
        ) : (
            <Badge
                dusk={dusk}
                variant="outline"
                className="border-status-warning/30 bg-status-warning-bg text-status-warning"
            >
                <XCircle className="mr-1 h-3 w-3" />
                Not connected
            </Badge>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email settings" />

            <SettingsLayout>
                {(flash?.success || flash?.warning || flash?.error) && (
                    <Card>
                        <CardContent className="space-y-2 py-4">
                            {flash?.success && (
                                <div className="flex items-center gap-2 text-sm font-medium text-status-success">
                                    <CheckCircle className="h-4 w-4" />
                                    {flash.success}
                                </div>
                            )}
                            {flash?.warning && (
                                <div className="flex items-center gap-2 text-sm font-medium text-status-warning">
                                    <XCircle className="h-4 w-4" />
                                    {flash.warning}
                                </div>
                            )}
                            {flash?.error && (
                                <div className="flex items-center gap-2 text-sm font-medium text-status-critical">
                                    <XCircle className="h-4 w-4" />
                                    {flash.error}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Mail className="h-5 w-5 text-primary" />
                            Email Configuration
                        </CardTitle>
                        <CardDescription>
                            Configure how the application sends emails.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">Email Provider</Label>
                            <div className="grid gap-3">
                                <button
                                    type="button"
                                    dusk="email-provider-smtp"
                                    onClick={() => setFormData((current) => ({ ...current, provider: 'smtp' }))}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        formData.provider === 'smtp'
                                            ? 'border-primary bg-primary/10/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            formData.provider === 'smtp'
                                                ? 'border-primary'
                                                : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {formData.provider === 'smtp' && (
                                            <div className="h-2 w-2 rounded-full bg-primary" />
                                        )}
                                    </div>
                                    <div>
                                        <div className="text-sm font-medium">SMTP</div>
                                        <div className="text-xs text-muted-foreground">
                                            Standard SMTP server or local mailer configuration.
                                        </div>
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    dusk="email-provider-microsoft"
                                    onClick={() => setFormData((current) => ({ ...current, provider: 'microsoft' }))}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        formData.provider === 'microsoft'
                                            ? 'border-primary bg-primary/10/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            formData.provider === 'microsoft'
                                                ? 'border-primary'
                                                : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {formData.provider === 'microsoft' && (
                                            <div className="h-2 w-2 rounded-full bg-primary" />
                                        )}
                                    </div>
                                    <div className="flex-1">
                                        <div className="text-sm font-medium">Microsoft 365</div>
                                        <div className="text-xs text-muted-foreground">
                                            Use a linked Microsoft identity when available.
                                        </div>
                                        {connections.microsoft.email && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {connections.microsoft.email}
                                            </div>
                                        )}
                                    </div>
                                    {renderProviderBadge(connections.microsoft.connected, 'email-provider-status-microsoft')}
                                </button>

                                <button
                                    type="button"
                                    dusk="email-provider-google"
                                    onClick={() => setFormData((current) => ({ ...current, provider: 'google' }))}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        formData.provider === 'google'
                                            ? 'border-primary bg-primary/10/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            formData.provider === 'google'
                                                ? 'border-primary'
                                                : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {formData.provider === 'google' && (
                                            <div className="h-2 w-2 rounded-full bg-primary" />
                                        )}
                                    </div>
                                    <div className="flex-1">
                                        <div className="text-sm font-medium">Google Workspace</div>
                                        <div className="text-xs text-muted-foreground">
                                            Use a linked Google identity when available.
                                        </div>
                                        {connections.google.email && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {connections.google.email}
                                            </div>
                                        )}
                                    </div>
                                    {renderProviderBadge(connections.google.connected, 'email-provider-status-google')}
                                </button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {formData.provider === 'smtp' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>SMTP Settings</CardTitle>
                            <CardDescription>
                                Values from the current mail configuration are used as defaults until you save overrides here.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-host">Host</Label>
                                    <Input
                                        id="smtp-host"
                                        dusk="email-smtp-host"
                                        placeholder="smtp.example.com"
                                        value={formData.smtp_host}
                                        onChange={(event) => setFormData((current) => ({ ...current, smtp_host: event.target.value }))}
                                    />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp-port">Port</Label>
                                        <Input
                                            id="smtp-port"
                                            dusk="email-smtp-port"
                                            inputMode="numeric"
                                            placeholder="587"
                                            value={formData.smtp_port}
                                            onChange={(event) => setFormData((current) => ({ ...current, smtp_port: event.target.value }))}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="smtp-encryption">Encryption</Label>
                                        <select
                                            id="smtp-encryption"
                                            dusk="email-smtp-encryption"
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                                            value={formData.smtp_encryption}
                                            onChange={(event) =>
                                                setFormData((current) => ({
                                                    ...current,
                                                    smtp_encryption: event.target.value as 'tls' | 'ssl' | 'none',
                                                }))
                                            }
                                        >
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="none">None</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-username">Username</Label>
                                    <Input
                                        id="smtp-username"
                                        dusk="email-smtp-username"
                                        placeholder="user@example.com"
                                        value={formData.smtp_username}
                                        onChange={(event) => setFormData((current) => ({ ...current, smtp_username: event.target.value }))}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-password">Password / App Password</Label>
                                    <Input
                                        id="smtp-password"
                                        dusk="email-smtp-password"
                                        type="password"
                                        placeholder={smtp_password_saved ? 'Leave blank to keep the saved password' : 'Enter SMTP password'}
                                        value={formData.smtp_password}
                                        onChange={(event) => setFormData((current) => ({ ...current, smtp_password: event.target.value }))}
                                    />
                                    {smtp_password_saved && (
                                        <p className="text-xs text-muted-foreground">
                                            A saved SMTP password is already on file. Leave this blank to keep it.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {formData.provider === 'microsoft' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Microsoft 365 Connection</CardTitle>
                            <CardDescription>
                                Link a Microsoft account to send email through Microsoft Graph.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-4 rounded-lg border p-4">
                                <div className="flex-1">
                                    <div className="text-sm font-medium">
                                        {connections.microsoft.connected
                                            ? 'Connected via Microsoft SSO'
                                            : 'Microsoft account not connected'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {connections.microsoft.connected
                                            ? `Linked as ${connections.microsoft.email ?? 'your Microsoft account'}.`
                                            : 'Connect a Microsoft account before using Microsoft delivery.'}
                                    </div>
                                </div>
                                {!connections.microsoft.connected && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/auth/microsoft/redirect?link=1" dusk="email-connect-microsoft">
                                            Connect Microsoft
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {formData.provider === 'google' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Google Workspace Connection</CardTitle>
                            <CardDescription>
                                Link a Google account to use Google Workspace delivery when that transport is available.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-4 rounded-lg border p-4">
                                <div className="flex-1">
                                    <div className="text-sm font-medium">
                                        {connections.google.connected
                                            ? 'Connected via Google'
                                            : 'Google account not connected'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {connections.google.connected
                                            ? `Linked as ${connections.google.email ?? 'your Google account'}.`
                                            : 'Connect a Google account to prepare for Google Workspace delivery.'}
                                    </div>
                                </div>
                                {!connections.google.connected && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/auth/google/redirect?link=1" dusk="email-connect-google">
                                            Connect Google
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Sender Details</CardTitle>
                        <CardDescription>
                            Default sender information for outgoing emails.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="from-address">From Address</Label>
                                <Input
                                    id="from-address"
                                    dusk="email-from-address"
                                    type="email"
                                    placeholder="noreply@yourorganisation.co.nz"
                                    value={formData.from_address}
                                    onChange={(event) => setFormData((current) => ({ ...current, from_address: event.target.value }))}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="from-name">From Name</Label>
                                <Input
                                    id="from-name"
                                    dusk="email-from-name"
                                    placeholder="Your Organisation"
                                    value={formData.from_name}
                                    onChange={(event) => setFormData((current) => ({ ...current, from_name: event.target.value }))}
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center gap-4">
                    <Button
                        dusk="email-test"
                        variant="outline"
                        type="button"
                        onClick={handleTest}
                        disabled={processing}
                    >
                        {testing ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Sending test...
                            </>
                        ) : (
                            <>
                                <Send className="mr-2 h-4 w-4" />
                                Send Test Email
                            </>
                        )}
                    </Button>
                    <Button
                        dusk="email-save"
                        type="button"
                        onClick={handleSave}
                        disabled={processing}
                        className="bg-primary hover:bg-primary"
                    >
                        {saving ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Saving...
                            </>
                        ) : (
                            'Save settings'
                        )}
                    </Button>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
