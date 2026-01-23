import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Credential = {
    id: number;
    type: string;
    issuer?: string | null;
    issued_at?: string | null;
    expires_at?: string | null;
    reference?: string | null;
    notes?: string | null;
};

type Props = {
    user: { id: number; name: string; email: string };
    credentials: Credential[];
    canManage: boolean;
};

export default function StaffCredentials({ user, credentials, canManage }: Props) {
    const form = useForm({
        type: '',
        issuer: '',
        issued_at: '',
        expires_at: '',
        reference: '',
        notes: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Staff', href: '/staff' },
                { title: user.name, href: `/staff/${user.id}` },
                { title: 'Credentials', href: `/staff/${user.id}/credentials` },
            ]}
        >
            <Head title={`Credentials: ${user.name}`} />

            <div className="p-4 space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="text-lg font-semibold">Credentials</div>
                        <div className="text-sm text-muted-foreground">
                            {user.name} • {user.email}
                        </div>
                    </div>
                    <Link href={`/staff/${user.id}`}>
                        <Button variant="outline">Back</Button>
                    </Link>
                </div>

                {canManage ? (
                    <form
                        className="rounded-md border p-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/staff/${user.id}/credentials`, { preserveScroll: true });
                        }}
                    >
                        <div className="font-medium">Add credential</div>
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} placeholder="e.g. First Aid" />
                            </div>
                            <div className="space-y-1">
                                <Label>Issuer</Label>
                                <Input value={form.data.issuer} onChange={(e) => form.setData('issuer', e.target.value)} placeholder="e.g. Red Cross" />
                            </div>
                            <div className="space-y-1">
                                <Label>Reference</Label>
                                <Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="Optional" />
                            </div>
                            <div className="space-y-1">
                                <Label>Issued</Label>
                                <Input type="date" value={form.data.issued_at} onChange={(e) => form.setData('issued_at', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Expires</Label>
                                <Input type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Notes</Label>
                                <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Optional" />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing}>Add</Button>
                            {form.recentlySuccessful ? (
                                <span className="text-xs text-muted-foreground">Saved.</span>
                            ) : null}
                        </div>
                    </form>
                ) : null}

                <div className="rounded-md border">
                    <div className="p-4 font-medium">Current credentials</div>
                    <div className="divide-y">
                        {credentials?.length ? (
                            credentials.map((c) => (
                                <div key={c.id} className="p-4 flex items-start justify-between gap-4">
                                    <div>
                                        <div className="text-sm font-semibold">{c.type}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {c.issuer ? `${c.issuer} • ` : ''}
                                            {c.issued_at ? `Issued ${c.issued_at} • ` : ''}
                                            {c.expires_at ? `Expires ${c.expires_at}` : 'No expiry'}
                                        </div>
                                        {c.reference ? <div className="text-xs text-muted-foreground">Ref: {c.reference}</div> : null}
                                        {c.notes ? <div className="text-xs text-muted-foreground mt-1">{c.notes}</div> : null}
                                    </div>

                                    {canManage ? (
                                        <Button
                                            variant="destructive"
                                            onClick={() => {
                                                if (!confirm('Remove this credential?')) return;
                                                form.delete(`/staff/${user.id}/credentials/${c.id}`, { preserveScroll: true });
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    ) : null}
                                </div>
                            ))
                        ) : (
                            <div className="p-4 text-sm text-muted-foreground">No credentials recorded.</div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
