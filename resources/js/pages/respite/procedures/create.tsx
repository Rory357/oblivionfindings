import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, useForm } from '@inertiajs/react';

export default function RespiteProcedureCreate() {
    const form = useForm({
        name: '',
        version: 1,
        trigger_event: '',
        description: '',
        steps_json: [],
        required_roles: [],
        active: true,
    });

    const { data, setData, post, processing, errors } = form;
    const example = [
        {
            id: 'arrival_checkin',
            title: 'Arrival / Check-in',
            required_evidence: ['signed_checkin'],
            sla_minutes: 120,
            stop_gate: true,
        },
    ];

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Procedures', href: '/respite/procedures' },
            { title: 'New Template', href: '/respite/procedures/create' },
        ]}>
            <Head title="New Procedure Template" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Procedure Template</h1>
                </div>
                <RespiteSubnav />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/procedures');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Template Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Name *</Label>
                                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <div className="mt-1 text-xs text-red-500">{errors.name}</div>}
                                </div>
                                <div>
                                    <Label>Version *</Label>
                                    <Input type="number" min={1} value={data.version} onChange={(e) => setData('version', Number(e.target.value))} />
                                    {errors.version && <div className="mt-1 text-xs text-red-500">{errors.version}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Trigger Event</Label>
                                <Input value={data.trigger_event} onChange={(e) => setData('trigger_event', e.target.value)} />
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                            </div>

                            <div>
                                <Label>Steps (JSON)</Label>
                                <Textarea
                                    value={JSON.stringify(data.steps_json, null, 2)}
                                    onChange={(e) => {
                                        try {
                                            const parsed = JSON.parse(e.target.value || '[]');
                                            setData('steps_json', parsed);
                                        } catch {
                                            setData('steps_json', data.steps_json);
                                        }
                                    }}
                                    rows={8}
                                />
                                <div className="mt-2 text-xs text-slate-500">
                                    Example:
                                    <pre className="mt-1 rounded-md border bg-slate-50 p-2 text-xs">
                                        {JSON.stringify(example, null, 2)}
                                    </pre>
                                </div>
                                {errors.steps_json && <div className="mt-1 text-xs text-red-500">{errors.steps_json}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Create Template'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
