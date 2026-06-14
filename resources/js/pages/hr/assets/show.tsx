import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Archive, RotateCcw, UserPlus, Wrench } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Assignment {
    id: number;
    assigned_at: string;
    returned_at: string | null;
    condition_on_assign: string | null;
    condition_on_return: string | null;
    notes: string | null;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
    };
    assigned_by_user: { id: number; name: string };
}

interface Asset {
    id: number;
    asset_tag: string;
    name: string;
    category: string;
    serial_number: string | null;
    make: string | null;
    model: string | null;
    purchase_date: string | null;
    purchase_cost: string | null;
    warranty_expiry: string | null;
    status: string;
    notes: string | null;
    current_assignment: Assignment | null;
    assignments: Assignment[];
}

interface Employee {
    id: number;
    user_id: number;
    position_title: string | null;
    user: { id: number; name: string };
}

interface Props {
    asset: Asset;
    employees: Employee[];
    can: { manage: boolean };
}

const statusColors: Record<string, string> = {
    available: 'bg-status-success-bg text-status-success',
    assigned: 'bg-status-info-bg text-status-info',
    maintenance: 'bg-status-warning-bg text-status-warning',
    retired: 'bg-muted text-foreground',
};

const categoryLabels: Record<string, string> = {
    laptop: 'Laptop',
    phone: 'Phone',
    tablet: 'Tablet',
    vehicle: 'Vehicle',
    key: 'Key',
    card: 'Card',
    uniform: 'Uniform',
    other: 'Other',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(num);
};

