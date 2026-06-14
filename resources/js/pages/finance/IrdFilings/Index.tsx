import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { TaxTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FileText, Send, Shield, CheckCircle, Clock, DollarSign, Landmark } from 'lucide-react';
import { useState } from 'react';

type Filing = {
    id: number;
    filing_type: string;
    period_from: string;
    period_to: string;
    total_amount: string;
    status: string;
    ird_reference: string | null;
    submitted_at: string | null;
    error_message: string | null;
    created_by: { id: number; name: string } | null;
    created_at: string;
};

type GstReturn = {
    id: number;
    period_start: string;
    period_end: string;
    gst_payable: string;
    status: string;
    ird_period: string;
};

type PaginatedData = {
    data: Filing[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
};

type PageProps = {
    filings: PaginatedData;
    availableGstReturns: GstReturn[];
    filters: {
        filing_type?: string;
        status?: string;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'IRD Filings', href: '/finance/ird-filings' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const formatDateTime = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const filingTypeLabels: Record<string, string> = {
    gst: 'GST Return',
    payday: 'Payday Filing',
    rlwt: 'RLWT',
    rwt: 'RWT',
    aim: 'AIM',
    ir3: 'IR3',
    ir4: 'IR4',
    ir7: 'IR7',
};

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-muted-foreground border-border' },
    validated: { label: 'Validated', className: 'bg-status-info-bg text-status-info border-status-info/30' },
    submitted: { label: 'Submitted', className: 'bg-status-warning-bg text-status-warning border-status-warning/30' },
    accepted: { label: 'Accepted', className: 'bg-status-success-bg text-status-success border-status-success/30' },
    rejected: { label: 'Rejected', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' },
    error: { label: 'Error', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' },
};

export default function IrdFilingsIndex({ filings, availableGstReturns, filters }: PageProps) {
    const [showCreateForm, setShowCreateForm] = useState(false);
    const [selectedGstReturn, setSelectedGstReturn] = useState<string>('');

    const createForm = useForm({
        ird_number: '',
    });

    // KPI calculations
    const allFilings = filings.data;
    const filedCount = allFilings.filter((f) => f.status === 'accepted' || f.status === 'submitted').length;
    const pendingCount = allFilings.filter((f) => f.status === 'draft' || f.status === 'validated').length;
    const totalFiledAmount = allFilings
        .filter((f) => f.status === 'accepted' || f.status === 'submitted')
        .reduce((sum, f) => sum + Math.abs(Number(f.total_amount)), 0);

    function applyFilter(key: string, value: string | undefined) {
        const params: Record<string, string> = { ...filters };
        if (value && value !== 'all') {
            params[key] = value;
        } else {
            delete params[key];
        }
        router.get('/finance/ird-filings', params, { preserveState: true });
    }

    function handleCreateFiling(e: React.FormEvent) {
        e.preventDefault();
        if (!selectedGstReturn) return;
        createForm.post(`/finance/ird-filings/from-gst/${selectedGstReturn}`);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IRD Filings" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Landmark}
                        title="IRD Filings"
                        description="Prepare and submit IRD e-filings directly to Inland Revenue"
                        stats={[
                            { label: 'Filed', value: filedCount },
                            { label: 'Pending', value: pendingCount },
                            { label: 'Total filed', value: formatCurrency(totalFiledAmount) },
                        ]}
                        actions={
                            <Button size="sm" onClick={() => setShowCreateForm(!showCreateForm)}>
                                <Send className="mr-1.5 h-4 w-4" />
                                New Filing
                            </Button>
                        }
                        footer={<TaxTabsFooter active="ird-filings" />}
                    />
                }
            >
                {/* KPI Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-success">
                                <CheckCircle className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Filed</p>
                                <p className="text-2xl font-bold">{filedCount}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-warning">
                                <Clock className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Pending</p>
                                <p className="text-2xl font-bold">{pendingCount}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                <DollarSign className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Filed Amount</p>
                                <p className="text-2xl font-bold font-mono tabular-nums">{formatCurrency(totalFiledAmount)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Create Filing Form */}
                {showCreateForm && availableGstReturns.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Create Filing from GST Return</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleCreateFiling} className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>GST Return</Label>
                                        <Select
                                            value={selectedGstReturn}
                                            onValueChange={setSelectedGstReturn}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select a GST return" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableGstReturns.map((ret) => (
                                                    <SelectItem key={ret.id} value={String(ret.id)}>
                                                        Period {ret.ird_period}: {formatDate(ret.period_start)} &ndash;{' '}
                                                        {formatDate(ret.period_end)} ({formatCurrency(Number(ret.gst_payable))})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="ird_number">IRD Number</Label>
                                        <Input
                                            id="ird_number"
                                            value={createForm.data.ird_number}
                                            onChange={(e) => createForm.setData('ird_number', e.target.value)}
                                            placeholder="e.g. 12-345-678"
                                            maxLength={11}
                                        />
                                        {createForm.errors.ird_number && (
                                            <p className="text-sm text-destructive">{createForm.errors.ird_number}</p>
                                        )}
                                    </div>
                                </div>
                                <div className="flex justify-end gap-3">
                                    <Button type="button" variant="outline" onClick={() => setShowCreateForm(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={!selectedGstReturn || !createForm.data.ird_number || createForm.processing}
                                    >
                                        Create Filing
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {showCreateForm && availableGstReturns.length === 0 && (
                    <Card>
                        <CardContent className="py-6">
                            <p className="text-center text-muted-foreground">
                                No GST returns available for filing. Prepare a GST return first.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Filings List */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Shield className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Filings</CardTitle>
                            </div>
                            <div className="flex items-center gap-3">
                                <Select
                                    value={filters.filing_type ?? 'all'}
                                    onValueChange={(v) => applyFilter('filing_type', v)}
                                >
                                    <SelectTrigger className="w-[150px]">
                                        <SelectValue placeholder="Type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Types</SelectItem>
                                        <SelectItem value="gst">GST</SelectItem>
                                        <SelectItem value="payday">Payday</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={filters.status ?? 'all'}
                                    onValueChange={(v) => applyFilter('status', v)}
                                >
                                    <SelectTrigger className="w-[150px]">
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="draft">Draft</SelectItem>
                                        <SelectItem value="validated">Validated</SelectItem>
                                        <SelectItem value="submitted">Submitted</SelectItem>
                                        <SelectItem value="accepted">Accepted</SelectItem>
                                        <SelectItem value="rejected">Rejected</SelectItem>
                                        <SelectItem value="error">Error</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Type</th>
                                        <th className="pb-3 pr-4 font-medium">Period</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Amount</th>
                                        <th className="pb-3 pr-4 font-medium">Status</th>
                                        <th className="pb-3 pr-4 font-medium">IRD Reference</th>
                                        <th className="pb-3 pr-4 font-medium">Submitted</th>
                                        <th className="pb-3 font-medium">Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filings.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No filings yet. Create a filing from a GST return to get started.
                                            </td>
                                        </tr>
                                    ) : (
                                        filings.data.map((filing) => {
                                            const status = statusConfig[filing.status] ?? statusConfig.draft;
                                            const amount = Number(filing.total_amount);

                                            return (
                                                <tr
                                                    key={filing.id}
                                                    className="border-b last:border-0 hover:bg-muted/50 cursor-pointer"
                                                    onClick={() => router.visit(`/finance/ird-filings/${filing.id}`)}
                                                >
                                                    <td className="py-3 pr-4">
                                                        {filingTypeLabels[filing.filing_type] ?? filing.filing_type}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {formatDate(filing.period_from)} &ndash;{' '}
                                                        {formatDate(filing.period_to)}
                                                    </td>
                                                    <td className={`py-3 pr-4 text-right font-mono font-semibold tabular-nums ${amount >= 0 ? 'text-status-critical' : 'text-status-success'}`}>
                                                        {formatCurrency(Math.abs(amount))}
                                                        {amount < 0 ? ' (Refund)' : ''}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <Badge variant="outline" className={status.className}>
                                                            {status.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-3 pr-4 font-mono text-xs">
                                                        {filing.ird_reference ?? '-'}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {filing.submitted_at
                                                            ? formatDateTime(filing.submitted_at)
                                                            : '-'}
                                                    </td>
                                                    <td className="py-3">
                                                        {filing.created_by?.name ?? '-'}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {filings.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {filings.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
