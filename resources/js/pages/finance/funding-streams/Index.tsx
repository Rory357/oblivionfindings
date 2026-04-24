import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Banknote, Plus, Pencil, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

type RevenueAccount = {
    id: number;
    code: string;
    name: string;
};

type FundingStream = {
    id: number;
    code: string;
    name: string;
    funder_type: string | null;
    contact_name: string | null;
    contact_email: string | null;
    default_revenue_account_id: number | null;
    default_revenue_account: RevenueAccount | null;
    is_active: boolean;
};

type PageProps = {
    fundingStreams: FundingStream[];
    revenueAccounts: RevenueAccount[];
};

const funderTypes = [
    { value: 'government', label: 'Government' },
    { value: 'moh', label: 'Ministry of Health' },
    { value: 'msd', label: 'Ministry of Social Development' },
    { value: 'acc', label: 'ACC' },
    { value: 'dhb', label: 'Health NZ / DHB' },
    { value: 'private', label: 'Private' },
    { value: 'donation', label: 'Donation / Trust' },
    { value: 'other', label: 'Other' },
];

function CreateFundingStreamDialog({ revenueAccounts }: { revenueAccounts: RevenueAccount[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        name: '',
        funder_type: '',
        contact_name: '',
        contact_email: '',
        default_revenue_account_id: '',
        is_active: true,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/funding-streams', {
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
                    Add Funding Stream
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Create Funding Stream</DialogTitle>
                    <DialogDescription>Add a new funding stream to track revenue sources.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="fs-code">Code *</Label>
                            <Input
                                id="fs-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="e.g. FS001"
                                maxLength={20}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="fs-name">Name *</Label>
                            <Input
                                id="fs-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. MOH Residential Care"
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Funder Type</Label>
                        <Select
                            value={data.funder_type}
                            onValueChange={(value) => setData('funder_type', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select funder type" />
                            </SelectTrigger>
                            <SelectContent>
                                {funderTypes.map((ft) => (
                                    <SelectItem key={ft.value} value={ft.value}>{ft.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="fs-contact">Contact Name</Label>
                            <Input
                                id="fs-contact"
                                value={data.contact_name}
                                onChange={(e) => setData('contact_name', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="fs-email">Contact Email</Label>
                            <Input
                                id="fs-email"
                                type="email"
                                value={data.contact_email}
                                onChange={(e) => setData('contact_email', e.target.value)}
                            />
                            {errors.contact_email && <p className="text-sm text-destructive">{errors.contact_email}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Default Revenue Account</Label>
                        <Select
                            value={data.default_revenue_account_id}
                            onValueChange={(value) => setData('default_revenue_account_id', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                {revenueAccounts.map((acc) => (
                                    <SelectItem key={acc.id} value={String(acc.id)}>
                                        {acc.code} - {acc.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="fs-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="fs-active" className="font-normal">Active</Label>
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

function EditFundingStreamDialog({ fundingStream, revenueAccounts }: { fundingStream: FundingStream; revenueAccounts: RevenueAccount[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        code: fundingStream.code,
        name: fundingStream.name,
        funder_type: fundingStream.funder_type || '',
        contact_name: fundingStream.contact_name || '',
        contact_email: fundingStream.contact_email || '',
        default_revenue_account_id: fundingStream.default_revenue_account_id
            ? String(fundingStream.default_revenue_account_id)
            : '',
        is_active: fundingStream.is_active,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/funding-streams/${fundingStream.id}`, {
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
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Funding Stream</DialogTitle>
                    <DialogDescription>Update funding stream details.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-fs-code">Code *</Label>
                            <Input
                                id="edit-fs-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                maxLength={20}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-fs-name">Name *</Label>
                            <Input
                                id="edit-fs-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Funder Type</Label>
                        <Select
                            value={data.funder_type}
                            onValueChange={(value) => setData('funder_type', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select funder type" />
                            </SelectTrigger>
                            <SelectContent>
                                {funderTypes.map((ft) => (
                                    <SelectItem key={ft.value} value={ft.value}>{ft.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-fs-contact">Contact Name</Label>
                            <Input
                                id="edit-fs-contact"
                                value={data.contact_name}
                                onChange={(e) => setData('contact_name', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-fs-email">Contact Email</Label>
                            <Input
                                id="edit-fs-email"
                                type="email"
                                value={data.contact_email}
                                onChange={(e) => setData('contact_email', e.target.value)}
                            />
                            {errors.contact_email && <p className="text-sm text-destructive">{errors.contact_email}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Default Revenue Account</Label>
                        <Select
                            value={data.default_revenue_account_id}
                            onValueChange={(value) => setData('default_revenue_account_id', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                {revenueAccounts.map((acc) => (
                                    <SelectItem key={acc.id} value={String(acc.id)}>
                                        {acc.code} - {acc.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="edit-fs-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="edit-fs-active" className="font-normal">Active</Label>
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

const funderTypeLabels: Record<string, string> = Object.fromEntries(
    funderTypes.map((ft) => [ft.value, ft.label])
);

export default function FundingStreamsIndex({ fundingStreams, revenueAccounts }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Funding Streams', href: '/finance/funding-streams' },
    ];

    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this funding stream?')) {
            router.delete(`/finance/funding-streams/${id}`);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funding Streams" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Funding Streams</h1>
                        <p className="text-muted-foreground">Manage funding sources and revenue allocations</p>
                    </div>
                    <CreateFundingStreamDialog revenueAccounts={revenueAccounts} />
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Banknote className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Funding Streams</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Funder Type</TableHead>
                                    <TableHead>Default Revenue Account</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {fundingStreams.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                            No funding streams defined yet. Create your first funding stream to get started.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    fundingStreams.map((fs) => (
                                        <TableRow key={fs.id}>
                                            <TableCell className="font-mono text-sm">{fs.code}</TableCell>
                                            <TableCell className="font-medium">{fs.name}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {fs.funder_type ? (funderTypeLabels[fs.funder_type] || fs.funder_type) : '-'}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {fs.default_revenue_account ? (
                                                    <span className="font-mono">
                                                        {fs.default_revenue_account.code} - {fs.default_revenue_account.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        fs.is_active
                                                            ? 'bg-status-success-bg text-status-success border-status-success/30'
                                                            : 'bg-muted-foreground/80/10 text-muted-foreground border-border/30'
                                                    }
                                                >
                                                    {fs.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditFundingStreamDialog
                                                        fundingStream={fs}
                                                        revenueAccounts={revenueAccounts}
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => handleDelete(fs.id)}
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
            </div>
        </AppLayout>
    );
}
