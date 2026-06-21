import {
    AddEmployeeDialog,
    type AddEmployeeFormData,
    type Department,
    DepartmentDialog,
    type DepartmentFilters,
    DepartmentsPane,
    HrTabs,
    type HrTabItem,
    type OrgNode,
    type OrgPerson,
    OrgChartPane,
    PeopleHero,
    type PaginatedDepartments,
    type PaginatedPositions,
    type PositionFilters,
    type PositionParent,
    PositionDialog,
    type PositionRow,
    PositionsPane,
    useHrTab,
} from '@/components/hr';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    Network,
    Search,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface EmployeeRow {
    id: number;
    profile_id: number | null;
    employee_number: string | null;
    position_title: string | null;
    employment_type: string | null;
    department: string | null;
    is_active: boolean;
    start_date: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
}

interface Props {
    profiles: {
        data: EmployeeRow[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    filters: {
        q: string;
        status: string | null;
        site_id: string | null;
        department: string | null;
        employment_type: string | null;
        joined: string | null;
        probation: string | null;
    };
    summary: {
        active: number;
        inactive: number;
        new_hires: number;
        on_probation: number;
        compliance_alerts: number;
        type_counts: Record<string, number>;
    };
    formData: AddEmployeeFormData | null;
    positions: PaginatedPositions;
    parentPositions: PositionParent[];
    positionFilters: PositionFilters;
    departmentsPane: PaginatedDepartments;
    departmentManagers: Array<{ id: number; name: string }>;
    departmentParents: Array<{ id: number; name: string }>;
    departmentFilters: DepartmentFilters;
    canDept: boolean;
    orgHierarchy: OrgNode[];
    orgPeople: OrgPerson[];
    canOrgManage: boolean;
    can: { manage: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

const NONE = '__none__';

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

const AVATAR_COLORS = [
    'bg-status-info-bg text-status-info dark:text-status-info',
    'bg-primary/15 text-primary dark:text-primary/70',
    'bg-status-success-bg text-status-success dark:text-status-success',
    'bg-status-warning-bg text-status-warning dark:text-status-warning',
    'bg-status-critical-bg text-status-critical dark:text-status-critical',
    'bg-status-info-bg text-status-info dark:text-status-info',
    'bg-status-critical-bg text-status-critical dark:text-status-critical',
    'bg-primary/15 text-primary dark:text-primary/70',
];

function getAvatarColor(id: number): string {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

const TYPE_STYLES: Record<string, string> = {
    full_time:
        'bg-status-info-bg text-status-info border-status-info/30 dark:bg-status-info-bg dark:text-status-info dark:border-status-info/30',
    part_time:
        'bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30',
    casual: 'bg-primary/10 text-primary border-primary dark:bg-primary/10 dark:text-primary/70 dark:border-primary/30',
    fixed_term:
        'bg-status-info-bg text-status-info border-status-info/30 dark:bg-status-info-bg dark:text-status-info dark:border-status-info/30',
    contractor:
        'bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30',
};

function formatLabel(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(value?: string | null): string {
    if (!value) return '\u2014';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function EmployeesIndex({
    profiles,
    sites,
    departments,
    filters,
    summary,
    formData,
    positions,
    parentPositions,
    positionFilters,
    departmentsPane,
    departmentManagers,
    departmentParents,
    departmentFilters,
    canDept,
    orgHierarchy,
    orgPeople,
    canOrgManage,
    can,
}: Props) {
    const [addOpen, setAddOpen] = useState(false);
    const [tab, setTab] = useHrTab('people');
    const KNOWN_TABS = ['people', 'positions', 'departments', 'orgchart'];
    // Fall back to People for unknown/retired tabs (e.g. an old ?tab=directory link).
    const activeTab = KNOWN_TABS.includes(tab) ? tab : 'people';
    const [posDialogOpen, setPosDialogOpen] = useState(false);
    const [editingPosition, setEditingPosition] = useState<PositionRow | null>(
        null,
    );
    const [deptDialogOpen, setDeptDialogOpen] = useState(false);
    const [editingDept, setEditingDept] = useState<Department | null>(null);

    const tabItems: HrTabItem[] = [
        {
            id: 'people',
            label: 'People',
            icon: Users,
            tone: 'primary',
            badge: profiles.total,
        },
        {
            id: 'positions',
            label: 'Positions',
            icon: Briefcase,
            tone: 'violet',
            badge: positions.total,
        },
        {
            id: 'departments',
            label: 'Departments',
            icon: Building2,
            tone: 'success',
            badge: departmentsPane.total,
        },
        {
            id: 'orgchart',
            label: 'Org chart',
            icon: Network,
            tone: 'warning',
        },
    ];

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/people',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    function clearFilters() {
        router.get('/hr/people', {}, { preserveState: true, replace: true });
    }

    function submitExport() {
        const token =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';
        const form = document.createElement('form');
        form.action = '/hr/import-export/export';
        form.method = 'POST';
        form.style.display = 'none';

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = token;
        form.appendChild(csrfInput);

        Object.entries(filters).forEach(([key, value]) => {
            if (!value) return;

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    const hasFilters = !!(
        filters.q ||
        filters.status ||
        filters.site_id ||
        filters.department ||
        filters.employment_type ||
        filters.joined ||
        filters.probation
    );

    const needs: { key: string; label: string; onClick: () => void }[] = [];
    if (summary.compliance_alerts > 0)
        needs.push({
            key: 'compliance',
            label: `${summary.compliance_alerts} compliance ${summary.compliance_alerts === 1 ? 'alert' : 'alerts'}`,
            onClick: () => router.visit('/hr/compliance'),
        });
    if (summary.on_probation > 0)
        needs.push({
            key: 'probation',
            label: `${summary.on_probation} on probation`,
            onClick: () => applyFilter('probation', '1'),
        });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="People" />

            <PageLayout
                hero={
                    <PeopleHero
                        totalPeople={profiles.total}
                        siteCount={sites.length}
                        summary={summary}
                        canManage={can.manage}
                        needs={needs}
                        handlers={{
                            onAdd: formData ? () => setAddOpen(true) : undefined,
                            onImport: can.manage
                                ? () => router.visit('/hr/import-export')
                                : undefined,
                            onExport: can.manage ? submitExport : undefined,
                            onStatActive: () => applyFilter('status', 'active'),
                            onStatNew: () => applyFilter('joined', '30'),
                            onStatProbation: () => applyFilter('probation', '1'),
                            onStatCompliance: () =>
                                router.visit('/hr/compliance'),
                        }}
                    />
                }
            >
                <HrTabs
                    value={activeTab}
                    onChange={setTab}
                    items={tabItems}
                    ariaLabel="People views"
                />

                {activeTab === 'people' && (
                    <>
                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or email..."
                            defaultValue={filters.q}
                            className="w-64 pl-9"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter')
                                    applyFilter(
                                        'q',
                                        (e.target as HTMLInputElement).value,
                                    );
                            }}
                        />
                    </div>

                    <Select
                        value={filters.status || NONE}
                        onValueChange={(v) =>
                            applyFilter('status', v === NONE ? null : v)
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.site_id || NONE}
                        onValueChange={(v) =>
                            applyFilter('site_id', v === NONE ? null : v)
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Sites</SelectItem>
                            {sites.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {departments.length > 0 && (
                        <Select
                            value={filters.department || NONE}
                            onValueChange={(v) =>
                                applyFilter('department', v === NONE ? null : v)
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="Department" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    All Departments
                                </SelectItem>
                                {departments.map((d) => (
                                    <SelectItem key={d.id} value={String(d.id)}>
                                        {d.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <Select
                        value={filters.employment_type || NONE}
                        onValueChange={(v) =>
                            applyFilter(
                                'employment_type',
                                v === NONE ? null : v,
                            )
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Types</SelectItem>
                            <SelectItem value="full_time">Full Time</SelectItem>
                            <SelectItem value="part_time">Part Time</SelectItem>
                            <SelectItem value="casual">Casual</SelectItem>
                            <SelectItem value="fixed_term">
                                Fixed Term
                            </SelectItem>
                            <SelectItem value="contractor">
                                Contractor
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Employee
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                            Employee #
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Position
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                            Department
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                            Type
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase xl:table-cell">
                                            Site
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase xl:table-cell">
                                            Start Date
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {profiles.data.map((p) => (
                                        <tr
                                            key={p.id}
                                            className="group cursor-pointer transition-colors hover:bg-muted/40"
                                            onClick={() => {
                                                if (p.profile_id)
                                                    router.visit(
                                                        `/hr/people/${p.profile_id}`,
                                                    );
                                            }}
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <div
                                                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(p.id)}`}
                                                    >
                                                        {getInitials(
                                                            p.user.name,
                                                        )}
                                                    </div>
                                                    <div className="min-w-0">
                                                        {p.profile_id ? (
                                                            <Link
                                                                href={`/hr/people/${p.profile_id}`}
                                                                className="font-medium text-foreground group-hover:text-primary"
                                                                onClick={(e) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                {p.user.name}
                                                            </Link>
                                                        ) : (
                                                            <span className="font-medium">
                                                                {p.user.name}
                                                            </span>
                                                        )}
                                                        <div className="truncate text-xs text-muted-foreground">
                                                            {p.user.email}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground lg:table-cell">
                                                {p.employee_number || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-sm">
                                                {p.position_title || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                                                {p.department || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                {p.employment_type ? (
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[11px] ${TYPE_STYLES[p.employment_type] || ''}`}
                                                    >
                                                        {formatLabel(
                                                            p.employment_type,
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {'\u2014'}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground xl:table-cell">
                                                {p.primary_site?.name ||
                                                    '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground xl:table-cell">
                                                {formatDate(p.start_date)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        p.is_active
                                                            ? 'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success'
                                                            : 'dark:bg-muted-foreground/80/10 border-border bg-muted text-muted-foreground dark:border-border/30 dark:text-muted-foreground'
                                                    }
                                                >
                                                    {p.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                    {profiles.data.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="px-4 py-16 text-center"
                                            >
                                                <Users className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                <p className="font-medium text-muted-foreground">
                                                    No employees found
                                                </p>
                                                <p className="mt-1 text-sm text-muted-foreground/70">
                                                    {hasFilters
                                                        ? 'Try adjusting your filters'
                                                        : 'Add employees to get started'}
                                                </p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {profiles.last_page > 1 && (
                    <LaravelPagination links={profiles.links} />
                )}
                    </>
                )}

                {tab === 'positions' && (
                    <PositionsPane
                        positions={positions}
                        departments={departments}
                        filters={positionFilters}
                        canManage={can.manage}
                        onCreate={() => {
                            setEditingPosition(null);
                            setPosDialogOpen(true);
                        }}
                        onEdit={(p) => {
                            setEditingPosition(p);
                            setPosDialogOpen(true);
                        }}
                    />
                )}

                {tab === 'departments' && (
                    <DepartmentsPane
                        departments={departmentsPane}
                        filters={departmentFilters}
                        canManage={canDept}
                        onCreate={() => {
                            setEditingDept(null);
                            setDeptDialogOpen(true);
                        }}
                        onEdit={(d) => {
                            setEditingDept(d);
                            setDeptDialogOpen(true);
                        }}
                    />
                )}

                {tab === 'orgchart' && (
                    <OrgChartPane
                        hierarchy={orgHierarchy}
                        people={orgPeople}
                        canManage={canOrgManage}
                    />
                )}
            </PageLayout>

            {formData ? (
                <AddEmployeeDialog
                    open={addOpen}
                    onClose={() => setAddOpen(false)}
                    formData={formData}
                    departments={departments}
                    sites={sites}
                />
            ) : null}

            {can.manage ? (
                <PositionDialog
                    open={posDialogOpen}
                    onClose={() => setPosDialogOpen(false)}
                    position={editingPosition}
                    parentPositions={parentPositions}
                    departments={departments}
                />
            ) : null}

            {canDept ? (
                <DepartmentDialog
                    open={deptDialogOpen}
                    onClose={() => setDeptDialogOpen(false)}
                    department={editingDept}
                    managers={departmentManagers}
                    parentOptions={departmentParents}
                />
            ) : null}
        </AppLayout>
    );
}
