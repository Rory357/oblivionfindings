import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string; status: string };
    incidents: Array<any>;
    can: { create: boolean; update: boolean; approve: boolean };
};

export default function ClientIncidents({ client, incidents, can }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();
    const [showNew, setShowNew] = useState(false);

    const types = useMemo(
        () => [
            'injury',
            'behaviour',
            'medication',
            'safeguarding',
            'property_damage',
            'missing_person',
            'complaint',
            'other',
        ],
        [],
    );

    const form = useForm({
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        location: '',
        title: '',
        description: '',
        immediate_action: '',
        follow_up_required: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Incidents', href: `/clients/${client.id}/incidents` },
            ]}
        >
            <Head title={`Incidents • ${name}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Incidents</h1>
                        <div className="mt-1 text-sm text-slate-500">{name}</div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={`/clients/${client.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to client
                        </Link>
                        {can.create && (
                            <Button size="sm" onClick={() => setShowNew((v) => !v)}>
                                {showNew ? 'Close' : 'New incident'}
                            </Button>
                        )}
                    </div>
                </div>

                {showNew && can.create && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">New incident</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Type</Label>
                                    <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                        <SelectContent>
                                            {types.map((t) => (
                                                <SelectItem key={t} value={t}>
                                                    {t.replaceAll('_', ' ')}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label>Severity</Label>
                                    <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)}>
                                        <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                        <SelectContent>
                                            {['low', 'medium', 'high', 'critical'].map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {s}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Occurred at</Label>
                                    <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                                </div>
                                <div className="space-y-1">
                                    <Label>Location</Label>
                                    <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label>Title</Label>
                                <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Description</Label>
                                <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Immediate action</Label>
                                <Textarea value={form.data.immediate_action} onChange={(e) => form.setData('immediate_action', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Follow up required</Label>
                                <Textarea value={form.data.follow_up_required} onChange={(e) => form.setData('follow_up_required', e.target.value)} />
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    disabled={form.processing}
                                    onClick={() =>
                                        form.post(`/clients/${client.id}/incidents`, {
                                            onSuccess: () => {
                                                form.reset();
                                                setShowNew(false);
                                            },
                                        })
                                    }
                                >
                                    Create
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="space-y-2">
                    {incidents.map((i) => (
                        <Card key={i.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">{i.title}</div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {i.type} • {i.severity} • {i.status}
                                                {i.occurred_at ? <span className="ml-2">• {i.occurred_at}</span> : null}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/incidents/${i.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Open
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            {(i.description || i.immediate_action || i.follow_up_required) && (
                                <CardContent className="space-y-2 text-sm">
                                    {i.description && (
                                        <div>
                                            <div className="text-xs text-slate-500">Description</div>
                                            <div className="whitespace-pre-wrap">{i.description}</div>
                                        </div>
                                    )}
                                    {i.immediate_action && (
                                        <div>
                                            <div className="text-xs text-slate-500">Immediate action</div>
                                            <div className="whitespace-pre-wrap">{i.immediate_action}</div>
                                        </div>
                                    )}
                                    {i.follow_up_required && (
                                        <div>
                                            <div className="text-xs text-slate-500">Follow up</div>
                                            <div className="whitespace-pre-wrap">{i.follow_up_required}</div>
                                        </div>
                                    )}
                                </CardContent>
                            )}
                        </Card>
                    ))}
                    {!incidents.length && <div className="text-sm text-slate-500">No incidents logged.</div>}
                </div>
            </div>
        </AppLayout>
    );
}
