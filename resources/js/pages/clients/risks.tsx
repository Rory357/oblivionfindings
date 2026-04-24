import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string; status: string };
    risks: Array<any>;
    can: { create: boolean; update: boolean };
};

export default function ClientRisks({ client, risks, can }: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();
    const [editingId, setEditingId] = useState<number | null>(null);

    const createForm = useForm({
        label: '',
        severity: 'medium',
        controls: '',
        review_date: '',
        active: true,
    });

    const editForm = useForm({
        label: '',
        severity: 'medium',
        controls: '',
        review_date: '',
        active: true,
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Risks', href: `/clients/${client.id}/risks` },
            ]}
        >
            <Head title={`Risks • ${name}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Risk register</h1>
                        <div className="mt-1 text-sm text-muted-foreground">{name}</div>
                    </div>
                    <Link href={`/clients/${client.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to {(labels?.['client.singular'] ?? 'Client').toLowerCase()}
                    </Link>
                </div>

                {can.create && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Add risk</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="space-y-1">
                                <Label>Label</Label>
                                <Input value={createForm.data.label} onChange={(e) => createForm.setData('label', e.target.value)} />
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Severity</Label>
                                    <Select value={createForm.data.severity} onValueChange={(v) => createForm.setData('severity', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {['low', 'medium', 'high', 'critical'].map((s) => (
                                                <SelectItem key={s} value={s}>{s}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label>Review date</Label>
                                    <Input type="date" value={createForm.data.review_date} onChange={(e) => createForm.setData('review_date', e.target.value)} />
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label>Controls</Label>
                                <Textarea value={createForm.data.controls} onChange={(e) => createForm.setData('controls', e.target.value)} />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox checked={createForm.data.active} onCheckedChange={(v) => createForm.setData('active', Boolean(v))} />
                                <span className="text-sm">Active</span>
                            </div>

                            <div className="flex items-center justify-end">
                                <Button
                                    disabled={createForm.processing}
                                    onClick={() =>
                                        createForm.post(`/clients/${client.id}/risks`, {
                                            onSuccess: () => createForm.reset(),
                                        })
                                    }
                                >
                                    Add
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="space-y-2">
                    {risks.map((r) => (
                        <Card key={r.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">{r.label}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {r.severity} • {r.active ? 'active' : 'inactive'}
                                                {r.review_date ? <span className="ml-2">• review: {r.review_date}</span> : null}
                                            </div>
                                        </div>
                                        {can.update && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setEditingId(r.id);
                                                    editForm.setData({
                                                        label: r.label || '',
                                                        severity: r.severity || 'medium',
                                                        controls: r.controls || '',
                                                        review_date: r.review_date || '',
                                                        active: Boolean(r.active),
                                                    });
                                                }}
                                            >
                                                Edit
                                            </Button>
                                        )}
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                {r.controls ? (
                                    <div>
                                        <div className="text-xs text-muted-foreground">Controls</div>
                                        <div className="whitespace-pre-wrap">{r.controls}</div>
                                    </div>
                                ) : null}

                                {editingId === r.id && can.update && (
                                    <div className="mt-3 rounded-md border p-3 space-y-3">
                                        <div className="space-y-1">
                                            <Label>Label</Label>
                                            <Input value={editForm.data.label} onChange={(e) => editForm.setData('label', e.target.value)} />
                                        </div>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div className="space-y-1">
                                                <Label>Severity</Label>
                                                <Select value={editForm.data.severity} onValueChange={(v) => editForm.setData('severity', v)}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {['low', 'medium', 'high', 'critical'].map((s) => (
                                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Review date</Label>
                                                <Input type="date" value={editForm.data.review_date} onChange={(e) => editForm.setData('review_date', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Controls</Label>
                                            <Textarea value={editForm.data.controls} onChange={(e) => editForm.setData('controls', e.target.value)} />
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Checkbox checked={editForm.data.active} onCheckedChange={(v) => editForm.setData('active', Boolean(v))} />
                                            <span className="text-sm">Active</span>
                                        </div>
                                        <div className="flex items-center justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setEditingId(null);
                                                    editForm.reset();
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                disabled={editForm.processing}
                                                onClick={() =>
                                                    editForm.put(`/clients/${client.id}/risks/${r.id}`, {
                                                        onSuccess: () => setEditingId(null),
                                                    })
                                                }
                                            >
                                                Save
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                    {!risks.length && <div className="text-sm text-muted-foreground">No risks recorded.</div>}
                </div>
            </div>
        </AppLayout>
    );
}
