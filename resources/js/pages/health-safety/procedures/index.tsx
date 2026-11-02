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
import {
    LeadBlock,
    RegisterBlocks,
    RegisterBoxes,
    RegisterMoreFilters,
    RegisterRailLine,
    overdueNote,
    shortDay,
    type LeadRow,
} from '@/components/health-safety/register-kit';
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
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import type { SharedData } from '@/types';
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
    FolderX,
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
    hero?: { attention: { review_due_soon: number; review_overdue: number; coverage_gaps: number } };
    /** G4-14: the lead block — overdue reviews (with owner) and the areas with no published procedure. */
    register_summary?: {
        lead: Array<{ id: number; title: string; review_date: string; owner: string | null }>;
        review_overdue: number;
        gap_categories: string[];
    };
};

const VIEWS: {
    key: string;
    label: string;
    title: string;
    icon: GroupedProfileNavTab['icon'];
}[] = [
    { key: 'all', label: 'All', title: 'All procedures', icon: LayoutGrid },
    { key: 'under_review', label: 'Under review', title: 'Under review', icon: Clock },
    { key: 'draft', label: 'Drafts', title: 'Drafts', icon: FileEdit },
    { key: 'review_due', label: 'Review due', title: 'Review due', icon: Clock },
    { key: 'archived', label: 'Archived', title: 'Archived procedures', icon: Archive },
];
/** Reached from the Published box; on the strip only while it's the open view. */
const PUBLISHED = { key: 'approved', label: 'Published', title: 'Published procedures', icon: CheckCircle2 };

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
    hero,
    register_summary: summary,
}: Props) {
    const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
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
    const shownViews = [...VIEWS, ...(filters.tab === 'approved' ? [PUBLISHED] : [])];
    const views: GroupedProfileNavTab[] = shownViews.map((view) => ({
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
    const current = shownViews.find((view) => view.key === filters.tab);
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

    // Header links keep the Site only.
    const siteLink = (tab: string) => `${PROCEDURE_REGISTER}?${new URLSearchParams({ tab, ...(filters.site_id ? { site_id: String(filters.site_id) } : {}) })}`;
    const gaps = summary?.gap_categories ?? [];
    const lead: LeadRow[] = [
        ...(summary?.lead ?? []).map((row): LeadRow => ({
            key: `review-${row.id}`,
            when: { day: shortDay(row.review_date), note: overdueNote(row.review_date) },
            title: row.title,
            sub: [`review overdue since ${shortDay(row.review_date)}`, row.owner ? `owner ${row.owner}` : 'No owner yet'].join(' · '),
            chip: { label: 'Review overdue', tone: 'critical' },
            action: can.manage ? { label: 'Start review', href: procedureHref(row.id, { command: 'record-review', returnTo }), ariaLabel: `Start review: ${row.title}` } : null,
            who: can.manage ? null : row.owner ? `${row.owner} reviews it` : 'Health and safety review it',
        })),
        ...(gaps.length
            ? [{
                  key: 'gaps',
                  title: 'No procedure yet',
                  sub: gaps.map((category, index) => (index === 0 ? procedureCategory(category) : procedureCategory(category).toLowerCase())).join(' · '),
                  chip: { label: gaps.length === 1 ? '1 area' : `${gaps.length} areas`, tone: 'warning' as const },
                  action: can.create ? { label: 'Add procedure', onClick: () => setCreating(true) } : null,
                  who: can.create ? null : 'Health and safety add them',
              } satisfies LeadRow]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('prevention', safetyCan),
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
                            subline="Current instructions and who has read them"
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
                            strip={
                                hero ? (
                                    <RegisterBoxes
                                        boxes={[
                                            { key: 'review_overdue', label: 'Reviews overdue', value: hero.attention.review_overdue, alert: true, icon: Clock, href: siteLink('review_due') },
                                            { key: 'coverage_gaps', label: 'Areas without a procedure', value: hero.attention.coverage_gaps, alert: true, icon: FolderX, href: siteLink('all') },
                                            { key: 'review_due_soon', label: 'Reviews due · next 30 days', value: hero.attention.review_due_soon, icon: Clock, href: siteLink('review_due') },
                                            { key: 'published', label: 'Published', value: tabCounts.approved ?? null, icon: CheckCircle2, href: siteLink('approved') },
                                        ]}
                                    />
                                ) : undefined
                            }
                            rail={
                                <RegisterRailLine
                                    rail={<SafetyWorkspaceRail hub="prevention" current="procedures" />}
                                    filters={
                                        <>
                                            <PageHeaderFilterSelect
                                                label={`All sites · ${sites.length}`}
                                                allValue=""
                                                value={filters.site_id ? String(filters.site_id) : ''}
                                                onChange={(value) => go({ site_id: value ? Number(value) : null })}
                                                options={[
                                                    { value: '', label: 'All sites' },
                                                    ...sites.map((site) => ({ value: String(site.id), label: site.name })),
                                                ]}
                                            />
                                            <RegisterMoreFilters
                                                groups={[
                                                    { key: 'category', label: 'Category', value: filters.category ?? '', allValue: '', options: [{ value: '', label: 'All categories' }, ...categories], onChange: (value) => go({ category: value || null }) },
                                                    {
                                                        key: 'status',
                                                        label: 'State',
                                                        value: filters.status ?? '',
                                                        allValue: '',
                                                        options: [
                                                            { value: '', label: 'All states' },
                                                            { value: 'draft', label: 'Draft' },
                                                            { value: 'under_review', label: 'Under review' },
                                                            { value: 'approved', label: 'Published' },
                                                            { value: 'archived', label: 'Archived' },
                                                        ],
                                                        onChange: (value) => go({ status: value || null }),
                                                    },
                                                    {
                                                        key: 'review',
                                                        label: 'Review date',
                                                        value: filters.review_state ?? '',
                                                        allValue: '',
                                                        options: [
                                                            { value: '', label: 'All review dates' },
                                                            { value: 'overdue', label: 'Overdue' },
                                                            { value: 'today', label: 'Due today' },
                                                            { value: 'due_soon', label: 'Due within 30 days' },
                                                            { value: 'current', label: 'Current' },
                                                            { value: 'none', label: 'Not scheduled' },
                                                        ],
                                                        onChange: (value) => go({ review_state: value || null }),
                                                    },
                                                ]}
                                                onClear={() => go({ category: null, status: null, review_state: null })}
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
                        className="grid min-w-0 gap-5"
                    >
                        {summary ? (
                            <RegisterBlocks
                                lead={
                                    <LeadBlock
                                        rows={lead}
                                        empty="No reviews are overdue and every area has a published procedure."
                                        more={
                                            summary.review_overdue > summary.lead.length
                                                ? { text: `${summary.review_overdue - summary.lead.length} more reviews are overdue`, action: { label: 'See all', href: siteLink('review_due') } }
                                                : null
                                        }
                                    />
                                }
                            />
                        ) : null}
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
