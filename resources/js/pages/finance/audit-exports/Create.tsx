import { Head, Link, useForm } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { PageHero, PageLayout } from '@/components/page';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Audit Exports', href: '/finance/audit-exports' },
    { title: 'New Export', href: '/finance/audit-exports/create' },
];

export default function AuditExportCreate() {
    const { data, setData, post, processing, errors } = useForm<{
        export_name: string;
        period_from: string;
        period_to: string;
        include_journals: boolean;
        include_bank_reconciliations: boolean;
        include_ap: boolean;
        include_ar: boolean;
        include_gst: boolean;
        include_fixed_assets: boolean;
        notes: string;
    }>({
        export_name: '',
        period_from: '',
        period_to: '',
        include_journals: true,
        include_bank_reconciliations: true,
        include_ap: true,
        include_ar: true,
        include_gst: true,
        include_fixed_assets: true,
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/audit-exports');
    };

    const sections = [
        { key: 'include_journals' as const, label: 'Journals & Journal Lines', description: 'All general ledger journals with line-level detail' },
        { key: 'include_bank_reconciliations' as const, label: 'Bank Reconciliations', description: 'Bank statement reconciliation records' },
        { key: 'include_ap' as const, label: 'Accounts Payable', description: 'Bills, payment runs, and vendor transactions' },
        { key: 'include_ar' as const, label: 'Accounts Receivable', description: 'Payment allocations and receivable transactions' },
        { key: 'include_gst' as const, label: 'GST Returns', description: 'GST/tax return filings and calculations' },
        { key: 'include_fixed_assets' as const, label: 'Fixed Assets & Depreciation', description: 'Asset register and depreciation schedules' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Audit Export" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/audit-exports"
                        title="New Audit Export"
                        description="Configure and generate an audit trail report for external auditors"
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Export Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Export Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="export_name">Export Name *</Label>
                                    <Input
                                        id="export_name"
                                        value={data.export_name}
                                        onChange={(e) => setData('export_name', e.target.value)}
                                        placeholder="e.g., FY2025-26 Annual Audit"
                                    />
                                    {errors.export_name && <p className="text-sm text-destructive">{errors.export_name}</p>}
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="period_from">Period From *</Label>
                                        <Input
                                            id="period_from"
                                            type="date"
                                            value={data.period_from}
                                            onChange={(e) => setData('period_from', e.target.value)}
                                        />
                                        {errors.period_from && <p className="text-sm text-destructive">{errors.period_from}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="period_to">Period To *</Label>
                                        <Input
                                            id="period_to"
                                            type="date"
                                            value={data.period_to}
                                            onChange={(e) => setData('period_to', e.target.value)}
                                        />
                                        {errors.period_to && <p className="text-sm text-destructive">{errors.period_to}</p>}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sections to Include */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Sections to Include</CardTitle>
                            <CardDescription>
                                Select which data sections to include in the audit export. Each section generates a separate CSV file.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {sections.map((section) => (
                                    <div key={section.key} className="flex items-start space-x-3 rounded-lg border p-3 hover:bg-muted/50">
                                        <Checkbox
                                            id={section.key}
                                            checked={data[section.key]}
                                            onCheckedChange={(checked) => setData(section.key, !!checked)}
                                        />
                                        <div className="flex-1">
                                            <Label htmlFor={section.key} className="cursor-pointer font-medium">
                                                {section.label}
                                            </Label>
                                            <p className="text-sm text-muted-foreground mt-0.5">{section.description}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                id="notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                rows={3}
                                placeholder="Optional notes about this export..."
                            />
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Generating...' : 'Generate Export'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/finance/audit-exports">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
