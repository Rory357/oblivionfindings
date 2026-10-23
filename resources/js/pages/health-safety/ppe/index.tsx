import { PageHeader, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageHeaderViewToggle, PageLayout } from '@/components/page';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { PPE_REGISTER, ppeHref, ppeReturn } from '@/lib/health-safety/ppe-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, HardHat, History, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { PpeCatalogueForm } from './_catalogue-form';
import type { CatalogueCommand, CatalogueDisplayRow, CatalogueRow } from './_catalogue-state';
import { PpeAllocationList, PpeCatalogueList, PpeInventoryList, type AllocationDisplayRow, type InventoryDisplayRow } from './_register-list';
import type { PpeReceipt } from './_workspace-types';
import { CATEGORY_OPTIONS, STATUS_FILTER_OPTIONS, type Paginator } from './ppe-shared';

type Filters = { site_id?: number | null; ppe_type_id?: number | null; category?: string | null; status?: string | null; search?: string | null };
type Props = { inventory: Paginator<InventoryDisplayRow>; allocations: Paginator<AllocationDisplayRow>; types: CatalogueDisplayRow[];
    filters: Filters; sites: { id: number; name: string }[]; tab: string; tabCounts: Record<string, number | null>;
    can: { view: boolean; manage: boolean }; source_integrity?: { inventory: 'intact' | 'legacy_unknown' | 'repair_required'; allocations: 'intact' | 'legacy_unknown' | 'repair_required' }; summary_scope?: string };
const TABS = [{ key: 'inv_all', label: 'All equipment' }, { key: 'inv_available', label: 'Available' }, { key: 'inv_allocated', label: 'Issued equipment' },
    { key: 'inv_inspection', label: 'Inspections due' }, { key: 'inv_expiring', label: 'Expiring' }, { key: 'inv_condemned', label: 'Condemned' },
    { key: 'alloc_active', label: 'Active issues' }, { key: 'alloc_unack', label: 'Awaiting acknowledgement' }, { key: 'types', label: 'Catalogue' }];
