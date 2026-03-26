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
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Link } from '@inertiajs/react';
import { ArrowRight, Copy, Globe, Info, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'SSO Configuration' },
];

function CopyBlock({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const fullUrl = origin + value;

    return (
        <div className="space-y-1">
            <Label className="text-xs text-muted-foreground">{label}</Label>
            <div className="flex items-center gap-2">
                <code className="block flex-1 rounded bg-muted p-2 text-xs font-mono">{fullUrl}</code>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 p-0"
                    onClick={() => {
                        navigator.clipboard.writeText(fullUrl);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 2000);
                    }}
                >
                    {copied ? <span className="text-xs text-emerald-600">Done</span> : <Copy className="h-3.5 w-3.5" />}
                </Button>
            </div>
        </div>
    );
}

const MicrosoftIcon = () => (
    <svg viewBox="0 0 23 23" className="h-5 w-5"><path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/></svg>
);

const GoogleIcon = () => (
    <svg viewBox="0 0 24 24" className="h-5 w-5"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
);

export default function SsoSettings() {
    const [microsoftEnabled, setMicrosoftEnabled] = useState(true);
    const [googleEnabled, setGoogleEnabled] = useState(true);
    const [portalMicrosoftEnabled, setPortalMicrosoftEnabled] = useState(true);
    const [portalGoogleEnabled, setPortalGoogleEnabled] = useState(true);
    const [autoProvision, setAutoProvision] = useState(false);
    const [requireApproval, setRequireApproval] = useState(true);
    const [groupSync, setGroupSync] = useState(true);

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
                                        <MicrosoftIcon />
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
                                        <GoogleIcon />
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

                    {/* Tabbed Configuration */}
                    <Tabs defaultValue="microsoft">
                        <TabsList className="grid w-full grid-cols-5">
                            <TabsTrigger value="microsoft">Microsoft 365</TabsTrigger>
                            <TabsTrigger value="google">Google Workspace</TabsTrigger>
                            <TabsTrigger value="provisioning">Provisioning</TabsTrigger>
                            <TabsTrigger value="groups">Group Mapping</TabsTrigger>
                            <TabsTrigger value="urls">URLs &amp; Setup</TabsTrigger>
                        </TabsList>

                        {/* Microsoft 365 Tab */}
                        <TabsContent value="microsoft">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <MicrosoftIcon />
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
                                            <p className="text-xs text-muted-foreground">Keep this secret -- never share it</p>
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
                                                <p className="text-xs text-muted-foreground">Allow clients/whanau to sign in with personal Microsoft accounts</p>
                                            </div>
                                            <Switch checked={portalMicrosoftEnabled} onCheckedChange={setPortalMicrosoftEnabled} />
                                        </div>
                                    </div>
                                    <div className="rounded-lg bg-blue-50 p-4 text-sm dark:bg-blue-950/20">
                                        <p className="flex items-center gap-2 font-medium text-blue-800 dark:text-blue-300">
                                            <Info className="h-4 w-4" />
                                            Required API Permissions
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {['User.Read', 'Mail.Send', 'Calendars.ReadWrite', 'GroupMember.Read.All'].map(scope => (
                                                <Badge key={scope} variant="outline" className="text-xs font-mono">{scope}</Badge>
                                            ))}
                                        </div>
                                        <p className="mt-2 text-xs text-blue-600 dark:text-blue-400">Configure these in your Azure App Registration &rarr; API permissions</p>
                                    </div>
                                    <Button className="bg-violet-600 hover:bg-violet-700">Save Microsoft Settings</Button>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Google Workspace Tab */}
                        <TabsContent value="google">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <GoogleIcon />
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
                                                <p className="text-xs text-muted-foreground">Allow clients/whanau to sign in with personal Google accounts</p>
                                            </div>
                                            <Switch checked={portalGoogleEnabled} onCheckedChange={setPortalGoogleEnabled} />
                                        </div>
                                    </div>
                                    <div className="rounded-lg bg-emerald-50 p-4 text-sm dark:bg-emerald-950/20">
                                        <p className="flex items-center gap-2 font-medium text-emerald-800 dark:text-emerald-300">
                                            <Info className="h-4 w-4" />
                                            Required OAuth Scopes
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {['email', 'profile', 'calendar', 'gmail.send'].map(scope => (
                                                <Badge key={scope} variant="outline" className="text-xs font-mono">{scope}</Badge>
                                            ))}
                                        </div>
                                        <p className="mt-2 text-xs text-emerald-600 dark:text-emerald-400">Configure these in Google Cloud Console &rarr; OAuth consent screen</p>
                                    </div>
                                    <Button className="bg-violet-600 hover:bg-violet-700">Save Google Settings</Button>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Provisioning Tab */}
                        <TabsContent value="provisioning">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <ShieldCheck className="h-5 w-5 text-violet-600" />
                                        Provisioning &amp; Security
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
                                        <Switch checked={groupSync} onCheckedChange={setGroupSync} />
                                    </div>
                                    <Button className="mt-4 bg-violet-600 hover:bg-violet-700">Save Provisioning Settings</Button>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Group Mapping Tab */}
                        <TabsContent value="groups">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <Users className="h-5 w-5 text-violet-600" />
                                                Security Group Mapping
                                            </CardTitle>
                                            <CardDescription>Map Azure AD or Google Workspace security groups to application roles</CardDescription>
                                        </div>
                                        <Button asChild className="bg-violet-600 hover:bg-violet-700">
                                            <Link href="/settings/sso-groups">Open Full Manager</Link>
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="rounded-lg border bg-muted/30 p-6 text-center">
                                        <Users className="mx-auto h-10 w-10 text-muted-foreground/40 mb-3" />
                                        <p className="font-medium">Map identity provider groups to roles</p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            When users sign in via Microsoft or Google, their group memberships can automatically assign application roles.
                                        </p>
                                        <div className="mt-4 flex justify-center gap-3">
                                            <Button variant="outline" asChild>
                                                <Link href="/settings/sso-groups">
                                                    <Users className="mr-1.5 h-3.5 w-3.5" /> Manage Mappings
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="rounded-lg bg-violet-50 p-4 text-sm dark:bg-violet-950/20">
                                        <p className="flex items-center gap-2 font-medium text-violet-800 dark:text-violet-300">
                                            <Info className="h-4 w-4" /> How it works
                                        </p>
                                        <ul className="mt-2 space-y-1 text-xs text-violet-700 dark:text-violet-400 list-disc list-inside">
                                            <li>Create mappings between external security groups and app roles</li>
                                            <li>When a user signs in via SSO, their groups are checked</li>
                                            <li>Roles are auto-assigned or removed based on your mappings</li>
                                            <li>Enable "Sync on login" in the Provisioning tab to activate</li>
                                        </ul>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* URLs & Setup Tab */}
                        <TabsContent value="urls">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Globe className="h-5 w-5 text-violet-600" />
                                        SSO URLs &amp; Setup
                                    </CardTitle>
                                    <CardDescription>Redirect URIs to configure in your identity provider</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <CopyBlock label="Microsoft Redirect URI" value="/auth/microsoft/callback" />
                                    <CopyBlock label="Google Redirect URI" value="/auth/google/callback" />
                                    <CopyBlock label="Portal Microsoft Redirect URI" value="/portal/auth/microsoft/callback" />
                                    <CopyBlock label="Portal Google Redirect URI" value="/portal/auth/google/callback" />

                                    <div className="mt-6 rounded-lg border bg-muted/30 p-4 text-sm space-y-3">
                                        <p className="font-medium">Setup Instructions</p>
                                        <ol className="list-decimal list-inside space-y-2 text-muted-foreground text-sm">
                                            <li>Register an application in your identity provider (Azure Portal or Google Cloud Console).</li>
                                            <li>Copy the Client ID and Client Secret into the corresponding tab above.</li>
                                            <li>Add all four redirect URIs above to your app's allowed redirect URLs.</li>
                                            <li>Grant the required API permissions / OAuth scopes listed in each provider tab.</li>
                                            <li>Enable SSO for staff and/or portal users using the toggles on each provider tab.</li>
                                        </ol>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>

                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
