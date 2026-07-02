import {
    AddEmployeeDialog,
    type AddEmployeeFormData,
    type Department,
    DepartmentDialog,
    type DepartmentFilters,
    DepartmentsPane,
    HrTabs,
    type HrTabItem,
    NeedsTriageDialog,
    type OrgNode,
    type OrgPerson,
    OrgChartPane,
    PeopleHero,
    type PaginatedPeople,
    type PeopleFilters,
    PeoplePane,
    type PeopleRow,
    type PaginatedDepartments,
    type PaginatedPositions,
    type PositionFilters,
    type PositionParent,
    PositionDialog,
    type PositionRow,
    PositionsPane,
    type TriageData,
    type TriageRail,
    useHrTab,
} from '@/components/hr';
import { RehireWizard, type RehireTarget } from '@/components/hr/rehire-wizard';
import { PageLayout } from '@/components/page';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Briefcase, Building2, Network, Pin, Star, Users } from 'lucide-react';
import { useEffect, useState, type MouseEvent, type ReactNode } from 'react';

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
        pending_invites: number;
        type_counts: Record<string, number>;
        understaffed_positions: number;
    };
    triage: TriageData;
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
    can: { manage: boolean; recruit: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

const KNOWN_TABS = ['people', 'positions', 'departments', 'orgchart'];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function EmployeesIndex({
    profiles,
    sites,
    departments,
    filters,
    summary,
    triage,
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
    const [triageOpen, setTriageOpen] = useState(false);
    const [triageRail, setTriageRail] = useState<TriageRail>('compliance');
    const [tab, setTab] = useHrTab('people');
    // Fall back to People for unknown/retired tabs (e.g. an old ?tab=directory link).
    const activeTab = KNOWN_TABS.includes(tab) ? tab : 'people';
    const [posDialogOpen, setPosDialogOpen] = useState(false);
    const [editingPosition, setEditingPosition] = useState<PositionRow | null>(
        null,
    );
    const [deptDialogOpen, setDeptDialogOpen] = useState(false);
    const [editingDept, setEditingDept] = useState<Department | null>(null);
    const [rehireTarget, setRehireTarget] = useState<RehireTarget | null>(null);
    const [pins, setPins] = useState<string[]>([]);
    // '' = no explicit default chosen yet (so the star only shows once a user
    // sets one); the page still opens to People via useHrTab's default.
    const [defaultTab, setDefaultTab] = useState<string>('');
    const [tabCtx, setTabCtx] = useState<ShiftCtxState | null>(null);

    // Restore persisted default view + pinned tabs. The stored default is only
    // applied when opened without an explicit ?tab= — switched after mount so
    // SSR and the first client render still agree (no hydration mismatch).
    useEffect(() => {
        try {
            const sp = new URLSearchParams(window.location.search);
            const sd = window.localStorage.getItem('hrp.defaultTab');
            if (sd && KNOWN_TABS.includes(sd)) {
                setDefaultTab(sd);
                if (!sp.get('tab') && sd !== tab) setTab(sd);
            }
            const rawPins = window.localStorage.getItem('hrp.pins');
            if (rawPins) {
                const parsed: unknown = JSON.parse(rawPins);
                if (Array.isArray(parsed))
                    setPins(
                        parsed.filter(
                            (p): p is string =>
                                typeof p === 'string' && KNOWN_TABS.includes(p),
                        ),
                    );
            }
        } catch {
            /* ignore malformed storage */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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

    const setDefaultView = (id: string) => {
        setDefaultTab(id);
        try {
            window.localStorage.setItem('hrp.defaultTab', id);
        } catch {
            /* ignore */
        }
    };

    const togglePin = (id: string) => {
        setPins((prev) => {
            const next = prev.includes(id)
                ? prev.filter((p) => p !== id)
                : [...prev, id];
            try {
                window.localStorage.setItem('hrp.pins', JSON.stringify(next));
            } catch {
                /* ignore */
            }
            return next;
        });
    };

    // Pinned tabs float to the front (stable order within each group).
    const orderedTabs = [
        ...tabItems.filter((t) => pins.includes(t.id)),
        ...tabItems.filter((t) => !pins.includes(t.id)),
    ];

    const tabDecorations: Record<string, ReactNode> = {};
    tabItems.forEach((t) => {
        const isDefault = defaultTab === t.id;
        const isPinned = pins.includes(t.id);
        if (isDefault || isPinned) {
            tabDecorations[t.id] = (
                <span className="ml-0.5 inline-flex items-center gap-0.5">
                    {isDefault ? (
                        <Star className="h-3 w-3 fill-current text-status-warning" />
                    ) : null}
                    {isPinned ? <Pin className="h-3 w-3" /> : null}
                </span>
            );
        }
    });

    const openTabMenu = (id: string, e: MouseEvent) => {
        e.preventDefault();
        const item = tabItems.find((t) => t.id === id);
        setTabCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'Tab',
            meta: item?.label ?? '',
            items: [
                {
                    icon: <Star className="h-4 w-4" />,
                    label:
                        defaultTab === id
                            ? 'Default view'
                            : 'Set as default view',
                    tone: defaultTab === id ? 'primary' : undefined,
                    onClick: () => setDefaultView(id),
                },
                {
                    icon: <Pin className="h-4 w-4" />,
                    label: pins.includes(id) ? 'Unpin tab' : 'Pin tab',
                    onClick: () => togglePin(id),
                },
            ],
        });
    };

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

    const openTriage = (rail: TriageRail) => {
        setTriageRail(rail);
        setTriageOpen(true);
    };

    // "Needs attention" chips → the cross-cutting triage modal (Compliance /
    // Probation / Invites rails). Understaffed positions live on the Positions
    // tab, so that chip deep-links there rather than into the triage queue.
    const needs: { key: string; label: string; onClick: () => void }[] = [];
    if (summary.compliance_alerts > 0)
        needs.push({
            key: 'compliance',
            label: `${summary.compliance_alerts} compliance ${summary.compliance_alerts === 1 ? 'alert' : 'alerts'}`,
            onClick: () => openTriage('compliance'),
        });
    if (summary.on_probation > 0)
        needs.push({
            key: 'probation',
            label: `${summary.on_probation} on probation`,
            onClick: () => openTriage('probation'),
        });
    if (summary.pending_invites > 0)
        needs.push({
            key: 'invites',
            label: `${summary.pending_invites} pending ${summary.pending_invites === 1 ? 'invite' : 'invites'}`,
            onClick: () => openTriage('invites'),
        });
    if (summary.understaffed_positions > 0)
        needs.push({
            key: 'understaffed',
            label: `${summary.understaffed_positions} understaffed ${summary.understaffed_positions === 1 ? 'position' : 'positions'}`,
            onClick: () => router.visit('/hr/people?tab=positions'),
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
                            onInvite:
                                can.manage && summary.pending_invites > 0
                                    ? () => openTriage('invites')
                                    : undefined,
                            onStatActive: () => applyFilter('status', 'active'),
                            onStatNew: () => applyFilter('joined', '30'),
                            onStatProbation: () => applyFilter('probation', '1'),
                            onStatCompliance:
                                summary.compliance_alerts > 0
                                    ? () => openTriage('compliance')
                                    : () => router.visit('/hr/compliance'),
                        }}
                    />
                }
            >
                <HrTabs
                    value={activeTab}
                    onChange={setTab}
                    items={orderedTabs}
                    ariaLabel="People views"
                    className="mb-6"
                    decorations={tabDecorations}
                    onItemContextMenu={openTabMenu}
                    trailing={
                        <span className="ml-auto hidden px-2 text-[11px] font-medium text-muted-foreground sm:inline">
                            Right-click a tab to set default or pin
                        </span>
                    }
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
                        onRehire={
                            can.manage
                                ? (row: PeopleRow) => {
                                      if (!row.profile_id) return;
                                      setRehireTarget({
                                          profileId: row.profile_id,
                                          name: row.user.name,
                                          startDate: row.start_date,
                                          endDate: row.end_date ?? null,
                                          positionTitle: row.position_title,
                                          positionRole: row.position_role ?? null,
                                          employmentType: row.employment_type,
                                          hoursPerWeek: row.hours_per_week ?? null,
                                          primarySiteId: row.primary_site?.id ?? null,
                                          employmentHistory:
                                              row.employment_history ?? [],
                                      });
                                  }
                                : undefined
                        }
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

            <NeedsTriageDialog
                open={triageOpen}
                onClose={() => setTriageOpen(false)}
                initialRail={triageRail}
                summary={summary}
                triage={triage}
                canManage={can.manage}
            />

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
                // key remounts the wizard per target so useForm re-initialises
                // from the selected row (else editing shows stale/empty fields).
                <PositionDialog
                    key={editingPosition?.id ?? 'new'}
                    open={posDialogOpen}
                    onClose={() => setPosDialogOpen(false)}
                    position={editingPosition}
                    parentPositions={parentPositions}
                    departments={departments}
                    canRecruit={can.recruit}
                />
            ) : null}

            {canDept ? (
                <DepartmentDialog
                    key={editingDept?.id ?? 'new'}
                    open={deptDialogOpen}
                    onClose={() => setDeptDialogOpen(false)}
                    department={editingDept}
                    managers={departmentManagers}
                    parentOptions={departmentParents}
                    siteOptions={sites}
                />
            ) : null}

            {can.manage && rehireTarget ? (
                // key remounts per target so useForm re-initialises from the row.
                <RehireWizard
                    key={rehireTarget.profileId}
                    target={rehireTarget}
                    sites={sites}
                    onClose={() => setRehireTarget(null)}
                />
            ) : null}

            {tabCtx ? (
                <ShiftContextMenu
                    ctx={tabCtx}
                    onClose={() => setTabCtx(null)}
                />
            ) : null}
        </AppLayout>
    );
}
