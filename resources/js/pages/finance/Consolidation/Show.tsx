import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Building2, Plus, Trash2, Play, Eye, ArrowLeftRight, Calendar, Hash } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { type BreadcrumbItem } from '@/types';

type Entity = {
    id: number;
    organization_id: number;
    entity_name: string;
    ownership_percentage: string;
    consolidation_method: 'full' | 'proportional' | 'equity';
    currency_code: string;
    is_active: boolean;
};

type Run = {
    id: number;
    period_from: string;
    period_to: string;
    status: 'draft' | 'processing' | 'completed' | 'failed';
    total_revenue: string;
    total_expenses: string;
    eliminations_count: number;
    created_by: string | null;
    created_at: string;
};

type Mapping = {
    id: number;
    entity_id: number;
    entity_name: string;
    source_account_id: number;
    source_account_code: string;
    source_account_name: string;
    consolidated_account_code: string;
    consolidated_account_name: string;
    is_elimination_account: boolean;
};

type Group = {
    id: number;
    name: string;
    description: string | null;
    base_currency_code: string;
    is_active: boolean;
    created_by?: string | null;
};

type PageProps = {
    group: Group;
    entities: Entity[];
    recentRuns: Run[];
    mappings?: Mapping[];
};

const statusColors: Record<string, string> = {
    draft: 'bg-muted-foreground/10 text-muted-foreground border-border/30',
    processing: 'bg-status-info-bg text-status-info border-status-info/30',
    completed: 'bg-status-success-bg text-status-success border-status-success/30',
    failed: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

const methodLabels: Record<string, string> = {
    full: 'Full',
    proportional: 'Proportional',
    equity: 'Equity',
};

function AddEntityDialog({ groupId }: { groupId: number }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        organization_id: '',
        entity_name: '',
        ownership_percentage: '100',
        consolidation_method: 'full',
        currency_code: 'NZD',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(`/finance/consolidation/${groupId}/entities`, {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-2 h-4 w-4" />
                    Add Entity
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Entity to Group</DialogTitle>
                    <DialogDescription>Add a subsidiary or related entity for consolidation.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="entity-org-id">Organisation ID *</Label>
                            <Input
                                id="entity-org-id"
                                type="number"
                                value={data.organization_id}
                                onChange={(e) => setData('organization_id', e.target.value)}
                                placeholder="Organisation ID"
                            />
                            {errors.organization_id && <p className="text-sm text-destructive">{errors.organization_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="entity-name">Entity Name *</Label>
                            <Input
                                id="entity-name"
                                value={data.entity_name}
                                onChange={(e) => setData('entity_name', e.target.value)}
                                placeholder="e.g. NZ Care Ltd"
                            />
                            {errors.entity_name && <p className="text-sm text-destructive">{errors.entity_name}</p>}
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="entity-ownership">Ownership %</Label>
                            <Input
                                id="entity-ownership"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={data.ownership_percentage}
                                onChange={(e) => setData('ownership_percentage', e.target.value)}
                            />
                            {errors.ownership_percentage && <p className="text-sm text-destructive">{errors.ownership_percentage}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="entity-method">Consolidation Method</Label>
                            <Select
                                value={data.consolidation_method}
                                onValueChange={(val) => setData('consolidation_method', val)}
                            >
                                <SelectTrigger id="entity-method">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="full">Full</SelectItem>
                                    <SelectItem value="proportional">Proportional</SelectItem>
                                    <SelectItem value="equity">Equity</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="entity-currency">Currency</Label>
                            <Input
                                id="entity-currency"
                                value={data.currency_code}
                                onChange={(e) => setData('currency_code', e.target.value.toUpperCase())}
                                maxLength={3}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Adding...' : 'Add Entity'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RunConsolidationDialog({ groupId }: { groupId: number }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        period_from: '',
        period_to: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(`/finance/consolidation/${groupId}/run`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="default">
                    <Play className="mr-2 h-4 w-4" />
                    Run Consolidation
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Run Consolidation</DialogTitle>
                    <DialogDescription>Consolidate financials for the selected period.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="run-from">Period From *</Label>
                            <Input
                                id="run-from"
                                type="date"
                                value={data.period_from}
                                onChange={(e) => setData('period_from', e.target.value)}
                            />
                            {errors.period_from && <p className="text-sm text-destructive">{errors.period_from}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="run-to">Period To *</Label>
                            <Input
                                id="run-to"
                                type="date"
                                value={data.period_to}
                                onChange={(e) => setData('period_to', e.target.value)}
                            />
                            {errors.period_to && <p className="text-sm text-destructive">{errors.period_to}</p>}
                        </div>
                    </div>
                    {(errors as any).consolidation && <p className="text-sm text-destructive">{(errors as any).consolidation}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Running...' : 'Run Consolidation'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function formatCurrency(value: string | number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(value));
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function ConsolidationShow({ group, entities, recentRuns, mappings }: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Consolidation', href: '/finance/consolidation' },
        { title: group.name, href: `/finance/consolidation/${group.id}` },
    ];

    function handleRemoveEntity(entityId: number) {
        if (confirm('Are you sure you want to remove this entity from the group?')) {
            router.delete(`/finance/consolidation/${group.id}/entities/${entityId}`);
        }
    }

    // KPI calculations
    const activeEntities = entities.filter((e) => e.is_active).length;
    const completedRuns = recentRuns.filter((r) => r.status === 'completed');
    const lastRunDate = completedRuns.length > 0 ? completedRuns[0].created_at : null;
    const totalEliminations = recentRuns.reduce((sum, r) => sum + r.eliminations_count, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Consolidation - ${group.name}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/consolidation"
                        title={group.name}
                        description={
                            <>
                                {group.description && <span className="block">{group.description}</span>}
                                <span className="text-sm">Base currency: {group.base_currency_code}</span>
                            </>
                        }
                        actions={
                            <>
                                <Link href={`/finance/intercompany/${group.id}`}>
                                    <Button variant="outline" size="sm">
                                        <ArrowLeftRight className="mr-2 h-4 w-4" />
                                        Intercompany
                                    </Button>
                                </Link>
                                <RunConsolidationDialog groupId={group.id} />
                            </>
                        }
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Active Entities</p>
                                <p className="text-2xl font-bold mt-1">{activeEntities}</p>
                                <p className="text-xs text-muted-foreground mt-1">{entities.length} total</p>
                            </div>
                            <Building2 className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Last Consolidation Run</p>
                                <p className="text-2xl font-bold mt-1">
                                    {lastRunDate ? formatDate(lastRunDate) : 'None'}
                                </p>
                                <p className="text-xs text-muted-foreground mt-1">{completedRuns.length} completed runs</p>
                            </div>
                            <Calendar className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Eliminations</p>
                                <p className="text-2xl font-bold mt-1">{totalEliminations}</p>
                                <p className="text-xs text-muted-foreground mt-1">across all runs</p>
                            </div>
                            <Hash className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                </div>

                {/* Entities */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Building2 className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Entities</CardTitle>
                            </div>
                            <AddEntityDialog groupId={group.id} />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Entity Name</TableHead>
                                    <TableHead>Org ID</TableHead>
                                    <TableHead>Ownership</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entities.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No entities added yet. Add your first subsidiary or related entity.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    entities.map((entity) => (
                                        <TableRow key={entity.id}>
                                            <TableCell className="font-medium">{entity.entity_name}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{entity.organization_id}</TableCell>
                                            <TableCell>{entity.ownership_percentage}%</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{methodLabels[entity.consolidation_method]}</Badge>
                                            </TableCell>
                                            <TableCell className="font-mono text-sm">{entity.currency_code}</TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        entity.is_active
                                                            ? 'bg-status-success-bg text-status-success border-status-success/30'
                                                            : 'bg-muted-foreground/10 text-muted-foreground border-border/30'
                                                    }
                                                >
                                                    {entity.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => handleRemoveEntity(entity.id)}
                                                >
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Recent Runs */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Play className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Consolidation Runs</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Revenue</TableHead>
                                    <TableHead className="text-right">Expenses</TableHead>
                                    <TableHead>Eliminations</TableHead>
                                    <TableHead>Created By</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentRuns.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No consolidation runs yet. Run your first consolidation.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    recentRuns.map((run) => (
                                        <TableRow key={run.id}>
                                            <TableCell className="text-sm">
                                                {run.period_from} to {run.period_to}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={statusColors[run.status]}>
                                                    {run.status.charAt(0).toUpperCase() + run.status.slice(1)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm">
                                                {formatCurrency(run.total_revenue)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm">
                                                {formatCurrency(run.total_expenses)}
                                            </TableCell>
                                            <TableCell>{run.eliminations_count}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {run.created_by || '-'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Link href={`/finance/consolidation/${group.id}/runs/${run.id}`}>
                                                    <Button variant="ghost" size="icon">
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
