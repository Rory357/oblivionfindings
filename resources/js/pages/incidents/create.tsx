import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    templates: Array<any>;
};

export default function IncidentCreate({ clients, templates }: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can?.incidents ?? {};
    const form = useForm({
        client_id: '',
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
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'New', href: '/incidents/create' }]}>
            <Head title="New incident" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">New incident</h1>
                        <div className="mt-1 text-sm text-slate-500">Standalone incident (draft)</div>
                    </div>
                    <div className="flex items-center gap-2">
                        {can.templatesManage && (
                            <Link href="/incidents/templates" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Templates
                            </Link>
                        )}
                        <Link href="/incidents" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Client</Label>
                                <Select value={form.data.client_id || '__none__'} onValueChange={(v) => form.setData('client_id', v === '__none__' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Select a client" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select...</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Template (optional)</Label>
                                <Select value={form.data.template_id || '__none__'} onValueChange={(v) => applyTemplate(v === '__none__' ? '' : v)}>
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
                                <Label>Occurred at</Label>
                                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} />
                            </div>

                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low','medium','high'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-center gap-2 pt-6">
                                <Checkbox checked={!!form.data.requires_followup} onCheckedChange={(v) => form.setData('requires_followup', !!v)} />
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

                        {Object.keys(form.errors).length > 0 && (
                            <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                                <p className="font-medium">Please fix the following errors:</p>
                                <ul className="mt-1 list-disc pl-5">
                                    {Object.entries(form.errors).map(([field, message]) => (
                                        <li key={field}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex items-center justify-end">
                            <Button
                                disabled={form.processing}
                                onClick={() => form.post('/incidents', { preserveScroll: true })}
                            >
                                Create draft
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
