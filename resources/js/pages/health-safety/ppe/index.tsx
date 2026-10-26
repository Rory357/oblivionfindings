/**
 * PPE & equipment register (approved H&S drawings pg-hsn-120, 120b, 120c — Stephan 23 Oct 2026): one header with
 * search, Export, File recovery and Add equipment, the four filters and the register views as rail tabs (the
 * quieter views under More); then the matching table. Add type sits on the catalogue table. The source-repair
 * warning and the closing caveat keep the code's wording.
 */
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Note } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { PPE_REGISTER, ppeHref, ppeReturn } from '@/lib/health-safety/ppe-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, HardHat, History, Info, Plus, X } from 'lucide-react';
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
const TABS = [{ key: 'inv_all', label: 'All equipment' }, { key: 'inv_available', label: 'Available' }, { key: 'inv_allocated', label: 'Issued equipment' },
    { key: 'inv_inspection', label: 'Inspections due' }, { key: 'inv_expiring', label: 'Expiring' }, { key: 'inv_condemned', label: 'Condemned' },
    { key: 'alloc_active', label: 'Active issues' }, { key: 'alloc_unack', label: 'Awaiting acknowledgement' }, { key: 'types', label: 'Catalogue' }];
/** The service-state filter keeps the register's word for equipment out with a worker. */
const STATUS_OPTIONS = STATUS_FILTER_OPTIONS.map((option) => (option.value === 'allocated' ? { ...option, label: 'Issued' } : option));
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
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' }, { title: 'PPE & equipment', href: PPE_REGISTER }]}>
        <Head title="PPE & equipment" />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader title="PPE & equipment" icon={HardHat} subline="Stock, issues, inspections and evidence"
                actions={<><PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ search: search.trim() || null }); }} placeholder="Find equipment…" ariaLabel="Search equipment" />
                    <PageHeaderGlassButton icon={Download} asChild><a href={PPE_REGISTER + '/export?' + query.toString()}>Export</a></PageHeaderGlassButton>
                    {props.can.manage && <><PageHeaderGlassButton icon={History} asChild><Link href="/health-safety/ppe/recovery">File recovery</Link></PageHeaderGlassButton>
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open('create_inventory')}>Add equipment</PageHeaderPrimaryButton></>}</>}
                filters={<>{filter('site_id', 'All sites', props.sites.map((site) => ({ value: String(site.id), label: site.name })))}
                    {filter('category', 'All categories', CATEGORY_OPTIONS)}{filter('status', 'Any status', STATUS_OPTIONS)}
                    {filter('ppe_type_id', 'Any catalogue type', props.types.filter((row) => row.source_integrity !== 'repair_required').map((row) => ({ value: String(row.id), label: row.name! })))}
                    {narrowed ? <PageHeaderFilterButton icon={X} onClick={() => drafts.request(() => router.get(PPE_REGISTER, { tab: props.tab }))}>Clear filters</PageHeaderFilterButton> : null}</>}
                rail={<PageHeaderRail items={TABS.map((tab) => ({ ...tab, count: props.tabCounts[tab.key] ?? undefined }))} value={props.tab} maxVisible={6} onSelect={(value) => visit({}, value)} ariaLabel="Equipment register views" />} />}>
                <div className="grid min-w-0 gap-5">
                {showRepair && <div role="status"><Note tone="warning">Some saved equipment facts need source repair. Those rows remain visible, their actions are withheld, and affected factual totals are not confirmed. Export is unavailable when matching facts cannot be proved.</Note></div>}
                {props.tab === 'types'
                    ? <PpeCatalogueTable rows={typeRows} canAdd={props.can.manage} onAdd={() => open('create_type')} onCommand={open} />
                    : props.tab.startsWith('alloc')
                        ? <PpeAllocationTable rows={props.allocations.data} total={props.allocations.total} returnTo={returnTo} footer={pager(props.allocations)} />
                        : <PpeInventoryTable rows={props.inventory.data} total={props.inventory.total} returnTo={returnTo} footer={pager(props.inventory)} />}
                <Note icon={Info}>Recorded quantities, fit-test completion and training completion do not confirm complete inventory, current competence or fitness for work. Catalogue definitions are organisation references; inventory records are restricted to approved Sites.</Note>
                </div>
            </PageLayout>
            {opened && <PpeCatalogueForm key={opened.command + ':' + (opened.type?.id ?? 'new')} command={opened.command} type={opened.type} actorId={auth.user.id} onClose={close} onDone={done} />}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
