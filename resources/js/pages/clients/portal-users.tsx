import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    portal_users: Array<{ id: number; name: string; email: string; relation: string }>;
    relation_options: string[];
};

export default function ClientPortalUsers({ client, portal_users, relation_options }: Props) {
    const { labels } = usePage().props as any;
    const form = useForm({
        email: '',
        name: '',
        relation: 'mother',
        portal_role: 'next_of_kin',
        action: 'link',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.setData('action', 'link');
        form.post(`/clients/${client.id}/portal-users`, { preserveScroll: true });
    };

    const userNotFound = (form.errors.email || '').toLowerCase().includes('no user found');

    const createUser = () => {
        form.setData('action', 'create_user');
        form.post(`/clients/${client.id}/portal-users`, { preserveScroll: true });
    };

    const saveContactOnly = () => {
        form.setData('action', 'contact_only');
        form.post(`/clients/${client.id}/portal-users`, { preserveScroll: true });
    };

    const name = `${client.first_name} ${client.last_name}`.trim();

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Portal Users', href: `/clients/${client.id}/portal-users` },
            ]}
        >
            <Head title={`Portal Users - ${name}`} />

            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Link a portal user</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <div className="md:col-span-2">
                                <Label htmlFor="name">Name (for new users)</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="Jane Smith"
                                />
                                {form.errors.name && (
                                    <div className="mt-1 text-xs text-red-600">{form.errors.name}</div>
                                )}
                            </div>

                            <div className="md:col-span-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    placeholder="user@example.com"
                                />
                                {form.errors.email && (
                                    <div className="mt-1 text-xs text-red-600">{form.errors.email}</div>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="portal_role">Portal role</Label>
                                <select
                                    id="portal_role"
                                    className="mt-2 w-full rounded-md border bg-white px-3 py-2 text-sm"
                                    value={form.data.portal_role}
                                    onChange={(e) => form.setData('portal_role', e.target.value)}
                                >
                                    <option value="client">{labels?.['client.singular'] ?? 'Client'}</option>
                                    <option value="next_of_kin">Next of kin</option>
                                </select>
                            </div>

                            <div>
                                <Label htmlFor="relation">Relation</Label>
                                <select
                                    id="relation"
                                    className="mt-2 w-full rounded-md border bg-white px-3 py-2 text-sm"
                                    value={form.data.relation}
                                    onChange={(e) => form.setData('relation', e.target.value)}
                                >
                                    {relation_options.map((r) => (
                                        <option key={r} value={r}>
                                            {r.replace(/_/g, ' ')}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="md:col-span-3">
                                <Button type="submit" disabled={form.processing || !form.data.email.trim()}>
                                    Link user
                                </Button>
                            </div>

                            {userNotFound && (
                                <div className="md:col-span-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
                                    <div className="font-medium text-amber-900">
                                        User not found for this email.
                                    </div>
                                    <div className="mt-1 text-amber-800">
                                        Do you want to create a user for this person?
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Button type="button" onClick={createUser} disabled={form.processing}>
                                            Yes - create user and send password setup email
                                        </Button>
                                        <Button type="button" variant="secondary" onClick={saveContactOnly} disabled={form.processing}>
                                            No - save as contact/display only
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Linked users</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {portal_users.map((u) => (
                            <div key={u.id} className="flex items-center justify-between gap-3 rounded-md border p-3">
                                <div>
                                    <div className="text-sm font-medium">{u.name}</div>
                                    <div className="text-xs text-slate-500">{u.email} - {u.relation}</div>
                                </div>
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        form.delete(`/clients/${client.id}/portal-users/${u.id}`, { preserveScroll: true })
                                    }
                                >
                                    Unlink
                                </Button>
                            </div>
                        ))}
                        {!portal_users.length && (
                            <div className="text-sm text-slate-500">No portal users linked yet.</div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
