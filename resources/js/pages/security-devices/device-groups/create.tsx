import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import {
    AutoRuleBuilder,
    type AutoRules,
    normaliseAutoRules,
} from './auto-rule-builder';

type Props = {
    group?: {
        id: number;
        name: string;
        type: string;
        description: string | null;
        auto_rules: AutoRules | null;
    };
    isEdit?: boolean;
};

export default function DeviceGroupForm({ group, isEdit = false }: Props) {
    const { data, setData, post, put, processing, errors, transform } = useForm(
        {
            name: group?.name ?? '',
            type: group?.type ?? 'custom',
            description: group?.description ?? '',
            auto_rules: group?.auto_rules ?? null,
        },
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((formData) => ({
            ...formData,
            auto_rules: normaliseAutoRules(formData.auto_rules),
        }));
        if (isEdit && group?.id) {
            put(`/security-devices/device-groups/${group.id}`);
        } else {
            post('/security-devices/device-groups');
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Device Groups',
                    href: '/security-devices/device-groups',
                },
                { title: isEdit ? 'Edit' : 'Create Group' },
            ]}
        >
            <Head
                title={`${isEdit ? 'Edit' : 'Create'} Group - Security & Devices`}
            />

            <PageShell>
                <PageHero
                    variant="compact"
                    title={
                        isEdit ? `Edit: ${group?.name}` : 'Create Device Group'
                    }
                    backHref="/security-devices/device-groups"
                    backLabel="Device Groups"
                />

                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Group Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <label
                                    htmlFor="device-group-name"
                                    className="mb-1.5 block text-sm font-medium"
                                >
                                    Name{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Input
                                    id="device-group-name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    placeholder="e.g. Auckland Office Network"
                                />
                                {errors.name && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="device-group-type"
                                    className="mb-1.5 block text-sm font-medium"
                                >
                                    Type
                                </label>
                                <Select
                                    value={data.type}
                                    onValueChange={(v) => setData('type', v)}
                                >
                                    <SelectTrigger id="device-group-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="location">
                                            Location
                                        </SelectItem>
                                        <SelectItem value="functional">
                                            Functional
                                        </SelectItem>
                                        <SelectItem value="vendor">
                                            Vendor
                                        </SelectItem>
                                        <SelectItem value="maintenance">
                                            Maintenance
                                        </SelectItem>
                                        <SelectItem value="custom">
                                            Custom
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.type && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {errors.type}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="device-group-description"
                                    className="mb-1.5 block text-sm font-medium"
                                >
                                    Description
                                </label>
                                <textarea
                                    id="device-group-description"
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    rows={3}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    placeholder="Optional description of this group's purpose..."
                                />
                                {errors.description && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {errors.description}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Automatic device membership</CardTitle>
                            <CardDescription>
                                Keep this group aligned with device records by
                                area, type, provider, status, or health.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {Object.entries(errors).find(([key]) =>
                                key.startsWith('auto_rules'),
                            )?.[1] && (
                                <p
                                    className="mb-4 text-sm text-destructive"
                                    role="alert"
                                >
                                    {
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith('auto_rules'),
                                        )?.[1]
                                    }
                                </p>
                            )}
                            <AutoRuleBuilder
                                value={data.auto_rules}
                                onChange={(rules) =>
                                    setData('auto_rules', rules)
                                }
                            />
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Group'
                                  : 'Create Group'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
