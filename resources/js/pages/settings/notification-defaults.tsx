import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

type RoleRow = { id: number; name: string; label: string };

type Props = {
    groups: Record<string, string[]>;
    roles: RoleRow[];
    matrix: Record<number, Record<string, boolean>>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Notification Defaults', href: '/settings/notification-defaults' },
];

export default function NotificationDefaults({ groups, roles, matrix }: Props) {
    const allKeys = Object.values(groups).flat();
    const { data, setData, put, processing } = useForm({
        matrix: matrix,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Defaults" />
            <SettingsLayout>
                <Head title="Notification defaults" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="Notification defaults"
                        description="Set default notification behaviour per role. Users can still override these in their own settings."
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Quick actions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    const next: any = { ...data.matrix };
                                    roles.forEach((r) => {
                                        next[r.id] = next[r.id] || {};
                                        allKeys.forEach(
                                            (k) => (next[r.id][k] = true),
                                        );
                                    });
                                    setData('matrix', next);
                                }}
                            >
                                Enable all
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    const next: any = { ...data.matrix };
                                    roles.forEach((r) => {
                                        next[r.id] = next[r.id] || {};
                                        allKeys.forEach(
                                            (k) => (next[r.id][k] = false),
                                        );
                                    });
                                    setData('matrix', next);
                                }}
                            >
                                Disable all
                            </Button>
                        </CardContent>
                    </Card>

                    {Object.entries(groups).map(([groupName, keys]) => (
                        <Card key={groupName}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {groupName}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {keys.map((key) => (
                                    <div key={key} className="space-y-2">
                                        <div className="text-sm font-medium">
                                            {key}
                                        </div>
                                        <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                                            {roles.map((role) => {
                                                const checked = Boolean(
                                                    (data.matrix as any)?.[
                                                        role.id
                                                    ]?.[key],
                                                );
                                                return (
                                                    <div
                                                        key={role.id}
                                                        className="flex items-center justify-between rounded-md border p-2"
                                                    >
                                                        <div className="text-xs font-medium">
                                                            {role.label}
                                                        </div>
                                                        <Checkbox
                                                            checked={checked}
                                                            onCheckedChange={(
                                                                v,
                                                            ) => {
                                                                const next: any =
                                                                    {
                                                                        ...(data.matrix as any),
                                                                    };
                                                                next[role.id] =
                                                                    {
                                                                        ...(next[
                                                                            role
                                                                                .id
                                                                        ] ||
                                                                            {}),
                                                                    };
                                                                next[role.id][
                                                                    key
                                                                ] = Boolean(v);
                                                                setData(
                                                                    'matrix',
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}

                    <Separator />

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" asChild>
                            <a href="/settings/notifications">Back</a>
                        </Button>
                        <Button
                            disabled={processing}
                            onClick={() => put('/settings/notifications/roles')}
                        >
                            Save defaults
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
