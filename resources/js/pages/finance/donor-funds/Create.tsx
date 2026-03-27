import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { FormEvent } from 'react';

interface Account {
    id: number;
    code: string;
    name: string;
}

interface FundingStream {
    id: number;
    name: string;
}

interface Props extends PageProps {
    glAccounts: Account[];
    fundingStreams: FundingStream[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Donor Funds', href: '/finance/donor-funds' },
    { title: 'New Fund', href: '/finance/donor-funds/create' },
];

export default function DonorFundCreate({ glAccounts, fundingStreams }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        fund_code: '',
        fund_name: '',
        donor_name: '',
        donor_contact: '',
        fund_type: 'grant',
        gl_account_id: '',
        funding_stream_id: '',
        budget_amount: '',
        start_date: '',
        end_date: '',
        restrictions: '',
        reporting_requirements: '',
        next_report_due: '',
        is_restricted: true,
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post('/finance/donor-funds');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Donor Fund" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">New Donor Fund</h1>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Basic Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Fund Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="fund_code">Fund Code</Label>
                                    <Input
                                        id="fund_code"
                                        value={data.fund_code}
                                        onChange={(e) => setData('fund_code', e.target.value)}
                                        placeholder="e.g. GNT-2026-001"
                                    />
                                    {errors.fund_code && <p className="mt-1 text-sm text-destructive">{errors.fund_code}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="fund_name">Fund Name</Label>
                                    <Input
                                        id="fund_name"
                                        value={data.fund_name}
                                        onChange={(e) => setData('fund_name', e.target.value)}
                                        placeholder="e.g. Lotteries NZ Community Grant"
                                    />
                                    {errors.fund_name && <p className="mt-1 text-sm text-destructive">{errors.fund_name}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="donor_name">Donor Name</Label>
                                    <Input
                                        id="donor_name"
                                        value={data.donor_name}
                                        onChange={(e) => setData('donor_name', e.target.value)}
                                        placeholder="e.g. Lotteries NZ"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="donor_contact">Donor Contact</Label>
                                    <Input
                                        id="donor_contact"
                                        value={data.donor_contact}
                                        onChange={(e) => setData('donor_contact', e.target.value)}
                                        placeholder="e.g. grants@lotterygrants.govt.nz"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="fund_type">Fund Type</Label>
                                    <Select value={data.fund_type} onValueChange={(val) => setData('fund_type', val)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="grant">Grant</SelectItem>
                                            <SelectItem value="donation">Donation</SelectItem>
                                            <SelectItem value="bequest">Bequest</SelectItem>
                                            <SelectItem value="trust">Trust</SelectItem>
                                            <SelectItem value="government">Government</SelectItem>
                                            <SelectItem value="sponsorship">Sponsorship</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.fund_type && <p className="mt-1 text-sm text-destructive">{errors.fund_type}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="budget_amount">Budget Amount (NZD)</Label>
                                    <Input
                                        id="budget_amount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.budget_amount}
                                        onChange={(e) => setData('budget_amount', e.target.value)}
                                        placeholder="Total grant amount"
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Switch
                                    id="is_restricted"
                                    checked={data.is_restricted}
                                    onCheckedChange={(checked) => setData('is_restricted', checked)}
                                />
                                <Label htmlFor="is_restricted">Restricted fund (expenditure limited to available balance)</Label>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Accounting */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Accounting</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="gl_account_id">GL Account (Liability/Equity)</Label>
                                    <Select value={data.gl_account_id} onValueChange={(val) => setData('gl_account_id', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select GL account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {glAccounts.map((acc) => (
                                                <SelectItem key={acc.id} value={String(acc.id)}>
                                                    {acc.code} - {acc.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="funding_stream_id">Funding Stream</Label>
                                    <Select value={data.funding_stream_id} onValueChange={(val) => setData('funding_stream_id', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select funding stream" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {fundingStreams.map((fs) => (
                                                <SelectItem key={fs.id} value={String(fs.id)}>
                                                    {fs.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Dates & Reporting */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Dates & Reporting</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label htmlFor="start_date">Start Date</Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={data.start_date}
                                        onChange={(e) => setData('start_date', e.target.value)}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="end_date">End Date</Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={data.end_date}
                                        onChange={(e) => setData('end_date', e.target.value)}
                                    />
                                    {errors.end_date && <p className="mt-1 text-sm text-destructive">{errors.end_date}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="next_report_due">Next Report Due</Label>
                                    <Input
                                        id="next_report_due"
                                        type="date"
                                        value={data.next_report_due}
                                        onChange={(e) => setData('next_report_due', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="restrictions">Restrictions</Label>
                                <Textarea
                                    id="restrictions"
                                    value={data.restrictions}
                                    onChange={(e) => setData('restrictions', e.target.value)}
                                    placeholder="How may funds be used? Any restrictions or conditions..."
                                    rows={3}
                                />
                            </div>

                            <div>
                                <Label htmlFor="reporting_requirements">Reporting Requirements</Label>
                                <Textarea
                                    id="reporting_requirements"
                                    value={data.reporting_requirements}
                                    onChange={(e) => setData('reporting_requirements', e.target.value)}
                                    placeholder="What reporting is required? Frequency, format, etc..."
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Fund'}
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/finance/donor-funds">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
