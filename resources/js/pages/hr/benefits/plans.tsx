import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Plus } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';

interface PlanType {
    value: string;
    label: string;
}

interface BenefitPlan {
    id: number;
    name: string;
    type: string;
    provider: string | null;
    description: string | null;
    employer_contribution_rate: string;
    is_active: boolean;
    enrollments_count: number;
}

interface Props {
    plans: { data: BenefitPlan[]; links: any[] };
    filters: { type: string | null };
    planTypes: PlanType[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Benefits', href: '/hr/benefits' },
    { title: 'Plans', href: '/hr/benefits/plans' },
];

const typeLabels: Record<string, string> = {
    kiwisaver: 'KiwiSaver',
    health_insurance: 'Health Insurance',
    life_insurance: 'Life Insurance',
    other: 'Other',
};

const emptyForm = {
    name: '',
    type: '',
    provider: '',
    description: '',
    employer_contribution_rate: '',
    is_active: true,
};

export default function BenefitPlans({ plans, filters, planTypes, can }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<typeof emptyForm>(emptyForm);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/benefits/plans', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/benefits/plans', form, {
            onSuccess: () => {
                setOpen(false);
                setForm(emptyForm);
            },
        });
    };

    const set = (key: string, value: string | boolean) => setForm((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Benefit Plans" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Benefit Plans</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage available benefit plans for employees
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" onClick={() => { setForm(emptyForm); setOpen(true); }}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Plan
                        </Button>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="max-w-xs">
                            <Label className="text-xs text-slate-500">Plan Type</Label>
                            <Select
                                value={filters.type || 'all'}
                                onValueChange={(val) => onFilter({ type: val === 'all' ? null : val })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    {planTypes.map((pt) => (
                                        <SelectItem key={pt.value} value={pt.value}>
                                            {pt.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Plans Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Employer Rate</TableHead>
                                    <TableHead>Active Enrollments</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {plans.data.map((plan) => (
                                    <TableRow key={plan.id}>
                                        <TableCell className="font-medium">{plan.name}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {typeLabels[plan.type] || plan.type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{plan.provider || '-'}</TableCell>
                                        <TableCell>{plan.employer_contribution_rate}%</TableCell>
                                        <TableCell>{plan.enrollments_count}</TableCell>
                                        <TableCell>
                                            <Badge variant={plan.is_active ? 'default' : 'secondary'}>
                                                {plan.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!plans.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-sm text-slate-500">
                                            No benefit plans found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {plans?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {plans.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            {/* Create Plan Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>New Benefit Plan</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label>Plan Name</Label>
                            <Input value={form.name} onChange={(e) => set('name', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Type</Label>
                            <Select value={form.type} onValueChange={(val) => set('type', val)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {planTypes.map((pt) => (
                                        <SelectItem key={pt.value} value={pt.value}>
                                            {pt.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Provider</Label>
                            <Input value={form.provider} onChange={(e) => set('provider', e.target.value)} />
                        </div>
                        <div>
                            <Label>Employer Contribution Rate (%)</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={form.employer_contribution_rate}
                                onChange={(e) => set('employer_contribution_rate', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label>Description</Label>
                            <Textarea value={form.description} onChange={(e) => set('description', e.target.value)} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit">Create Plan</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
