import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Save, Settings } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type PreferenceItem = {
    key: string;
    label: string;
    description: string;
    enabled: boolean;
};

type Props = {
    preferences: PreferenceItem[];
};

export default function Preferences({ preferences }: Props) {
    const [localPreferences, setLocalPreferences] =
        useState<PreferenceItem[]>(preferences);
    const [saving, setSaving] = useState(false);

    const handleToggle = (key: string) => {
        setLocalPreferences((prev) =>
            prev.map((p) =>
                p.key === key ? { ...p, enabled: !p.enabled } : p,
            ),
        );
    };

    const handleSave = () => {
        setSaving(true);
        router.post(
            '/portal/preferences',
            {
                preferences: localPreferences.map((p) => ({
                    key: p.key,
                    enabled: p.enabled,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Preferences saved successfully.');
                },
                onFinish: () => {
                    setSaving(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: 'Preferences', href: '/portal/preferences' },
            ]}
        >
            <Head title="Notification preferences" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Settings}
                        title="Notification preferences"
                        description="Choose which notifications you'd like to receive."
                        stats={[
                            { label: 'Total', value: localPreferences.length },
                            {
                                label: 'Enabled',
                                value: localPreferences.filter((p) => p.enabled)
                                    .length,
                            },
                        ]}
                    />
                }
            >
                <div className="space-y-3">
                    {localPreferences.map((pref) => (
                        <Card key={pref.key}>
                            <CardContent className="flex items-center justify-between gap-4 py-4">
                                <div className="space-y-0.5">
                                    <div className="flex items-center gap-2">
                                        <Settings className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">
                                            {pref.label}
                                        </span>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {pref.description}
                                    </p>
                                </div>
                                <Switch
                                    checked={pref.enabled}
                                    onCheckedChange={() =>
                                        handleToggle(pref.key)
                                    }
                                    aria-label={pref.label}
                                />
                            </CardContent>
                        </Card>
                    ))}

                    {localPreferences.length === 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    No preferences available
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    There are no notification preferences to
                                    configure at this time.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {localPreferences.length > 0 && (
                    <div className="flex justify-end">
                        <Button onClick={handleSave} disabled={saving}>
                            <Save className="mr-2 h-4 w-4" />
                            {saving ? 'Saving...' : 'Save preferences'}
                        </Button>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
