/**
 * Safe work procedures (approved H&S drawing pg-hsn-63 — Stephan 23 Oct
 * 2026): one header with four filter chips, the register views as a
 * tier-two strip, then every procedure by its plain name with where it is up
 * to as stage dots (draft, under review, published) — the same stages its
 * record shows — its review date, owner, version and documents.
 */
import { ProcedureWizardDialog } from '@/components/health-safety/procedure-wizard-dialog';
import {
    ProcedureReviewWords,
    procedureCategory,
    procedureStage,
} from '@/components/health-safety/procedures/procedure-kit';
import type { ProcedurePickers } from '@/components/health-safety/procedures/types';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Ring, StageDots } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    PROCEDURE_REGISTER,
    procedureHref,
    procedureReturn,
} from '@/lib/health-safety/procedure-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    Clock,
    Copy,
    Eye,
    FileEdit,
    FileText,
    LayoutGrid,
    Plus,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Row = {
    provenance?: string;
    id: number;
    reference_number: string | null;
    title: string;
    purpose: string | null;
    category: string;
    status: string;
    version: number;
    review_date: string | null;
    working_status?: string | null;
    owner: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    sites_count: number;
    roles_count: number;
    documents_count: number;
};
type Filters = {
    q: string | null;
    tab: string;
    category: string | null;
    status: string | null;
    site_id: number | null;
    review_state: string | null;
};
type Props = ProcedurePickers & {
    procedures: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        last_page: number;
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    filters: Filters;
    tabCounts: Record<string, number>;
    can: { view: boolean; create: boolean; manage: boolean; approve: boolean };
};

const VIEWS: {
    key: string;
    label: string;
    title: string;
    icon: GroupedProfileNavTab['icon'];
}[] = [
    { key: 'all', label: 'All', title: 'All procedures', icon: LayoutGrid },
    { key: 'approved', label: 'Published', title: 'Published procedures', icon: CheckCircle2 },
    { key: 'under_review', label: 'Under review', title: 'Under review', icon: Clock },
    { key: 'draft', label: 'Drafts', title: 'Drafts', icon: FileEdit },
    { key: 'review_due', label: 'Review due', title: 'Review due', icon: Clock },
    { key: 'archived', label: 'Archived', title: 'Archived procedures', icon: Archive },
];

