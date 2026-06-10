import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Minus, TrendingDown, TrendingUp } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type HistoryEntry = {
    id: number;
    change_type: string;
    previous_hourly_rate: string | null;
    new_hourly_rate: string;
    previous_annual_salary: string | null;
    new_annual_salary: string;
    change_percentage: number | null;
    reason: string | null;
    effective_date: string;
    approver: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    created_at: string;
};

type EmployeeProfile = {
    id: number;
    user: { id: number; name: string };
    position_title: string;
    annual_salary: string;
    hourly_rate: string;
};

type Props = {
    profile: EmployeeProfile;
    history: { data: HistoryEntry[]; links: any[] };
    can: { manage: boolean };
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(num);
};

const getChangeTypeColor = (type: string) => {
    switch (type) {
        case 'initial':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'promotion':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'review':
            return 'bg-primary/10 text-primary border-primary';
        case 'adjustment':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'correction':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

export default function CompensationHistory({ profile, history, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'People', href: '/hr/people' },
        { title: profile.user.name, href: `/hr/people/${profile.id}` },
        {
            title: 'Compensation History',
            href: `/hr/compensation/history/${profile.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Compensation History - ${profile.user.name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/hr/people/${profile.id}`}
                        title="Compensation History"
                        description={`${profile.user.name} · ${profile.position_title}`}
                    />
                }
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Current Annual Salary
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {formatCurrency(profile.annual_salary)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Current Hourly Rate
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {formatCurrency(profile.hourly_rate)}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            History Timeline
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {history.data.length === 0 && (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No compensation history recorded.
                            </p>
                        )}
                        <div className="space-y-4">
                            {history.data.map((entry, idx) => {
                                const pctVal = entry.change_percentage;
                                const isPositive =
                                    pctVal !== null && pctVal > 0;
                                const isNegative =
                                    pctVal !== null && pctVal < 0;

                                return (
                                    <div
                                        key={entry.id}
                                        className="relative flex gap-4 border-l-2 border-border pb-4 pl-6 last:border-transparent last:pb-0"
                                    >
                                        <div className="absolute top-0 -left-2 h-4 w-4 rounded-full border-2 border-white bg-muted" />

                                        <div className="flex-1 space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {formatDate(
                                                        entry.effective_date,
                                                    )}
                                                </span>
                                                <Badge
                                                    className={getChangeTypeColor(
                                                        entry.change_type,
                                                    )}
                                                >
                                                    {entry.change_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                {pctVal !== null && (
                                                    <span
                                                        className={`flex items-center gap-0.5 text-xs font-medium ${isPositive ? 'text-status-success' : isNegative ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                    >
                                                        {isPositive ? (
                                                            <TrendingUp className="h-3 w-3" />
                                                        ) : isNegative ? (
                                                            <TrendingDown className="h-3 w-3" />
                                                        ) : (
                                                            <Minus className="h-3 w-3" />
                                                        )}
                                                        {isPositive ? '+' : ''}
                                                        {pctVal}%
                                                    </span>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span className="text-muted-foreground">
                                                        Hourly Rate:{' '}
                                                    </span>
                                                    {entry.previous_hourly_rate && (
                                                        <span className="text-muted-foreground line-through">
                                                            {formatCurrency(
                                                                entry.previous_hourly_rate,
                                                            )}
                                                        </span>
                                                    )}{' '}
                                                    <span className="font-medium">
                                                        {formatCurrency(
                                                            entry.new_hourly_rate,
                                                        )}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground">
                                                        Annual Salary:{' '}
                                                    </span>
                                                    {entry.previous_annual_salary && (
                                                        <span className="text-muted-foreground line-through">
                                                            {formatCurrency(
                                                                entry.previous_annual_salary,
                                                            )}
                                                        </span>
                                                    )}{' '}
                                                    <span className="font-medium">
                                                        {formatCurrency(
                                                            entry.new_annual_salary,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>

                                            {entry.reason && (
                                                <p className="text-sm text-muted-foreground">
                                                    {entry.reason}
                                                </p>
                                            )}

                                            <div className="flex gap-4 text-xs text-muted-foreground">
                                                {entry.approver && (
                                                    <span>
                                                        Approved by{' '}
                                                        {entry.approver.name}
                                                    </span>
                                                )}
                                                {entry.creator && (
                                                    <span>
                                                        Created by{' '}
                                                        {entry.creator.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {history?.links?.length ? (
                    <LaravelPagination links={history.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
