import PageShell from '@/components/page-shell';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import {
    PageHeader, PageHeaderGlassButton, PageHeaderMeterBig,
    PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton,
    PageHeaderRail, PageHeaderSearch, PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import {
    WorkOrderCreateWizard, type WizardAsset, type WizardChecklistRun,
} from '@/pages/fleet-assets/maintenance/work-orders/create-wizard';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, LayoutGrid, List, Plus, ShieldAlert, Wrench } from 'lucide-react';
import { useEffect, useState } from 'react';

type WorkOrder = {
    id: number; reference_number: string | null; title: string;
    status: string; priority: string; next_action: string | null;
    waiting_reason: string | null; active_hold: boolean;
    asset: { id: number; name: string; asset_tag: string | null; category: string | null;
        site_id: number; site_name: string | null } | null;
    assigned_to: { id: number; name: string } | null;
    due_at: string | null;
};
type QueueView = 'all' | 'mine' | 'holds' | 'release' | 'closed' | 'unassigned';
type Props = {
    work_orders: { data: WorkOrder[]; links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: { current_page: number; last_page: number; total: number } };
    filters: { view?: QueueView; site_id?: string; q?: string; status?: string; priority?: string;
        asset_id?: string; overdue?: string };
    stats: { all: number; mine: number; holds: number; release: number; closed: number; unassigned: number };
    site_options: Array<{ id: number; name: string }>;
    can: { report: boolean; manage: boolean };
    assets: WizardAsset[]; checklist_runs: WizardChecklistRun[];
    prefill_asset_id?: string | null; prefill_checklist_run_id?: string | null;
    prefill_existing_work_order_id?: string | null; prefill_corrects_report_id?: string | null;
};
const viewLabels: Record<QueueView, string> = {
    all: 'Work queue', mine: 'Assigned to me', holds: 'Work with an active hold',
    release: 'Awaiting release review', closed: 'Completed work', unassigned: 'Needs an owner',
};
const human = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const queueNextAction = (work: WorkOrder) => work.status === 'cancelled' ? 'Work cancelled'
    : work.status === 'completed' ? work.active_hold ? 'Review release requirements' : 'Work completed'
        : work.next_action ?? 'Assessment and next action needed';
const queueTarget = (work: WorkOrder) => (work.status === 'completed' && !work.active_hold) || work.status === 'cancelled'
    ? 'No next action due' : work.due_at ? formatDateTime(work.due_at) : 'Target not set';
const assetHref = (asset: NonNullable<WorkOrder['asset']>) =>
    asset.category === 'vehicle' ? `/fleet-assets/vehicles/${asset.id}` : `/fleet-assets/assets/${asset.id}`;

