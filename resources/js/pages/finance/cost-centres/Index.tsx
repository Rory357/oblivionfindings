import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { ConfirmDialog, LedgerTabsFooter, useRowContextMenu, type RowCtxItem } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Building2, Plus, Pencil, Trash2, Layers } from 'lucide-react';
import { FormEvent, useState } from 'react';

type CostCentre = {
    id: number;
    code: string;
    name: string;
    type: string | null;
    site_id: number | null;
    parent_id: number | null;
    is_active: boolean;
};

type PageProps = {
    costCentres: CostCentre[];
};

function CreateCostCentreDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        name: '',
        type: '',
        is_active: true,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/cost-centres', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Add Cost Centre
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Cost Centre</DialogTitle>
                    <DialogDescription>Add a new cost centre for tracking expenses.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="cc-code">Code *</Label>
                            <Input
                                id="cc-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="e.g. CC001"
                                maxLength={20}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="cc-name">Name *</Label>
                            <Input
                                id="cc-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Administration"
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="cc-type">Type</Label>
                        <Input
                            id="cc-type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            placeholder="e.g. Department, Site, Programme"
                        />
                        {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="cc-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="cc-active" className="font-normal">Active</Label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditCostCentreDialog({ costCentre }: { costCentre: CostCentre }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        code: costCentre.code,
        name: costCentre.name,
        type: costCentre.type || '',
        is_active: costCentre.is_active,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/cost-centres/${costCentre.id}`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon">
                    <Pencil className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Cost Centre</DialogTitle>
                    <DialogDescription>Update cost centre details.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-cc-code">Code *</Label>
                            <Input
                                id="edit-cc-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                maxLength={20}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-cc-name">Name *</Label>
                            <Input
                                id="edit-cc-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-cc-type">Type</Label>
                        <Input
                            id="edit-cc-type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="edit-cc-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="edit-cc-active" className="font-normal">Active</Label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function CostCentresIndex({ costCentres }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Cost Centres', href: '/finance/cost-centres' },
    ];

    const [deleteTarget, setDeleteTarget] = useState<CostCentre | null>(null);
    const [deleting, setDeleting] = useState(false);

    function confirmDelete() {
        if (!deleteTarget) return;
        router.delete(`/finance/cost-centres/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    }

    const activeCount = costCentres.filter((c) => c.is_active).length;

    // Right-click row menu — mirrors the row's existing inline action.
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (cc: CostCentre): RowCtxItem[] => [
        { kind: 'item', label: 'Delete', icon: Trash2, tone: 'critical', onSelect: () => setDeleteTarget(cc) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cost Centres" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        footer={<LedgerTabsFooter active="cost-centres" />}
                        icon={Layers}
                        title="Cost Centres"
                        description="Manage cost centres for expense tracking and allocation"
                        stats={[
                            { label: 'Total', value: costCentres.length },
                            { label: 'Active', value: activeCount },
                        ]}
                        actions={<CreateCostCentreDialog />}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Cost Centres</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {costCentres.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-muted-foreground py-8">
                                            No cost centres defined yet. Create your first cost centre to get started.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    costCentres.map((cc) => (
                                        <TableRow key={cc.id} onContextMenu={rowMenu.open(rowMenuItems(cc))}>
                                            <TableCell className="font-mono text-sm">{cc.code}</TableCell>
                                            <TableCell className="font-medium">{cc.name}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {cc.type || '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        cc.is_active
                                                            ? 'bg-status-success-bg text-status-success border-status-success/30'
                                                            : 'bg-muted-foreground/10 text-muted-foreground border-border/30'
                                                    }
                                                >
                                                    {cc.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditCostCentreDialog costCentre={cc} />
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Delete ${cc.name}`}
                                                        onClick={() => setDeleteTarget(cc)}
                                                    >
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {rowMenu.element}
            </PageLayout>

            <ConfirmDialog
                open={!!deleteTarget}
                onOpenChange={(open) => !open && setDeleteTarget(null)}
                title="Delete cost centre?"
                description={
                    <>
                        This permanently deletes cost centre{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.code} — {deleteTarget?.name}
                        </span>
                        . This can&rsquo;t be undone.
                    </>
                }
                confirmLabel="Delete cost centre"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
