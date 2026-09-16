import { FinanceSectionRail } from '@/components/finance';
import {
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
} from '@/components/lists';
import { PageHeader, PageLayout } from '@/components/page';
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
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { CalendarDays, Check, FileText } from 'lucide-react';
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

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FREQUENCY_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    two_monthly: 'Two-monthly',
    six_monthly: 'Six-monthly',
};

const BASIS_LABELS: Record<string, string> = {
    invoice: 'Invoice basis',
    payments: 'Payments basis',
    hybrid: 'Hybrid basis',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Tax & compliance', href: '/finance/tax' },
    { title: 'GST returns', href: '/finance/gst-returns' },
    { title: 'Prepare return', href: '/finance/gst-returns/prepare' },
];

export default function GstReturnPrepare({
    filingDates,
    currentYear,
}: PageProps) {
    const { data, setData, post, processing, errors } = useForm({
        period_start: '',
        period_end: '',
        filing_frequency: '',
        basis: '',
    });

    const [selectedFrequency, setSelectedFrequency] = useState<string>('');

    const handleFrequencyChange = (value: string) => {
        setSelectedFrequency(value);
        setData('filing_frequency', value);
    };

    const handlePeriodSelect = (period: FilingPeriod) => {
        setData((prev) => ({
            ...prev,
            period_start: period.period_start,
            period_end: period.period_end,
        }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/gst-returns');
    };

    const activePeriods = selectedFrequency
        ? (filingDates[selectedFrequency as keyof typeof filingDates] ?? [])
        : [];

    const isSelected = (period: FilingPeriod) =>
        data.period_start === period.period_start &&
        data.period_end === period.period_end;

    const periodActions = (period: FilingPeriod): MenuItem[] => [
        {
            label: 'Use this period',
            icon: Check,
            onClick: () => handlePeriodSelect(period),
        },
    ];

    const periodColumns: EntityTableColumn<FilingPeriod>[] = [
        {
            key: 'ird_period',
            label: 'IRD period',
            width: '160px',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    {p.ird_period}
                </span>
            ),
        },
        {
            key: 'due_date',
            label: 'Due date',
            width: '180px',
            cell: (p) => (
                <span className="whitespace-nowrap">
                    {shortDate(p.due_date)}
                </span>
            ),
        },
        {
            key: 'selected',
            label: 'Selected',
            width: '140px',
            cell: (p) =>
                isSelected(p) ? (
                    <StatusBadge variant="success">Selected</StatusBadge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/gst-returns"
            icon={FileText}
            title="Prepare GST return"
            subline={`Tax & compliance · choose a filing frequency and period · ${currentYear} filing calendar`}
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Prepare GST return" />

            <PageLayout hero={header}>
                <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                <CardTitle className="text-section-title">
                                    Return details
                                </CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="filing_frequency">
                                        Filing frequency
                                    </Label>
                                    <Select
                                        value={data.filing_frequency}
                                        onValueChange={handleFrequencyChange}
                                    >
                                        <SelectTrigger id="filing_frequency">
                                            <SelectValue placeholder="Select frequency" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                FREQUENCY_LABELS,
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
                                        Accounting basis
                                    </Label>
                                    <Select
                                        value={data.basis}
                                        onValueChange={(v) =>
                                            setData('basis', v)
                                        }
                                    >
                                        <SelectTrigger id="basis">
                                            <SelectValue placeholder="Select basis" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(BASIS_LABELS).map(
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
                                        Period start
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
                                        Period end
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
                        <div className="flex flex-col gap-5">
                            <ListCaption
                                title={`${currentYear} filing calendar`}
                                caption={`${FREQUENCY_LABELS[selectedFrequency]} · select a period to fill the dates above`}
                            />
                            <EntityTable
                                rows={activePeriods}
                                rowKey={(p) => p.ird_period}
                                identityLabel="Period"
                                minWidth={840}
                                identity={(p) => ({
                                    icon: CalendarDays,
                                    name: `${shortDate(p.period_start)} – ${shortDate(p.period_end)}`,
                                })}
                                columns={periodColumns}
                                actionsFor={periodActions}
                                onOpen={handlePeriodSelect}
                            />
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            <FileText className="mr-2 h-4 w-4" />
                            Prepare return
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
