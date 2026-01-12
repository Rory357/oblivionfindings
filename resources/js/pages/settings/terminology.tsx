import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
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

const LABEL_KEYS = [
    'client.singular',
    'client.plural',
    'staff.singular',
    'staff.plural',
    'worker.singular',
    'worker.plural',
    'shift.singular',
    'shift.plural',
    'timesheet.singular',
    'timesheet.plural',
];

export default function TerminologyPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const initial = Object.fromEntries(
        LABEL_KEYS.map((k) => [
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
                    You don’t have permission to manage terminology.
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
                        description="Rename key terms in the UI (e.g. Clients → Patients)."
                    />

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/terminology');
                        }}
                        className="space-y-6"
                    >
                        <div className="space-y-4">
                            {LABEL_KEYS.map((key) => (
                                <div key={key} className="space-y-2">
                                    <Label htmlFor={key}>{key}</Label>
                                    <Input
                                        id={key}
                                        value={form.data.labels[key] ?? ''}
                                        onChange={(e) =>
                                            form.setData('labels', {
                                                ...form.data.labels,
                                                [key]: e.target.value,
                                            })
                                        }
                                    />
                                    <div className="text-xs text-muted-foreground">
                                        Default: {props.defaults?.[key] ?? ''}
                                    </div>
                                </div>
                            ))}
                        </div>

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
