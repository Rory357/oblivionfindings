import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    DollarSign,
    Download,
    FileSpreadsheet,
    Plus,
} from 'lucide-react';

const ANY = '__ANY__';

const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

type PayrollExport = {
    id: number;
    status: string;
    period_start: string;
    period_end: string;
    total_hours: number;
    total_amount: number;
    exported_at: string | null;
    created_at: string;
};

type Props = {
    exports: {
        data: PayrollExport[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status?: string;
    };
    stats: {
        total: number;
        hours_this_period: number;
        amount_this_period: number;
    };
};

const STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'outline',
    exported: 'secondary',
    confirmed: 'default',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function PayrollExportIndex({
    exports: payrollExports = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {} as any,
    stats = {} as any,
}: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/operations/payroll-export',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Payroll Export" />
            <PageHeader
                title="Payroll Export"
                description="Generate and manage payroll export files."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard
                        label="Total Exports"
                        value={stats?.total ?? 0}
                        icon={FileSpreadsheet}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Hours This Period"
                        value={`${(stats?.hours_this_period ?? 0).toLocaleString('en-NZ')} hrs`}
                        icon={Clock}
                        color="blue"
                    />
                    <OpsStatCard
                        label="Amount This Period"
                        value={nzd.format(stats?.amount_this_period ?? 0)}
                        icon={DollarSign}
                        color="emerald"
                    />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <Select
                        value={filters?.status ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('status', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="exported">Exported</SelectItem>
                            <SelectItem value="confirmed">Confirmed</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex-1" />
                    <Button asChild size="sm">
                        <Link href="/operations/payroll-export/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            Generate Export
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(payrollExports?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileSpreadsheet className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Payroll Exports
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    Generate your first payroll export to get
                                    started.
                                </p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/payroll-export/create">
                                        Generate Export
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(payrollExports?.data ?? []).map((exp) => (
                        <Card
                            key={exp.id}
                            className="transition-all hover:border-border hover:shadow-sm"
                        >
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <FileSpreadsheet className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            {`Export #${exp.id}`}
                                        </span>
                                        <Badge
                                            variant={
                                                STATUS_VARIANTS[exp.status] ??
                                                'outline'
                                            }
                                            className="h-4 px-1.5 text-[9px] capitalize"
                                        >
                                            {exp.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <CalendarDays className="h-3 w-3" />
                                            {formatDate(
                                                exp.period_start,
                                            )} - {formatDate(exp.period_end)}
                                        </span>
                                        <span>{exp.total_hours} hrs</span>
                                        <span className="font-semibold text-emerald-700 tabular-nums dark:text-emerald-400">
                                            {nzd.format(exp.total_amount)}
                                        </span>
                                        {exp.exported_at && (
                                            <span>
                                                Exported:{' '}
                                                {formatDate(exp.exported_at)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    {exp.status === 'exported' && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 px-2 text-xs"
                                            onClick={() =>
                                                router.post(
                                                    `/operations/payroll-export/${exp.id}/confirm`,
                                                )
                                            }
                                        >
                                            Confirm
                                        </Button>
                                    )}
                                    {(exp.status === 'exported' ||
                                        exp.status === 'confirmed') && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 px-2 text-xs"
                                            onClick={() =>
                                                window.location.assign(
                                                    `/operations/payroll-export/${exp.id}/download`,
                                                )
                                            }
                                        >
                                            <Download className="mr-1 h-3 w-3" />{' '}
                                            Download
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(payrollExports?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(payrollExports?.links ?? []).map(
                            (link: any, i: number) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ),
                        )}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
