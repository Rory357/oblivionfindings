import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Step = {
    id?: number;
    step_order: number;
    approver_type: string;
    approver_role_id: number | null;
    approver_user_id: number | null;
    auto_approve_after_days: number | null;
};

type Chain = {
    id: number;
    name: string;
    process_type: string;
    is_active: boolean;
    steps_count: number;
    instances_count: number;
    created_by: string;
    steps: Step[];
    created_at: string;
};

type Props = {
    chains: Chain[];
    processTypes: string[];
    roles: Array<{ id: number; name: string }>;
    users: Array<{ id: number; name: string }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Approvals', href: '/hr/approvals/pending' },
    { title: 'Chains', href: '/hr/approvals/chains' },
];

export default function ApprovalChains({ chains, processTypes, roles, users }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [steps, setSteps] = useState<Step[]>([
        { step_order: 1, approver_type: 'manager', approver_role_id: null, approver_user_id: null, auto_approve_after_days: null },
    ]);

    const form = useForm({
        name: '',
        process_type: '',
        is_active: true,
        steps: steps,
    });

    const addStep = () => {
        const newSteps = [
            ...steps,
            { step_order: steps.length + 1, approver_type: 'manager', approver_role_id: null, approver_user_id: null, auto_approve_after_days: null },
        ];
        setSteps(newSteps);
        form.setData('steps', newSteps);
    };

    const removeStep = (index: number) => {
        const newSteps = steps
            .filter((_, i) => i !== index)
            .map((s, i) => ({ ...s, step_order: i + 1 }));
        setSteps(newSteps);
        form.setData('steps', newSteps);
    };

    const updateStep = (index: number, field: string, value: string | number | null) => {
        const newSteps = [...steps];
        newSteps[index] = { ...newSteps[index], [field]: value };
        setSteps(newSteps);
        form.setData('steps', newSteps);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/hr/approvals/chains', {
            onSuccess: () => {
                setShowForm(false);
                setSteps([{ step_order: 1, approver_type: 'manager', approver_role_id: null, approver_user_id: null, auto_approve_after_days: null }]);
                form.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approval Chains" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Approval Chains</h1>
                        <p className="text-sm text-muted-foreground">Configure multi-level approval workflows for HR processes</p>
                    </div>
                    <Button size="sm" onClick={() => setShowForm(!showForm)}>
                        <Plus className="mr-1.5 h-4 w-4" />
                        New Chain
                    </Button>
                </div>

                {showForm && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create Approval Chain</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>Name</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder="e.g. Standard Leave Approval"
                                        />
                                        {form.errors.name && <p className="text-sm text-status-critical">{form.errors.name}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Process Type</Label>
                                        <Select value={form.data.process_type} onValueChange={(v) => form.setData('process_type', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select process type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {processTypes.map((pt) => (
                                                    <SelectItem key={pt} value={pt}>
                                                        <span className="capitalize">{pt}</span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.process_type && <p className="text-sm text-status-critical">{form.errors.process_type}</p>}
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <Label>Approval Steps</Label>
                                        <Button type="button" variant="outline" size="sm" onClick={addStep}>
                                            <Plus className="mr-1 h-3 w-3" />
                                            Add Step
                                        </Button>
                                    </div>
                                    {steps.map((step, index) => (
                                        <div key={index} className="flex items-center gap-3 rounded-md border p-3">
                                            <span className="text-sm font-medium text-muted-foreground">Step {step.step_order}</span>
                                            <Select
                                                value={step.approver_type}
                                                onValueChange={(v) => updateStep(index, 'approver_type', v)}
                                            >
                                                <SelectTrigger className="w-36">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="manager">Manager</SelectItem>
                                                    <SelectItem value="role">Role</SelectItem>
                                                    <SelectItem value="user">Specific User</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {step.approver_type === 'role' && (
                                                <Select
                                                    value={step.approver_role_id?.toString() ?? ''}
                                                    onValueChange={(v) => updateStep(index, 'approver_role_id', parseInt(v))}
                                                >
                                                    <SelectTrigger className="w-48">
                                                        <SelectValue placeholder="Select role" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((role) => (
                                                            <SelectItem key={role.id} value={role.id.toString()}>
                                                                {role.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                            {step.approver_type === 'user' && (
                                                <Select
                                                    value={step.approver_user_id?.toString() ?? ''}
                                                    onValueChange={(v) => updateStep(index, 'approver_user_id', parseInt(v))}
                                                >
                                                    <SelectTrigger className="w-48">
                                                        <SelectValue placeholder="Select user" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {users.map((user) => (
                                                            <SelectItem key={user.id} value={user.id.toString()}>
                                                                {user.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                            <Input
                                                type="number"
                                                placeholder="Auto-approve days"
                                                className="w-40"
                                                value={step.auto_approve_after_days ?? ''}
                                                onChange={(e) => updateStep(index, 'auto_approve_after_days', e.target.value ? parseInt(e.target.value) : null)}
                                            />
                                            {steps.length > 1 && (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => removeStep(index)}>
                                                    <Trash2 className="h-4 w-4 text-status-critical" />
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>

                                <div className="flex gap-2">
                                    <Button type="submit" disabled={form.processing}>Create Chain</Button>
                                    <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Chains Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Process Type</TableHead>
                                    <TableHead>Steps</TableHead>
                                    <TableHead>Instances</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Created By</TableHead>
                                    <TableHead>Created</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {chains.map((chain) => (
                                    <TableRow key={chain.id}>
                                        <TableCell className="font-medium">{chain.name}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className="capitalize">{chain.process_type}</Badge>
                                        </TableCell>
                                        <TableCell>{chain.steps_count}</TableCell>
                                        <TableCell>{chain.instances_count}</TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className={chain.is_active
                                                    ? 'border-status-success/30 text-status-success bg-status-success'
                                                    : 'border-border/30 text-muted-foreground bg-muted-foreground/80/10'
                                                }
                                            >
                                                {chain.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{chain.created_by}</TableCell>
                                        <TableCell className="text-muted-foreground">{chain.created_at}</TableCell>
                                    </TableRow>
                                ))}
                                {chains.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                            No approval chains configured.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
