import { Head, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface CostCentre {
    id: number;
    code: string;
    name: string;
}

interface FundingStream {
    id: number;
    code: string;
    name: string;
}

interface TaxRate {
    id: number;
    code: string;
    name: string;
    rate: string;
}

interface JournalLine {
    account_id: string;
    description: string;
    debit: string;
    credit: string;
    cost_centre_id: string;
    funding_stream_id: string;
    tax_rate_id: string;
    tax_amount: string;
}

interface Props extends PageProps {
    accounts: Account[];
    costCentres: CostCentre[];
    fundingStreams: FundingStream[];
    taxRates: TaxRate[];
}

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const emptyLine = (): JournalLine => ({
    account_id: '',
    description: '',
    debit: '',
    credit: '',
    cost_centre_id: '',
    funding_stream_id: '',
    tax_rate_id: '',
    tax_amount: '',
});

export default function JournalsCreate({ auth, accounts, costCentres, fundingStreams, taxRates }: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        journal_date: string;
        type: string;
        reference: string;
        description: string;
        lines: JournalLine[];
        post_immediately: boolean;
    }>({
        journal_date: new Date().toISOString().split('T')[0],
        type: 'standard',
        reference: '',
        description: '',
        lines: [emptyLine(), emptyLine()],
        post_immediately: false,
    });

    const totalDebits = data.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0);
    const totalCredits = data.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0);
    const difference = Math.round((totalDebits - totalCredits) * 100) / 100;
    const isBalanced = difference === 0 && totalDebits > 0;

    const updateLine = (index: number, field: keyof JournalLine, value: string) => {
        const updated = [...data.lines];
        updated[index] = { ...updated[index], [field]: value };
        setData('lines', updated);
    };

    const addLine = () => {
        setData('lines', [...data.lines, emptyLine()]);
    };

    const removeLine = (index: number) => {
        if (data.lines.length <= 2) return;
        setData('lines', data.lines.filter((_, i) => i !== index));
    };

    const handleSubmit = (postImmediately: boolean) => {
        setData('post_immediately', postImmediately);
        // Use setTimeout to ensure state update is flushed before submit
        setTimeout(() => {
            post('/finance/journals');
        }, 0);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Journals', href: '/finance/journals' },
                { title: 'Create', href: '/finance/journals/create' },
            ]}
        >
            <Head title="New Journal Entry" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">New Journal Entry</h1>
                    <p className="text-gray-500 mt-1">Create a manual general ledger journal entry</p>
                </div>

                {errors.posting && (
                    <div className="mb-4 rounded-md bg-red-50 border border-red-200 p-4">
                        <p className="text-sm text-red-800">{errors.posting}</p>
                    </div>
                )}

                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(false); }}>
                    {/* Header Fields */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Journal Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <Label htmlFor="journal_date">Date</Label>
                                    <Input
                                        id="journal_date"
                                        type="date"
                                        value={data.journal_date}
                                        onChange={(e) => setData('journal_date', e.target.value)}
                                    />
                                    {errors.journal_date && (
                                        <p className="text-sm text-red-600 mt-1">{errors.journal_date}</p>
                                    )}
                                </div>

                                <div>
                                    <Label htmlFor="type">Type</Label>
                                    <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                        <SelectTrigger id="type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="standard">Standard</SelectItem>
                                            <SelectItem value="adjustment">Adjustment</SelectItem>
                                            <SelectItem value="opening">Opening</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.type && (
                                        <p className="text-sm text-red-600 mt-1">{errors.type}</p>
                                    )}
                                </div>

                                <div>
                                    <Label htmlFor="reference">Reference</Label>
                                    <Input
                                        id="reference"
                                        value={data.reference}
                                        onChange={(e) => setData('reference', e.target.value)}
                                        placeholder="Optional reference"
                                    />
                                    {errors.reference && (
                                        <p className="text-sm text-red-600 mt-1">{errors.reference}</p>
                                    )}
                                </div>

                                <div className="sm:col-span-2 lg:col-span-1">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Journal description"
                                        rows={1}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-600 mt-1">{errors.description}</p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="mb-6">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Line Items</CardTitle>
                            <Button type="button" size="sm" variant="outline" onClick={addLine}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Line
                            </Button>
                        </CardHeader>
                        <CardContent className="p-0">
                            {errors.lines && (
                                <p className="text-sm text-red-600 px-6 py-2">{errors.lines}</p>
                            )}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="min-w-[200px]">Account</TableHead>
                                        <TableHead className="min-w-[150px]">Description</TableHead>
                                        <TableHead className="w-[130px]">Debit ($)</TableHead>
                                        <TableHead className="w-[130px]">Credit ($)</TableHead>
                                        <TableHead className="min-w-[150px]">Cost Centre</TableHead>
                                        <TableHead className="min-w-[150px]">Funding Stream</TableHead>
                                        <TableHead className="w-[50px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.lines.map((line, index) => (
                                        <TableRow key={index}>
                                            <TableCell>
                                                <Select
                                                    value={line.account_id}
                                                    onValueChange={(v) => updateLine(index, 'account_id', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select account" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {accounts.map((acc) => (
                                                            <SelectItem key={acc.id} value={String(acc.id)}>
                                                                {acc.code} - {acc.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {errors[`lines.${index}.account_id` as keyof typeof errors] && (
                                                    <p className="text-xs text-red-600 mt-1">
                                                        {errors[`lines.${index}.account_id` as keyof typeof errors]}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    value={line.description}
                                                    onChange={(e) => updateLine(index, 'description', e.target.value)}
                                                    placeholder="Line description"
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={line.debit}
                                                    onChange={(e) => updateLine(index, 'debit', e.target.value)}
                                                    placeholder="0.00"
                                                    className="text-right font-mono"
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={line.credit}
                                                    onChange={(e) => updateLine(index, 'credit', e.target.value)}
                                                    placeholder="0.00"
                                                    className="text-right font-mono"
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Select
                                                    value={line.cost_centre_id}
                                                    onValueChange={(v) => updateLine(index, 'cost_centre_id', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="None" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {costCentres.map((cc) => (
                                                            <SelectItem key={cc.id} value={String(cc.id)}>
                                                                {cc.code} - {cc.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell>
                                                <Select
                                                    value={line.funding_stream_id}
                                                    onValueChange={(v) => updateLine(index, 'funding_stream_id', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="None" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {fundingStreams.map((fs) => (
                                                            <SelectItem key={fs.id} value={String(fs.id)}>
                                                                {fs.code} - {fs.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeLine(index)}
                                                    disabled={data.lines.length <= 2}
                                                    className="text-gray-400 hover:text-red-600"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={2} className="text-right font-semibold">
                                            Totals
                                        </TableCell>
                                        <TableCell className="text-right font-mono font-semibold">
                                            {formatNZD(totalDebits)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono font-semibold">
                                            {formatNZD(totalCredits)}
                                        </TableCell>
                                        <TableCell colSpan={3} />
                                    </TableRow>
                                    <TableRow>
                                        <TableCell colSpan={2} className="text-right font-semibold">
                                            Difference
                                        </TableCell>
                                        <TableCell
                                            colSpan={2}
                                            className={cn(
                                                'text-right font-mono font-semibold',
                                                difference !== 0 ? 'text-red-600' : 'text-green-600',
                                            )}
                                        >
                                            {formatNZD(Math.abs(difference))}
                                            {difference !== 0 && (
                                                <span className="ml-2 text-xs">
                                                    ({difference > 0 ? 'Debits exceed' : 'Credits exceed'})
                                                </span>
                                            )}
                                            {difference === 0 && totalDebits > 0 && (
                                                <span className="ml-2 text-xs">Balanced</span>
                                            )}
                                        </TableCell>
                                        <TableCell colSpan={3} />
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </CardContent>
                    </Card>

                    {/* Submit Buttons */}
                    <div className="flex items-center gap-3 justify-end">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Save as Draft
                        </Button>
                        <Button
                            type="button"
                            disabled={processing || !isBalanced}
                            onClick={() => handleSubmit(true)}
                        >
                            Save &amp; Post
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
