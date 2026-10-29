/**
 * PPE & equipment register (approved H&S drawings pg-hsn-120, 120b, 120c — Stephan 23 Oct 2026): one header with
 * search, Export, File recovery and Add equipment, the four filters, the Risks and prevention hub on the rail and the
 * register views as tier-two tabs (drawing D4); then the matching table. Add type sits on the equipment types table. The damaged-record
 * warning and the closing note are in plain words.
 */
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Note } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { PPE_REGISTER, ppeHref, ppeReturn } from '@/lib/health-safety/ppe-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ban, CalendarClock, ClipboardCheck, Download, HardHat, History, Hourglass, Info, Package, Plus, Tags, UserCheck, Users, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { PpeCatalogueForm } from './_catalogue-form';
import type { CatalogueCommand, CatalogueDisplayRow, CatalogueRow } from './_catalogue-state';
import { PpeAllocationTable, PpeCatalogueTable, PpeInventoryTable, type AllocationDisplayRow, type InventoryDisplayRow } from './_register-list';
import type { PpeReceipt } from './_workspace-types';
import { CATEGORY_OPTIONS, STATUS_FILTER_OPTIONS, type Paginator } from './ppe-shared';

type Filters = { site_id?: number | null; ppe_type_id?: number | null; category?: string | null; status?: string | null; search?: string | null };
type Props = { inventory: Paginator<InventoryDisplayRow>; allocations: Paginator<AllocationDisplayRow>; types: CatalogueDisplayRow[];
    filters: Filters; sites: { id: number; name: string }[]; tab: string; tabCounts: Record<string, number | null>;
    can: { view: boolean; manage: boolean }; source_integrity?: { inventory: 'intact' | 'legacy_unknown' | 'repair_required'; allocations: 'intact' | 'legacy_unknown' | 'repair_required' }; summary_scope?: string };
const TABS = [{ key: 'inv_all', label: 'All equipment', icon: HardHat }, { key: 'inv_available', label: 'In stock', icon: Package },
    { key: 'inv_allocated', label: 'Issued equipment', icon: UserCheck }, { key: 'inv_inspection', label: 'Inspections due', icon: ClipboardCheck },
    { key: 'inv_expiring', label: 'Expiring', icon: CalendarClock }, { key: 'inv_condemned', label: 'Taken out of use', icon: Ban },
    { key: 'alloc_active', label: 'Currently issued', icon: Users }, { key: 'alloc_unack', label: 'Not yet confirmed by worker', icon: Hourglass },
    { key: 'types', label: 'Equipment types', icon: Tags }];
