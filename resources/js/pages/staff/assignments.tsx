import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    status: string;
};

type Props = {
    user: { id: number; name: string; email: string };
    clients: Client[];
    assignedIds: number[];
};

export default function StaffAssignments({
    user,
    clients,
    assignedIds,
}: Props) {
    const { labels } = usePage().props as any;
    const staffLabel = labels?.['staff.singular'] ?? 'Staff';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const form = useForm<{ client_ids: number[] }>({
        client_ids: assignedIds ?? [],
    });

    const toggle = (id: number, checked: boolean) => {
        const next = checked
            ? Array.from(new Set([...form.data.client_ids, id]))
            : form.data.client_ids.filter((x) => x !== id);
        form.setData('client_ids', next);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: staffLabel, href: '/staff' },
                { title: user.name, href: `/staff/${user.id}` },
                { title: 'Assignments', href: `/staff/${user.id}/assignments` },
            ]}
        >
            <Head title="Staff assignments" />

            <PageShell>
                <PageHero
                    icon={ClipboardList}
                    title={`Assign ${clientPlural}`}
                    description={`Assign ${clientPlural.toLowerCase()} to ${user.name}.`}
                    stats={[
                        {
                            label: 'Assigned',
                            value: form.data.client_ids.length,
                        },
                        { label: 'Available', value: clients.length },
                    ]}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/staff/${user.id}/assignments`);
                    }}
                    className="space-y-4"
                >
                    <div className="divide-y rounded-md border">
                        {clients.map((c) => {
                            const checked = form.data.client_ids.includes(c.id);
                            return (
                                <label
                                    key={c.id}
                                    className="flex items-center justify-between gap-3 p-3"
                                >
                                    <div>
                                        <div className="text-sm font-medium">
                                            {c.first_name} {c.last_name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {c.status}
                                        </div>
                                    </div>

                                    <Checkbox
                                        checked={checked}
                                        onCheckedChange={(v) =>
                                            toggle(c.id, !!v)
                                        }
                                    />
                                </label>
                            );
                        })}
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
            </PageShell>
        </AppLayout>
    );
}
