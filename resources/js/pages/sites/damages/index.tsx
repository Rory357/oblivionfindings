import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    DollarSign,
    Eye,
    Pencil,
    Plus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmAction } from '../_confirm-action';

type Site = {
    id: number;
    name: string;
    type: string;
    display_type: string;
};

type Damage = {
    id: number;
    title: string;
    description?: string;
    severity: 'minor' | 'moderate' | 'major' | 'critical';
    status:
        | 'reported'
        | 'assessed'
        | 'repair_scheduled'
        | 'repair_in_progress'
        | 'repaired'
        | 'closed';
    location_in_site?: string;
    damage_date?: string;
    discovered_date?: string;
    estimated_cost?: number;
    actual_cost?: number;
    insurance_claim_ref?: string;
    insurance_status?: string;
    repair_notes?: string;
    reported_by: { id: number; name: string };
    assigned_to: { id: number; name: string } | null;
    checklist_run_id?: number | null;
    created_at: string;
};

type Props = {
    site: Site;
    damages: Damage[];
    canCreate: boolean;
    canManage: boolean;
};

const formatCurrency = (amount: number | undefined | null) => {
    if (amount == null) return '-';
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
};

const severityColors: Record<string, string> = {
    minor: 'bg-muted-foreground/80/20 text-muted-foreground border-border/30',
    moderate:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    major: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    critical:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
};

const statusColors: Record<string, string> = {
    reported: 'bg-status-info-bg text-status-info border-status-info/30',
    assessed:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    repair_scheduled: 'bg-primary/20 text-primary/70 border-primary/30',
    repair_in_progress:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    repaired:
        'bg-status-success-bg text-status-success border-status-success/30',
    closed: 'bg-muted-foreground/80/20 text-muted-foreground border-border/30',
};

const statusLabels: Record<string, string> = {
    reported: 'Reported',
    assessed: 'Assessed',
    repair_scheduled: 'Repair Scheduled',
    repair_in_progress: 'Repair In Progress',
    repaired: 'Repaired',
    closed: 'Closed',
};

const severityLabels: Record<string, string> = {
    minor: 'Minor',
    moderate: 'Moderate',
    major: 'Major',
    critical: 'Critical',
};

const allStatuses = [
    'reported',
    'assessed',
    'repair_scheduled',
    'repair_in_progress',
    'repaired',
    'closed',
] as const;
const allSeverities = ['minor', 'moderate', 'major', 'critical'] as const;

