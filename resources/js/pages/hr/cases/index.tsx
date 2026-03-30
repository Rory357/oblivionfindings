import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Clock3, FolderOpen, Plus, Search, ShieldAlert } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

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
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Cases', href: '/hr/cases' },
];

const NONE = '__none__';

const statuses = ['open', 'under_investigation', 'awaiting_response', 'resolved', 'closed'];
const caseTypes = ['grievance', 'disciplinary', 'investigation', 'welfare', 'complaint', 'other'];
const severities = ['low', 'medium', 'high', 'critical'];
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
    open: 'bg-blue-100 text-blue-800 border-blue-200',
    under_investigation: 'bg-purple-100 text-purple-800 border-purple-200',
    awaiting_response: 'bg-amber-100 text-amber-800 border-amber-200',
    resolved: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    closed: 'bg-slate-100 text-slate-800 border-slate-200',
};

const badgeClassByCaseType: Record<string, string> = {
    disciplinary: 'bg-red-100 text-red-800 border-red-200',
    grievance: 'bg-orange-100 text-orange-800 border-orange-200',
    investigation: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    welfare: 'bg-green-100 text-green-800 border-green-200',
    complaint: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    other: 'bg-slate-100 text-slate-800 border-slate-200',
};

const badgeClassBySeverity: Record<string, string> = {
    critical: 'bg-red-100 text-red-800 border-red-200',
    high: 'bg-orange-100 text-orange-800 border-orange-200',
    medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    low: 'bg-slate-100 text-slate-800 border-slate-200',
};

export default function HrCasesIndex({ cases, summary, filters, can }: Props) {
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

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">HR Cases</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Manage disciplinary, grievance, and investigation workflows.
                        </p>
                    </div>

                    {can.manage && (
                        <Link href="/hr/cases/create">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Open Case
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Disciplinary SLA Snapshot</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-slate-500">Open Cases</div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold">
                                <Briefcase className="h-4 w-4 text-slate-500" />
                                {summary.open_cases}
                            </div>
                            <div className="text-xs text-slate-400">Unassigned: {summary.unassigned_open_cases}</div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-slate-500">Disciplinary Active</div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold">
                                <ShieldAlert className="h-4 w-4 text-slate-500" />
                                {summary.disciplinary_active}
                            </div>
                            <div className="text-xs text-slate-400">High/Critical: {summary.high_severity_open_cases}</div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-slate-500">SLA Watch</div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold text-amber-700">
                                <Clock3 className="h-4 w-4" />
                                {summary.disciplinary_sla_due_24h}
                            </div>
                            <div className="text-xs text-slate-400">Due within 24h</div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-slate-500">Escalation Risk</div>
                            <div className="mt-1 flex items-center gap-2 text-xl font-semibold text-red-700">
                                <AlertTriangle className="h-4 w-4" />
                                {summary.escalation_candidates}
                            </div>
                            <div className="text-xs text-slate-400">
                                Overdue: {summary.disciplinary_sla_overdue} | Missing deadline: {summary.disciplinary_missing_deadline}
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
                            <Label className="text-xs text-slate-500">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input
                                    placeholder="Case number, title, or subject"
                                    value={filters.q || ''}
                                    onChange={(event) => onFilter({ q: event.target.value })}
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(value) => onFilter({ status: value === NONE ? null : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Statuses</SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {status.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Case Type</Label>
                            <Select
                                value={filters.case_type ?? NONE}
                                onValueChange={(value) => onFilter({ case_type: value === NONE ? null : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All case types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Case Types</SelectItem>
                                    {caseTypes.map((caseType) => (
                                        <SelectItem key={caseType} value={caseType}>
                                            {caseType.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Severity</Label>
                            <Select
                                value={filters.severity ?? NONE}
                                onValueChange={(value) => onFilter({ severity: value === NONE ? null : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All severities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Severities</SelectItem>
                                    {severities.map((severity) => (
                                        <SelectItem key={severity} value={severity}>
                                            {severity}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Disciplinary SLA</Label>
                            <Select
                                value={filters.sla_window ?? NONE}
                                onValueChange={(value) => onFilter({ sla_window: value === NONE ? null : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All SLA windows" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All SLA Windows</SelectItem>
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
                                    const subjectName = hrCase.subject?.name ?? 'Unknown';
                                    const assigneeName = hrCase.assigned_to?.name ?? hrCase.assignedTo?.name ?? null;

                                    return (
                                        <TableRow key={hrCase.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Briefcase className="h-4 w-4 text-slate-400" />
                                                    <div>
                                                        <div className="font-medium">{hrCase.case_number}</div>
                                                        <div className="text-xs text-slate-500">{hrCase.title}</div>
                                                        {assigneeName ? (
                                                            <div className="text-xs text-slate-400">Assigned: {assigneeName}</div>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>{subjectName}</TableCell>
                                            <TableCell>
                                                <Badge className={badgeClassByCaseType[hrCase.case_type] ?? badgeClassByCaseType.other}>
                                                    {hrCase.case_type.replace(/_/g, ' ')}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={badgeClassByStatus[hrCase.status] ?? badgeClassByStatus.closed}>
                                                    {hrCase.status.replace(/_/g, ' ')}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={badgeClassBySeverity[hrCase.severity] ?? badgeClassBySeverity.low}>
                                                    {hrCase.severity}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{formatDate(hrCase.opened_at)}</TableCell>
                                            <TableCell>{formatDate(hrCase.closed_at)}</TableCell>
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
                                        <TableCell colSpan={8} className="py-16 text-center">
                                            <div className="flex flex-col items-center gap-3">
                                                <FolderOpen className="h-10 w-10 text-muted-foreground/50" />
                                                <div>
                                                    <p className="font-medium text-muted-foreground">No HR cases found</p>
                                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                                        {filters.q || filters.status || filters.case_type || filters.severity || filters.sla_window
                                                            ? 'Try adjusting your search or filter criteria.'
                                                            : 'No cases have been opened yet.'}
                                                    </p>
                                                </div>
                                                {can.manage && !filters.q && !filters.status && !filters.case_type && !filters.severity && (
                                                    <Button asChild size="sm" className="mt-2">
                                                        <Link href="/hr/cases/create">
                                                            <Plus className="mr-1.5 h-4 w-4" />
                                                            Open Case
                                                        </Link>
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
                            Showing {(cases.current_page - 1) * cases.per_page + 1} to{' '}
                            {Math.min(cases.current_page * cases.per_page, cases.total)} of{' '}
                            {cases.total} results
                        </p>
                        {cases.last_page > 1 && (
                            <LaravelPagination links={cases.links} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
