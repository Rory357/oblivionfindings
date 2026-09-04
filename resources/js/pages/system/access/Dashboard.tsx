import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Lock,
    Settings,
    Shield,
    ShieldCheck,
    UserCheck,
    UserCog,
    Users,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'Access Control', href: '/system/access' },
];

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
    level_display: string;
    type: 'system' | 'custom';
    description: string | null;
    users_count: number;
    permissions_count: number;
};

type Stats = {
    total_roles: number;
    system_roles: number;
    custom_roles: number;
    permission_groups: number;
    total_permissions: number;
    active_users: number;
    pending_invitations: number;
};

type Props = {
    stats: Stats;
    roles: Role[];
};

export default function AccessControlDashboard({ stats, roles }: Props) {
    const { auth } = usePage().props as any;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Access Control" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Shield}
                        title="Access Control"
                        description="Manage roles, permissions, and user access for your organization."
                        stats={[
                            { label: 'Roles', value: stats.total_roles },
                            {
                                label: 'Permissions',
                                value: stats.total_permissions,
                            },
                            {
                                label: 'Active users',
                                value: stats.active_users,
                            },
                            {
                                label: 'Pending invites',
                                value: stats.pending_invitations,
                            },
                        ]}
                    />
                }
            >
                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Total Roles
                            </CardTitle>
                            <Shield className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_roles}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {stats.system_roles} system,{' '}
                                {stats.custom_roles} custom
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Permission Groups
                            </CardTitle>
                            <Lock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.permission_groups}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {stats.total_permissions} total permissions
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Active Users
                            </CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.active_users}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                With assigned roles
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Pending
                            </CardTitle>
                            <UserCheck className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.pending_invitations}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Awaiting approval
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Feature Cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <Shield className="h-5 w-5 text-primary" />
                                </div>
                                <CardTitle className="text-lg">
                                    Roles Management
                                </CardTitle>
                            </div>
                            <CardDescription>
                                View and manage roles for your organization.
                                System roles are pre-defined, custom roles can
                                be created.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 text-sm text-muted-foreground">
                                {stats.system_roles} system roles available.
                                Create custom roles with specific permissions.
                            </div>
                            <Link href="/system/access/roles">
                                <Button variant="outline" className="w-full">
                                    Manage Roles
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <Settings className="h-5 w-5 text-primary" />
                                </div>
                                <CardTitle className="text-lg">
                                    Permissions Matrix
                                </CardTitle>
                            </div>
                            <CardDescription>
                                View permissions across all roles. See which
                                roles have which permissions and visualize
                                access levels.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 text-sm text-muted-foreground">
                                See which roles have which permissions.
                                Visualize access levels.
                            </div>
                            <Link href="/system/access/matrix">
                                <Button variant="outline" className="w-full">
                                    View Matrix
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <UserCog className="h-5 w-5 text-primary" />
                                </div>
                                <CardTitle className="text-lg">
                                    User Assignments
                                </CardTitle>
                            </div>
                            <CardDescription>
                                Manage user role assignments. Assign roles to
                                staff members and manage their access levels.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 text-sm text-muted-foreground">
                                {stats.active_users} users with role
                                assignments.
                            </div>
                            <Link href="/system/access/assignments">
                                <Button variant="outline" className="w-full">
                                    Manage Assignments
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                </div>

                {/* Available Roles Section */}
                <div>
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Available Roles
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Roles that can be assigned to organization
                                members
                            </p>
                        </div>
                        <Link href="/system/access/roles">
                            <Button>
                                <ShieldCheck className="mr-2 h-4 w-4" />
                                Create Custom Role
                            </Button>
                        </Link>
                    </div>

                    <div className="space-y-3">
                        {roles.map((role) => (
                            <Card
                                key={role.id}
                                className="transition-shadow hover:shadow-sm"
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-4">
                                            <div className="rounded-full bg-primary/10 p-2">
                                                <Shield className="h-5 w-5 text-primary" />
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">
                                                        {role.label}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            role.type ===
                                                            'system'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {role.type === 'system'
                                                            ? 'System'
                                                            : 'Custom'}
                                                    </Badge>
                                                    <Badge variant="outline">
                                                        {role.level_display}
                                                    </Badge>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {role.description ||
                                                        `${role.permissions_count} permissions · ${role.users_count} users`}
                                                </p>
                                            </div>
                                        </div>
                                        <Link
                                            dusk={`system-role-view-${role.id}`}
                                            href={`/system/access/roles?edit=${role.id}`}
                                        >
                                            <Button variant="ghost" size="sm">
                                                View
                                            </Button>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