export default function WorkOrdersIndex({
    work_orders, filters = {}, stats, site_options, can, assets, checklist_runs,
    prefill_asset_id, prefill_checklist_run_id, prefill_existing_work_order_id, prefill_corrects_report_id,
}: Props) {
    const [wizardOpen, setWizardOpen] = useState(false);
    const [layout, setLayout] = useState<'table' | 'cards'>('table');
    const [search, setSearch] = useState(filters.q ?? '');
    const view: QueueView = filters.view ?? 'all';
    const data = work_orders.data ?? [];
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('new') === '1') setWizardOpen(true);
    }, []);
    useEffect(() => {
        if (search === (filters.q ?? '')) return;
        const timer = window.setTimeout(() => {
            router.get('/fleet-assets/maintenance/work-orders',
                { ...filters, q: search || undefined, page: 1 },
                { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search, filters]);
    const apply = (changes: Record<string, string | number | undefined>) =>
        router.get('/fleet-assets/maintenance/work-orders',
            { ...filters, ...changes, page: 1 }, { preserveState: true, preserveScroll: true });
    const base = '/fleet-assets/maintenance/work-orders';
    const meters = [
        { key: 'mine', label: 'My work', count: stats.mine, caption: 'Assigned to you', tone: 'brand' },
        { key: 'holds', label: 'Active holds', count: stats.holds,
            caption: 'Restriction still in effect', tone: 'critical' },
        { key: 'release', label: 'Awaiting release', count: stats.release,
            caption: 'Repair is a separate step', tone: 'warning' },
        { key: 'unassigned', label: 'Needs an owner', count: stats.unassigned,
            caption: 'Allocation required', tone: 'warning' },
    ] as const;
    return <AppLayout breadcrumbs={[
        { title: 'Fleet & Assets', href: '/fleet-assets' },
        { title: 'Maintenance', href: base },
    ]}>
        <Head title="Maintenance" />
        <PageShell>
            <PageHeader variant="index" icon={Wrench} title="Maintenance" className="overflow-clip!"
                subline="Owned work · evidence · release"
                actions={<>
                    <PageHeaderSearch value={search} onChange={setSearch}
                        placeholder="Search work, assets or owners…" />
                    {can.manage && <PageHeaderGlassButton icon={ClipboardCheck}
                        onClick={() => router.visit('/fleet-assets/inspections/create')}>Record check</PageHeaderGlassButton>}
                    {can.report && <PageHeaderPrimaryButton icon={Plus}
                        onClick={() => setWizardOpen(true)}>Report a problem</PageHeaderPrimaryButton>}
                </>}
                meters={meters.map((meter) => <PageHeaderMeterBlock key={meter.key}
                    label={meter.label} tone={meter.tone} href={`${base}?view=${meter.key}`}>
                    <PageHeaderMeterBig>{meter.count}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>{meter.caption}</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>)}
                filters={<div className="flex w-full flex-wrap items-center gap-2">
                    <span className="mr-auto text-xs text-primary-foreground/70">
                        {stats.all} records in approved sites
                    </span>
                    <select aria-label="Filter by site"
                        className="h-7 max-w-[210px] rounded-md border border-primary-foreground/20 bg-primary/40 px-2 text-xs text-primary-foreground"
                        value={filters.site_id ?? ''} onChange={(event) =>
                            apply({ site_id: event.target.value || undefined })}>
                        <option value="">All approved sites</option>
                        {site_options.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                    <PageHeaderViewToggle value={layout} onChange={setLayout} ariaLabel="Queue layout"
                        options={[{ value: 'table', label: 'Table', icon: List },
                            { value: 'cards', label: 'Cards', icon: LayoutGrid }]} />
                </div>}
                rail={<PageHeaderRail value={view === 'unassigned' ? 'all' : view}
                    onSelect={(next) => apply({ view: next })}
                    items={[
                        { key: 'all', label: 'All work', count: stats.all },
                        { key: 'mine', label: 'My work' },
                        { key: 'holds', label: 'On hold', count: stats.holds, alert: stats.holds > 0 },
                        { key: 'release', label: 'Awaiting release', count: stats.release },
                        { key: 'closed', label: 'Completed' },
                    ]} />}
            />
            <div className="mt-5 flex flex-wrap items-center justify-between gap-2">
                <div><h2 className="text-lg font-semibold">{viewLabels[view]}</h2>
                    <p className="text-sm text-muted-foreground">{work_orders.meta.total} {work_orders.meta.total === 1 ? 'record' : 'records'} shown</p></div>
                <p className="text-xs text-muted-foreground">Work-order references also appear in All Tasks</p>
            </div>
            {data.length === 0 ? <Card className="mt-4"><CardContent className="p-6">
                <FleetEmptyState icon={search ? Wrench : ClipboardCheck}
                    title={search ? 'No work matches your search' : 'No work in this view'}
                    description={search
                        ? 'Try a work number, asset tag or owner. Your filters are still applied.'
                        : 'There are no records in the selected scope. This does not establish asset readiness.'}
                    actionLabel="Clear filters"
                    onAction={() => { setSearch(''); router.visit(base); }} />
            </CardContent></Card> : layout === 'table' ?
                <Card className="mt-4 max-w-full overflow-x-auto" data-fleet-narrow-strategy="horizontal-scroll">
                    <table className="w-full min-w-[960px] text-sm">
                        <thead className="bg-muted/50 text-xs text-muted-foreground">
                            <tr><th className="px-4 py-3 text-left">Work / source</th>
                                <th className="px-4 py-3 text-left">Asset / site</th>
                                <th className="px-4 py-3 text-left">State / restriction</th>
                                <th className="px-4 py-3 text-left">Owner</th>
                                <th className="px-4 py-3 text-left">Next action / target</th></tr>
                        </thead>
                        <tbody>{data.map((work) => <tr key={work.id} className="border-t align-top hover:bg-muted/30">
                            <td className="px-4 py-3"><Link href={`${base}/${work.id}`}
                                className="font-semibold text-primary hover:underline">{work.title}</Link>
                                <span className="block text-xs text-muted-foreground">{work.reference_number ?? `WO-${work.id}`} · Report retained</span></td>
                            <td className="px-4 py-3">{work.asset ? <><Link href={assetHref(work.asset)}
                                className="font-medium text-primary hover:underline">{work.asset.name}</Link>
                                <span className="block text-xs text-muted-foreground">{work.asset.asset_tag ?? 'Asset'} · {work.asset.site_name ?? 'Approved site'}</span></> : 'Resource unavailable'}</td>
                            <td className="px-4 py-3"><Badge variant="outline">{human(work.status)}</Badge>
                                {work.active_hold && <span className="mt-1 flex items-center gap-1 text-xs font-semibold text-destructive">
                                    <ShieldAlert className="size-3" /> Hold active</span>}</td>
                            <td className="px-4 py-3">{work.assigned_to?.name ?? 'Needs an owner'}</td>
                            <td className="max-w-[280px] px-4 py-3">{queueNextAction(work)}
                                <span className="block text-xs text-muted-foreground">
                                    {queueTarget(work)}
                                </span></td>
                        </tr>)}</tbody>
                    </table>
                </Card> :
                <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{data.map((work) =>
                    <Card key={work.id} className="min-w-0"><CardContent className="p-4">
                        <div className="flex items-start justify-between gap-2">
                            <Link href={`${base}/${work.id}`} className="font-semibold text-primary hover:underline">
                                {work.title}</Link><Badge variant="outline">{human(work.status)}</Badge>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">{work.reference_number ?? `WO-${work.id}`} · {work.asset?.name ?? 'Resource unavailable'}</p>
                        <p className="mt-3 text-sm">{queueNextAction(work)}</p>
                        <p className="mt-1 text-xs text-muted-foreground">{work.assigned_to?.name ?? 'Needs an owner'} · {queueTarget(work)}</p>
                        {work.active_hold && <p className="mt-3 flex items-center gap-1 text-xs font-semibold text-destructive"><ShieldAlert className="size-3" /> Hold active</p>}
                        {work.asset && <Link href={assetHref(work.asset)} className="mt-3 inline-block text-xs text-primary hover:underline">{work.asset.asset_tag ?? work.asset.name} · {work.asset.site_name ?? 'Approved site'}</Link>}
                    </CardContent></Card>)}</div>}
            {work_orders.meta.last_page > 1 && <div className="mt-5 flex flex-wrap justify-center gap-1">
                {work_orders.links.map((link, index) => <Button key={index} size="sm"
                    variant={link.active ? 'default' : 'outline'} disabled={!link.url}
                    onClick={() => link.url && router.visit(link.url)}>
                    {link.label.replace(/&laquo;|&raquo;/g, '').replace(/&hellip;/g, '…') || 'Page'}
                </Button>)}
            </div>}
            <p className="mt-5 text-xs text-muted-foreground">All dates use Pacific/Auckland · Only records in your approved sites are included</p>
            <WorkOrderCreateWizard open={wizardOpen} onClose={() => setWizardOpen(false)}
                assets={assets ?? []} checklistRuns={checklist_runs ?? []}
                prefillAssetId={prefill_asset_id} prefillChecklistRunId={prefill_checklist_run_id}
                prefillExistingWorkOrderId={prefill_existing_work_order_id}
                prefillCorrectsReportId={prefill_corrects_report_id} />
        </PageShell>
    </AppLayout>;
}
