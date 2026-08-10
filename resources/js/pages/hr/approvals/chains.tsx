import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    GitBranch,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
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

type LeaveChain = {
    id: number;
    user_id: number;
    user_name: string;
    approver_user_id: number;
    approver_name: string;
    delegate_user_id: number | null;
    delegate_name: string | null;
    approval_level: number;
    escalation_after_hours: number;
    is_active: boolean;
};

type Props = {
    chains: Chain[];
    leaveChains: LeaveChain[];
    processTypes: string[];
    roles: Array<{ id: number; name: string }>;
    users: Array<{ id: number; name: string }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Approvals', href: '/hr/approvals/pending' },
    { title: 'Chains', href: '/hr/approvals/chains' },
];

export default function ApprovalChains({
    chains,
    leaveChains,
    processTypes,
    roles,
    users,
}: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingLeaveId, setEditingLeaveId] = useState<number | null>(null);
    const [steps, setSteps] = useState<Step[]>([
        {
            step_order: 1,
            approver_type: 'manager',
            approver_role_id: null,
            approver_user_id: null,
            auto_approve_after_days: null,
        },
    ]);

    const form = useForm({
        name: '',
        process_type: '',
        is_active: true,
        steps: steps,
    });
    const leaveForm = useForm({
        user_id: '',
        approver_user_id: '',
        delegate_user_id: '',
        approval_level: 1,
        escalation_after_hours: 48,
        is_active: true,
    });

    const submitLeaveRoute = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingLeaveId(null);
                leaveForm.reset();
            },
        };
        if (editingLeaveId) {
            leaveForm.put(
                `/hr/approvals/leave-chains/${editingLeaveId}`,
                options,
            );
        } else {
            leaveForm.post('/hr/approvals/leave-chains', options);
        }
    };

    const editLeaveRoute = (chain: LeaveChain) => {
        setEditingLeaveId(chain.id);
        leaveForm.setData({
            user_id: String(chain.user_id),
            approver_user_id: String(chain.approver_user_id),
            delegate_user_id: chain.delegate_user_id
                ? String(chain.delegate_user_id)
                : '',
            approval_level: chain.approval_level,
            escalation_after_hours: chain.escalation_after_hours,
            is_active: chain.is_active,
        });
    };

    const moveLeaveRoute = (chain: LeaveChain, delta: number) => {
        const siblings = leaveChains.filter(
            (item) => item.user_id === chain.user_id,
        );
        const index = siblings.findIndex((item) => item.id === chain.id);
        const target = index + delta;
        if (target < 0 || target >= siblings.length) return;
        const ordered = siblings.map((item) => item.id);
        [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
        router.post(
            '/hr/approvals/leave-chains/reorder',
            {
                user_id: chain.user_id,
                ordered_ids: ordered,
            },
            { preserveScroll: true },
        );
    };

    const addStep = () => {
        const newSteps = [
            ...steps,
            {
                step_order: steps.length + 1,
                approver_type: 'manager',
                approver_role_id: null,
                approver_user_id: null,
                auto_approve_after_days: null,
            },
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

    const updateStep = (
        index: number,
        field: string,
        value: string | number | null,
    ) => {
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
                setSteps([
                    {
                        step_order: 1,
                        approver_type: 'manager',
                        approver_role_id: null,
                        approver_user_id: null,
                        auto_approve_after_days: null,
                    },
                ]);
                form.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approval Chains" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={GitBranch}
                        title="Approval Chains"
                        description="Configure multi-level approval workflows for HR processes."
                        stats={[
                            { label: 'Chains', value: chains.length },
                            {
                                label: 'Active',
                                value: chains.filter((c) => c.is_active).length,
                            },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                onClick={() => setShowForm(!showForm)}
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Chain
                            </Button>
                        }
                    />
                }
            >
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
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Standard Leave Approval"
                                        />
                                        {form.errors.name && (
                                            <p className="text-sm text-status-critical">
                                                {form.errors.name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Process Type</Label>
                                        <Select
                                            value={form.data.process_type}
                                            onValueChange={(v) =>
                                                form.setData('process_type', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select process type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {processTypes.map((pt) => (
                                                    <SelectItem
                                                        key={pt}
                                                        value={pt}
                                                    >
                                                        <span className="capitalize">
                                                            {pt}
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.process_type && (
                                            <p className="text-sm text-status-critical">
                                                {form.errors.process_type}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <Label>Approval Steps</Label>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={addStep}
                                        >
                                            <Plus className="mr-1 h-3 w-3" />
                                            Add Step
                                        </Button>
                                    </div>
                                    {steps.map((step, index) => (
                                        <div
                                            key={index}
                                            className="flex items-center gap-3 rounded-md border p-3"
                                        >
                                            <span className="text-sm font-medium text-muted-foreground">
                                                Step {step.step_order}
                                            </span>
                                            <Select
                                                value={step.approver_type}
                                                onValueChange={(v) =>
                                                    updateStep(
                                                        index,
                                                        'approver_type',
                                                        v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="w-36">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="manager">
                                                        Manager
                                                    </SelectItem>
                                                    <SelectItem value="role">
                                                        Role
                                                    </SelectItem>
                                                    <SelectItem value="user">
                                                        Specific User
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {step.approver_type === 'role' && (
                                                <Select
                                                    value={
                                                        step.approver_role_id?.toString() ??
                                                        ''
                                                    }
                                                    onValueChange={(v) =>
                                                        updateStep(
                                                            index,
                                                            'approver_role_id',
                                                            parseInt(v),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="w-48">
                                                        <SelectValue placeholder="Select role" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((role) => (
                                                            <SelectItem
                                                                key={role.id}
                                                                value={role.id.toString()}
                                                            >
                                                                {role.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                            {step.approver_type === 'user' && (
                                                <Select
                                                    value={
                                                        step.approver_user_id?.toString() ??
                                                        ''
                                                    }
                                                    onValueChange={(v) =>
                                                        updateStep(
                                                            index,
                                                            'approver_user_id',
                                                            parseInt(v),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="w-48">
                                                        <SelectValue placeholder="Select user" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {users.map((user) => (
                                                            <SelectItem
                                                                key={user.id}
                                                                value={user.id.toString()}
                                                            >
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
                                                value={
                                                    step.auto_approve_after_days ??
                                                    ''
                                                }
                                                onChange={(e) =>
                                                    updateStep(
                                                        index,
                                                        'auto_approve_after_days',
                                                        e.target.value
                                                            ? parseInt(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    )
                                                }
                                            />
                                            {steps.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeStep(index)
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4 text-status-critical" />
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>

                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Create Chain
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setShowForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Native leave approval routing</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Route each employee's leave request through ordered
                            approvers. This remains separate from the generic
                            workflow chains below.
                        </p>
                        <form
                            onSubmit={submitLeaveRoute}
                            className="grid gap-3 rounded-lg border p-3 md:grid-cols-6"
                        >
                            <Select
                                value={leaveForm.data.user_id}
                                onValueChange={(value) =>
                                    leaveForm.setData('user_id', value)
                                }
                                disabled={editingLeaveId !== null}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={leaveForm.data.approver_user_id}
                                onValueChange={(value) =>
                                    leaveForm.setData('approver_user_id', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Approver" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={
                                    leaveForm.data.delegate_user_id || 'none'
                                }
                                onValueChange={(value) =>
                                    leaveForm.setData(
                                        'delegate_user_id',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Delegate" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        No delegate
                                    </SelectItem>
                                    {users.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="number"
                                min={1}
                                value={leaveForm.data.approval_level}
                                disabled={editingLeaveId !== null}
                                onChange={(event) =>
                                    leaveForm.setData(
                                        'approval_level',
                                        Number(event.target.value),
                                    )
                                }
                                placeholder="Level"
                            />
                            <Input
                                type="number"
                                min={1}
                                value={leaveForm.data.escalation_after_hours}
                                onChange={(event) =>
                                    leaveForm.setData(
                                        'escalation_after_hours',
                                        Number(event.target.value),
                                    )
                                }
                                placeholder="Escalate hours"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={leaveForm.processing}
                                >
                                    {editingLeaveId ? 'Save' : 'Add route'}
                                </Button>
                                {editingLeaveId ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setEditingLeaveId(null);
                                            leaveForm.reset();
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                ) : null}
                            </div>
                        </form>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Level</TableHead>
                                        <TableHead>Approver</TableHead>
                                        <TableHead>Delegate</TableHead>
                                        <TableHead>Escalates</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leaveChains.map((chain) => (
                                        <TableRow key={chain.id}>
                                            <TableCell className="font-medium">
                                                {chain.user_name}
                                            </TableCell>
                                            <TableCell>
                                                {chain.approval_level}
                                            </TableCell>
                                            <TableCell>
                                                {chain.approver_name}
                                            </TableCell>
                                            <TableCell>
                                                {chain.delegate_name ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {chain.escalation_after_hours}h
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {chain.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex min-h-11 justify-end gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Move approval level up"
                                                        onClick={() =>
                                                            moveLeaveRoute(
                                                                chain,
                                                                -1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowUp className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Move approval level down"
                                                        onClick={() =>
                                                            moveLeaveRoute(
                                                                chain,
                                                                1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowDown className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Edit leave approval route"
                                                        onClick={() =>
                                                            editLeaveRoute(
                                                                chain,
                                                            )
                                                        }
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.patch(
                                                                `/hr/approvals/leave-chains/${chain.id}/active`,
                                                                {
                                                                    is_active:
                                                                        !chain.is_active,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {chain.is_active
                                                            ? 'Deactivate'
                                                            : 'Activate'}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Remove leave approval route"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/hr/approvals/leave-chains/${chain.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-status-critical" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {leaveChains.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="py-8 text-center text-muted-foreground"
                                            >
                                                No employee leave routes
                                                configured.
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Generic workflow chains table */}
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
                                        <TableCell className="font-medium">
                                            {chain.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {chain.process_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {chain.steps_count}
                                        </TableCell>
                                        <TableCell>
                                            {chain.instances_count}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    chain.is_active
                                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                        : 'border-border/30 bg-muted-foreground/10 text-muted-foreground'
                                                }
                                            >
                                                {chain.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {chain.created_by}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {chain.created_at}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {chains.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No approval chains configured.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
