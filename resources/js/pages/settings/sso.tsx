import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Globe, Key, Lock, Shield, ShieldCheck, Users, XCircle } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'SSO Configuration' },
];

export default function SsoSettings() {
    // These would come from backend in production
    const [microsoftEnabled, setMicrosoftEnabled] = useState(true);
    const [googleEnabled, setGoogleEnabled] = useState(true);
    const [portalMicrosoftEnabled, setPortalMicrosoftEnabled] = useState(true);
    const [portalGoogleEnabled, setPortalGoogleEnabled] = useState(true);
    const [autoProvision, setAutoProvision] = useState(false);
    const [requireApproval, setRequireApproval] = useState(true);

    const microsoftConfigured = !!(process.env.MICROSOFT_CLIENT_ID); // placeholder
    const googleConfigured = !!(process.env.GOOGLE_CLIENT_ID); // placeholder

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSO Configuration" />
            <SettingsLayout>
                <div className="space-y-6">

                    {/* Overview Stats */}
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card className="border-l-4 border-l-[#00a4ef]">
                            <CardContent className="pt-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Microsoft 365</p>
                                        <div className="mt-1 flex items-center gap-2">
                                            <Badge className="bg-emerald-100 text-emerald-700 text-xs">Configured</Badge>
                                        </div>
                                    </div>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#00a4ef]/10">
                                        <svg viewBox="0 0 23 23" className="h-5 w-5"><path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/></svg>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-[#4285F4]">
                            <CardContent className="pt-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Google Workspace</p>
                                        <div className="mt-1 flex items-center gap-2">
                                            <Badge className="bg-emerald-100 text-emerald-700 text-xs">Configured</Badge>
                                        </div>
                                    </div>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#4285F4]/10">
                                        <svg viewBox="0 0 24 24" className="h-5 w-5"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-violet-500">
                            <CardContent className="pt-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Group Mappings</p>
                                        <div className="mt-1">
                                            <Link href="/settings/sso-groups" className="text-sm text-violet-600 hover:underline flex items-center gap-1">
                                                Manage mappings <ArrowRight className="h-3 w-3" />
                                            </Link>
                                        </div>
                                    </div>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-100">
                                        <Users className="h-5 w-5 text-violet-600" />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Microsoft 365 Configuration */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <svg viewBox="0 0 23 23" className="h-5 w-5"><path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/></svg>
                                Microsoft 365 / Entra ID
                            </CardTitle>
                            <CardDescription>Configure Microsoft single sign-on for staff and portal users</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Tenant ID</Label>
                                    <Input placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" defaultValue="" />
                                    <p className="text-xs text-muted-foreground">Your Microsoft Entra ID (Azure AD) tenant ID</p>
                                </div>
                                <div className="space-y-2">
                                    <Label>Client ID</Label>
                                    <Input placeholder="Application (client) ID" defaultValue="" />
                                </div>
                                <div className="space-y-2">
                                    <Label>Client Secret</Label>
                                    <Input type="password" placeholder="••••••••" defaultValue="" />
                                    <p className="text-xs text-muted-foreground">Keep this secret — never share it</p>
                                </div>
                                <div className="space-y-2">
                                    <Label>Organisation Domain</Label>
                                    <Input placeholder="e.g. yourcompany.co.nz" defaultValue="" />
                                    <p className="text-xs text-muted-foreground">Only emails from this domain can sign in as staff</p>
                                </div>
                            </div>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">Enable Microsoft SSO for staff</p>
                                        <p className="text-xs text-muted-foreground">Show "Sign in with Microsoft" on staff login page</p>
                                    </div>
                                    <Switch checked={microsoftEnabled} onCheckedChange={setMicrosoftEnabled} />
                                </div>
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">Enable Microsoft SSO for portal</p>
                                        <p className="text-xs text-muted-foreground">Allow clients/whānau to sign in with personal Microsoft accounts</p>
                                    </div>
                                    <Switch checked={portalMicrosoftEnabled} onCheckedChange={setPortalMicrosoftEnabled} />
                                </div>
                            </div>
                            <div className="rounded-lg bg-blue-50 p-4 text-sm dark:bg-blue-950/20">
                                <p className="font-medium text-blue-800 dark:text-blue-300">Required API Permissions</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {['User.Read', 'Mail.Send', 'Calendars.ReadWrite', 'GroupMember.Read.All'].map(scope => (
                                        <Badge key={scope} variant="outline" className="text-xs font-mono">{scope}</Badge>
                                    ))}
                                </div>
                                <p className="mt-2 text-xs text-blue-600 dark:text-blue-400">Configure these in your Azure App Registration → API permissions</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Google Workspace Configuration */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <svg viewBox="0 0 24 24" className="h-5 w-5"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                Google Workspace
                            </CardTitle>
                            <CardDescription>Configure Google single sign-on for staff and portal users</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Client ID</Label>
                                    <Input placeholder="xxxx.apps.googleusercontent.com" defaultValue="" />
                                </div>
                                <div className="space-y-2">
                                    <Label>Client Secret</Label>
                                    <Input type="password" placeholder="••••••••" defaultValue="" />
                                </div>
                            </div>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">Enable Google SSO for staff</p>
                                        <p className="text-xs text-muted-foreground">Show "Sign in with Google" on staff login page</p>
                                    </div>
                                    <Switch checked={googleEnabled} onCheckedChange={setGoogleEnabled} />
                                </div>
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">Enable Google SSO for portal</p>
                                        <p className="text-xs text-muted-foreground">Allow clients/whānau to sign in with personal Google accounts</p>
                                    </div>
                                    <Switch checked={portalGoogleEnabled} onCheckedChange={setPortalGoogleEnabled} />
                                </div>
                            </div>
                            <div className="rounded-lg bg-emerald-50 p-4 text-sm dark:bg-emerald-950/20">
                                <p className="font-medium text-emerald-800 dark:text-emerald-300">Required OAuth Scopes</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {['email', 'profile', 'calendar', 'gmail.send'].map(scope => (
                                        <Badge key={scope} variant="outline" className="text-xs font-mono">{scope}</Badge>
                                    ))}
                                </div>
                                <p className="mt-2 text-xs text-emerald-600 dark:text-emerald-400">Configure these in Google Cloud Console → OAuth consent screen</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Provisioning Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck className="h-5 w-5 text-violet-600" />
                                Provisioning & Security
                            </CardTitle>
                            <CardDescription>Control how new users are created via SSO</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                <div>
                                    <p className="text-sm font-medium">Auto-provision staff accounts</p>
                                    <p className="text-xs text-muted-foreground">Automatically create accounts for users from your org domain</p>
                                </div>
                                <Switch checked={autoProvision} onCheckedChange={setAutoProvision} />
                            </div>
                            <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                <div>
                                    <p className="text-sm font-medium">Require admin approval for new accounts</p>
                                    <p className="text-xs text-muted-foreground">New SSO users need admin approval before they can access the system</p>
                                </div>
                                <Switch checked={requireApproval} onCheckedChange={setRequireApproval} />
                            </div>
                            <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                <div>
                                    <p className="text-sm font-medium">Sync Azure AD groups on login</p>
                                    <p className="text-xs text-muted-foreground">Automatically update roles based on group mappings when users sign in</p>
                                </div>
                                <Switch defaultChecked />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Quick Links */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Globe className="h-5 w-5 text-violet-600" />
                                SSO URLs
                            </CardTitle>
                            <CardDescription>Redirect URIs to configure in your identity provider</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Microsoft Redirect URI</Label>
                                <code className="block rounded bg-muted p-2 text-xs font-mono">{typeof window !== 'undefined' ? window.location.origin : ''}/auth/microsoft/callback</code>
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Google Redirect URI</Label>
                                <code className="block rounded bg-muted p-2 text-xs font-mono">{typeof window !== 'undefined' ? window.location.origin : ''}/auth/google/callback</code>
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Portal Microsoft Redirect URI</Label>
                                <code className="block rounded bg-muted p-2 text-xs font-mono">{typeof window !== 'undefined' ? window.location.origin : ''}/portal/auth/microsoft/callback</code>
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Portal Google Redirect URI</Label>
                                <code className="block rounded bg-muted p-2 text-xs font-mono">{typeof window !== 'undefined' ? window.location.origin : ''}/portal/auth/google/callback</code>
                            </div>
                        </CardContent>
                    </Card>

                    <Button className="bg-violet-600 hover:bg-violet-700">Save SSO Configuration</Button>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
