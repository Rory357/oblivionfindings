import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    groups: Record<string, string[]>;
    userPrefs: Record<string, boolean>;
    roleDefaults: Record<string, boolean>;
    canManageRoleDefaults: boolean;
};

export default function NotificationPreferences({
    groups,
    userPrefs,
    roleDefaults,
    canManageRoleDefaults,
}: Props) {
    const { data, setData, put, processing } = useForm({
        prefs: { ...Object.fromEntries(Object.entries(roleDefaults).map(([k, v]) => [k, v])), ...userPrefs },
    });

    const keys = Object.values(groups).flat();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'Notifications' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification preferences" />
            <SettingsLayout>
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <HeadingSmall
                        title="Notifications"
                        description="Choose which in-app notifications you want to receive."
                    />
                    {canManageRoleDefaults && (
                        <Button variant="outline" asChild>
                            <a href="/settings/notifications/roles">Role defaults</a>
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Quick actions</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                const next: Record<string, boolean> = {};
                                keys.forEach((k) => (next[k] = true));
                                setData('prefs', next);
                            }}
                        >
                            Enable all
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                const next: Record<string, boolean> = {};
                                keys.forEach((k) => (next[k] = false));
                                setData('prefs', next);
                            }}
                        >
                            Disable all
                        </Button>
                    </CardContent>
                </Card>

                {Object.entries(groups).map(([groupName, groupKeys]) => (
                    <Card key={groupName}>
                        <CardHeader>
                            <CardTitle className="text-base">{groupName}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {groupKeys.map((key) => {
                                const checked = Boolean((data.prefs as any)[key]);
                                return (
                                    <div key={key} className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="text-sm font-medium">{key}</div>
                                            {roleDefaults[key] !== undefined && (
                                                <div className="text-xs text-muted-foreground">
                                                    Role default: {roleDefaults[key] ? 'On' : 'Off'}
                                                </div>
                                            )}
                                        </div>
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={(v) =>
                                                setData('prefs', {
                                                    ...(data.prefs as any),
                                                    [key]: Boolean(v),
                                                })
                                            }
                                        />
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                ))}

                <Separator />

                <div className="flex justify-end">
                    <Button
                        disabled={processing}
                        onClick={() => put('/settings/notifications')}
                    >
                        Save
                    </Button>
                </div>
            </div>
            </SettingsLayout>
        </AppLayout>
    );
}
