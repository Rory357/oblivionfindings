import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';

type Props = {
    incident: any;
    can: { update: boolean; approve: boolean };
};

export default function IncidentShow({ incident, can }: Props) {
    const clientName = incident.client ? `${incident.client.first_name} ${incident.client.last_name}` : 'Client';
    const form = useForm({
        type: incident.type || 'other',
        severity: incident.severity || 'low',
        status: incident.status || 'draft',
        occurred_at: incident.occurred_at ? incident.occurred_at.slice(0, 16) : '',
        location: incident.location || '',
        title: incident.title || '',
        description: incident.description || '',
        immediate_action: incident.immediate_action || '',
        follow_up_required: incident.follow_up_required || '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Incidents', href: '/incidents' },
                { title: incident.title || `Incident #${incident.id}`, href: `/incidents/${incident.id}` },
            ]}
        >
            <Head title={incident.title || `Incident #${incident.id}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{incident.title}</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            <Link className="underline" href={`/clients/${incident.client_id}`}>{clientName}</Link>
                            <span className="mx-2">•</span>
                            {incident.type} • {incident.severity} • {incident.status}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={`/clients/${incident.client_id}/incidents`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Client incidents
                        </Link>
                        {can.approve && incident.status !== 'reviewed' && (
                            <Button size="sm" variant="outline" onClick={() => form.post(`/incidents/${incident.id}/review`)}>Mark reviewed</Button>
                        )}
                        {can.approve && incident.status !== 'closed' && (
                            <Button size="sm" variant="outline" onClick={() => form.post(`/incidents/${incident.id}/close`)}>Close</Button>
                        )}
                        {can.update && incident.status === 'draft' && (
                            <Button size="sm" variant="outline" onClick={() => form.post(`/incidents/${incident.id}/submit`)}>Submit</Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} disabled={!can.update} />
                            </div>
                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)} disabled={!can.update}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low','medium','high','critical'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Status</Label>
                                <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)} disabled={!can.update}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['draft','submitted','reviewed','closed'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label>Occurred at</Label>
                                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} disabled={!can.update} />
                            </div>
                            <div className="space-y-1">
                                <Label>Location</Label>
                                <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} disabled={!can.update} />
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Title</Label>
                            <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} disabled={!can.update} />
                        </div>

                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} disabled={!can.update} />
                        </div>
                        <div className="space-y-1">
                            <Label>Immediate action</Label>
                            <Textarea value={form.data.immediate_action} onChange={(e) => form.setData('immediate_action', e.target.value)} disabled={!can.update} />
                        </div>
                        <div className="space-y-1">
                            <Label>Follow up required</Label>
                            <Textarea value={form.data.follow_up_required} onChange={(e) => form.setData('follow_up_required', e.target.value)} disabled={!can.update} />
                        </div>

                        {can.update && (
                            <div className="flex items-center justify-end">
                                <Button disabled={form.processing} onClick={() => form.put(`/incidents/${incident.id}`)}>Save</Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
