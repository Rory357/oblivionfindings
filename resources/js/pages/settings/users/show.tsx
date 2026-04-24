import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Calendar,
    CheckCircle2,
    Clock,
    Globe,
    KeyRound,
    LogIn,
    LogOut,
    Mail,
    Monitor,
    Phone,
    Plus,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Smartphone,
    Trash2,
    User,
    UserCog,
    X,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

type Role = { id: number; name: string; label?: string };

type LoginLog = {
    id: number;
    event_type: string;
    ip_address: string;
    user_agent?: string;
    location?: string;
    metadata?: Record<string, any>;
    created_at: string;
};

type Session = {
    id: string;
    ip_address?: string;
    user_agent?: string;
    last_activity: number;
    is_current?: boolean;
};

type StaffProfile = {
    id?: number;
    employee_id?: string;
    job_title?: string;
    department?: string;
    hire_date?: string;
    status?: string;
    work_phone?: string;
    mobile_phone?: string;
    emergency_contact_name?: string;
    emergency_contact_phone?: string;
    emergency_contact_relationship?: string;
};

type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        avatar?: string;
        is_active: boolean;
        approved_at?: string;
        created_at?: string;
        roles?: Role[];
        user_type?: string;
        staff_profile?: StaffProfile | null;
        last_login_at?: string;
        last_login_ip?: string;
        login_count?: number;
        two_factor_confirmed_at?: string;
    };
    allRoles?: Role[];
    login_logs?: LoginLog[];
    active_sessions?: Session[];
    login_stats?: {
        this_month: number;
        last_ip?: string;
        active_sessions: number;
    };
};

