import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function ServiceAgreementCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        client_id: '',
        title: '',
        reference_number: '',
        agreement_type: 'ndis',
        funding_body: '',
        funding_reference: '',
        status: 'draft',
        starts_at: '',
        ends_at: '',
        total_budget: '',
        hourly_rate: '',
        daily_rate: '',
        terms: '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/service-agreements');
    };

    return (
        <AppLayout>
            <Head title="Create Service Agreement" />
            <PageHeader title="Create Service Agreement" backHref="/operations/service-agreements" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader><CardTitle className="text-base">Agreement Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Client *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Agreement Type *</Label>
                                    <Select value={data.agreement_type} onValueChange={(v) => setData('agreement_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="ndis">NDIS</SelectItem>
                                            <SelectItem value="private">Private</SelectItem>
                                            <SelectItem value="block">Block</SelectItem>
                                            <SelectItem value="spot">Spot</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Title *</Label>
                                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. NDIS Service Agreement 2026" />
                                    {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Reference Number</Label>
                                    <Input value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} placeholder="e.g. SA-2026-001" />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Funding Body</Label>
                                    <Input value={data.funding_body} onChange={(e) => setData('funding_body', e.target.value)} placeholder="e.g. NDIA" />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Funding Reference</Label>
                                    <Input value={data.funding_reference} onChange={(e) => setData('funding_reference', e.target.value)} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label>Start Date</Label>
                                    <Input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>End Date</Label>
                                    <Input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Total Budget ($)</Label>
                                    <Input type="number" step="0.01" value={data.total_budget} onChange={(e) => setData('total_budget', e.target.value)} placeholder="0.00" />
                                    {errors.total_budget && <p className="text-xs text-destructive">{errors.total_budget}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Hourly Rate ($)</Label>
                                    <Input type="number" step="0.01" value={data.hourly_rate} onChange={(e) => setData('hourly_rate', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Daily Rate ($)</Label>
                                    <Input type="number" step="0.01" value={data.daily_rate} onChange={(e) => setData('daily_rate', e.target.value)} />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Terms & Conditions</Label>
                                <Textarea value={data.terms} onChange={(e) => setData('terms', e.target.value)} rows={4} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Notes</Label>
                                <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/service-agreements')}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Create Agreement</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