export default function ProceduresIndex({
    procedures,
    filters,
    tabCounts,
    can,
    sites,
    roles,
    trainingCourses,
    owners,
    categories,
    can_apply_organisation_wide,
}: Props) {
    const { url } = usePage();
    const returnTo = procedureReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(() => {
        if (
            can.create &&
            new URLSearchParams(url.split('?')[1]).get('new') === '1'
        )
            setCreating(true);
    }, [can.create, url]);
    const params = (next: Partial<Filters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value != null && value !== '',
            ),
        ) as Record<string, string | number>;
    const query = (next: Partial<Filters> = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const go = (next: Partial<Filters>) =>
        router.get(PROCEDURE_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const views: GroupedProfileNavTab[] = VIEWS.map((view) => ({
        key: view.key,
        label: view.label,
        icon: view.icon,
        count: tabCounts[view.key] ?? 0,
    }));
    const href = (id: number) => procedureHref(id, { returnTo });
    const pickers = {
        sites,
        roles,
        trainingCourses,
        owners,
        categories,
        can_apply_organisation_wide,
    };
    const filtered = Object.entries(filters).some(
        ([key, value]) => key !== 'tab' && value,
    );

    const actionsFor = (row: Row): MenuItem[] =>
        compactMenu([
            {
                label: 'Open procedure',
                icon: Eye,
                onClick: () => router.visit(href(row.id)),
            },
            !!row.working_status &&
                row.working_status !== row.status && {
                    label:
                        row.working_status === 'under_review'
                            ? 'Open the draft under review'
                            : 'Open the working draft',
                    icon: FileEdit,
                    onClick: () =>
                        router.visit(
                            procedureHref(row.id, {
                                revision: 'working',
                                returnTo,
                            }),
                        ),
                },
            { separator: true },
            {
                label: 'Copy link',
                icon: Copy,
                onClick: () =>
                    void navigator.clipboard?.writeText(
                        window.location.origin + procedureHref(row.id),
                    ),
            },
        ]);

    const columns: RecordTableColumn<Row>[] = [
        {
            key: 'procedure',
            label: 'Procedure',
            className: 'min-w-[16rem] max-w-[24rem]',
            cell: (row) => (
                <RecordIdentity
                    title={
                        <Link
                            href={href(row.id)}
                            onClick={(event) => event.stopPropagation()}
                            className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {row.title}
                        </Link>
                    }
                    sub={[row.reference_number, procedureCategory(row.category)]
                        .filter(Boolean)
                        .join(' · ')}
                />
            ),
        },
        {
            key: 'stage',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (row) => {
                const stage = procedureStage(row);
                return stage ? (
                    <StageDots
                        stage={stage.stage}
                        of={3}
                        label={stage.label}
                        note={stage.note}
                        tone={stage.tone}
                    />
                ) : (
                    <StatusBadge variant="neutral">
                        <Archive className="size-3" aria-hidden="true" />
                        Archived
                    </StatusBadge>
                );
            },
        },
        {
            key: 'review',
            label: 'Review',
            nowrap: true,
            cell: (row) =>
                row.status === 'archived' ? (
                    <span className="text-muted-foreground">
                        Review not scheduled
                    </span>
                ) : (
                    <ProcedureReviewWords date={row.review_date} />
                ),
        },
        {
            key: 'owner',
            label: 'Owner',
            nowrap: true,
            cell: (row) =>
                row.owner ? (
                    <RecordPerson name={row.owner.name} />
                ) : (
                    <span className="text-muted-foreground">No owner</span>
                ),
        },
        {
            key: 'version',
            label: 'Version',
            nowrap: true,
            cell: (row) => <span className="tabular-nums">{row.version}</span>,
        },
        {
            key: 'documents',
            label: 'Documents',
            nowrap: true,
            cell: (row) => (
                <span className="tabular-nums">{row.documents_count}</span>
            ),
        },
    ];

    const total = procedures.total ?? procedures.data.length;
    const current = VIEWS.find((view) => view.key === filters.tab);
    const pager = (
        <>
            <p className="text-subtle">
                Showing {procedures.data.length} of {total}
            </p>
            {procedures.last_page > 1 ? (
                <LaravelPagination links={procedures.links} />
            ) : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health and safety', href: '/health-safety' },
                { title: 'Safe work procedures', href: PROCEDURE_REGISTER },
            ]}
        >
            <Head title="Safe work procedures" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            wrapTitle
                            icon={FileText}
                            title="Safe work procedures"
                            subline="Current instructions, controlled revisions and staff sign-off"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                go({
                                                    q: search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search procedures"
                                        ariaLabel="Search procedures"
                                    />
                                    <PageHeaderGlassButton asChild>
                                        <a
                                            href={`${PROCEDURE_REGISTER}/export?${query()}`}
                                        >
                                            Export
                                        </a>
                                    </PageHeaderGlassButton>
                                    {can.create && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => setCreating(true)}
                                        >
                                            Add procedure
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="All sites"
                                        allValue=""
                                        value={
                                            filters.site_id
                                                ? String(filters.site_id)
                                                : ''
                                        }
                                        onChange={(value) =>
                                            go({
                                                site_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        options={[
                                            { value: '', label: 'All sites' },
                                            ...sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All categories"
                                        allValue=""
                                        value={filters.category ?? ''}
                                        onChange={(value) =>
                                            go({ category: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All categories',
                                            },
                                            ...categories,
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All states"
                                        allValue=""
                                        value={filters.status ?? ''}
                                        onChange={(value) =>
                                            go({ status: value || null })
                                        }
                                        options={[
                                            { value: '', label: 'All states' },
                                            { value: 'draft', label: 'Draft' },
                                            {
                                                value: 'under_review',
                                                label: 'Under review',
                                            },
                                            {
                                                value: 'approved',
                                                label: 'Published',
                                            },
                                            {
                                                value: 'archived',
                                                label: 'Archived',
                                            },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All review dates"
                                        allValue=""
                                        value={filters.review_state ?? ''}
                                        onChange={(value) =>
                                            go({ review_state: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All review dates',
                                            },
                                            {
                                                value: 'overdue',
                                                label: 'Overdue',
                                            },
                                            {
                                                value: 'today',
                                                label: 'Due today',
                                            },
                                            {
                                                value: 'due_soon',
                                                label: 'Due within 30 days',
                                            },
                                            {
                                                value: 'current',
                                                label: 'Current',
                                            },
                                            {
                                                value: 'none',
                                                label: 'Not scheduled',
                                            },
                                        ]}
                                    />
                                    {filtered && (
                                        <PageHeaderFilterButton
                                            icon={X}
                                            onClick={() =>
                                                go({
                                                    q: null,
                                                    category: null,
                                                    status: null,
                                                    site_id: null,
                                                    review_state: null,
                                                })
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderFilterButton>
                                    )}
                                </>
                            }
                            rail={
                                <SafetyWorkspaceRail
                                    hub="prevention"
                                    current="procedures"
                                />
                            }
                        />
                    }
                    tabs={
                        <TierTwoTabs
                            tabs={views}
                            activeTab={filters.tab}
                            onTab={(tab) => {
                                if (tab !== filters.tab) go({ tab });
                            }}
                            testIdPrefix="procedure-register"
                            ariaLabel="Procedure views"
                            panelId="procedure-register-panel"
                            // Views carry no href, so the strip renders its own buttons.
                            renderLink={() => null}
                        />
                    }
                >
                    <div
                        id="procedure-register-panel"
                        role="tabpanel"
                        aria-label={current?.title ?? 'Procedures'}
                        className="min-w-0"
                    >
                        <RecordTable
                            title={current?.title ?? 'Procedures'}
                            sub={
                                procedures.from && procedures.to
                                    ? `Showing ${procedures.from}–${procedures.to} of ${total}`
                                    : `Showing ${procedures.data.length} of ${total}`
                            }
                            ariaLabel="Procedure register table"
                            rows={procedures.data}
                            rowKey={(row) => row.id}
                            onOpen={(row) => router.visit(href(row.id))}
                            actionsFor={actionsFor}
                            menuTitle={(row) =>
                                row.reference_number
                                    ? `${row.reference_number} · ${row.title}`
                                    : row.title
                            }
                            menuIcon={FileText}
                            columns={columns}
                            footer={procedures.data.length ? pager : undefined}
                            empty={
                                <div className="grid justify-items-start gap-3 p-4">
                                    <p className="sg-quiet">
                                        <Ring tone="none" />
                                        <span>
                                            No procedures match this view. Try
                                            another view or clear the filters.
                                        </span>
                                    </p>
                                    {can.create ? (
                                        <Button
                                            onClick={() => setCreating(true)}
                                        >
                                            Add procedure
                                        </Button>
                                    ) : null}
                                </div>
                            }
                        />
                    </div>
                </PageLayout>
                {creating && (
                    <ProcedureWizardDialog
                        open
                        options={pickers}
                        onClose={() => drafts.request(() => setCreating(false))}
                        onOpenProcedure={(id) => {
                            setCreating(false);
                            router.visit(
                                procedureHref(id, {
                                    revision: 'working',
                                    returnTo,
                                }),
                            );
                        }}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