function relativeTime(dateStr?: string | null): string {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function absoluteTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function parseBrowser(ua?: string | null): { browser: string; os: string } {
    if (!ua) return { browser: 'Unknown', os: 'Unknown' };
    let browser = 'Unknown';
    if (ua.includes('Edg/') || ua.includes('Edge/')) browser = 'Edge';
    else if (ua.includes('Chrome/') && !ua.includes('Edg/')) browser = 'Chrome';
    else if (ua.includes('Firefox/')) browser = 'Firefox';
    else if (ua.includes('Safari/') && !ua.includes('Chrome/'))
        browser = 'Safari';

    let os = 'Unknown';
    if (ua.includes('Windows')) os = 'Windows';
    else if (ua.includes('Mac OS')) os = 'Mac';
    else if (ua.includes('Linux') && !ua.includes('Android')) os = 'Linux';
    else if (ua.includes('Android')) os = 'Android';
    else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';

    return { browser, os };
}

const eventConfig: Record<
    string,
    { label: string; color: string; icon: typeof LogIn }
> = {
    login: { label: 'Signed in', color: 'bg-status-success', icon: LogIn },
    logout: { label: 'Signed out', color: 'bg-status-info', icon: LogOut },
    failed_login: {
        label: 'Failed login attempt',
        color: 'bg-status-critical',
        icon: XCircle,
    },
    password_changed: {
        label: 'Password changed',
        color: 'bg-status-warning',
        icon: KeyRound,
    },
    role_changed: {
        label: 'Role changed',
        color: 'bg-status-warning',
        icon: Shield,
    },
    '2fa_enabled': {
        label: '2FA enabled',
        color: 'bg-status-warning',
        icon: ShieldCheck,
    },
    '2fa_disabled': {
        label: '2FA disabled',
        color: 'bg-status-warning',
        icon: ShieldAlert,
    },
    approved: {
        label: 'Account approved',
        color: 'bg-primary',
        icon: CheckCircle2,
    },
    suspended: {
        label: 'Account suspended',
        color: 'bg-primary',
        icon: ShieldAlert,
    },
};

const eventFilterCategories: Record<string, string[]> = {
    all: [],
    logins: ['login'],
    logouts: ['logout'],
    failed: ['failed_login'],
    security: [
        'password_changed',
        'role_changed',
        '2fa_enabled',
        '2fa_disabled',
        'approved',
        'suspended',
    ],
};

export default function UserShow({
    user,
    allRoles = [],
    login_logs = [],
    active_sessions = [],
    login_stats,
}: Props) {
    const page = usePage<{ auth?: { user?: { id?: number } } }>();
    const u = user ?? ({} as any);
    const roles: Role[] = u.roles ?? [];
    const currentUserId = page.props.auth?.user?.id;
    const isSystemView = page.url.startsWith('/system/users');
    const usersBasePath = isSystemView ? '/system/users' : '/settings/users';
    const isSelf = currentUserId === u.id;
    const initials = (u.name ?? '?')
        .split(' ')
        .map((n: string) => n[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    const [showAddRole, setShowAddRole] = useState(false);
    const assignedIds = new Set(roles.map((r: Role) => r.id));
    const availableRoles = allRoles.filter((r) => !assignedIds.has(r.id));
    const [eventFilter, setEventFilter] = useState('all');
    const stats = login_stats ?? {
        this_month: 0,
        last_ip: undefined,
        active_sessions: 0,
    };

    const filteredLogs =
        eventFilter === 'all'
            ? login_logs
            : login_logs.filter((log) =>
                  eventFilterCategories[eventFilter]?.includes(log.event_type),
              );

    const breadcrumbs: BreadcrumbItem[] = isSystemView
        ? [
              { title: 'Dashboard', href: '/dashboard' },
              { title: 'System Users', href: usersBasePath },
              { title: u.name ?? 'User', href: `${usersBasePath}/${u.id}` },
          ]
        : [
              { title: 'Settings', href: '/settings' },
              { title: 'Users', href: usersBasePath },
              { title: u.name ?? 'User', href: `${usersBasePath}/${u.id}` },
          ];

    const content = (
        <div className="space-y-6">
            {/* Back link */}
            <Link
                dusk="user-back-link"
                href={usersBasePath}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="h-4 w-4" /> Back to Users
            </Link>

            {/* Profile Header */}
            <Card className="relative overflow-hidden bg-white dark:bg-muted">
                <div className="h-1.5 w-full bg-primary" />
                <div className="px-6 py-6">
                    <div className="flex items-center gap-5">
                        <Avatar className="h-16 w-16 border-2 border-primary/30 shadow-md">
                            <AvatarImage src={u.avatar} alt={u.name} />
                            <AvatarFallback className="bg-primary text-lg font-semibold text-white">
                                {initials}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-3">
                                <h1 className="truncate text-xl font-semibold tracking-tight">
                                    {u.name}
                                </h1>
                                {u.is_active ? (
                                    <Badge className="bg-status-success-bg text-xs text-status-success">
                                        Active
                                    </Badge>
                                ) : (
                                    <Badge
                                        variant="destructive"
                                        className="text-xs"
                                    >
                                        Inactive
                                    </Badge>
                                )}
                                {u.user_type && (
                                    <Badge
                                        variant="secondary"
                                        className="text-xs capitalize"
                                    >
                                        {u.user_type}
                                    </Badge>
                                )}
                            </div>
                            <p className="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground">
                                <Mail className="h-3.5 w-3.5" /> {u.email}
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            {!u.is_active && !isSelf && (
                                <Button
                                    size="sm"
                                    dusk="user-approve-action"
                                    onClick={() =>
                                        router.post(
                                            `${usersBasePath}/${u.id}/approve`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    Approve
                                </Button>
                            )}
                            {u.is_active && !isSelf && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    dusk="user-suspend-action"
                                    className="border-status-warning/30 text-status-warning hover:bg-status-warning-bg"
                                    onClick={() =>
                                        router.post(
                                            `${usersBasePath}/${u.id}/suspend`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <ShieldAlert className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    Suspend
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </Card>

            {/* Tabs */}
            <Tabs defaultValue="overview">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="activity">
                        Activity & Security
                    </TabsTrigger>
                    <TabsTrigger value="sessions">Sessions</TabsTrigger>
                    {u.staff_profile && (
                        <TabsTrigger value="staff">Staff Profile</TabsTrigger>
                    )}
                </TabsList>

                {/* Tab 1: Overview */}
                <TabsContent value="overview" className="mt-6">
                    <div className="grid gap-6 lg:grid-cols-[1fr_0.67fr]">
                        {/* Left column — Account Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <User className="h-5 w-5 text-primary" />{' '}
                                    Account Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Name
                                    </span>
                                    <span className="col-span-2 font-medium">
                                        {u.name}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Email
                                    </span>
                                    <span className="col-span-2">
                                        {u.email}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        User Type
                                    </span>
                                    <span className="col-span-2 capitalize">
                                        {u.user_type ?? '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <span className="col-span-2">
                                        {u.is_active ? (
                                            <span className="flex items-center gap-1 text-status-success">
                                                <CheckCircle2 className="h-3.5 w-3.5" />{' '}
                                                Active
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-1 text-status-critical">
                                                <XCircle className="h-3.5 w-3.5" />{' '}
                                                Inactive
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Approved
                                    </span>
                                    <span className="col-span-2">
                                        {u.approved_at
                                            ? new Date(
                                                  u.approved_at,
                                              ).toLocaleDateString('en-NZ')
                                            : '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Member since
                                    </span>
                                    <span className="col-span-2 flex items-center gap-1">
                                        <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                                        {u.created_at
                                            ? new Date(
                                                  u.created_at,
                                              ).toLocaleDateString('en-NZ', {
                                                  day: 'numeric',
                                                  month: 'long',
                                                  year: 'numeric',
                                              })
                                            : '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Last Login
                                    </span>
                                    <span
                                        className="col-span-2"
                                        title={absoluteTime(u.last_login_at)}
                                    >
                                        {u.last_login_at ? (
                                            <>
                                                {relativeTime(u.last_login_at)}{' '}
                                                <span className="text-muted-foreground">
                                                    (
                                                    {absoluteTime(
                                                        u.last_login_at,
                                                    )}
                                                    )
                                                </span>
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Login Count
                                    </span>
                                    <span className="col-span-2 font-medium">
                                        {u.login_count ?? 0}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Last IP
                                    </span>
                                    <span className="col-span-2 font-mono text-xs">
                                        {u.last_login_ip ?? '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        2FA Status
                                    </span>
                                    <span className="col-span-2">
                                        {u.two_factor_confirmed_at ? (
                                            <Badge className="gap-1 bg-status-success-bg text-xs text-status-success">
                                                <ShieldCheck className="h-3 w-3" />{' '}
                                                Enabled
                                            </Badge>
                                        ) : (
                                            <Badge className="gap-1 bg-status-warning-bg text-xs text-status-warning">
                                                <ShieldAlert className="h-3 w-3" />{' '}
                                                Not Enabled
                                            </Badge>
                                        )}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Right column */}
                        <div className="space-y-6">
                            {/* Roles */}
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <Shield className="h-5 w-5 text-primary" />{' '}
                                                Roles
                                            </CardTitle>
                                            <CardDescription>
                                                Assign or remove roles for this
                                                user
                                            </CardDescription>
                                        </div>
                                        {availableRoles.length > 0 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                dusk="user-role-add-toggle"
                                                className="gap-1"
                                                onClick={() =>
                                                    setShowAddRole(!showAddRole)
                                                }
                                            >
                                                <Plus className="h-3.5 w-3.5" />{' '}
                                                Add
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {showAddRole &&
                                        availableRoles.length > 0 && (
                                            <div className="bg-primary/10/50 flex flex-wrap gap-1.5 rounded-lg border border-dashed border-primary p-3">
                                                <span className="mb-1 w-full text-xs text-muted-foreground">
                                                    Click to assign:
                                                </span>
                                                {availableRoles.map((role) => (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        key={role.id}
                                                        dusk={`user-role-assign-${role.id}`}
                                                        onClick={() => {
                                                            const newIds = [
                                                                ...Array.from(
                                                                    assignedIds,
                                                                ),
                                                                role.id,
                                                            ];
                                                            router.put(
                                                                `${usersBasePath}/${u.id}`,
                                                                {
                                                                    role_ids:
                                                                        newIds,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                            setShowAddRole(
                                                                false,
                                                            );
                                                        }}
                                                        className="h-auto gap-1 rounded-full border-primary bg-white px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary/10"
                                                    >
                                                        <Plus className="h-3 w-3" />{' '}
                                                        {role.label ||
                                                            role.name}
                                                    </Button>
                                                ))}
                                            </div>
                                        )}
                                    {roles.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No roles assigned
                                        </p>
                                    ) : (
                                        <div className="space-y-2">
                                            {roles.map((role: Role) => (
                                                <div
                                                    key={role.id}
                                                    className="flex items-center justify-between rounded-lg border px-3 py-2"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <Shield className="h-3.5 w-3.5 text-primary" />
                                                        <span className="text-sm font-medium">
                                                            {role.label ||
                                                                role.name}
                                                        </span>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        dusk={`user-role-remove-${role.id}`}
                                                        onClick={() => {
                                                            const newIds =
                                                                Array.from(
                                                                    assignedIds,
                                                                ).filter(
                                                                    (id) =>
                                                                        id !==
                                                                        role.id,
                                                                );
                                                            router.put(
                                                                `${usersBasePath}/${u.id}`,
                                                                {
                                                                    role_ids:
                                                                        newIds,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                        className="h-7 w-7 rounded text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical"
                                                        title="Remove role"
                                                    >
                                                        <X className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Quick Stats */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Clock className="h-5 w-5 text-primary" />{' '}
                                        Quick Stats
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Logins this month
                                        </span>
                                        <span className="font-medium">
                                            {stats.this_month}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Active sessions
                                        </span>
                                        <Badge
                                            variant="secondary"
                                            className="text-xs"
                                        >
                                            {stats.active_sessions}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Last IP
                                        </span>
                                        <span className="font-mono text-xs">
                                            {stats.last_ip ?? '—'}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </TabsContent>

                {/* Tab 2: Activity & Security */}
                <TabsContent value="activity" className="mt-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Activity Log</h2>
                        <Select
                            value={eventFilter}
                            onValueChange={setEventFilter}
                        >
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All Events" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Events</SelectItem>
                                <SelectItem value="logins">Logins</SelectItem>
                                <SelectItem value="logouts">Logouts</SelectItem>
                                <SelectItem value="failed">
                                    Failed Attempts
                                </SelectItem>
                                <SelectItem value="security">
                                    Security
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {filteredLogs.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="mb-4 rounded-full bg-muted p-4">
                                    <Clock className="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 className="text-lg font-semibold">
                                    No activity recorded yet
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Login events and security actions will
                                    appear here.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-2">
                            {filteredLogs.map((log) => {
                                const config = eventConfig[log.event_type] ?? {
                                    label: log.event_type.replace(/_/g, ' '),
                                    color: 'bg-muted',
                                    icon: Clock,
                                };
                                const Icon = config.icon;
                                const { browser, os } = parseBrowser(
                                    log.user_agent,
                                );
                                const isFailed =
                                    log.event_type === 'failed_login';
                                let description = config.label;
                                if (
                                    log.event_type === 'role_changed' &&
                                    log.metadata
                                ) {
                                    const meta = log.metadata;
                                    if (meta.added)
                                        description = `Role changed: added ${meta.added}`;
                                    else if (meta.removed)
                                        description = `Role changed: removed ${meta.removed}`;
                                    else if (meta.description)
                                        description = meta.description;
                                }

                                return (
                                    <div
                                        key={log.id}
                                        className={`flex items-start gap-4 rounded-lg border bg-white p-4 dark:bg-muted ${isFailed ? 'border-l-4 border-l-red-400' : ''}`}
                                    >
                                        <div
                                            className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-white ${config.color}`}
                                        >
                                            <Icon className="h-4 w-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {description}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                                {log.ip_address && (
                                                    <Badge
                                                        variant="outline"
                                                        className="font-mono text-xs"
                                                    >
                                                        <Globe className="mr-1 h-3 w-3" />
                                                        {log.ip_address}
                                                    </Badge>
                                                )}
                                                {log.user_agent && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {browser} on {os}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <span
                                            className="shrink-0 text-xs text-muted-foreground"
                                            title={absoluteTime(log.created_at)}
                                        >
                                            {relativeTime(log.created_at)}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </TabsContent>

                {/* Tab 3: Sessions */}
                <TabsContent value="sessions" className="mt-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            {active_sessions.length} active session
                            {active_sessions.length !== 1 ? 's' : ''}
                        </p>
                        {active_sessions.length > 1 && (
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() =>
                                    router.delete(
                                        `${usersBasePath}/${u.id}/sessions`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                                Terminate All Other Sessions
                            </Button>
                        )}
                    </div>

                    {active_sessions.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="mb-4 rounded-full bg-muted p-4">
                                    <Monitor className="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 className="text-lg font-semibold">
                                    No active sessions
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    This user does not have any active sessions.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-2">
                            {active_sessions.map((session) => {
                                const { browser, os } = parseBrowser(
                                    session.user_agent,
                                );
                                const lastActive = session.last_activity
                                    ? Math.floor(
                                          (Date.now() / 1000 -
                                              session.last_activity) /
                                              60,
                                      )
                                    : null;
                                const BrowserIcon =
                                    os === 'iOS' || os === 'Android'
                                        ? Smartphone
                                        : Monitor;

                                return (
                                    <Card
                                        key={session.id}
                                        className="bg-white dark:bg-muted"
                                    >
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                <BrowserIcon className="h-5 w-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="text-sm font-medium">
                                                        {browser} on {os}
                                                    </p>
                                                    {session.is_current && (
                                                        <Badge className="bg-status-success-bg text-xs text-status-success">
                                                            Current Session
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {session.ip_address && (
                                                        <span className="font-mono">
                                                            {session.ip_address}
                                                        </span>
                                                    )}
                                                    {lastActive !== null && (
                                                        <span>
                                                            Last active:{' '}
                                                            {lastActive < 1
                                                                ? 'just now'
                                                                : `${lastActive} min ago`}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            {!session.is_current && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                                    onClick={() =>
                                                        router.delete(
                                                            `${usersBasePath}/${u.id}/sessions/${session.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Terminate
                                                </Button>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </TabsContent>

                {/* Tab 4: Staff Profile */}
                {u.staff_profile && (
                    <TabsContent value="staff" className="mt-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <UserCog className="h-5 w-5 text-primary" />{' '}
                                    Staff Profile
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Employee ID
                                    </span>
                                    <span className="col-span-2 font-mono">
                                        {u.staff_profile.employee_id ?? '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Job Title
                                    </span>
                                    <span className="col-span-2 font-medium">
                                        {u.staff_profile.job_title ?? '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Department
                                    </span>
                                    <span className="col-span-2">
                                        {u.staff_profile.department ?? '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Hire Date
                                    </span>
                                    <span className="col-span-2">
                                        {u.staff_profile.hire_date
                                            ? new Date(
                                                  u.staff_profile.hire_date,
                                              ).toLocaleDateString('en-NZ', {
                                                  day: 'numeric',
                                                  month: 'long',
                                                  year: 'numeric',
                                              })
                                            : '—'}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <span className="col-span-2">
                                        {u.staff_profile.status ===
                                            'active' && (
                                            <Badge className="bg-status-success-bg text-xs text-status-success">
                                                Active
                                            </Badge>
                                        )}
                                        {u.staff_profile.status ===
                                            'on_leave' && (
                                            <Badge className="bg-status-warning-bg text-xs text-status-warning">
                                                On Leave
                                            </Badge>
                                        )}
                                        {u.staff_profile.status ===
                                            'terminated' && (
                                            <Badge
                                                variant="destructive"
                                                className="text-xs"
                                            >
                                                Terminated
                                            </Badge>
                                        )}
                                        {!u.staff_profile.status && '—'}
                                    </span>
                                </div>
                                {u.staff_profile.work_phone && (
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Work Phone
                                        </span>
                                        <span className="col-span-2 flex items-center gap-1">
                                            <Phone className="h-3.5 w-3.5 text-muted-foreground" />
                                            {u.staff_profile.work_phone}
                                        </span>
                                    </div>
                                )}
                                {u.staff_profile.mobile_phone && (
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Mobile Phone
                                        </span>
                                        <span className="col-span-2 flex items-center gap-1">
                                            <Smartphone className="h-3.5 w-3.5 text-muted-foreground" />
                                            {u.staff_profile.mobile_phone}
                                        </span>
                                    </div>
                                )}

                                {/* Emergency Contact */}
                                {u.staff_profile.emergency_contact_name && (
                                    <>
                                        <div className="mt-4 border-t pt-4">
                                            <h4 className="mb-3 text-sm font-semibold">
                                                Emergency Contact
                                            </h4>
                                        </div>
                                        <div className="grid grid-cols-3 gap-2 text-sm">
                                            <span className="text-muted-foreground">
                                                Name
                                            </span>
                                            <span className="col-span-2 font-medium">
                                                {
                                                    u.staff_profile
                                                        .emergency_contact_name
                                                }
                                            </span>
                                        </div>
                                        {u.staff_profile
                                            .emergency_contact_phone && (
                                            <div className="grid grid-cols-3 gap-2 text-sm">
                                                <span className="text-muted-foreground">
                                                    Phone
                                                </span>
                                                <span className="col-span-2">
                                                    {
                                                        u.staff_profile
                                                            .emergency_contact_phone
                                                    }
                                                </span>
                                            </div>
                                        )}
                                        {u.staff_profile
                                            .emergency_contact_relationship && (
                                            <div className="grid grid-cols-3 gap-2 text-sm">
                                                <span className="text-muted-foreground">
                                                    Relationship
                                                </span>
                                                <span className="col-span-2 capitalize">
                                                    {
                                                        u.staff_profile
                                                            .emergency_contact_relationship
                                                    }
                                                </span>
                                            </div>
                                        )}
                                    </>
                                )}

                                {/* View Full HR Profile link */}
                                {u.staff_profile.id && (
                                    <div className="border-t pt-4">
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/hr/staff/${u.staff_profile.id}`}
                                            >
                                                <UserCog className="mr-2 h-4 w-4" />
                                                View Full HR Profile
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                )}
            </Tabs>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`${u.name ?? 'User'} — ${isSystemView ? 'System' : 'Settings'}`}
            />
            {isSystemView ? (
                content
            ) : (
                <SettingsLayout>{content}</SettingsLayout>
            )}
        </AppLayout>
    );
}
