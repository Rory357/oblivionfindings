import {
    ExitInterviewWizard,
    type DepartureReasonOption,
    type ExitInterviewEmployeeOption,
    type ExitInterviewerOption,
} from '@/components/hr/exit-interview-wizards';
import { LifecycleTabs } from '@/components/hr/lifecycle-tabs';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, LogOut, Plus, Star } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface ExitInterview {
    id: number;
    interview_date: string;
    departure_reason: string;
    would_recommend: boolean | null;
    overall_satisfaction: number | null;
    is_confidential: boolean;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
    };
    interviewer: { id: number; name: string };
}

interface Props {
    interviews: {
        data: ExitInterview[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    stats: {
        total: number;
        avg_satisfaction: number | null;
        recommend_pct: number | null;
        last_90_days: number;
    };
    employees: ExitInterviewEmployeeOption[];
    interviewers: ExitInterviewerOption[];
    departureReasons: DepartureReasonOption[];
    filters: { reason: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Exit Interviews', href: '/hr/exit-interviews' },
];

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

const reasonLabels: Record<string, string> = {
    career_growth: 'Career Growth',
    compensation: 'Compensation',
    work_life_balance: 'Work-Life Balance',
    management: 'Management Issues',
    culture: 'Company Culture',
    relocation: 'Relocation',
    retirement: 'Retirement',
    personal: 'Personal Reasons',
    redundancy: 'Redundancy',
    contract_end: 'Contract End',
    other: 'Other',
};

function SatisfactionStars({ rating }: { rating: number | null }) {
    if (rating === null)
        return <span className="text-sm text-muted-foreground">-</span>;
    return (
        <div className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((star) => (
                <Star
                    key={star}
                    className={`h-4 w-4 ${star <= rating ? 'fill-status-warning text-status-warning' : 'text-muted-foreground'}`}
                />
            ))}
        </div>
    );
}

export default function ExitInterviewsIndex({
    interviews,
    stats,
    employees,
    interviewers,
    departureReasons,
    filters,
    can,
}: Props) {
    const NONE = '__none__';
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/exit-interviews',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Exit Interviews" />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={LogOut}
                        title="Exit Interviews"
                        description="Track departure feedback and identify retention insights."
                        stats={[
                            { label: 'Total recorded', value: stats.total },
                            {
                                label: 'Last 90 days',
                                value: stats.last_90_days,
                            },
                            {
                                label: 'Avg satisfaction',
                                value:
                                    stats.avg_satisfaction !== null
                                        ? `${stats.avg_satisfaction}/5`
                                        : '—',
                            },
                            {
                                label: 'Would recommend',
                                value:
                                    stats.recommend_pct !== null
                                        ? `${stats.recommend_pct}%`
                                        : '—',
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Link href="/hr/exit-interviews/trends">
                                    <Button size="sm" variant="outline">
                                        <BarChart3 className="mr-1.5 h-4 w-4" />
                                        Trends
                                    </Button>
                                </Link>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setWizardOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Interview
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
                tabs={<LifecycleTabs active="exit-interviews" />}
            >
                {/* Filters */}
                <Card className="mb-4">
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Departure Reason
                            </Label>
                            <Select
                                value={filters.reason ?? NONE}
                                onValueChange={(v) =>
                                    onFilter({ reason: v === NONE ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All reasons" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Reasons
                                    </SelectItem>
                                    {Object.entries(reasonLabels).map(
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
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Departure Reason</TableHead>
                                    <TableHead>Satisfaction</TableHead>
                                    <TableHead>Recommend</TableHead>
                                    <TableHead>Interviewer</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {interviews.data.map((interview) => (
                                    <TableRow key={interview.id}>
                                        <TableCell className="font-medium">
                                            {interview.employee_profile?.user
                                                ?.name ?? 'Unknown'}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(
                                                interview.interview_date,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {reasonLabels[
                                                    interview.departure_reason
                                                ] ?? interview.departure_reason}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <SatisfactionStars
                                                rating={
                                                    interview.overall_satisfaction
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {interview.would_recommend ===
                                            null ? (
                                                '-'
                                            ) : interview.would_recommend ? (
                                                <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                                    Yes
                                                </Badge>
                                            ) : (
                                                <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                    No
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {interview.interviewer?.name ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/exit-interviews/${interview.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!interviews.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No exit interviews found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                <LaravelPagination
                    links={interviews?.links ?? []}
                    className="mt-4"
                />

                {can.manage && wizardOpen && (
                    <ExitInterviewWizard
                        employees={employees}
                        interviewers={interviewers}
                        departureReasons={departureReasons}
                        onClose={() => setWizardOpen(false)}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
