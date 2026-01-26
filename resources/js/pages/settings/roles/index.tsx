import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

type Permission = { id: number; key: string; description?: string | null };
type RoleItem = {
    id: number;
    name: string;
    label: string;
    permission_keys: string[];
};

type Props = {
    roles: RoleItem[];
    permissions: Permission[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Roles', href: '/settings/roles' },
];

export default function RolesIndex(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    if (!can?.settings?.manageAccess) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Roles" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don’t have permission to manage roles.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles" />

            <SettingsLayout>
                <div className="flex items-start justify-between gap-3">
                    <HeadingSmall
                        title="Roles"
                        description="Create roles and assign permissions. Roles cannot be deleted for audit safety."
                    />

                    <Button asChild>
                        <Link href="/settings/roles/create">New role</Link>
                    </Button>
                </div>

                <div className="space-y-3">
                    {props.roles.map((role) => (
                        <Card key={role.id}>
                            <CardHeader className="flex flex-row items-start justify-between">
                                <div>
                                    <CardTitle className="text-base">
                                        {role.label}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            ({role.name})
                                        </span>
                                    </CardTitle>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        <Badge variant="secondary">
                                            {role.permission_keys.length} permissions
                                        </Badge>
                                    </div>
                                </div>

                                <Button size="sm" variant="outline" asChild>
                                    <Link href={`/settings/roles/${role.id}/edit`}>Edit</Link>
                                </Button>
                            </CardHeader>

                            {role.permission_keys.length > 0 && (
                                <CardContent>
                                    <div className="text-xs text-muted-foreground mb-2">
                                        Permissions
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {role.permission_keys.slice(0, 18).map((k) => (
                                            <Badge key={k} variant="outline">
                                                {k}
                                            </Badge>
                                        ))}
                                        {role.permission_keys.length > 18 && (
                                            <Badge variant="outline">
                                                +{role.permission_keys.length - 18} more
                                            </Badge>
                                        )}
                                    </div>
                                </CardContent>
                            )}
                        </Card>
                    ))}

                    {props.roles.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-muted-foreground">
                            No roles found.
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
