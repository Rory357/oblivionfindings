import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleAlert,
    Copy,
    ExternalLink,
    Globe,
    Info,
    Loader2,
    Plus,
    RefreshCw,
    Shield,
    ShieldCheck,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'SSO Configuration', href: '/settings/sso' },
];

type Role = {
    id: number;
    name: string;
    label: string | null;
};

type Mapping = {
    id: number;
    provider: string;
    external_group_id: string;
    external_group_name: string;
    role_id: number;
    auto_assign: boolean;
    auto_remove: boolean;
    last_synced_at: string | null;
    role?: Role;
};

type Props = {
    mappings?: Mapping[];
    roles?: Role[];
    stats?: { total: number; microsoft: number; google: number };
    sso_config?: {
        microsoft_tenant_id?: string;
        microsoft_client_id?: string;
        microsoft_connected?: boolean;
        microsoft_staff_enabled?: boolean;
        microsoft_portal_enabled?: boolean;
        microsoft_domain?: string;
        google_client_id?: string;
        google_connected?: boolean;
        google_staff_enabled?: boolean;
        google_portal_enabled?: boolean;
        google_domain?: string;
        auto_create_staff?: boolean;
        default_role_id?: number;
        require_admin_approval?: boolean;
        auto_link_existing?: boolean;
        portal_auto_create?: boolean;
    };
};

function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{label}</Label>
            <div className="flex items-center gap-2">
                <Input
                    value={value}
                    readOnly
                    className="bg-muted/50 font-mono text-xs"
                />
                <Button
                    variant="outline"
                    size="icon"
                    className="shrink-0"
                    onClick={handleCopy}
                >
                    {copied ? (
                        <CheckCircle2 className="h-4 w-4 text-status-success" />
                    ) : (
                        <Copy className="h-4 w-4" />
                    )}
                </Button>
            </div>
        </div>
    );
}

// ─── Tab 1: Microsoft 365 ────────────────────────────────────────────────────

