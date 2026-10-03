import { useControlledProduct } from '@/components/emar/controlled/product-client';
import type {
    ControlledOverride,
    ControlledProductPayload,
} from '@/components/emar/controlled/product-types';
import {
    dateTime,
    Notice,
    RecordList,
    StateBadge,
} from '@/components/emar/controlled/product-ui';
import { useControlledDialogs } from '@/components/emar/controlled/workspace-dialogs';
import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import { compactMenu } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    ClipboardCheck,
    Home,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

type View = 'all' | 'waiting' | 'followup' | 'closed';
function initialFilters(): { view: View; site: string; search: string } {
    if (typeof window === 'undefined')
        return { view: 'all', site: 'all', search: '' };
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view');
    return {
        view: ['all', 'waiting', 'followup', 'closed'].includes(view ?? '')
            ? (view as View)
            : 'all',
        site: params.get('site_id') ?? 'all',
        search: params.get('q') ?? '',
    };
}
export default function WitnessOverrides({
    product,
    initialPayload,
}: {
    product?: ControlledProductPayload;
    initialPayload?: ControlledProductPayload;
}) {
    const workspace = useControlledProduct(product ?? initialPayload);
    const dialogs = useControlledDialogs(workspace);
    const payload = workspace.payload;
    const [filters, setFilters] = useState(initialFilters);
    const { view, site, search } = filters;
    const change = (patch: Partial<typeof filters>) => {
        const next = { ...filters, ...patch };
        setFilters(next);
        const url = new URL(window.location.href);
        url.searchParams.set('view', next.view);
        for (const [key, value] of [
            ['site_id', next.site === 'all' ? '' : next.site],
            ['q', next.search],
        ]) {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        }
        router.replace({
            url: url.pathname + url.search,
            preserveState: true,
            preserveScroll: true,
        });
    };
    const setView = (view: View) => change({ view });
    const setSite = (site: string) => change({ site });
    const setSearch = (search: string) => change({ search });
    const q = search.trim().toLowerCase();
    const rows = (payload?.overrides ?? []).filter(
        (override) =>
            (site === 'all' || String(override.site_id) === site) &&
            (!q ||
                `${override.site_name} ${override.requested_by_name} ${override.reason}`
                    .toLowerCase()
                    .includes(q)) &&
            (view === 'all' ||
                (view === 'waiting' &&
                    ['waiting', 'pending'].includes(override.status)) ||
                (view === 'followup' &&
                    override.doses.some((dose) => !dose.signed_off_at)) ||
                (view === 'closed' &&
                    (['declined', 'cancelled', 'signed_off'].includes(
                        override.status,
                    ) ||
                        (override.status === 'approved' &&
                            override.doses.length > 0 &&
                            override.doses.every(
                                (dose) => dose.signed_off_at,
                            ))))),
    );
    const state = (override: ControlledOverride) =>
        override.followup_overdue ? (
            <StatusBadge variant="critical">Sign-off overdue</StatusBadge>
        ) : override.status === 'approved' &&
          override.doses.some((dose) => !dose.signed_off_at) ? (
            <StatusBadge variant="info">Count and sign-off due</StatusBadge>
        ) : (
            <StateBadge status={override.status} />
        );
    let content;
    if (workspace.loading && !payload)
        content = (
            <Card className="p-5" aria-busy="true">
                <SkeletonTable />
                <span className="sr-only">Loading witness overrides…</span>
            </Card>
        );
    else if (!payload)
        content = (
            <ErrorState
                title={
                    workspace.error?.status === 403
                        ? 'You can’t view witness overrides'
                        : 'Couldn’t load witness overrides'
                }
                message={workspace.error?.message}
                onRetry={() => void workspace.refresh()}
            />
        );
    else if (payload.can.view === false)
        content = (
            <EmptyState
                icon={ShieldCheck}
                title="You can’t view controlled medicines"
                description="Controlled records are limited to permitted roles and approved houses."
            />
        );
    else
        content = (
            <>
                <ListCaption
                    title="Witness overrides"
                    caption={`${rows.length} shown · All outstanding work and latest ${payload.history_limit ?? 500} completed records`}
                />
                <RecordList
                    rows={rows}
                    rowKey={(override) => override.id}
                    identity={(override) => ({
                        name: override.site_name,
                        icon: ShieldCheck,
                        subline: `Override ${override.id} · ${override.requested_by_name} · ${dateTime(override.requested_at)}`,
                    })}
                    columns={[
                        {
                            key: 'coverage',
                            label: 'Coverage',
                            width: '1.3fr',
                            cell: (override) => (
                                <div>
                                    <p>
                                        {override.medicine_ids.length}{' '}
                                        medicine(s)
                                    </p>
                                    <p className="text-caption">
                                        {dateTime(override.starts_at)} →{' '}
                                        {dateTime(override.expires_at)}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'Status',
                            width: '1.2fr',
                            cell: state,
                        },
                        {
                            key: 'due',
                            label: 'Follow-up due',
                            width: '1fr',
                            cell: (override) =>
                                dateTime(override.followup_due_at),
                        },
                    ]}
                    actionsFor={(override) =>
                        compactMenu([
                            {
                                label: 'View override',
                                icon: BookOpen,
                                onClick: () =>
                                    dialogs.detail({
                                        kind: 'override',
                                        id: override.id,
                                    }),
                            },
                            payload.can.override &&
                                ['waiting', 'pending'].includes(
                                    override.status,
                                ) && {
                                    label: 'Approve or decline',
                                    icon: ShieldCheck,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'override_decide',
                                            targetId: override.id,
                                            siteId: override.site_id,
                                        }),
                                },
                            override.can_signoff &&
                                override.doses.some(
                                    (dose) => !dose.signed_off_at,
                                ) && {
                                    label: 'Count and sign off',
                                    icon: ClipboardCheck,
                                    onClick: () => dialogs.followup(override),
                                },
                        ])
                    }
                    onOpen={(override) =>
                        dialogs.detail({ kind: 'override', id: override.id })
                    }
                    emptyTitle="No witness overrides to show"
                    emptyDescription="Try another house or view. Requests and their follow-ups remain in the history."
                />
            </>
        );
    const totals = payload?.meters;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/meds/today' },
                { title: 'Safety & oversight', href: '/emar' },
                {
                    title: 'Witness overrides',
                    href: '/emar/safety/witness-overrides',
                },
            ]}
        >
            <Head title="Witness overrides" />
            <div className="space-y-5">
                <PageHeader
                    icon={ShieldCheck}
                    title="Witness overrides"
                    subline="Requests, explicit coverage and witnessed follow-up · Pacific/Auckland"
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search houses or requesters"
                            />
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                disabled={workspace.loading}
                                onClick={() => void workspace.refresh()}
                            >
                                Refresh
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Waiting for decision"
                                onClick={() => setView('waiting')}
                                tone={
                                    totals?.total_waiting_overrides
                                        ? 'warning'
                                        : 'brand'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {totals?.total_waiting_overrides ?? '—'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Approve or decline
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Follow-up"
                                onClick={() => setView('followup')}
                            >
                                <PageHeaderMeterBig>
                                    {totals?.total_pending_followups ?? '—'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Witnessed count and sign-off
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Overdue"
                                onClick={() => setView('followup')}
                                tone={
                                    totals?.total_overdue_followups
                                        ? 'warning'
                                        : 'brand'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {totals?.total_overdue_followups ?? '—'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Lead action needed
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Controlled checks"
                                href="/meds/today?view=controlled"
                            >
                                <PageHeaderMeterBig>
                                    <ClipboardCheck className="size-5" />
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Counts and witness requests
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                icon={Home}
                                label="House"
                                value={site}
                                allValue="all"
                                options={[
                                    { value: 'all', label: 'All your houses' },
                                    ...(payload?.sites ?? []).map((house) => ({
                                        value: String(house.id),
                                        label: house.name,
                                    })),
                                ]}
                                onChange={setSite}
                            />
                            <PageHeaderFilterSelect
                                label="Show"
                                value={view}
                                allValue="all"
                                onChange={(value) => setView(value as View)}
                                options={[
                                    { value: 'all', label: 'All overrides' },
                                    {
                                        value: 'waiting',
                                        label: 'Waiting for decision',
                                    },
                                    {
                                        value: 'followup',
                                        label: 'Count and sign-off due',
                                    },
                                    {
                                        value: 'closed',
                                        label: 'Closed or declined',
                                    },
                                ]}
                            />
                            <PageHeaderMeterCaption>
                                As at {dateTime(payload?.as_at)}
                            </PageHeaderMeterCaption>
                        </>
                    }
                    rail={<EmarHubRail />}
                />
                {workspace.offline ? (
                    <Notice title="You’re offline">
                        Decisions, witnessed counts and sign-offs cannot be
                        saved offline.
                    </Notice>
                ) : null}
                {workspace.error && payload ? (
                    <Notice title="These records may be out of date">
                        {workspace.error.message}
                    </Notice>
                ) : null}
                {content}
                {dialogs.node}
            </div>
        </AppLayout>
    );
}