const STATUS_OPTIONS = STATUS_FILTER_OPTIONS;
export function ppeRegisterQuery(filters: Filters, tab: string) {
    const query = new URLSearchParams({ tab });
    Object.entries(filters).forEach(([key, value]) => { if (value != null && value !== '') query.set(key, String(value)); });
    return query;
}
export default function PpeRegister(props: Props) {
    const { auth } = usePage<SharedData>().props; const { url } = usePage();
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(props.filters.search ?? '');
    useEffect(() => setSearch(props.filters.search ?? ''), [props.filters.search]);
    const [opened, setOpened] = useState<{ command: CatalogueCommand; type?: CatalogueRow } | null>(null);
    const returnTo = ppeReturn(url);
    const query = ppeRegisterQuery(props.filters, props.tab);
    const visit = (filters: Partial<Filters> = {}, tab = props.tab) => drafts.request(() =>
        router.get(PPE_REGISTER, Object.fromEntries(ppeRegisterQuery({ ...props.filters, ...filters }, tab)), { preserveScroll: true, preserveState: false }));
    const open = (command: CatalogueCommand, type?: CatalogueRow) => drafts.request(() => setOpened({ command, type }));
    const close = () => drafts.request(() => setOpened(null));
    const done = (receipt: PpeReceipt) => {
        setOpened(null);
        if (receipt.root_type === 'inventory') router.get(ppeHref('inventory', receipt.root_id, { returnTo }));
        else router.get(PPE_REGISTER, Object.fromEntries(ppeRegisterQuery(props.filters, 'types')), { preserveScroll: true });
    };
    const typeRows = props.types.filter((row) => (!props.filters.ppe_type_id || row.id === props.filters.ppe_type_id) &&
        (!props.filters.category || row.source_integrity === 'repair_required' || row.category === props.filters.category) &&
        (!props.filters.search || row.source_integrity === 'repair_required' || [row.name, row.description, row.hazards_addressed, row.standards_reference]
            .some((value) => value?.toLowerCase().includes(props.filters.search!.toLowerCase()))));
    const filter = (key: keyof Filters, label: string, choices: { value: string; label: string }[]) => <PageHeaderFilterSelect label={label}
        value={props.filters[key] == null || props.filters[key] === '' ? 'all' : String(props.filters[key])} options={[{ value: 'all', label }, ...choices]}
        onChange={(value) => visit({ [key]: value === 'all' ? null : ['site_id', 'ppe_type_id'].includes(key) ? Number(value) : value })} />;
    const narrowed = ['site_id', 'category', 'status', 'ppe_type_id', 'search'].some((key) => { const value = props.filters[key as keyof Filters]; return value != null && value !== ''; });
    const showRepair = props.source_integrity?.inventory === 'repair_required' || props.source_integrity?.allocations === 'repair_required' || props.inventory.data.some((row) => row.source_integrity === 'repair_required') ||
        props.allocations.data.some((row) => row.source_integrity === 'repair_required');
    const pager = (page: Paginator<unknown>): ReactNode => <>
        <p className="text-subtle">Showing {page.data.length} of {page.total}</p>
        {page.last_page > 1 ? <LaravelPagination links={page.links} lastPage={page.last_page} /> : null}
    </>;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Risks and prevention', href: '/health-safety/registers?view=prevention' }, { title: 'PPE & equipment', href: PPE_REGISTER }]}>
        <Head title="PPE & equipment" />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader title="PPE & equipment" icon={HardHat} subline="Protective gear (PPE): stock, who has it, inspections and files"
                actions={<><PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ search: search.trim() || null }); }} placeholder="Find equipment…" ariaLabel="Search equipment" />
                    <PageHeaderGlassButton icon={Download} asChild><a href={PPE_REGISTER + '/export?' + query.toString()}>Export</a></PageHeaderGlassButton>
                    {props.can.manage && <><PageHeaderGlassButton icon={History} asChild><Link href="/health-safety/ppe/recovery">Files to tidy up</Link></PageHeaderGlassButton>
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open('create_inventory')}>Add equipment</PageHeaderPrimaryButton></>}</>}
                filters={<>{filter('site_id', 'All sites', props.sites.map((site) => ({ value: String(site.id), label: site.name })))}
                    {filter('category', 'All categories', CATEGORY_OPTIONS)}{filter('status', 'Any status', STATUS_OPTIONS)}
                    {filter('ppe_type_id', 'Any equipment type', props.types.filter((row) => row.source_integrity !== 'repair_required').map((row) => ({ value: String(row.id), label: row.name! })))}
                    {narrowed ? <PageHeaderFilterButton icon={X} onClick={() => drafts.request(() => router.get(PPE_REGISTER, { tab: props.tab }))}>Clear filters</PageHeaderFilterButton> : null}</>}
                rail={<SafetyWorkspaceRail hub="prevention" current="ppe" />} />}
                tabs={<TierTwoTabs tabs={TABS.map((tab) => ({ ...tab, count: props.tabCounts[tab.key] ?? undefined }))} activeTab={props.tab} onTab={(value) => visit({}, value)}
                    testIdPrefix="ppe-register" ariaLabel="Equipment register views" panelId="ppe-register-panel"
                    // Views carry no href, so the strip renders its own buttons.
                    renderLink={() => null} />}>
                <div id="ppe-register-panel" role="tabpanel" aria-label={TABS.find((tab) => tab.key === props.tab)?.label ?? 'Equipment'} className="grid min-w-0 gap-5">
                {showRepair && <div role="status"><Note tone="warning">Some equipment records are damaged. You can still see them, but you can’t change them and totals may be wrong. Export won’t work for these records until an administrator fixes them.</Note></div>}
                {props.tab === 'types'
                    ? <PpeCatalogueTable rows={typeRows} canAdd={props.can.manage} onAdd={() => open('create_type')} onCommand={open} />
                    : props.tab.startsWith('alloc')
                        ? <PpeAllocationTable rows={props.allocations.data} total={props.allocations.total} returnTo={returnTo} footer={pager(props.allocations)} />
                        : <PpeInventoryTable rows={props.inventory.data} total={props.inventory.total} returnTo={returnTo} footer={pager(props.inventory)} />}
                <Note icon={Info}>Numbers here are what people recorded. They don’t prove every item is on the shelf, or that a worker is trained and fit for work. Equipment types are shared across the organisation; you only see equipment at your sites.</Note>
                </div>
            </PageLayout>
            {opened && <PpeCatalogueForm key={opened.command + ':' + (opened.type?.id ?? 'new')} command={opened.command} type={opened.type} actorId={auth.user.id} onClose={close} onDone={done} />}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