const METERS = ['inv_all', 'inv_available', 'inv_inspection', 'inv_expiring', 'inv_condemned', 'alloc_unack'];
export function ppeRegisterQuery(filters: Filters, tab: string, view: 'cards' | 'table') {
    const query = new URLSearchParams({ tab, view });
    Object.entries(filters).forEach(([key, value]) => { if (value != null && value !== '') query.set(key, String(value)); });
    return query;
}
export default function PpeRegister(props: Props) {
    const { auth } = usePage<SharedData>().props; const { url } = usePage();
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(props.filters.search ?? '');
    const [opened, setOpened] = useState<{ command: CatalogueCommand; type?: CatalogueRow } | null>(null);
    const view = new URL(url, 'https://ppe.invalid').searchParams.get('view') === 'cards' ? 'cards' : 'table';
    const returnTo = ppeReturn(url);
    const query = ppeRegisterQuery(props.filters, props.tab, view);
    const visit = (filters: Partial<Filters> = {}, tab = props.tab, nextView = view) => drafts.request(() =>
        router.get(PPE_REGISTER, Object.fromEntries(ppeRegisterQuery({ ...props.filters, ...filters }, tab, nextView)), { preserveScroll: true, preserveState: false }));
    const open = (command: CatalogueCommand, type?: CatalogueRow) => drafts.request(() => setOpened({ command, type }));
    const close = () => drafts.request(() => setOpened(null));
    const done = (receipt: PpeReceipt) => {
        setOpened(null);
        if (receipt.root_type === 'inventory') router.get(ppeHref('inventory', receipt.root_id, { returnTo }));
        else router.get(PPE_REGISTER, Object.fromEntries(ppeRegisterQuery(props.filters, 'types', view)), { preserveScroll: true });
    };
    const typeRows = props.types.filter((row) => (!props.filters.ppe_type_id || row.id === props.filters.ppe_type_id) &&
        (!props.filters.category || row.source_integrity === 'repair_required' || row.category === props.filters.category) &&
        (!props.filters.search || row.source_integrity === 'repair_required' || [row.name, row.description, row.hazards_addressed, row.standards_reference]
            .some((value) => value?.toLowerCase().includes(props.filters.search!.toLowerCase()))));
    const filter = (key: keyof Filters, label: string, choices: { value: string; label: string }[]) => <PageHeaderFilterSelect label={label}
        value={props.filters[key] == null || props.filters[key] === '' ? 'all' : String(props.filters[key])} options={choices}
        onChange={(value) => visit({ [key]: value === 'all' ? null : ['site_id', 'ppe_type_id'].includes(key) ? Number(value) : value })} />;
    const showRepair = props.source_integrity?.inventory === 'repair_required' || props.source_integrity?.allocations === 'repair_required' || props.inventory.data.some((row) => row.source_integrity === 'repair_required') ||
        props.allocations.data.some((row) => row.source_integrity === 'repair_required');
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'PPE & equipment', href: PPE_REGISTER }]}>
        <Head title="PPE & equipment" />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline title="PPE & equipment" icon={HardHat} subline="Recorded stock, issues, inspections and retained evidence"
                mobileSummary={props.tab === 'types' ? typeRows.length + ' catalogue types' : (props.tab.startsWith('alloc') ? props.allocations.total : props.inventory.total) + ' matching records'}
                actions={<><PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ search }); }} placeholder="Find equipment…" ariaLabel="Search equipment" />
                    <PageHeaderGlassButton icon={Search} onClick={() => visit({ search })}>Search</PageHeaderGlassButton>
                    <PageHeaderGlassButton icon={Download} asChild><a href={PPE_REGISTER + '/export?' + query.toString()}>Export stock</a></PageHeaderGlassButton>
                    {props.can.manage && <><PageHeaderGlassButton icon={History} asChild><Link href="/health-safety/ppe/recovery">File recovery</Link></PageHeaderGlassButton><PageHeaderGlassButton icon={Plus} onClick={() => open('create_type')}>Add type</PageHeaderGlassButton>
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open('create_inventory')}>Add equipment</PageHeaderPrimaryButton></>}</>}
                meters={<>{METERS.map((key) => <PageHeaderMeterBlock key={key} label={TABS.find((tab) => tab.key === key)!.label} href={PPE_REGISTER + '?' + ppeRegisterQuery(props.filters, key, view)}>
                    <PageHeaderMeterBig>{props.tabCounts[key] == null ? 'Not confirmed' : props.tabCounts[key]}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>{key === 'inv_all' ? 'Inventory records · within filters' : 'Recorded matches · within filters'}</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>)}</>}
                filters={<>{filter('site_id', 'Site', props.sites.map((site) => ({ value: String(site.id), label: site.name })))}
                    {filter('category', 'Category', CATEGORY_OPTIONS)}{filter('status', 'Service state', STATUS_FILTER_OPTIONS)}
                    {filter('ppe_type_id', 'Catalogue type', props.types.filter((row) => row.source_integrity !== 'repair_required').map((row) => ({ value: String(row.id), label: row.name! })))}
                    <PageHeaderViewToggle value={view} onChange={(value) => visit({}, props.tab, value)} options={[{ value: 'cards', label: 'Cards' }, { value: 'table', label: 'Table' }]} ariaLabel="Equipment list view" />
                    <PageHeaderGlassButton onClick={() => drafts.request(() => router.get(PPE_REGISTER))}>Clear filters</PageHeaderGlassButton></>}
                rail={<PageHeaderRail items={TABS.map((tab) => ({ ...tab, count: props.tabCounts[tab.key] ?? undefined }))} value={props.tab} onSelect={(value) => visit({}, value)} ariaLabel="Equipment register views" />} />}>
                {showRepair && <div role="status" className="rounded-xl border border-status-warning bg-status-warning-bg p-4"><p className="text-subtle">Some saved equipment facts need source repair. Those rows remain visible, their actions are withheld, and affected factual totals are not confirmed. Export is unavailable when matching facts cannot be proved.</p></div>}
                {props.tab === 'types' ? <PpeCatalogueList rows={typeRows} view={view} onCommand={open} /> : props.tab.startsWith('alloc') ? <>
                    <PpeAllocationList rows={props.allocations.data} total={props.allocations.total} returnTo={returnTo} view={view} />
                    <LaravelPagination links={props.allocations.links} lastPage={props.allocations.last_page} /></> : <>
                    <PpeInventoryList rows={props.inventory.data} total={props.inventory.total} returnTo={returnTo} view={view} />
                    <LaravelPagination links={props.inventory.links} lastPage={props.inventory.last_page} /></>}
                <p className="text-caption text-muted-foreground">Recorded quantities, fit-test completion and training completion do not confirm complete inventory, current competence or fitness for work. Catalogue definitions are organisation references; inventory records are restricted to approved Sites.</p>
            </PageLayout>
            {opened && <PpeCatalogueForm key={opened.command + ':' + (opened.type?.id ?? 'new')} command={opened.command} type={opened.type} actorId={auth.user.id} onClose={close} onDone={done} />}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
