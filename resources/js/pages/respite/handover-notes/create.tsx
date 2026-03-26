import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    stays: any[];
    stayId?: string;
    handoverTypes: string[];
};

export default function HandoverNoteCreate({ stays, stayId, handoverTypes }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        stay_id: stayId || '',
        handover_type: '',
        notes: '',
        sensitive_flag: false,
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: 'New', href: '/respite/handover-notes/create' }]}>
            <Head title="New Handover Note" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Handover Note</h1>
                    <div className="mt-1 text-sm text-slate-500">Record handover information for a respite stay.</div>
                </div>
                <RespiteSubnav />

                <form onSubmit={(e) => { e.preventDefault(); post('/respite/handover-notes'); }} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Handover Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Stay *</Label>
                                    <Select value={data.stay_id} onValueChange={(v) => setData('stay_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select stay" /></SelectTrigger>
                                        <SelectContent>
                                            {stays.map((s: any) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.client?.first_name} {s.client?.last_name} — Stay #{s.id}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.stay_id && <div className="mt-1 text-xs text-red-500">{errors.stay_id}</div>}
                                </div>
                                <div>
                                    <Label>Handover Type *</Label>
                                    <Select value={data.handover_type} onValueChange={(v) => setData('handover_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            {handoverTypes.map((t) => (
                                                <SelectItem key={t} value={t}>{t.replace(/_/g, ' ')}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.handover_type && <div className="mt-1 text-xs text-red-500">{errors.handover_type}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Notes *</Label>
                                <Textarea rows={6} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Enter handover notes..." />
                                {errors.notes && <div className="mt-1 text-xs text-red-500">{errors.notes}</div>}
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.sensitive_flag} onChange={(e) => setData('sensitive_flag', e.target.checked)} />
                                Mark as sensitive
                            </label>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Create Handover Note'}</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