function MicrosoftTab({ config }: { config: Props['sso_config'] }) {
    const [tenantId, setTenantId] = useState(config?.microsoft_tenant_id ?? '');
    const [clientId, setClientId] = useState(config?.microsoft_client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [domain, setDomain] = useState(config?.microsoft_domain ?? '');
    const [staffEnabled, setStaffEnabled] = useState(
        config?.microsoft_staff_enabled ?? false,
    );
    const [portalEnabled, setPortalEnabled] = useState(
        config?.microsoft_portal_enabled ?? false,
    );
    const connected = config?.microsoft_connected ?? false;

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <svg
                                    className="h-5 w-5"
                                    viewBox="0 0 23 23"
                                    fill="none"
                                >
                                    <path d="M1 1h10v10H1z" fill="#F25022" />
                                    <path d="M12 1h10v10H12z" fill="#7FBA00" />
                                    <path d="M1 12h10v10H1z" fill="#00A4EF" />
                                    <path d="M12 12h10v10H12z" fill="#FFB900" />
                                </svg>
                                Microsoft 365 / Entra ID
                            </CardTitle>
                            <CardDescription>
                                Configure Microsoft single sign-on for staff and
                                portal users
                            </CardDescription>
                        </div>
                        {connected ? (
                            <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                Connected
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                            >
                                <CircleAlert className="mr-1 h-3 w-3" />
                                Not Connected
                            </Badge>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="ms-tenant">Tenant ID</Label>
                            <Input
                                id="ms-tenant"
                                value={tenantId}
                                onChange={(e) => setTenantId(e.target.value)}
                                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="ms-client">Client ID</Label>
                            <Input
                                id="ms-client"
                                value={clientId}
                                onChange={(e) => setClientId(e.target.value)}
                                placeholder="Application (client) ID"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="ms-secret">Client Secret</Label>
                            <Input
                                id="ms-secret"
                                type="password"
                                value={clientSecret}
                                onChange={(e) =>
                                    setClientSecret(e.target.value)
                                }
                                placeholder="••••••••"
                            />
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="ms-domain">
                                Organisation Domain
                            </Label>
                            <Input
                                id="ms-domain"
                                value={domain}
                                onChange={(e) => setDomain(e.target.value)}
                                placeholder="yourcompany.co.nz"
                            />
                            <p className="text-xs text-muted-foreground">
                                Only emails from this domain can sign in as
                                staff
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 border-t pt-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Enable Microsoft SSO for staff</Label>
                                <p className="text-xs text-muted-foreground">
                                    Show &quot;Sign in with Microsoft&quot; on
                                    the staff login page
                                </p>
                            </div>
                            <Switch
                                checked={staffEnabled}
                                onCheckedChange={setStaffEnabled}
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Enable Microsoft SSO for portal</Label>
                                <p className="text-xs text-muted-foreground">
                                    Allow clients/wh&#257;nau to sign in with
                                    personal Microsoft accounts
                                </p>
                            </div>
                            <Switch
                                checked={portalEnabled}
                                onCheckedChange={setPortalEnabled}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Required API Permissions */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Info className="h-4 w-4 text-primary" />
                        Required API Permissions
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <Badge variant="secondary">User.Read</Badge>
                        <Badge variant="secondary">Mail.Send</Badge>
                        <Badge variant="secondary">Calendars.ReadWrite</Badge>
                        <Badge variant="secondary">GroupMember.Read.All</Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Configure these in your Azure App Registration &rarr;
                        API permissions
                    </p>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button className="bg-primary hover:bg-primary">
                    Save Microsoft Settings
                </Button>
            </div>
        </div>
    );
}

// ─── Tab 2: Google Workspace ─────────────────────────────────────────────────

function GoogleTab({ config }: { config: Props['sso_config'] }) {
    const [clientId, setClientId] = useState(config?.google_client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [domain, setDomain] = useState(config?.google_domain ?? '');
    const [staffEnabled, setStaffEnabled] = useState(
        config?.google_staff_enabled ?? false,
    );
    const [portalEnabled, setPortalEnabled] = useState(
        config?.google_portal_enabled ?? false,
    );
    const connected = config?.google_connected ?? false;

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <Globe className="h-5 w-5 text-status-info" />
                                Google Workspace
                            </CardTitle>
                            <CardDescription>
                                Configure Google single sign-on for staff and
                                portal users
                            </CardDescription>
                        </div>
                        {connected ? (
                            <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                Connected
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                            >
                                <CircleAlert className="mr-1 h-3 w-3" />
                                Not Connected
                            </Badge>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="g-client">Client ID</Label>
                            <Input
                                id="g-client"
                                value={clientId}
                                onChange={(e) => setClientId(e.target.value)}
                                placeholder="Application client ID"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="g-secret">Client Secret</Label>
                            <Input
                                id="g-secret"
                                type="password"
                                value={clientSecret}
                                onChange={(e) =>
                                    setClientSecret(e.target.value)
                                }
                                placeholder="••••••••"
                            />
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="g-domain">
                                Organisation Domain
                            </Label>
                            <Input
                                id="g-domain"
                                value={domain}
                                onChange={(e) => setDomain(e.target.value)}
                                placeholder="yourcompany.co.nz"
                            />
                            <p className="text-xs text-muted-foreground">
                                Only emails from this domain can sign in as
                                staff
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 border-t pt-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Enable Google SSO for staff</Label>
                                <p className="text-xs text-muted-foreground">
                                    Show &quot;Sign in with Google&quot; on the
                                    staff login page
                                </p>
                            </div>
                            <Switch
                                checked={staffEnabled}
                                onCheckedChange={setStaffEnabled}
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Enable Google SSO for portal</Label>
                                <p className="text-xs text-muted-foreground">
                                    Allow clients/wh&#257;nau to sign in with
                                    personal Google accounts
                                </p>
                            </div>
                            <Switch
                                checked={portalEnabled}
                                onCheckedChange={setPortalEnabled}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Required Scopes */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Info className="h-4 w-4 text-primary" />
                        Required OAuth Scopes
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <Badge variant="secondary">openid</Badge>
                        <Badge variant="secondary">email</Badge>
                        <Badge variant="secondary">profile</Badge>
                        <Badge variant="secondary">calendar</Badge>
                        <Badge variant="secondary">gmail.send</Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Configure these in your Google Cloud Console &rarr;
                        OAuth consent screen
                    </p>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button className="bg-primary hover:bg-primary">
                    Save Google Settings
                </Button>
            </div>
        </div>
    );
}

// ─── Tab 3: Provisioning ─────────────────────────────────────────────────────

function ProvisioningTab({
    config,
    roles = [],
}: {
    config: Props['sso_config'];
    roles: Role[];
}) {
    const [autoCreateStaff, setAutoCreateStaff] = useState(
        config?.auto_create_staff ?? false,
    );
    const [defaultRoleId, setDefaultRoleId] = useState<string>(
        config?.default_role_id?.toString() ?? '',
    );
    const [requireApproval, setRequireApproval] = useState(
        config?.require_admin_approval ?? false,
    );
    const [autoLink, setAutoLink] = useState(
        config?.auto_link_existing ?? false,
    );
    const [portalAutoCreate, setPortalAutoCreate] = useState(
        config?.portal_auto_create ?? true,
    );

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Users className="h-5 w-5 text-primary" />
                        User Provisioning
                    </CardTitle>
                    <CardDescription>
                        Configure how users are created when they sign in via
                        SSO for the first time
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    {/* Staff provisioning */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-medium tracking-wider text-muted-foreground uppercase">
                            Staff Accounts
                        </h4>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Auto-create staff accounts</Label>
                                <p className="text-xs text-muted-foreground">
                                    Automatically create staff accounts when
                                    users sign in with your organisation domain
                                </p>
                            </div>
                            <Switch
                                checked={autoCreateStaff}
                                onCheckedChange={setAutoCreateStaff}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Default role</Label>
                            <Select
                                value={defaultRoleId}
                                onValueChange={setDefaultRoleId}
                            >
                                <SelectTrigger className="w-full max-w-xs">
                                    <SelectValue placeholder="Select a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles.map((r) => (
                                        <SelectItem
                                            key={r.id}
                                            value={r.id.toString()}
                                        >
                                            {r.label || r.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Role assigned to auto-provisioned staff
                            </p>
                        </div>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Require admin approval</Label>
                                <p className="text-xs text-muted-foreground">
                                    New SSO users require admin approval before
                                    accessing the system
                                </p>
                            </div>
                            <Switch
                                checked={requireApproval}
                                onCheckedChange={setRequireApproval}
                            />
                        </div>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Auto-link existing accounts</Label>
                                <p className="text-xs text-muted-foreground">
                                    If an SSO user&apos;s email matches an
                                    existing account, link them automatically
                                </p>
                            </div>
                            <Switch
                                checked={autoLink}
                                onCheckedChange={setAutoLink}
                            />
                        </div>
                    </div>

                    <div className="space-y-4 border-t pt-4">
                        <h4 className="text-sm font-medium tracking-wider text-muted-foreground uppercase">
                            Portal Accounts
                        </h4>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Auto-create portal accounts</Label>
                                <p className="text-xs text-muted-foreground">
                                    Automatically create portal accounts for
                                    clients/wh&#257;nau signing in via SSO
                                </p>
                            </div>
                            <Switch
                                checked={portalAutoCreate}
                                onCheckedChange={setPortalAutoCreate}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button className="bg-primary hover:bg-primary">
                    Save Provisioning Settings
                </Button>
            </div>
        </div>
    );
}

// ─── Tab 4: Group Mapping ────────────────────────────────────────────────────

function GroupMappingTab({
    mappings = [],
    roles = [],
    stats = { total: 0, microsoft: 0, google: 0 },
}: {
    mappings: Mapping[];
    roles: Role[];
    stats: { total: number; microsoft: number; google: number };
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [fetching, setFetching] = useState(false);

    const form = useForm({
        provider: 'microsoft',
        external_group_id: '',
        external_group_name: '',
        role_id: '',
        auto_assign: true,
        auto_remove: false,
    });

    function handleStore(e: React.FormEvent) {
        e.preventDefault();
        form.post('/settings/sso-groups', {
            onSuccess: () => {
                setDialogOpen(false);
                form.reset();
            },
        });
    }

    function handleDelete(id: number) {
        if (!confirm('Remove this group mapping?')) return;
        router.delete(`/settings/sso-groups/${id}`);
    }

    function handleFetchGroups() {
        setFetching(true);
        router.post(
            '/settings/sso-groups/fetch',
            {},
            {
                onFinish: () => setFetching(false),
            },
        );
    }

    function handleUpdateMapping(id: number, field: string, value: any) {
        const mapping = mappings.find((m) => m.id === id);
        if (!mapping) return;

        router.put(`/settings/sso-groups/${id}`, {
            role_id: field === 'role_id' ? value : mapping.role_id,
            auto_assign: field === 'auto_assign' ? value : mapping.auto_assign,
            auto_remove: field === 'auto_remove' ? value : mapping.auto_remove,
        });
    }

    return (
        <div className="space-y-6">
            {/* Stats */}
            <div className="grid grid-cols-3 gap-4">
                <Card>
                    <CardContent className="pt-4">
                        <div className="text-2xl font-bold">{stats.total}</div>
                        <div className="text-xs text-muted-foreground">
                            Total Mappings
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4">
                        <div className="text-2xl font-bold">
                            {stats.microsoft}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Microsoft
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4">
                        <div className="text-2xl font-bold">{stats.google}</div>
                        <div className="text-xs text-muted-foreground">
                            Google
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Main Card */}
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck className="h-5 w-5 text-primary" />
                                SSO Group Mapping
                            </CardTitle>
                            <CardDescription>
                                Map security groups from Microsoft Entra ID or
                                Google Workspace to application roles
                            </CardDescription>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleFetchGroups}
                                disabled={fetching}
                            >
                                {fetching ? (
                                    <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                ) : (
                                    <RefreshCw className="mr-1 h-4 w-4" />
                                )}
                                Fetch Groups
                            </Button>
                            <Dialog
                                open={dialogOpen}
                                onOpenChange={setDialogOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        size="sm"
                                        className="bg-primary hover:bg-primary"
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        Add Mapping
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Add Group Mapping
                                        </DialogTitle>
                                        <DialogDescription>
                                            Map an external security group to an
                                            application role.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form
                                        onSubmit={handleStore}
                                        className="space-y-4"
                                    >
                                        <div className="space-y-2">
                                            <Label>Provider</Label>
                                            <Select
                                                value={form.data.provider}
                                                onValueChange={(v) =>
                                                    form.setData('provider', v)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="microsoft">
                                                        Microsoft
                                                    </SelectItem>
                                                    <SelectItem value="google">
                                                        Google
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>External Group ID</Label>
                                            <Input
                                                value={
                                                    form.data.external_group_id
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'external_group_id',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. 00000000-0000-0000-0000-000000000000"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Group Name</Label>
                                            <Input
                                                value={
                                                    form.data
                                                        .external_group_name
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'external_group_name',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. Support Workers"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Mapped Role</Label>
                                            <Select
                                                value={form.data.role_id.toString()}
                                                onValueChange={(v) =>
                                                    form.setData('role_id', v)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a role" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {roles.map((r) => (
                                                        <SelectItem
                                                            key={r.id}
                                                            value={r.id.toString()}
                                                        >
                                                            {r.label || r.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <Label>Auto-Assign</Label>
                                            <Switch
                                                checked={form.data.auto_assign}
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'auto_assign',
                                                        v,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <Label>Auto-Remove</Label>
                                            <Switch
                                                checked={form.data.auto_remove}
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'auto_remove',
                                                        v,
                                                    )
                                                }
                                            />
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                type="submit"
                                                disabled={form.processing}
                                                className="bg-primary hover:bg-primary"
                                            >
                                                Create Mapping
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {mappings.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <Shield className="mb-3 h-10 w-10 text-muted-foreground" />
                            <h3 className="text-sm font-medium">
                                No group mappings
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Add a mapping to automatically sync roles from
                                your identity provider.
                            </p>
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Group Name</TableHead>
                                    <TableHead className="hidden md:table-cell">
                                        External ID
                                    </TableHead>
                                    <TableHead>Mapped Role</TableHead>
                                    <TableHead>Auto-Assign</TableHead>
                                    <TableHead>Auto-Remove</TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Last Synced
                                    </TableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {mappings.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    m.provider === 'microsoft'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {m.provider === 'microsoft'
                                                    ? 'Microsoft'
                                                    : 'Google'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {m.external_group_name}
                                        </TableCell>
                                        <TableCell className="hidden max-w-[200px] truncate font-mono text-xs text-muted-foreground md:table-cell">
                                            {m.external_group_id}
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={m.role_id.toString()}
                                                onValueChange={(v) =>
                                                    handleUpdateMapping(
                                                        m.id,
                                                        'role_id',
                                                        parseInt(v),
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="h-8 w-[140px]">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {roles.map((r) => (
                                                        <SelectItem
                                                            key={r.id}
                                                            value={r.id.toString()}
                                                        >
                                                            {r.label || r.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <Switch
                                                checked={m.auto_assign}
                                                onCheckedChange={(v) =>
                                                    handleUpdateMapping(
                                                        m.id,
                                                        'auto_assign',
                                                        v,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Switch
                                                checked={m.auto_remove}
                                                onCheckedChange={(v) =>
                                                    handleUpdateMapping(
                                                        m.id,
                                                        'auto_remove',
                                                        v,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="hidden text-xs text-muted-foreground lg:table-cell">
                                            {m.last_synced_at
                                                ? new Date(
                                                      m.last_synced_at,
                                                  ).toLocaleDateString()
                                                : 'Never'}
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8 text-status-critical hover:text-status-critical"
                                                onClick={() =>
                                                    handleDelete(m.id)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ─── Tab 5: URLs & Setup ─────────────────────────────────────────────────────

function UrlsSetupTab() {
    return (
        <div className="space-y-6">
            {/* Redirect URLs */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ExternalLink className="h-5 w-5 text-primary" />
                        Redirect URLs
                    </CardTitle>
                    <CardDescription>
                        Configure these URLs in your identity provider&apos;s
                        app registration
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <h4 className="text-sm font-medium">Microsoft</h4>
                    <CopyField
                        label="Staff Redirect URI"
                        value="https://oblivionfindings.test/auth/microsoft/callback"
                    />
                    <CopyField
                        label="Portal Redirect URI"
                        value="https://oblivionfindings.test/portal/auth/microsoft/callback"
                    />

                    <div className="border-t pt-4">
                        <h4 className="mb-4 text-sm font-medium">Google</h4>
                        <div className="space-y-4">
                            <CopyField
                                label="Staff Redirect URI"
                                value="https://oblivionfindings.test/auth/google/callback"
                            />
                            <CopyField
                                label="Portal Redirect URI"
                                value="https://oblivionfindings.test/portal/auth/google/callback"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Setup Guide */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Info className="h-5 w-5 text-primary" />
                        Setup Guide
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* Microsoft Setup */}
                    <div>
                        <h4 className="mb-3 flex items-center gap-2 text-sm font-medium">
                            <svg
                                className="h-4 w-4"
                                viewBox="0 0 23 23"
                                fill="none"
                            >
                                <path d="M1 1h10v10H1z" fill="#F25022" />
                                <path d="M12 1h10v10H12z" fill="#7FBA00" />
                                <path d="M1 12h10v10H1z" fill="#00A4EF" />
                                <path d="M12 12h10v10H12z" fill="#FFB900" />
                            </svg>
                            Microsoft Entra ID Setup
                        </h4>
                        <ol className="list-inside list-decimal space-y-2 text-sm text-muted-foreground">
                            <li>
                                Go to{' '}
                                <span className="font-medium text-foreground">
                                    Azure Portal &rarr; Entra ID &rarr; App
                                    Registrations
                                </span>
                            </li>
                            <li>
                                Create a new registration with a meaningful name
                            </li>
                            <li>
                                Add the redirect URIs from the section above
                            </li>
                            <li>
                                Navigate to{' '}
                                <span className="font-medium text-foreground">
                                    Certificates &amp; secrets
                                </span>{' '}
                                and create a new client secret
                            </li>
                            <li>
                                Go to{' '}
                                <span className="font-medium text-foreground">
                                    API permissions
                                </span>{' '}
                                and add: User.Read, Mail.Send,
                                Calendars.ReadWrite, GroupMember.Read.All
                            </li>
                            <li>
                                Copy the{' '}
                                <span className="font-medium text-foreground">
                                    Tenant ID
                                </span>{' '}
                                and{' '}
                                <span className="font-medium text-foreground">
                                    Client ID
                                </span>{' '}
                                from the Overview page into the Microsoft 365
                                tab
                            </li>
                        </ol>
                    </div>

                    <div className="border-t pt-4">
                        <h4 className="mb-3 flex items-center gap-2 text-sm font-medium">
                            <Globe className="h-4 w-4 text-status-info" />
                            Google Workspace Setup
                        </h4>
                        <ol className="list-inside list-decimal space-y-2 text-sm text-muted-foreground">
                            <li>
                                Go to{' '}
                                <span className="font-medium text-foreground">
                                    Google Cloud Console &rarr; APIs &amp;
                                    Services &rarr; Credentials
                                </span>
                            </li>
                            <li>
                                Create an OAuth 2.0 Client ID (Web application
                                type)
                            </li>
                            <li>
                                Add the redirect URIs from the section above
                            </li>
                            <li>
                                Copy the{' '}
                                <span className="font-medium text-foreground">
                                    Client ID
                                </span>{' '}
                                and{' '}
                                <span className="font-medium text-foreground">
                                    Client Secret
                                </span>{' '}
                                into the Google Workspace tab
                            </li>
                        </ol>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

// ─── Main Page ───────────────────────────────────────────────────────────────

export default function SsoConfig({
    mappings = [],
    roles = [],
    stats = { total: 0, microsoft: 0, google: 0 },
    sso_config,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSO Configuration" />
            <SettingsLayout>
                <PageHero
                    variant="compact"
                    title="SSO Configuration"
                    description="Manage single sign-on providers, user provisioning, and group mappings for your organisation."
                />

                <TabsRoot defaultValue="microsoft" className="w-full">
                    <TabsList className="grid w-full grid-cols-5">
                        <TabsTrigger value="microsoft">
                            Microsoft 365
                        </TabsTrigger>
                        <TabsTrigger value="google">
                            Google Workspace
                        </TabsTrigger>
                        <TabsTrigger value="provisioning">
                            Provisioning
                        </TabsTrigger>
                        <TabsTrigger value="groups">Group Mapping</TabsTrigger>
                        <TabsTrigger value="urls">URLs &amp; Setup</TabsTrigger>
                    </TabsList>

                    <TabsContent value="microsoft">
                        <MicrosoftTab config={sso_config} />
                    </TabsContent>

                    <TabsContent value="google">
                        <GoogleTab config={sso_config} />
                    </TabsContent>

                    <TabsContent value="provisioning">
                        <ProvisioningTab config={sso_config} roles={roles} />
                    </TabsContent>

                    <TabsContent value="groups">
                        <GroupMappingTab
                            mappings={mappings}
                            roles={roles}
                            stats={stats}
                        />
                    </TabsContent>

                    <TabsContent value="urls">
                        <UrlsSetupTab />
                    </TabsContent>
                </TabsRoot>
            </SettingsLayout>
        </AppLayout>
    );
}