export default function AssetShow({ asset, employees, can }: Props) {
    const [assignOpen, setAssignOpen] = useState(false);
    const [returnOpen, setReturnOpen] = useState(false);
    const [assignForm, setAssignForm] = useState({
        employee_profile_id: '',
        assigned_at: new Date().toISOString().split('T')[0],
        condition_on_assign: '',
        notes: '',
    });
    const [returnForm, setReturnForm] = useState({
        returned_at: new Date().toISOString().split('T')[0],
        condition_on_return: '',
        notes: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Assets', href: '/hr/assets' },
        {
            title: `${asset.asset_tag} - ${asset.name}`,
            href: `/hr/assets/${asset.id}`,
        },
    ];

    const submitAssign = (e: FormEvent) => {
        e.preventDefault();
        router.post(`/hr/assets/${asset.id}/assign`, assignForm, {
            onSuccess: () => setAssignOpen(false),
        });
    };

    const submitReturn = (e: FormEvent) => {
        e.preventDefault();
        if (!asset.current_assignment) return;
        router.post(
            `/hr/assets/assignments/${asset.current_assignment.id}/return`,
            returnForm,
            {
                onSuccess: () => setReturnOpen(false),
            },
        );
    };

    const sendToMaintenance = () => {
        if (
            confirm(
                'Send this asset to maintenance? It will be marked unavailable until returned to service.',
            )
        ) {
            router.post(`/hr/assets/${asset.id}/maintenance`);
        }
    };

    const returnFromMaintenance = () => {
        if (confirm('Return this asset to service (back to available)?')) {
            router.post(`/hr/assets/${asset.id}/return-from-maintenance`);
        }
    };

    const retireAsset = () => {
        if (
            confirm(
                'Retire this asset? This decommissions it and removes it from the active pool.',
            )
        ) {
            router.post(`/hr/assets/${asset.id}/retire`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${asset.asset_tag} - ${asset.name}`} />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/assets"
                        title={
                            <span className="flex items-center gap-2">
                                {asset.name}
                                <Badge
                                    variant="outline"
                                    className="font-mono text-xs"
                                >
                                    {asset.asset_tag}
                                </Badge>
                                <span
                                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[asset.status] ?? ''}`}
                                >
                                    {asset.status}
                                </span>
                            </span>
                        }
                        description={
                            <>
                                {categoryLabels[asset.category] || asset.category}
                                {asset.make && ` - ${asset.make}`}
                                {asset.model && ` ${asset.model}`}
                            </>
                        }
                        actions={
                            can.manage ? (
                                <>
                                    {asset.status === 'available' && (
                                        <Button
                                            size="sm"
                                            onClick={() => setAssignOpen(true)}
                                        >
                                            <UserPlus className="mr-1.5 h-4 w-4" />
                                            Assign
                                        </Button>
                                    )}
                                    {asset.status === 'assigned' &&
                                        asset.current_assignment && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setReturnOpen(true)
                                                }
                                            >
                                                <RotateCcw className="mr-1.5 h-4 w-4" />
                                                Return
                                            </Button>
                                        )}
                                    {asset.status === 'available' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={sendToMaintenance}
                                        >
                                            <Wrench className="mr-1.5 h-4 w-4" />
                                            Maintenance
                                        </Button>
                                    )}
                                    {asset.status === 'maintenance' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={returnFromMaintenance}
                                        >
                                            <RotateCcw className="mr-1.5 h-4 w-4" />
                                            Return to service
                                        </Button>
                                    )}
                                    {(asset.status === 'available' ||
                                        asset.status === 'maintenance') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={retireAsset}
                                        >
                                            <Archive className="mr-1.5 h-4 w-4" />
                                            Retire
                                        </Button>
                                    )}
                                </>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Asset Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Asset Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <span className="text-muted-foreground">
                                    Serial Number
                                </span>
                                <p className="mt-0.5 font-mono">
                                    {asset.serial_number || '-'}
                                </p>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Purchase Date
                                </span>
                                <p className="mt-0.5">
                                    {formatDate(asset.purchase_date)}
                                </p>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Purchase Cost
                                </span>
                                <p className="mt-0.5">
                                    {formatCurrency(asset.purchase_cost)}
                                </p>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Warranty Expiry
                                </span>
                                <p className="mt-0.5">
                                    {formatDate(asset.warranty_expiry)}
                                </p>
                            </div>
                        </div>
                        {asset.notes && (
                            <div className="mt-4 text-sm">
                                <span className="text-muted-foreground">
                                    Notes
                                </span>
                                <p className="mt-0.5">{asset.notes}</p>
                            </div>
                        )}
                        {asset.current_assignment && (
                            <div className="mt-4 rounded-md border border-status-info/30 bg-status-info-bg p-3 text-sm">
                                <span className="font-medium text-status-info">
                                    Currently Assigned to:
                                </span>{' '}
                                <span>
                                    {
                                        asset.current_assignment
                                            .employee_profile?.user?.name
                                    }
                                </span>
                                <span className="ml-2 text-status-info">
                                    since{' '}
                                    {formatDate(
                                        asset.current_assignment.assigned_at,
                                    )}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Assignment History */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Assignment History
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Assigned</TableHead>
                                    <TableHead>Returned</TableHead>
                                    <TableHead>Condition (Assign)</TableHead>
                                    <TableHead>Condition (Return)</TableHead>
                                    <TableHead>Assigned By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {asset.assignments?.map((assignment) => (
                                    <TableRow key={assignment.id}>
                                        <TableCell className="font-medium">
                                            {
                                                assignment.employee_profile
                                                    ?.user?.name
                                            }
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDateTime(
                                                assignment.assigned_at,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {assignment.returned_at ? (
                                                formatDateTime(
                                                    assignment.returned_at,
                                                )
                                            ) : (
                                                <Badge variant="outline">
                                                    Current
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {assignment.condition_on_assign ||
                                                '-'}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {assignment.condition_on_return ||
                                                '-'}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {assignment.assigned_by_user?.name}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!asset.assignments?.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No assignment history.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Assign Dialog */}
            <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Assign Asset</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitAssign} className="space-y-4">
                        <div>
                            <Label>Employee</Label>
                            <Select
                                value={assignForm.employee_profile_id}
                                onValueChange={(val) =>
                                    setAssignForm((p) => ({
                                        ...p,
                                        employee_profile_id: val,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((emp) => (
                                        <SelectItem
                                            key={emp.id}
                                            value={String(emp.id)}
                                        >
                                            {emp.user?.name}{' '}
                                            {emp.position_title
                                                ? `- ${emp.position_title}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Assigned Date</Label>
                            <Input
                                type="date"
                                value={assignForm.assigned_at}
                                onChange={(e) =>
                                    setAssignForm((p) => ({
                                        ...p,
                                        assigned_at: e.target.value,
                                    }))
                                }
                                required
                            />
                        </div>
                        <div>
                            <Label>Condition on Assignment</Label>
                            <Input
                                value={assignForm.condition_on_assign}
                                onChange={(e) =>
                                    setAssignForm((p) => ({
                                        ...p,
                                        condition_on_assign: e.target.value,
                                    }))
                                }
                                placeholder="e.g. New, Good, Fair"
                            />
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={assignForm.notes}
                                onChange={(e) =>
                                    setAssignForm((p) => ({
                                        ...p,
                                        notes: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAssignOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Assign</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Return Dialog */}
            <Dialog open={returnOpen} onOpenChange={setReturnOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Return Asset</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitReturn} className="space-y-4">
                        <div>
                            <Label>Return Date</Label>
                            <Input
                                type="date"
                                value={returnForm.returned_at}
                                onChange={(e) =>
                                    setReturnForm((p) => ({
                                        ...p,
                                        returned_at: e.target.value,
                                    }))
                                }
                                required
                            />
                        </div>
                        <div>
                            <Label>Condition on Return</Label>
                            <Input
                                value={returnForm.condition_on_return}
                                onChange={(e) =>
                                    setReturnForm((p) => ({
                                        ...p,
                                        condition_on_return: e.target.value,
                                    }))
                                }
                                placeholder="e.g. Good, Damaged, Fair"
                            />
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={returnForm.notes}
                                onChange={(e) =>
                                    setReturnForm((p) => ({
                                        ...p,
                                        notes: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setReturnOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Return Asset</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
