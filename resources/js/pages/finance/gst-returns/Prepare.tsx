import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
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
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { CalendarDays, FileText } from 'lucide-react';
import { useState } from 'react';

type FilingPeriod = {
    period_start: string;
    period_end: string;
    due_date: string;
    ird_period: string;
};

type PageProps = {
    filingDates: {
        monthly: FilingPeriod[];
        two_monthly: FilingPeriod[];
        six_monthly: FilingPeriod[];
    };
    currentYear: number;
};

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const frequencyLabels: Record<string, string> = {
    monthly: 'Monthly',
    two_monthly: 'Two-Monthly',
    six_monthly: 'Six-Monthly',
};

const basisLabels: Record<string, string> = {
    invoice: 'Invoice Basis',
    payments: 'Payments Basis',
    hybrid: 'Hybrid Basis',
};

export default function GstReturnPrepare({
    filingDates,
    currentYear,
}: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'GST Returns', href: '/finance/gst-returns' },
        { title: 'Prepare Return', href: '/finance/gst-returns/prepare' },
    ];

    const { data, setData, post, processing, errors } = useForm({
        period_start: '',
        period_end: '',
        filing_frequency: '',
        basis: '',
    });

    const [selectedFrequency, setSelectedFrequency] = useState<string>('');

    function handleFrequencyChange(value: string) {
        setSelectedFrequency(value);
        setData('filing_frequency', value);
    }

    function handlePeriodSelect(period: FilingPeriod) {
        setData((prev) => ({
            ...prev,
            period_start: period.period_start,
            period_end: period.period_end,
        }));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/finance/gst-returns');
    }

    const activePeriods = selectedFrequency
        ? (filingDates[selectedFrequency as keyof typeof filingDates] ?? [])
        : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Prepare GST Return" />

            <PageLayout
                width="narrow"
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/gst-returns"
                        title="Prepare GST Return"
                        description="Select your filing frequency and period to prepare a new GST return"
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Return Details</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="filing_frequency">
                                        Filing Frequency
                                    </Label>
                                    <Select
                                        value={data.filing_frequency}
                                        onValueChange={handleFrequencyChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select frequency" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                frequencyLabels,
                                            ).map(([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.filing_frequency && (
                                        <p className="text-sm text-destructive">
                                            {errors.filing_frequency}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="basis">
                                        Accounting Basis
                                    </Label>
                                    <Select
                                        value={data.basis}
                                        onValueChange={(v) =>
                                            setData('basis', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select basis" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(basisLabels).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    {errors.basis && (
                                        <p className="text-sm text-destructive">
                                            {errors.basis}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="period_start">
                                        Period Start
                                    </Label>
                                    <Input
                                        id="period_start"
                                        type="date"
                                        value={data.period_start}
                                        onChange={(e) =>
                                            setData(
                                                'period_start',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.period_start && (
                                        <p className="text-sm text-destructive">
                                            {errors.period_start}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="period_end">
                                        Period End
                                    </Label>
                                    <Input
                                        id="period_end"
                                        type="date"
                                        value={data.period_end}
                                        onChange={(e) =>
                                            setData(
                                                'period_end',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.period_end && (
                                        <p className="text-sm text-destructive">
                                            {errors.period_end}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {selectedFrequency && activePeriods.length > 0 && (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <CalendarDays className="h-5 w-5 text-muted-foreground" />
                                    <CardTitle>
                                        {currentYear} Filing Calendar (
                                        {frequencyLabels[selectedFrequency]})
                                    </CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pr-4 pb-3 font-medium">
                                                    Period
                                                </th>
                                                <th className="pr-4 pb-3 font-medium">
                                                    IRD Period
                                                </th>
                                                <th className="pr-4 pb-3 font-medium">
                                                    Due Date
                                                </th>
                                                <th className="pb-3 font-medium"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {activePeriods.map((period, i) => {
                                                const isSelected =
                                                    data.period_start ===
                                                        period.period_start &&
                                                    data.period_end ===
                                                        period.period_end;

                                                return (
                                                    <tr
                                                        key={i}
                                                        className={`cursor-pointer border-b transition-colors last:border-0 ${
                                                            isSelected
                                                                ? 'bg-primary/5'
                                                                : 'hover:bg-muted/50'
                                                        }`}
                                                        onClick={() =>
                                                            handlePeriodSelect(
                                                                period,
                                                            )
                                                        }
                                                    >
                                                        <td className="py-3 pr-4">
                                                            {formatDate(
                                                                period.period_start,
                                                            )}{' '}
                                                            &ndash;{' '}
                                                            {formatDate(
                                                                period.period_end,
                                                            )}
                                                        </td>
                                                        <td className="py-3 pr-4 font-mono text-muted-foreground">
                                                            {period.ird_period}
                                                        </td>
                                                        <td className="py-3 pr-4">
                                                            {formatDate(
                                                                period.due_date,
                                                            )}
                                                        </td>
                                                        <td className="py-3 text-right">
                                                            {isSelected && (
                                                                <span className="text-xs font-medium text-primary">
                                                                    Selected
                                                                </span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing} size="lg">
                            <FileText className="mr-2 h-4 w-4" />
                            Prepare Return
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
