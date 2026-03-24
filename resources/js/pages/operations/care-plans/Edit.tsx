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
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Props = {
    care_plan: {
        id: number;
        client_id: number;
        title: string;
        plan_type: string;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
        next_review_at: string | null;
        content: any;
        client: { id: number; first_name: string; last_name: string } | null;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function CarePlanEdit({ care_plan, clients }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const { data, setData, put, processing, errors } = useForm({
        client_id: String(care_plan.client_id),
        title: care_plan.title,
        plan_type: care_plan.plan_type,
        status: care_plan.status,
        starts_at: care_plan.starts_at ?? '',
        ends_at: care_plan.ends_at ?? '',
        next_review_at: care_plan.next_review_at ?? '',
        content: typeof care_plan.content === 'string' ? care_plan.content : JSON.stringify(care_plan.content ?? '', null, 2),
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/operations/care-plans/${care_plan.id}`);
    };

    return (
        <AppLayout>
            <Head title={`Edit: ${care_plan.title}`} />
            <PageHeader title={`Edit: ${care_plan.title}`} backHref={`/operations/care-plans/${care_plan.id}`} />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader><CardTitle className="text-base">Plan Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>{clientSingular} *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder={`Select ${clientSingular.toLowerCase()}`} /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Plan Type *</Label>
                                    <Select value={data.plan_type} onValueChange={(v) => setData('plan_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="support_plan">Support Plan</SelectItem>
                                            <SelectItem value="behaviour_plan">Behaviour Plan</SelectItem>
                                            <SelectItem value="health_plan">Health Plan</SelectItem>
                                            <SelectItem value="transition_plan">Transition Plan</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Title *</Label>
                                <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
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
                                    <Label>Next Review</Label>
                                    <Input type="date" value={data.next_review_at} onChange={(e) => setData('next_review_at', e.target.value)} />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger className="w-[160px]"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="draft">Draft</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="review">Review</SelectItem>
                                        <SelectItem value="archived">Archived</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Plan Content / Notes</Label>
                                <Textarea value={data.content} onChange={(e) => setData('content', e.target.value)} rows={6} />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(`/operations/care-plans/${care_plan.id}`)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
