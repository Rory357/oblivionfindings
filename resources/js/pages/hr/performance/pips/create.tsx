import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, useForm, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Trash2 } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

interface StaffMember {
    id: number;
    name: string;
    email: string;
}

interface Props {
    staff: StaffMember[];
}

interface MilestoneRow {
    title: string;
    description: string;
    due_date: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: 'PIPs', href: '/hr/performance/pips' },
    { title: 'Create', href: '/hr/performance/pips/create' },
];

export default function PipCreate({ staff }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        employee_user_id: '',
        title: '',
        reason: '',
        expectations: '',
        support_offered: '',
        consequences: '',
        start_date: '',
        end_date: '',
        review_date: '',
        milestones: [] as MilestoneRow[],
    });

    const addMilestone = () => {
        setData('milestones', [...data.milestones, { title: '', description: '', due_date: '' }]);
    };

    const removeMilestone = (index: number) => {
        setData('milestones', data.milestones.filter((_, i) => i !== index));
    };

    const updateMilestone = (index: number, field: keyof MilestoneRow, value: string) => {
        const updated = [...data.milestones];
        updated[index] = { ...updated[index], [field]: value };
        setData('milestones', updated);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/performance/pips');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create PIP" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Create Performance Improvement Plan</h1>
                    <div className="mt-1 text-sm text-slate-500">
                        Set up a structured plan with milestones to support employee development
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Plan Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Employee</Label>
                                    <Select value={data.employee_user_id} onValueChange={(val) => setData('employee_user_id', val)}>
                                        <SelectTrigger><SelectValue placeholder="Select employee..." /></SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.employee_user_id && <p className="mt-1 text-xs text-red-500">{errors.employee_user_id}</p>}
                                </div>
                                <div>
                                    <Label>Title</Label>
                                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="PIP title" />
                                    {errors.title && <p className="mt-1 text-xs text-red-500">{errors.title}</p>}
                                </div>
                            </div>

                            <div>
                                <Label>Reason / Areas of Concern</Label>
                                <Textarea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={3} />
                                {errors.reason && <p className="mt-1 text-xs text-red-500">{errors.reason}</p>}
                            </div>

                            <div>
                                <Label>Expectations / Goals</Label>
                                <Textarea value={data.expectations} onChange={(e) => setData('expectations', e.target.value)} rows={3} />
                                {errors.expectations && <p className="mt-1 text-xs text-red-500">{errors.expectations}</p>}
                            </div>

                            <div>
                                <Label>Support Offered</Label>
                                <Textarea value={data.support_offered} onChange={(e) => setData('support_offered', e.target.value)} rows={2} />
                            </div>

                            <div>
                                <Label>Consequences if Not Met</Label>
                                <Textarea value={data.consequences} onChange={(e) => setData('consequences', e.target.value)} rows={2} />
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Start Date</Label>
                                    <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                                    {errors.start_date && <p className="mt-1 text-xs text-red-500">{errors.start_date}</p>}
                                </div>
                                <div>
                                    <Label>End Date</Label>
                                    <Input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                                    {errors.end_date && <p className="mt-1 text-xs text-red-500">{errors.end_date}</p>}
                                </div>
                                <div>
                                    <Label>Review Date</Label>
                                    <Input type="date" value={data.review_date} onChange={(e) => setData('review_date', e.target.value)} />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Milestones</CardTitle>
                                <Button type="button" size="sm" variant="outline" onClick={addMilestone}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Milestone
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {data.milestones.length === 0 && (
                                <p className="text-center text-sm text-slate-400">No milestones added yet. Click "Add Milestone" to begin.</p>
                            )}
                            {data.milestones.map((milestone, index) => (
                                <div key={index} className="rounded-lg border p-4 space-y-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm font-medium text-slate-500">Milestone {index + 1}</span>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => removeMilestone(index)}>
                                            <Trash2 className="h-4 w-4 text-red-400" />
                                        </Button>
                                    </div>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <Label>Title</Label>
                                            <Input
                                                value={milestone.title}
                                                onChange={(e) => updateMilestone(index, 'title', e.target.value)}
                                                placeholder="Milestone title"
                                            />
                                        </div>
                                        <div>
                                            <Label>Due Date</Label>
                                            <Input
                                                type="date"
                                                value={milestone.due_date}
                                                onChange={(e) => updateMilestone(index, 'due_date', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Description</Label>
                                        <Textarea
                                            value={milestone.description}
                                            onChange={(e) => updateMilestone(index, 'description', e.target.value)}
                                            rows={2}
                                        />
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>Create PIP</Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href="/hr/performance/pips">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
