import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    group?: { id: number; name: string; type: string; description: string | null };
    isEdit?: boolean;
};

export default function DeviceGroupForm({ group, isEdit = false }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: group?.name ?? '',
        type: group?.type ?? 'custom',
        description: group?.description ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
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
                { title: 'Device Groups', href: '/security-devices/device-groups' },
                { title: isEdit ? 'Edit' : 'Create Group' },
            ]}
        >
            <Head title={`${isEdit ? 'Edit' : 'Create'} Group - Security & Devices`} />

            <PageShell>
                <PageHeader
                    title={isEdit ? `Edit: ${group?.name}` : 'Create Device Group'}
                    backHref="/security-devices/device-groups"
                    backLabel="Device Groups"
                />

                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <Card>
                        <CardHeader><CardTitle>Group Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <label className="text-sm font-medium mb-1.5 block">
                                    Name <span className="text-destructive">*</span>
                                </label>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Auckland Office Network"
                                />
                                {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Type</label>
                                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="location">Location</SelectItem>
                                        <SelectItem value="functional">Functional</SelectItem>
                                        <SelectItem value="vendor">Vendor</SelectItem>
                                        <SelectItem value="maintenance">Maintenance</SelectItem>
                                        <SelectItem value="custom">Custom</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.type && <p className="mt-1 text-xs text-destructive">{errors.type}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-1.5 block">Description</label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={3}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Optional description of this group's purpose..."
                                />
                                {errors.description && <p className="mt-1 text-xs text-destructive">{errors.description}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" type="button" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : isEdit ? 'Update Group' : 'Create Group'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
