import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Target } from 'lucide-react';

interface Plan {
    id: number;
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    vision_statement: string | null;
    mission_statement: string | null;
    values: string[] | null;
}

export default function EditStrategy({ auth, plan }: { auth: any; plan: Plan }) {
    const { data, setData, put, processing, errors } = useForm({
        title: plan.title,
        planning_horizon: plan.planning_horizon,
        period_start: plan.period_start,
        period_end: plan.period_end,
        vision_statement: plan.vision_statement ?? '',
        mission_statement: plan.mission_statement ?? '',
        values: (plan.values ?? []).join('\n'),
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/governance/strategy/${plan.id}`, {
            data: {
                ...data,
                values: data.values.split('\n').map((v: string) => v.trim()).filter(Boolean),
            },
        });
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategy', href: '/governance/strategy' },
                { title: plan.title, href: `/governance/strategy/${plan.id}` },
                { title: 'Edit', href: `/governance/strategy/${plan.id}/edit` },
            ]}
        >
            <Head title={`Edit: ${plan.title}`} />
            <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center gap-3 mb-6">
                    <Target className="w-8 h-8 text-purple-600" />
                    <h1 className="text-3xl font-bold text-gray-900">Edit Strategic Plan</h1>
                </div>
                <Card>
                    <CardHeader><CardTitle>Plan Details</CardTitle></CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label>Title</Label>
                                <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                {errors.title && <p className="text-sm text-red-600 mt-1">{errors.title}</p>}
                            </div>
                            <div>
                                <Label>Planning Horizon</Label>
                                <Select value={data.planning_horizon} onValueChange={(v) => setData('planning_horizon', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="1_year">1 Year</SelectItem>
                                        <SelectItem value="3_year">3 Year</SelectItem>
                                        <SelectItem value="5_year">5 Year</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Period Start</Label>
                                    <Input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Period End</Label>
                                    <Input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)} />
                                </div>
                            </div>
                            <div>
                                <Label>Vision Statement</Label>
                                <Textarea value={data.vision_statement} onChange={(e) => setData('vision_statement', e.target.value)} rows={3} />
                            </div>
                            <div>
                                <Label>Mission Statement</Label>
                                <Textarea value={data.mission_statement} onChange={(e) => setData('mission_statement', e.target.value)} rows={3} />
                            </div>
                            <div>
                                <Label>Values (one per line)</Label>
                                <Textarea value={data.values} onChange={(e) => setData('values', e.target.value)} rows={4} placeholder={"Excellence\nIntegrity\nCompassion"} />
                            </div>
                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>Update Plan</Button>
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
