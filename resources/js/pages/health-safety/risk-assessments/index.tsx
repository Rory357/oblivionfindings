import {
    buildRaCtxItems,
    RaTable,
    type RaCtxHandlers,
} from '@/components/health-safety/risk-assessments/ra-table';
import { RaWizardDialog } from '@/components/health-safety/risk-assessments/ra-wizard-dialog';
import type {
    RaPickers,
    RaRow,
} from '@/components/health-safety/risk-assessments/types';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { ShiftContextMenu, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    RISK_REGISTER,
    riskHref,
    riskReturn,
    type RiskCommand,
} from '@/lib/health-safety/risk-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    Clock,
    FileDown,
    FileEdit,
    LayoutGrid,
    Plus,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState, type MouseEvent } from 'react';

interface Filters {
    tab: string;
    status: string | null;
    risk_level: string | null;
    due_for_review: string | null;
    risk_acceptable: string | null;
    site_id: number | null;
    client_id: number | null;
    hs_event_id: number | null;
    search: string | null;
}
interface Props {
    assessments: {
        data: RaRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    tabCounts: {
        all: number;
        active: number;
        drafts: number;
        due: number;
        high: number;
        closed: number;
    };
    hero: {
        total: number;
        active: number;
        high_extreme_active: number;
        drafts: number;
        under_review: number;
        due_for_review: number;
        residual_not_acceptable: number;
        awaiting_approval: number;
        compliance: {
            reviews_overdue: number;
            high_extreme_without_approval: number;
            residual_not_acceptable: number;
            pct_active_scheduled: number;
        };
    };
    pickers: RaPickers;
    can: { manage: boolean; viewReports?: boolean };
    filters: Filters;
}
export default function RiskAssessmentsIndex({
    assessments,
    tabCounts,
    hero,
    pickers,
    can,
    filters,
}: Props) {
    const returnTo = riskReturn(usePage().url);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const drafts = useSafetyWorkspaceDrafts();
    useEffect(() => setSearch(filters.search ?? ''), [filters.search]);
    const params = (next: Partial<Filters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, v]) => v != null && v !== '',
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
        router.get(RISK_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const recordHref = (id: number, command?: RiskCommand) =>
        riskHref(id, { returnTo, command });
    const openDetail = (id: number, command?: RiskCommand) => {
        setCtx(null);
        router.visit(recordHref(id, command));
    };
    const handlers: RaCtxHandlers = {
        onView: (r) => openDetail(r.id),
        onEdit: (r) => openDetail(r.id),
        onApprove: (r) => openDetail(r.id, 'approve'),
        onReview: (r) => openDetail(r.id, 'review'),
        onResidual: (r) => openDetail(r.id, 'residual'),
        onSupersede: (r) => openDetail(r.id),
        onArchive: (r) => openDetail(r.id, 'archive'),
        onCopyLink: (r) => {
            void navigator.clipboard?.writeText(
                window.location.origin + recordHref(r.id),
            );
            setCtx(null);
        },
        onOpenCurrent: openDetail,
    };
    const onCtx = (e: MouseEvent, row: RaRow) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: row.status.replaceAll('_', ' '),
            meta: row.reference_number + ' · ' + row.title,
            items: buildRaCtxItems(row, can.manage, handlers),
        });
    };
    const tabs = [
        { key: 'all', label: 'All', icon: LayoutGrid, count: tabCounts.all },
        {
            key: 'active',
            label: 'Active',
            icon: ShieldAlert,
            count: tabCounts.active,
        },
        {
            key: 'drafts',
            label: 'Drafts',
            icon: FileEdit,
            count: tabCounts.drafts,
        },
        {
            key: 'due',
            label: 'Due for review',
            icon: Clock,
            count: tabCounts.due,
        },
        {
            key: 'high',
            label: 'High & extreme',
            icon: TriangleAlert,
            count: tabCounts.high,
        },
        {
            key: 'closed',
            label: 'Historical',
            icon: Archive,
            count: tabCounts.closed,
        },
    ];
    const selected = Object.entries(filters).some(
        ([key, value]) => key !== 'tab' && value != null && value !== '',
    );
    const select = (
        label: string,
        key: keyof Filters,
        options: { value: string; label: string }[],
        numeric = false,
    ) => (
        <PageHeaderFilterSelect
            label={label}
            value={String(filters[key] ?? '')}
            allValue=""
            options={[
                { value: '', label: 'All ' + label.toLowerCase() },
                ...options,
            ]}
            onChange={(v) =>
                go({ [key]: v ? (numeric ? Number(v) : v) : null })
            }
        />
    );
    const metricHref = (next: Partial<Filters>) =>
        RISK_REGISTER + '?' + query(next);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Risk assessments', href: RISK_REGISTER },
            ]}
        >
            <Head title="Risk assessments" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            frontline
                            title="Risk assessments"
                            icon={ShieldAlert}
                            subline="Assess risk, record controls and keep reviews up to date"
                            mobileSummary={
                                assessments.total +
                                ' matching assessments · ' +
                                hero.due_for_review +
                                ' due for review'
                            }
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter')
                                                go({
                                                    search:
                                                        search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search assessments…"
                                        ariaLabel="Search risk assessments"
                                    />
                                    {can.viewReports && (
                                        <PageHeaderGlassButton
                                            icon={FileDown}
                                            asChild
                                        >
                                            <a
                                                href={
                                                    '/health-safety/reports/risk-assessment-register?' +
                                                    query()
                                                }
                                            >
                                                Export register
                                            </a>
                                        </PageHeaderGlassButton>
                                    )}
                                    {can.manage && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => setCreating(true)}
                                        >
                                            New risk assessment
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Active"
                                        href={metricHref({ tab: 'active' })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.active}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            In force
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Due for review"
                                        href={metricHref({ tab: 'due' })}
                                        tone={
                                            hero.due_for_review
                                                ? 'warning'
                                                : 'brand'
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.due_for_review}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Due today or overdue
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="High & extreme"
                                        href={metricHref({ tab: 'high' })}
                                        tone={
                                            tabCounts.high
                                                ? 'critical'
                                                : 'brand'
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {tabCounts.high}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Matching assessments
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Awaiting approval"
                                        href={metricHref({ tab: 'drafts' })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.awaiting_approval}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Draft assessments
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            filters={
                                <>
                                    {select(
                                        'Status',
                                        'status',
                                        [
                                            'active',
                                            'under_review',
                                            'draft',
                                            'superseded',
                                            'archived',
                                        ].map((value) => ({
                                            value,
                                            label: value.replaceAll('_', ' '),
                                        })),
                                    )}
                                    {select(
                                        'Risk level',
                                        'risk_level',
                                        [
                                            'low',
                                            'medium',
                                            'high',
                                            'extreme',
                                        ].map((value) => ({
                                            value,
                                            label: value,
                                        })),
                                    )}
                                    {select(
                                        'Sites',
                                        'site_id',
                                        pickers.sites.map((p) => ({
                                            value: String(p.id),
                                            label: p.name,
                                        })),
                                        true,
                                    )}
                                    {select(
                                        'Clients',
                                        'client_id',
                                        pickers.clients.map((p) => ({
                                            value: String(p.id),
                                            label: p.name,
                                        })),
                                        true,
                                    )}
                                    {select(
                                        'Safety reviews',
                                        'hs_event_id',
                                        pickers.events.map((p) => ({
                                            value: String(p.id),
                                            label: p.name,
                                        })),
                                        true,
                                    )}
                                    {select(
                                        'Acceptability',
                                        'risk_acceptable',
                                        [
                                            { value: '1', label: 'Acceptable' },
                                            {
                                                value: '0',
                                                label: 'Not acceptable',
                                            },
                                        ],
                                    )}
                                    <PageHeaderFilterCheck
                                        label="Due for review"
                                        checked={
                                            filters.due_for_review === 'true'
                                        }
                                        onChange={(checked) =>
                                            go({
                                                due_for_review: checked
                                                    ? 'true'
                                                    : null,
                                            })
                                        }
                                    />
                                    {selected && (
                                        <PageHeaderGlassButton
                                            onClick={() =>
                                                router.get(
                                                    RISK_REGISTER,
                                                    { tab: filters.tab },
                                                    {
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderGlassButton>
                                    )}
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={tabs}
                                    value={filters.tab}
                                    onSelect={(tab) => go({ tab })}
                                    ariaLabel="Risk assessment views"
                                />
                            }
                        />
                    }
                >
                    <RaTable
                        rows={assessments.data}
                        countLabel={
                            (assessments.from ?? 0) +
                            '–' +
                            (assessments.to ?? 0) +
                            ' of ' +
                            assessments.total
                        }
                        onOpen={openDetail}
                        hrefFor={recordHref}
                        onCtx={onCtx}
                        emptyCta={
                            can.manage ? (
                                <Button
                                    className="frontline-tap"
                                    onClick={() => setCreating(true)}
                                >
                                    New risk assessment
                                </Button>
                            ) : undefined
                        }
                    />
                    {assessments.last_page > 1 && (
                        <LaravelPagination links={assessments.links} />
                    )}
                </PageLayout>
                {ctx && (
                    <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
                )}
                {creating && (
                    <RaWizardDialog
                        kind="new"
                        pickers={pickers}
                        initialAttach={
                            filters.hs_event_id
                                ? { type: 'event', id: filters.hs_event_id }
                                : null
                        }
                        onClose={() => drafts.request(() => setCreating(false))}
                        onSuccess={(id) => {
                            setCreating(false);
                            if (id) openDetail(id);
                        }}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
