import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

function Toggle({
    enabled,
    onToggle,
}: {
    enabled: boolean;
    onToggle: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- Custom switch track/knob control, not a standard action button.
        <button
            type="button"
            role="switch"
            aria-checked={enabled}
            onClick={onToggle}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none ${
                enabled ? 'bg-primary' : 'bg-muted'
            }`}
        >
            <span
                className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform duration-200 ease-in-out ${
                    enabled ? 'translate-x-5' : 'translate-x-0.5'
                } mt-0.5`}
            />
        </button>
    );
}

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
            <Head title="Notification Preferences" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Settings}
                        title="Notification Preferences"
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
                                <Toggle
                                    enabled={pref.enabled}
                                    onToggle={() => handleToggle(pref.key)}
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
                            {saving ? 'Saving...' : 'Save Preferences'}
                        </Button>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
