import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string; status: string };
    incidents: Array<any>;
    templates: Array<any>;
    can: { create: boolean; templatesManage: boolean };
};

export default function ClientIncidents({ client, incidents, templates, can }: Props) {
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
        template_id: '',
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        description: '',
        requires_followup: false,
        immediate_action_taken: '',
        witnesses: '',
    });

    const applyTemplate = (templateId: string) => {
        form.setData('template_id', templateId);
        const t = templates.find((x) => String(x.id) === String(templateId));
        if (!t) return;

        if (t.type) form.setData('type', t.type);
        if (t.severity) form.setData('severity', t.severity);
        if (t.default_description && !form.data.description) form.setData('description', t.default_description);
    };

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
                    <div className="flex flex-wrap items-center gap-2">
                        {can.templatesManage && (
                            <Link
                                href="/incidents/templates"
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Templates
                            </Link>
                        )}
                        <Link
                            href={`/incidents/create`}
                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                        >
                            New (global)
                        </Link>
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
                            <CardTitle className="text-base">New incident (draft)</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Template (optional)</Label>
                                    <Select
                                        value={form.data.template_id || '__none__'}
                                        onValueChange={(v) => applyTemplate(v === '__none__' ? '' : v)}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Pick a template" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">None</SelectItem>
                                            {templates.map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <Label>Type</Label>
                                    <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                        <SelectContent>
                                            {types.map((t) => (
                                                <SelectItem key={t} value={t}>{t.replaceAll('_', ' ')}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <Label>Severity</Label>
                                    <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)}>
                                        <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                        <SelectContent>
                                            {['low', 'medium', 'high'].map((s) => (
                                                <SelectItem key={s} value={s}>{s}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Occurred at</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                                    />
                                </div>

                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox
                                        checked={!!form.data.requires_followup}
                                        onCheckedChange={(v) => form.setData('requires_followup', !!v)}
                                    />
                                    <Label>Requires follow-up</Label>
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label>Description</Label>
                                <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Immediate action taken</Label>
                                <Textarea value={form.data.immediate_action_taken} onChange={(e) => form.setData('immediate_action_taken', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Witnesses</Label>
                                <Textarea value={form.data.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} />
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
                                    Create draft
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
                                            <div className="font-semibold">{i.type} • {i.severity}</div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {i.status}
                                                {i.shift_id ? <span className="ml-2">• Shift-linked</span> : <span className="ml-2">• Standalone</span>}
                                                {i.occurred_at ? <span className="ml-2">• {i.occurred_at}</span> : null}
                                            </div>
                                        </div>
                                        <Link href={`/incidents/${i.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Open
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            {(i.description || i.immediate_action_taken || i.witnesses) && (
                                <CardContent className="space-y-2 text-sm">
                                    {i.description && (
                                        <div>
                                            <div className="text-xs text-slate-500">Description</div>
                                            <div className="whitespace-pre-wrap">{i.description}</div>
                                        </div>
                                    )}
                                    {i.immediate_action_taken && (
                                        <div>
                                            <div className="text-xs text-slate-500">Immediate action</div>
                                            <div className="whitespace-pre-wrap">{i.immediate_action_taken}</div>
                                        </div>
                                    )}
                                    {i.witnesses && (
                                        <div>
                                            <div className="text-xs text-slate-500">Witnesses</div>
                                            <div className="whitespace-pre-wrap">{i.witnesses}</div>
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
