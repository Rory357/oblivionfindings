import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Building2, Plus, Eye, Users, Network } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Textarea } from '@/components/ui/textarea';

type ConsolidationGroup = {
    id: number;
    name: string;
    description: string | null;
    base_currency_code: string;
    is_active: boolean;
    entities_count: number;
    runs_count: number;
    created_by: string | null;
    created_at: string;
};

type PageProps = {
    groups: ConsolidationGroup[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Consolidation', href: '/finance/consolidation' },
];

function CreateGroupDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        description: '',
        base_currency_code: 'NZD',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/consolidation', {
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
                    New Group
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Consolidation Group</DialogTitle>
                    <DialogDescription>Set up a new group for inter-company consolidation.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="group-name">Group Name *</Label>
                        <Input
                            id="group-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. NZ Holdings Group"
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="group-description">Description</Label>
                        <Textarea
                            id="group-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Optional description of this consolidation group"
                            rows={3}
                        />
                        {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="group-currency">Base Currency Code *</Label>
                        <Input
                            id="group-currency"
                            value={data.base_currency_code}
                            onChange={(e) => setData('base_currency_code', e.target.value.toUpperCase())}
                            placeholder="NZD"
                            maxLength={3}
                        />
                        {errors.base_currency_code && <p className="text-sm text-destructive">{errors.base_currency_code}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Group'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ConsolidationIndex({ groups }: PageProps) {
    const activeGroups = groups.filter((g) => g.is_active);
    const totalEntities = groups.reduce((sum, g) => sum + g.entities_count, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Consolidation Groups" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Consolidation Groups</h1>
                        <p className="text-muted-foreground">Manage inter-company consolidation groups and entities</p>
                    </div>
                    <CreateGroupDialog />
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                <Building2 className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Active Groups</p>
                                <p className="text-2xl font-bold">{activeGroups.length}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                <Users className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Entities</p>
                                <p className="text-2xl font-bold">{totalEntities}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Groups</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Currency</TableHead>
                                    <TableHead>Entities</TableHead>
                                    <TableHead>Runs</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {groups.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                            No consolidation groups yet. Create your first group to get started.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    groups.map((group) => (
                                        <TableRow key={group.id}>
                                            <TableCell>
                                                <div>
                                                    <div className="font-medium">{group.name}</div>
                                                    {group.description && (
                                                        <div className="text-sm text-muted-foreground truncate max-w-[300px]">
                                                            {group.description}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="font-mono text-sm">{group.base_currency_code}</TableCell>
                                            <TableCell>{group.entities_count}</TableCell>
                                            <TableCell>{group.runs_count}</TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        group.is_active
                                                            ? 'bg-status-success-bg text-status-success border-status-success/30'
                                                            : 'bg-muted text-muted-foreground border-border'
                                                    }
                                                >
                                                    {group.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Link href={`/finance/consolidation/${group.id}`}>
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
            </div>
        </AppLayout>
    );
}
