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
    type PaginatedPeople,
    type PeopleFilters,
    PeoplePane,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Briefcase, Building2, Network, Users } from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Props {
    profiles: PaginatedPeople;
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    filters: PeopleFilters;
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
                    <PeoplePane
                        profiles={profiles}
                        filters={filters}
                        sites={sites}
                        departments={departments}
                        managers={formData?.managers ?? []}
                        canManage={can.manage}
                        onAdd={formData ? () => setAddOpen(true) : undefined}
                    />
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
