import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { SettingsTabsFooter } from '@/components/finance/settings-hub';
import {
    FundingStreamDialog,
    type EditableFundingStream,
    type FundingStreamRevenueAccount,
} from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Banknote, Plus, Pencil, Trash2, Sprout } from 'lucide-react';
import { useState } from 'react';

type RevenueAccount = FundingStreamRevenueAccount;

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
    canManage: boolean;
};

const funderTypes = [
    { value: 'whaikaha', label: 'Whaikaha' },
    { value: 'carer_support', label: 'Carer Support' },
    { value: 'nasc', label: 'NASC-allocated' },
    { value: 'egl_if', label: 'EGL / Individualised Funding' },
    { value: 'acc', label: 'ACC' },
    { value: 'te_whatu_ora', label: 'Te Whatu Ora' },
    { value: 'msd', label: 'MSD' },
    { value: 'private', label: 'Private' },
    { value: 'other', label: 'Other' },
];

const funderTypeLabels: Record<string, string> = Object.fromEntries(
    funderTypes.map((ft) => [ft.value, ft.label]),
);

export default function FundingStreamsIndex({ fundingStreams, revenueAccounts, canManage = false }: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editStream, setEditStream] = useState<EditableFundingStream | null>(null);

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Funding Streams', href: '/finance/funding-streams' },
    ];

    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this funding stream?')) {
            router.delete(`/finance/funding-streams/${id}`);
        }
    }

    const openEdit = (fs: FundingStream) =>
        setEditStream({
            id: fs.id,
            code: fs.code,
            name: fs.name,
            funder_type: fs.funder_type,
            contact_name: fs.contact_name,
            contact_email: fs.contact_email,
            default_revenue_account_id: fs.default_revenue_account_id,
            is_active: fs.is_active,
        });

    const activeCount = fundingStreams.filter((fs) => fs.is_active).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funding Streams" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Sprout}
                        title="Funding Streams"
                        description="Manage funding sources and revenue allocations"
                        stats={[
                            { label: 'Total', value: fundingStreams.length },
                            { label: 'Active', value: activeCount },
                        ]}
                        actions={
                            canManage ? (
                                <Button onClick={() => setCreateOpen(true)}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Funding Stream
                                </Button>
                            ) : undefined
                        }
                        footer={<SettingsTabsFooter active="funding-streams" />}
                    />
                }
            >
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
                                    {canManage && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {fundingStreams.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={canManage ? 6 : 5} className="text-center text-muted-foreground py-8">
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
                                            {canManage && (
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Edit ${fs.name}`}
                                                            onClick={() => openEdit(fs)}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Delete ${fs.name}`}
                                                            onClick={() => handleDelete(fs.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>

            {canManage && (
                <FundingStreamDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    revenueAccounts={revenueAccounts}
                />
            )}

            {canManage && editStream && (
                <FundingStreamDialog
                    key={editStream.id}
                    open
                    fundingStream={editStream}
                    onClose={() => setEditStream(null)}
                    revenueAccounts={revenueAccounts}
                />
            )}
        </AppLayout>
    );
}
