import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';

type Props = {
    defaults: Record<string, string>;
    overrides: Record<string, string | null>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Terminology', href: '/settings/terminology' },
];

const LABEL_GROUPS: { title: string; keys: string[] }[] = [
    {
        title: 'People',
        keys: [
            'client.singular', 'client.plural',
            'staff.singular', 'staff.plural',
            'worker.singular', 'worker.plural',
        ],
    },
    {
        title: 'Scheduling',
        keys: [
            'shift.singular', 'shift.plural',
            'timesheet.singular', 'timesheet.plural',
            'respite.singular', 'respite.plural',
        ],
    },
    {
        title: 'Locations & Assets',
        keys: [
            'site.singular', 'site.plural',
            'asset.singular', 'asset.plural',
        ],
    },
    {
        title: 'Clinical & Safety',
        keys: [
            'medication.singular', 'medication.plural',
            'incident.singular', 'incident.plural',
            'risk.singular', 'risk.plural',
        ],
    },
    {
        title: 'Records',
        keys: [
            'note.singular', 'note.plural',
            'timeline.singular', 'timeline.plural',
            'document.singular', 'document.plural',
            'report.singular', 'report.plural',
        ],
    },
    {
        title: 'Security',
        keys: [
            'emergency_access.singular', 'emergency_access.plural',
        ],
    },
];

const ALL_KEYS = LABEL_GROUPS.flatMap((g) => g.keys);

function humanizeKey(key: string): string {
    return key
        .replace(/\./g, ' ')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function TerminologyPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const initial = Object.fromEntries(
        ALL_KEYS.map((k) => [
            k,
            (props.overrides?.[k] ?? props.defaults?.[k] ?? '').toString(),
        ]),
    );

    const form = useForm<{ labels: Record<string, string> }>({
        labels: initial,
    });

    if (!can?.settings?.manageTerminology) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Terminology" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don't have permission to manage terminology.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Terminology" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Terminology"
                        description="Rename key terms in the UI (e.g. Clients → Patients). Leave blank to use the default."
                    />

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/terminology');
                        }}
                        className="space-y-6"
                    >
                        {LABEL_GROUPS.map((group) => (
                            <Card key={group.title}>
                                <CardHeader>
                                    <CardTitle className="text-base">{group.title}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {group.keys.map((key) => (
                                            <div key={key} className="space-y-1">
                                                <Label htmlFor={key} className="text-xs">
                                                    {humanizeKey(key)}
                                                </Label>
                                                <Input
                                                    id={key}
                                                    value={form.data.labels[key] ?? ''}
                                                    placeholder={props.defaults?.[key] ?? ''}
                                                    onChange={(e) =>
                                                        form.setData('labels', {
                                                            ...form.data.labels,
                                                            [key]: e.target.value,
                                                        })
                                                    }
                                                />
                                                {props.overrides?.[key] && props.overrides[key] !== props.defaults?.[key] && (
                                                    <div className="text-xs text-muted-foreground">
                                                        Default: {props.defaults?.[key]}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.reset()}
                            >
                                Reset
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
