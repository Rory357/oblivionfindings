import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Copy,
    KeyRound,
    Pencil,
    Plus,
    Shield,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useMemo } from 'react';

type Permission = { id: number; key: string; description?: string | null };
type RoleItem = {
    id: number;
    name: string;
    label: string;
    description?: string | null;
    users_count: number;
    permission_keys: string[];
};

type Props = {
    roles: RoleItem[];
    permissions: Permission[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Roles & Permissions', href: '/settings/roles' },
];

/** Colour-coded left border based on role key conventions */
function roleBorderColour(name: string): string {
    const n = name.toLowerCase();
    if (n.includes('admin') || n === 'superadmin') return 'border-l-violet-500';
    if (n.includes('staff') || n.includes('worker') || n.includes('coordinator')) return 'border-l-blue-500';
    if (n.includes('readonly') || n.includes('viewer') || n.includes('read_only')) return 'border-l-emerald-500';
    if (n.includes('portal') || n.includes('family') || n.includes('external')) return 'border-l-amber-500';
    return 'border-l-slate-400';
}

/** Extract top permission groups from keys (e.g. "sites", "clients", "assets") */
function topPermissionGroups(keys: string[], max = 3): string[] {
    const counts: Record<string, number> = {};
    for (const k of keys) {
        const prefix = k.split('.')[0] ?? 'other';
        counts[prefix] = (counts[prefix] ?? 0) + 1;
    }
    return Object.entries(counts)
        .sort((a, b) => b[1] - a[1])
        .slice(0, max)
        .map(([g]) =>
            g
                .split('_')
                .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
                .join(' '),
        );
}

export default function RolesIndex(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const stats = useMemo(() => {
        const totalRoles = props.roles.length;
        const totalPermissions = props.permissions.length;
        const totalUsers = props.roles.reduce((sum, r) => sum + (r.users_count ?? 0), 0);
        return { totalRoles, totalPermissions, totalUsers };
    }, [props.roles, props.permissions]);

    if (!can?.settings?.manageAccess) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Roles & Permissions" />
                <SettingsLayout>
                    <HeadingSmall title="Roles & Permissions" description="" />
                    <div className="rounded-md border p-4 text-sm">
                        You don't have permission to manage roles.
                    </div>
                </SettingsLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles & Permissions" />

            <SettingsLayout>
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <HeadingSmall
                        title="Roles & Permissions"
                        description="Manage roles and their permission sets"
                    />
                    <Button className="bg-primary hover:bg-primary" asChild>
                        <Link href="/settings/roles/create">
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Role
                        </Link>
                    </Button>
                </div>

                {/* Stats row */}
                <div className="grid grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 py-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                <Shield className="h-5 w-5 text-primary dark:text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.totalRoles}</p>
                                <p className="text-xs text-muted-foreground">Total Roles</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 py-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-info-bg">
                                <KeyRound className="h-5 w-5 text-status-info dark:text-status-info" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.totalPermissions}</p>
                                <p className="text-xs text-muted-foreground">Total Permissions</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 py-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-success-bg">
                                <Users className="h-5 w-5 text-status-success dark:text-status-success" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.totalUsers}</p>
                                <p className="text-xs text-muted-foreground">Users Assigned</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Role cards grid */}
                {props.roles.length > 0 ? (
                    <div className="grid gap-4 md:grid-cols-2">
                        {props.roles.map((role) => {
                            const topGroups = topPermissionGroups(role.permission_keys);
                            const borderClass = roleBorderColour(role.name);

                            return (
                                <Card
                                    key={role.id}
                                    className={`border-l-4 ${borderClass} transition-shadow hover:shadow-md`}
                                >
                                    <CardContent className="p-5">
                                        {/* Top row: label + actions */}
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <h3 className="text-lg font-semibold leading-tight">
                                                    {role.label}
                                                </h3>
                                                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                                    {role.name}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1.5">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-8 gap-1.5"
                                                    asChild
                                                >
                                                    <Link href={`/settings/roles/${role.id}/edit`}>
                                                        <Pencil className="h-3.5 w-3.5" />
                                                        Edit
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-8 w-8 p-0"
                                                    title="Clone role"
                                                    onClick={() =>
                                                        router.visit('/settings/roles/create', {
                                                            data: { clone: role.id },
                                                        })
                                                    }
                                                >
                                                    <Copy className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </div>

                                        {/* Description */}
                                        <p className="mt-2 text-sm text-muted-foreground line-clamp-2">
                                            {role.description || 'No description'}
                                        </p>

                                        {/* Badges row */}
                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary" className="gap-1">
                                                <Users className="h-3 w-3" />
                                                {role.users_count ?? 0} user{(role.users_count ?? 0) !== 1 ? 's' : ''}
                                            </Badge>
                                            <Badge variant="secondary" className="gap-1">
                                                <ShieldCheck className="h-3 w-3" />
                                                {role.permission_keys.length} permission{role.permission_keys.length !== 1 ? 's' : ''}
                                            </Badge>
                                        </div>

                                        {/* Permission groups summary */}
                                        {topGroups.length > 0 && (
                                            <div className="mt-3 flex items-center gap-1.5">
                                                <span className="text-xs text-muted-foreground">Top areas:</span>
                                                {topGroups.map((g) => (
                                                    <Badge
                                                        key={g}
                                                        variant="outline"
                                                        className="text-xs font-normal"
                                                    >
                                                        {g}
                                                    </Badge>
                                                ))}
                                                {role.permission_keys.length > 0 && (
                                                    <span className="text-xs text-muted-foreground">
                                                        +{new Set(role.permission_keys.map((k) => k.split('.')[0])).size - topGroups.length > 0
                                                            ? `${new Set(role.permission_keys.map((k) => k.split('.')[0])).size - topGroups.length} more`
                                                            : ''}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                <Shield className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <h3 className="mt-4 text-sm font-medium">No roles found</h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Get started by creating your first role.
                            </p>
                            <Button className="mt-4 bg-primary hover:bg-primary" asChild>
                                <Link href="/settings/roles/create">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Role
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