export default function SiteDamages({
    site,
    damages,
    canCreate,
    canManage,
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editingDamage, setEditingDamage] = useState<Damage | null>(null);
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterSeverity, setFilterSeverity] = useState('all');

    const createForm = useForm({
        title: '',
        description: '',
        severity: 'moderate',
        location_in_site: '',
        damage_date: '',
        discovered_date: '',
        estimated_cost: '',
        insurance_claim_ref: '',
        insurance_status: '',
    });

    const editForm = useForm({
        status: '',
        repair_notes: '',
        estimated_cost: '',
        actual_cost: '',
        insurance_claim_ref: '',
        insurance_status: '',
    });

    const filteredDamages = useMemo(() => {
        return damages.filter((d) => {
            if (filterStatus !== 'all' && d.status !== filterStatus)
                return false;
            if (filterSeverity !== 'all' && d.severity !== filterSeverity)
                return false;
            return true;
        });
    }, [damages, filterStatus, filterSeverity]);

    const openDamages = useMemo(
        () =>
            damages.filter(
                (d) => d.status !== 'repaired' && d.status !== 'closed',
            ),
        [damages],
    );

    const severityCounts = useMemo(() => {
        const counts: Record<string, number> = {
            minor: 0,
            moderate: 0,
            major: 0,
            critical: 0,
        };
        openDamages.forEach((d) => {
            counts[d.severity] = (counts[d.severity] || 0) + 1;
        });
        return counts;
    }, [openDamages]);

    const totalEstimatedCost = useMemo(() => {
        return openDamages.reduce((sum, d) => sum + (d.estimated_cost || 0), 0);
    }, [openDamages]);

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(`/sites/${site.id}/damages`, {
            onSuccess: () => {
                setCreateOpen(false);
                createForm.reset();
            },
        });
    };

    const openEdit = (damage: Damage) => {
        setEditingDamage(damage);
        editForm.setData({
            status: damage.status,
            repair_notes: damage.repair_notes || '',
            estimated_cost: damage.estimated_cost?.toString() || '',
            actual_cost: damage.actual_cost?.toString() || '',
            insurance_claim_ref: damage.insurance_claim_ref || '',
            insurance_status: damage.insurance_status || '',
        });
        setEditOpen(true);
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingDamage) return;
        editForm.put(`/sites/${site.id}/damages/${editingDamage.id}`, {
            onSuccess: () => {
                setEditOpen(false);
                setEditingDamage(null);
                editForm.reset();
            },
        });
    };

    const handleStatusChange = (damage: Damage, newStatus: string) => {
        router.put(
            `/sites/${site.id}/damages/${damage.id}`,
            { status: newStatus },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Damages', href: `/sites/${site.id}/damages` },
            ]}
        >
            <Head title={`${site.name} - Damages`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Damage Tracking"
                        description={site.name}
                        backHref={`/sites/${site.id}`}
                        stats={[
                            { label: 'Open', value: openDamages.length },
                            { label: 'Total', value: damages.length },
                            {
                                label: 'Est. cost',
                                value: formatCurrency(totalEstimatedCost),
                            },
                        ]}
                        actions={
                            canCreate && (
                                <Button
                                    size="sm"
                                    onClick={() => setCreateOpen(true)}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Report Damage
                                </Button>
                            )
                        }
                    />
                }
            >
                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">
                                {openDamages.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Total Open
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex flex-wrap gap-2">
                                {allSeverities.map((sev) => (
                                    <Badge
                                        key={sev}
                                        className={severityColors[sev]}
                                    >
                                        {severityLabels[sev]}:{' '}
                                        {severityCounts[sev]}
                                    </Badge>
                                ))}
                            </div>
                            <div className="mt-2 text-sm text-muted-foreground">
                                By Severity
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-warning/20 bg-status-warning">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-1 text-2xl font-bold text-status-warning">
                                <DollarSign className="h-5 w-5" />
                                {formatCurrency(totalEstimatedCost)}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Total Estimated Cost
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    <div className="w-48">
                        <Select
                            value={filterStatus}
                            onValueChange={setFilterStatus}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Filter by status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All Statuses
                                </SelectItem>
                                {allStatuses.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {statusLabels[s]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="w-48">
                        <Select
                            value={filterSeverity}
                            onValueChange={setFilterSeverity}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Filter by severity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All Severities
                                </SelectItem>
                                {allSeverities.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {severityLabels[s]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Damages Table */}
                <Card>
                    <CardContent className="p-0">
                        {filteredDamages.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <AlertTriangle className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No damages recorded</p>
                                {canCreate && (
                                    <p className="mt-1 text-sm">
                                        Click "Report Damage" to log a new
                                        damage report
                                    </p>
                                )}
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Location</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Est. Cost</TableHead>
                                        <TableHead>Reported By</TableHead>
                                        <TableHead className="text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredDamages.map((damage) => (
                                        <TableRow key={damage.id}>
                                            <TableCell className="font-medium">
                                                <div className="flex items-center gap-2">
                                                    {damage.title}
                                                    {damage.checklist_run_id && (
                                                        <Badge className="border-primary/30 bg-primary/20 px-1.5 py-0 text-[10px] text-primary/70">
                                                            <ClipboardCheck className="mr-0.5 h-3 w-3" />
                                                            Checklist
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        severityColors[
                                                            damage.severity
                                                        ]
                                                    }
                                                >
                                                    {
                                                        severityLabels[
                                                            damage.severity
                                                        ]
                                                    }
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        statusColors[
                                                            damage.status
                                                        ]
                                                    }
                                                >
                                                    {
                                                        statusLabels[
                                                            damage.status
                                                        ]
                                                    }
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {damage.location_in_site || '-'}
                                            </TableCell>
                                            <TableCell>
                                                {damage.damage_date
                                                    ? new Date(
                                                          damage.damage_date,
                                                      ).toLocaleDateString()
                                                    : '-'}
                                            </TableCell>
                                            <TableCell>
                                                {formatCurrency(
                                                    damage.estimated_cost,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {damage.reported_by.name}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {canManage &&
                                                        damage.status ===
                                                            'reported' && (
                                                            <ConfirmAction
                                                                title="Change damage status?"
                                                                description={`Change "${damage.title}" to "${statusLabels.assessed}"?`}
                                                                confirmLabel="Change status"
                                                                onConfirm={() =>
                                                                    handleStatusChange(
                                                                        damage,
                                                                        'assessed',
                                                                    )
                                                                }
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Mark as Assessed"
                                                                >
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </ConfirmAction>
                                                        )}
                                                    {canManage && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                openEdit(damage)
                                                            }
                                                            title="Edit"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Create Dialog */}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Report Damage</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleCreate} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Title *</Label>
                                    <Input
                                        value={createForm.data.title}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g., Broken window in lounge"
                                        required
                                    />
                                    {createForm.errors.title && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {createForm.errors.title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Severity *</Label>
                                    <Select
                                        value={createForm.data.severity}
                                        onValueChange={(v) =>
                                            createForm.setData('severity', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="minor">
                                                Minor
                                            </SelectItem>
                                            <SelectItem value="moderate">
                                                Moderate
                                            </SelectItem>
                                            <SelectItem value="major">
                                                Major
                                            </SelectItem>
                                            <SelectItem value="critical">
                                                Critical
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Description *</Label>
                                <Textarea
                                    value={createForm.data.description}
                                    onChange={(e) =>
                                        createForm.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Describe the damage in detail..."
                                    required
                                />
                                {createForm.errors.description && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {createForm.errors.description}
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Location in Site</Label>
                                    <Input
                                        value={createForm.data.location_in_site}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'location_in_site',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g., Bedroom 3, Kitchen"
                                    />
                                </div>
                                <div>
                                    <Label>Estimated Cost</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={createForm.data.estimated_cost}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'estimated_cost',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="0.00"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Damage Date *</Label>
                                    <Input
                                        type="date"
                                        value={createForm.data.damage_date}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'damage_date',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {createForm.errors.damage_date && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {createForm.errors.damage_date}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Discovered Date *</Label>
                                    <Input
                                        type="date"
                                        value={createForm.data.discovered_date}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'discovered_date',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {createForm.errors.discovered_date && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {createForm.errors.discovered_date}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Insurance Claim Ref</Label>
                                    <Input
                                        value={
                                            createForm.data.insurance_claim_ref
                                        }
                                        onChange={(e) =>
                                            createForm.setData(
                                                'insurance_claim_ref',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Optional"
                                    />
                                </div>
                                <div>
                                    <Label>Insurance Status</Label>
                                    <Select
                                        value={
                                            createForm.data.insurance_status ||
                                            undefined
                                        }
                                        onValueChange={(v) =>
                                            createForm.setData(
                                                'insurance_status',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Not applicable" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="not_applicable">
                                                Not Applicable
                                            </SelectItem>
                                            <SelectItem value="pending">
                                                Pending
                                            </SelectItem>
                                            <SelectItem value="submitted">
                                                Submitted
                                            </SelectItem>
                                            <SelectItem value="approved">
                                                Approved
                                            </SelectItem>
                                            <SelectItem value="declined">
                                                Declined
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setCreateOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={createForm.processing}
                                >
                                    Report Damage
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Edit Dialog */}
                <Dialog
                    open={editOpen}
                    onOpenChange={(open) => {
                        setEditOpen(open);
                        if (!open) setEditingDamage(null);
                    }}
                >
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>
                                Update Damage: {editingDamage?.title}
                            </DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleEdit} className="space-y-4">
                            <div>
                                <Label>Status *</Label>
                                <Select
                                    value={editForm.data.status}
                                    onValueChange={(v) =>
                                        editForm.setData('status', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {allStatuses.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {statusLabels[s]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Estimated Cost</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={editForm.data.estimated_cost}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'estimated_cost',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="0.00"
                                    />
                                </div>
                                <div>
                                    <Label>Actual Cost</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={editForm.data.actual_cost}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'actual_cost',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="0.00"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Insurance Claim Ref</Label>
                                    <Input
                                        value={
                                            editForm.data.insurance_claim_ref
                                        }
                                        onChange={(e) =>
                                            editForm.setData(
                                                'insurance_claim_ref',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Insurance Status</Label>
                                    <Select
                                        value={
                                            editForm.data.insurance_status ||
                                            undefined
                                        }
                                        onValueChange={(v) =>
                                            editForm.setData(
                                                'insurance_status',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Not applicable" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="not_applicable">
                                                Not Applicable
                                            </SelectItem>
                                            <SelectItem value="pending">
                                                Pending
                                            </SelectItem>
                                            <SelectItem value="submitted">
                                                Submitted
                                            </SelectItem>
                                            <SelectItem value="approved">
                                                Approved
                                            </SelectItem>
                                            <SelectItem value="declined">
                                                Declined
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Repair Notes</Label>
                                <Textarea
                                    value={editForm.data.repair_notes}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'repair_notes',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Add notes about this damage..."
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={editForm.processing}
                                >
                                    Save Changes
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
