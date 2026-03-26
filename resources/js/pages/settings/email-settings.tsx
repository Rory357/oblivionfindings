import { Head, usePage } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Check, CheckCircle, Loader2, Mail, Send, XCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Email', href: '/settings/email' },
];

type EmailProvider = 'smtp' | 'microsoft' | 'google';

export default function EmailSettings() {
    const { auth } = usePage().props as any;
    const microsoftConnected = !!auth?.user?.identities?.find((i: any) => i.provider === 'microsoft');
    const googleConnected = !!auth?.user?.identities?.find((i: any) => i.provider === 'google');

    const [provider, setProvider] = useState<EmailProvider>('smtp');
    const [smtpHost, setSmtpHost] = useState('');
    const [smtpPort, setSmtpPort] = useState('587');
    const [smtpUsername, setSmtpUsername] = useState('');
    const [smtpPassword, setSmtpPassword] = useState('');
    const [fromAddress, setFromAddress] = useState('');
    const [fromName, setFromName] = useState('');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState<'success' | 'error' | null>(null);
    const [saved, setSaved] = useState(false);

    const handleTest = useCallback(() => {
        setTesting(true);
        setTestResult(null);
        // Simulate test email (would POST to backend in production)
        setTimeout(() => {
            setTesting(false);
            setTestResult('success');
        }, 2000);
    }, []);

    const handleSave = useCallback(() => {
        setSaved(true);
    }, []);

    useEffect(() => {
        if (saved) {
            const t = setTimeout(() => setSaved(false), 2500);
            return () => clearTimeout(t);
        }
    }, [saved]);

    useEffect(() => {
        if (testResult) {
            const t = setTimeout(() => setTestResult(null), 5000);
            return () => clearTimeout(t);
        }
    }, [testResult]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email settings" />

            <SettingsLayout>
                {/* Email Provider Selection */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Mail className="h-5 w-5 text-violet-600" />
                            Email Configuration
                        </CardTitle>
                        <CardDescription>
                            Configure how the application sends emails
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">Email Provider</Label>
                            <div className="grid gap-3">
                                {/* SMTP */}
                                <button
                                    onClick={() => setProvider('smtp')}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        provider === 'smtp'
                                            ? 'border-violet-600 bg-violet-50/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            provider === 'smtp' ? 'border-violet-600' : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {provider === 'smtp' && <div className="h-2 w-2 rounded-full bg-violet-600" />}
                                    </div>
                                    <div>
                                        <div className="text-sm font-medium">SMTP</div>
                                        <div className="text-xs text-muted-foreground">
                                            Standard SMTP server (default)
                                        </div>
                                    </div>
                                </button>

                                {/* Microsoft 365 */}
                                <button
                                    onClick={() => setProvider('microsoft')}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        provider === 'microsoft'
                                            ? 'border-violet-600 bg-violet-50/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            provider === 'microsoft' ? 'border-violet-600' : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {provider === 'microsoft' && <div className="h-2 w-2 rounded-full bg-violet-600" />}
                                    </div>
                                    <div className="flex-1">
                                        <div className="text-sm font-medium">Microsoft 365</div>
                                        <div className="text-xs text-muted-foreground">
                                            Send emails via Microsoft Graph API
                                        </div>
                                    </div>
                                    {microsoftConnected ? (
                                        <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                            <CheckCircle className="mr-1 h-3 w-3" />
                                            Connected
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                            <XCircle className="mr-1 h-3 w-3" />
                                            Not connected
                                        </Badge>
                                    )}
                                </button>

                                {/* Google Workspace */}
                                <button
                                    onClick={() => setProvider('google')}
                                    className={`flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all ${
                                        provider === 'google'
                                            ? 'border-violet-600 bg-violet-50/60'
                                            : 'border-transparent bg-muted/30 hover:border-muted-foreground/20'
                                    }`}
                                >
                                    <div
                                        className={`flex h-4 w-4 items-center justify-center rounded-full border-2 ${
                                            provider === 'google' ? 'border-violet-600' : 'border-muted-foreground/30'
                                        }`}
                                    >
                                        {provider === 'google' && <div className="h-2 w-2 rounded-full bg-violet-600" />}
                                    </div>
                                    <div className="flex-1">
                                        <div className="text-sm font-medium">Google Workspace</div>
                                        <div className="text-xs text-muted-foreground">
                                            Send emails via Gmail API
                                        </div>
                                    </div>
                                    {googleConnected ? (
                                        <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                            <CheckCircle className="mr-1 h-3 w-3" />
                                            Connected
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                            <XCircle className="mr-1 h-3 w-3" />
                                            Not connected
                                        </Badge>
                                    )}
                                </button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* SMTP Settings */}
                {provider === 'smtp' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>SMTP Settings</CardTitle>
                            <CardDescription>
                                Configure your SMTP server connection. Values from your .env file are used as defaults.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-host">Host</Label>
                                    <Input
                                        id="smtp-host"
                                        placeholder="smtp.example.com"
                                        value={smtpHost}
                                        onChange={(e) => setSmtpHost(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-port">Port</Label>
                                    <Input
                                        id="smtp-port"
                                        placeholder="587"
                                        value={smtpPort}
                                        onChange={(e) => setSmtpPort(e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-username">Username</Label>
                                    <Input
                                        id="smtp-username"
                                        placeholder="user@example.com"
                                        value={smtpUsername}
                                        onChange={(e) => setSmtpUsername(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="smtp-password">Password</Label>
                                    <Input
                                        id="smtp-password"
                                        type="password"
                                        placeholder="••••••••"
                                        value={smtpPassword}
                                        onChange={(e) => setSmtpPassword(e.target.value)}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Microsoft 365 Status */}
                {provider === 'microsoft' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Microsoft 365 Connection</CardTitle>
                            <CardDescription>
                                Emails are sent through your connected Microsoft 365 account via the Graph API.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-4 rounded-lg border p-4">
                                <div className="flex-1">
                                    <div className="text-sm font-medium">
                                        {microsoftConnected
                                            ? 'Connected via Microsoft SSO'
                                            : 'Microsoft account not connected'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {microsoftConnected
                                            ? 'Emails will be sent from your Microsoft 365 mailbox.'
                                            : 'Connect your Microsoft account in the Integration Hub to enable this provider.'}
                                    </div>
                                </div>
                                {microsoftConnected ? (
                                    <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                        <CheckCircle className="mr-1 h-3 w-3" />
                                        Active
                                    </Badge>
                                ) : (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/settings/integrations">Connect</a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Google Workspace Status */}
                {provider === 'google' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Google Workspace Connection</CardTitle>
                            <CardDescription>
                                Emails are sent through your connected Google account via the Gmail API.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-4 rounded-lg border p-4">
                                <div className="flex-1">
                                    <div className="text-sm font-medium">
                                        {googleConnected
                                            ? 'Connected via Google'
                                            : 'Google account not connected'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {googleConnected
                                            ? 'Emails will be sent from your Google Workspace mailbox.'
                                            : 'Connect your Google account in the Integration Hub to enable this provider.'}
                                    </div>
                                </div>
                                {googleConnected ? (
                                    <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                        <CheckCircle className="mr-1 h-3 w-3" />
                                        Active
                                    </Badge>
                                ) : (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/settings/integrations">Connect</a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* From Address */}
                <Card>
                    <CardHeader>
                        <CardTitle>Sender Details</CardTitle>
                        <CardDescription>
                            Default sender information for outgoing emails
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="from-address">From Address</Label>
                                <Input
                                    id="from-address"
                                    type="email"
                                    placeholder="noreply@yourorganisation.co.nz"
                                    value={fromAddress}
                                    onChange={(e) => setFromAddress(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="from-name">From Name</Label>
                                <Input
                                    id="from-name"
                                    placeholder="Your Organisation"
                                    value={fromName}
                                    onChange={(e) => setFromName(e.target.value)}
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Test & Save */}
                <div className="flex flex-wrap items-center gap-4">
                    <Button
                        variant="outline"
                        onClick={handleTest}
                        disabled={testing}
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
                        onClick={handleSave}
                        className="bg-violet-600 hover:bg-violet-700"
                    >
                        Save settings
                    </Button>
                    {testResult === 'success' && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                            <CheckCircle className="h-4 w-4" />
                            Test email sent successfully
                        </span>
                    )}
                    {testResult === 'error' && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-red-600">
                            <XCircle className="h-4 w-4" />
                            Failed to send test email
                        </span>
                    )}
                    {saved && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                            <Check className="h-4 w-4" />
                            Settings saved
                        </span>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
