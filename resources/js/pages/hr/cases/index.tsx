import {
    NewCaseWizard,
    type CaseOption,
    type CaseStaffOption,
} from '@/components/hr/case-wizards';
import { LifecycleTabs } from '@/components/hr/lifecycle-tabs';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import {
    AlertTriangle,
    Briefcase,
    Clock3,
    Folder,
    FolderOpen,
    Plus,
    Search,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type CaseRelation = {
    id: number;
    name: string;
} | null;

type HrCaseRow = {
    id: number;
    case_number: string;
    case_type: string;
    status: string;
    severity: string;
    title: string;
    opened_at: string;
    closed_at: string | null;
    subject?: CaseRelation;
    assigned_to?: CaseRelation;
    assignedTo?: CaseRelation;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    cases: {
        data: HrCaseRow[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    summary: {
        open_cases: number;
        unassigned_open_cases: number;
        high_severity_open_cases: number;
        disciplinary_active: number;
        disciplinary_sla_overdue: number;
        disciplinary_sla_due_24h: number;
        disciplinary_missing_deadline: number;
        escalation_candidates: number;
    };
    filters: {
        status: string | null;
        case_type: string | null;
        severity: string | null;
        q: string | null;
        sla_window?: string | null;
    };
    can: { manage: boolean; disciplinary?: boolean };
    staff: CaseStaffOption[];
    caseTypes: CaseOption[];
    severities: CaseOption[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Cases', href: '/hr/cases' },
];

const NONE = '__none__';

const statuses = [
    'open',
    'under_investigation',
    'awaiting_response',
    'resolved',
    'closed',
];
const caseTypeFilters = [
    'grievance',
    'disciplinary',
    'investigation',
    'welfare',
    'complaint',
    'other',
];
const severityFilters = ['low', 'medium', 'high', 'critical'];
const slaWindows = ['overdue', 'due_24h', 'missing_deadline', 'escalation'];

const formatDate = (value?: string | null) => {
    if (!value) return '--';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const badgeClassByStatus: Record<string, string> = {
    open: 'bg-status-info-bg text-status-info border-status-info/30',
    under_investigation: 'bg-primary/10 text-primary border-primary',
    awaiting_response:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    resolved:
        'bg-status-success-bg text-status-success border-status-success/30',
    closed: 'bg-muted text-foreground border-border',
};

const badgeClassByCaseType: Record<string, string> = {
    disciplinary:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    grievance:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    investigation: 'bg-primary/10 text-primary border-primary',
    welfare:
        'bg-status-success-bg text-status-success border-status-success/30',
    complaint: 'bg-status-info-bg text-status-info border-status-info/30',
    other: 'bg-muted text-foreground border-border',
};

const badgeClassBySeverity: Record<string, string> = {
    critical:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    medium: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    low: 'bg-muted text-foreground border-border',
};

export default function HrCasesIndex({
    cases,
    summary,
    filters,
    can,
    staff,
    caseTypes,
    severities,
}: Props) {
    // Open the New-case wizard on mount when deep-linked via ?new (the old
    // GET /hr/cases/create route redirects here with that param).
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );

    const onFilter = (next: Partial<Props['filters']>) => {
        const payload = { ...filters, ...next };

        router.get(
            '/hr/cases',
            {
                status: payload.status || undefined,
                case_type: payload.case_type || undefined,
                severity: payload.severity || undefined,
                q: payload.q || undefined,
                sla_window: payload.sla_window || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Cases" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Folder}
                        title="HR Cases"
                        description="Manage disciplinary, grievance, and investigation workflows."
                        stats={[
                            { label: 'Open', value: summary.open_cases },
                            { label: 'Unassigned', value: summary.unassigned_open_cases },
                            { label: 'High severity', value: summary.high_severity_open_cases },
                            { label: 'SLA overdue', value: summary.disciplinary_sla_overdue },
                        ]}
                        actions={
                            can.manage ? (
                                <Button size="sm" onClick={() => setWizardOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Open Case
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                <LifecycleTabs active="cases" />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Disciplinary SLA Snapshot
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground">
                                Open Cases
                            </div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold">
                                <Briefcase className="h-4 w-4 text-muted-foreground" />
                                {summary.open_cases}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Unassigned: {summary.unassigned_open_cases}
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground">
                                Disciplinary Active
                            </div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold">
                                <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                                {summary.disciplinary_active}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                High/Critical:{' '}
                                {summary.high_severity_open_cases}
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground">
                                SLA Watch
                            </div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold text-status-warning">
                                <Clock3 className="h-4 w-4" />
                                {summary.disciplinary_sla_due_24h}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Due within 24h
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground">
                                Escalation Risk
                            </div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold text-status-critical">
                                <AlertTriangle className="h-4 w-4" />
                                {summary.escalation_candidates}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Overdue: {summary.disciplinary_sla_overdue} |
                                Missing deadline:{' '}
                                {summary.disciplinary_missing_deadline}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                        <div className="sm:col-span-5">
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Case number, title, or subject"
                                    value={filters.q || ''}
                                    onChange={(event) =>
                                        onFilter({ q: event.target.value })
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(value) =>
                                    onFilter({
                                        status: value === NONE ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Statuses
                                    </SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {status.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Case Type
                            </Label>
                            <Select
                                value={filters.case_type ?? NONE}
                                onValueChange={(value) =>
                                    onFilter({
                                        case_type:
                                            value === NONE ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All case types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Case Types
                                    </SelectItem>
                                    {caseTypeFilters.map((caseType) => (
                                        <SelectItem
                                            key={caseType}
                                            value={caseType}
                                        >
                                            {caseType.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Severity
                            </Label>
                            <Select
                                value={filters.severity ?? NONE}
                                onValueChange={(value) =>
                                    onFilter({
                                        severity: value === NONE ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All severities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Severities
                                    </SelectItem>
                                    {severityFilters.map((severity) => (
                                        <SelectItem
                                            key={severity}
                                            value={severity}
                                        >
                                            {severity}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Disciplinary SLA
                            </Label>
                            <Select
                                value={filters.sla_window ?? NONE}
                                onValueChange={(value) =>
                                    onFilter({
                                        sla_window:
                                            value === NONE ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All SLA windows" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All SLA Windows
                                    </SelectItem>
                                    {slaWindows.map((window) => (
                                        <SelectItem key={window} value={window}>
                                            {window.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Case</TableHead>
                                    <TableHead>Subject</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Severity</TableHead>
                                    <TableHead>Opened</TableHead>
                                    <TableHead>Closed</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {cases.data.map((hrCase) => {
                                    const subjectName =
                                        hrCase.subject?.name ?? 'Unknown';
                                    const assigneeName =
                                        hrCase.assigned_to?.name ??
                                        hrCase.assignedTo?.name ??
                                        null;

                                    return (
                                        <TableRow key={hrCase.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Briefcase className="h-4 w-4 text-muted-foreground" />
                                                    <div>
                                                        <div className="font-medium">
                                                            {hrCase.case_number}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {hrCase.title}
                                                        </div>
                                                        {assigneeName ? (
                                                            <div className="text-xs text-muted-foreground">
                                                                Assigned:{' '}
                                                                {assigneeName}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>{subjectName}</TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        badgeClassByCaseType[
                                                            hrCase.case_type
                                                        ] ??
                                                        badgeClassByCaseType.other
                                                    }
                                                >
                                                    {hrCase.case_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        badgeClassByStatus[
                                                            hrCase.status
                                                        ] ??
                                                        badgeClassByStatus.closed
                                                    }
                                                >
                                                    {hrCase.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        badgeClassBySeverity[
                                                            hrCase.severity
                                                        ] ??
                                                        badgeClassBySeverity.low
                                                    }
                                                >
                                                    {hrCase.severity}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(hrCase.opened_at)}
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(hrCase.closed_at)}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={`/hr/cases/${hrCase.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {cases.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="py-16 text-center"
                                        >
                                            <div className="flex flex-col items-center gap-3">
                                                <FolderOpen className="h-10 w-10 text-muted-foreground/50" />
                                                <div>
                                                    <p className="font-medium text-muted-foreground">
                                                        No HR cases found
                                                    </p>
                                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                                        {filters.q ||
                                                        filters.status ||
                                                        filters.case_type ||
                                                        filters.severity ||
                                                        filters.sla_window
                                                            ? 'Try adjusting your search or filter criteria.'
                                                            : 'No cases have been opened yet.'}
                                                    </p>
                                                </div>
                                                {can.manage &&
                                                    !filters.q &&
                                                    !filters.status &&
                                                    !filters.case_type &&
                                                    !filters.severity && (
                                                        <Button
                                                            size="sm"
                                                            className="mt-2"
                                                            onClick={() =>
                                                                setWizardOpen(
                                                                    true,
                                                                )
                                                            }
                                                        >
                                                            <Plus className="mr-1.5 h-4 w-4" />
                                                            Open Case
                                                        </Button>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {cases.total > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(cases.current_page - 1) * cases.per_page + 1} to{' '}
                            {Math.min(
                                cases.current_page * cases.per_page,
                                cases.total,
                            )}{' '}
                            of {cases.total} results
                        </p>
                        {cases.last_page > 1 && (
                            <LaravelPagination links={cases.links} />
                        )}
                    </div>
                )}
            </PageLayout>

            {wizardOpen && can.manage ? (
                <NewCaseWizard
                    staff={staff}
                    caseTypes={caseTypes}
                    severities={severities}
                    onClose={() => setWizardOpen(false)}
                />
            ) : null}
        </AppLayout>
    );
}
