/**
 * Protective gear (board G4-11, approved by Stephan 1 Nov 2026): the G2 header — four boxes, then one line with the
 * Risks and prevention pages, the site filter, More filters and Find — and the register views as tier-two tabs under
 * it (the bar stays under the picked tab, decision 8). Then one lead block ("Needs doing now", most overdue first, each
 * row's problems in one chip, only the most urgent row's button filled), the quiet rows beside it, and the full
 * equipment table, problems first, with the standard pager. Add type sits on the equipment types table.
 */
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
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
    LeadBlock,
    QuietRows,
    RegisterBlocks,
    RegisterBoxes,
    RegisterMoreFilters,
    RegisterRailLine,
    overdueNote,
    shortDay,
    type LeadRow,
    type QuietRow,
} from '@/components/health-safety/register-kit';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Note } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { PPE_REGISTER, ppeHref, ppeReturn } from '@/lib/health-safety/ppe-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Clock,
    Download,
    FileText,
    HardHat,
    Hourglass,
    LayoutGrid,
    List,
    Package,
    Plus,
    Send,
    Tags,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { PpeCatalogueForm } from './_catalogue-form';
import type { CatalogueCommand, CatalogueDisplayRow, CatalogueRow } from './_catalogue-state';
import { PpeAllocationTable, PpeCatalogueTable, PpeInventoryTable, type AllocationDisplayRow, type InventoryDisplayRow } from './_register-list';
import type { PpeReceipt } from './_workspace-types';
import { CATEGORY_OPTIONS, STATUS_FILTER_OPTIONS, condLabel, type Paginator } from './ppe-shared';

type Filters = { site_id?: number | null; ppe_type_id?: number | null; category?: string | null; status?: string | null; search?: string | null };

/** G4-11: the header counts and the lead block, for the selected Site (null = the count can't be confirmed). */
export type PpeLeadRow =
    | { kind: 'inventory'; id: number; name: string; serial_number: string | null; site: string | null; location: string | null; condition: string | null;
        due: string | null; expiry_date: string | null; problems: Array<'inspection_overdue' | 'past_expiry' | 'poor_condition'>; can_inspect: boolean }
    | { kind: 'allocation'; id: number; name: string; serial_number: string | null; site: string | null; worker: string | null; handed_out_at: string | null; problems: ['no_fit_test'] };
export type PpeRegisterSummary = {
    boxes: { inspections_overdue: number | null; past_expiry: number | null; fit_tests_missing: number | null; handed_out_30d: number | null };
    lead: PpeLeadRow[];
    lead_total: number | null;
    unconfirmed: number | null;
};

type Props = { inventory: Paginator<InventoryDisplayRow>; allocations: Paginator<AllocationDisplayRow>; types: CatalogueDisplayRow[];
    filters: Filters; sites: { id: number; name: string }[]; tab: string; tabCounts: Record<string, number | null>;
    can: { view: boolean; manage: boolean }; source_integrity?: { inventory: 'intact' | 'legacy_unknown' | 'repair_required'; allocations: 'intact' | 'legacy_unknown' | 'repair_required' };
    summary_scope?: string; register_summary?: PpeRegisterSummary };

/** The six register views under the header (G4-11). The other views open from the quiet rows and boxes. */
const TABS = [{ key: 'inv_all', label: 'All equipment', icon: List }, { key: 'inv_available', label: 'In stock', icon: Package },
    { key: 'inv_allocated', label: 'Handed out', icon: Send }, { key: 'inv_inspection', label: 'Inspections due', icon: Clock },
    { key: 'inv_expiring', label: 'Expiring', icon: AlertTriangle }, { key: 'inv_condemned', label: 'Taken out of use', icon: Ban }];
const OTHER_VIEWS = [{ key: 'alloc_active', label: 'Gear handed out to people', icon: Users }, { key: 'alloc_unack', label: 'Not yet confirmed by worker', icon: Hourglass },
    { key: 'types', label: 'Equipment types', icon: Tags }];
const PROBLEM_LABELS: Record<string, string> = { inspection_overdue: 'Inspection overdue', past_expiry: 'Past expiry date', poor_condition: 'Poor condition' };

export function ppeRegisterQuery(filters: Filters, tab: string) {
    const query = new URLSearchParams({ tab });
    Object.entries(filters).forEach(([key, value]) => { if (value != null && value !== '') query.set(key, String(value)); });
    return query;
}

/** "Tue 13 Oct" — the day gear was handed out, on the NZ calendar. */
function handedOutDay(iso: string | null): string {
    if (!iso) return '';
    const day = toDateInput(iso);
    const weekday = new Date(`${day}T00:00:00Z`).toLocaleDateString('en-NZ', { weekday: 'short', timeZone: 'UTC' });
    return `${weekday} ${formatDateOnly(day).replace(/\s\d{4}$/, '')}`;
}

/** The lead block's rows (G4-11): overdue equipment first, then gear handed out without a fit test. */
export function ppeLeadRows(rows: PpeLeadRow[], returnTo: string): LeadRow[] {
    return rows.map((row): LeadRow => {
        if (row.kind === 'allocation') {
            const worker = row.worker ?? 'a worker';
            return {
                key: `allocation-${row.id}`,
                when: row.handed_out_at ? { day: shortDay(toDateInput(row.handed_out_at)), note: 'handed out' } : null,
                title: `${row.name} — ${worker}`,
                sub: [row.site, `given to ${worker}${row.handed_out_at ? ` ${handedOutDay(row.handed_out_at)}` : ''}`, 'no fit test recorded'].filter(Boolean).join(' · '),
                chip: { label: 'No fit test on record', tone: 'warning' },
                action: { label: 'Open', href: ppeHref('allocations', row.id, { section: 'fit-training', returnTo }), ariaLabel: `Open: ${row.name} — ${worker}` },
            };
        }
        const name = [row.name, row.serial_number].filter(Boolean).join(' ');
        return {
            key: `inventory-${row.id}`,
            when: row.due ? { day: shortDay(row.due), note: overdueNote(row.due) } : null,
            title: name,
            sub: [row.site, row.location, row.problems.includes('past_expiry') && row.expiry_date ? `past expiry since ${shortDay(row.expiry_date)}` : `condition ${condLabel(row.condition)}`]
                .filter(Boolean).join(' · '),
            chip: { label: row.problems.map((problem) => PROBLEM_LABELS[problem]).join(' · '), tone: 'critical' },
            action: row.can_inspect ? { label: 'Record inspection', href: ppeHref('inventory', row.id, { command: 'inspect', returnTo }), ariaLabel: `Record inspection: ${name}` } : null,
            who: row.can_inspect ? null : 'Health and safety record this',
        };
    });
}

export default function PpeRegister(props: Props) {
    const { auth } = usePage<SharedData>().props; const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(props.filters.search ?? '');
    useEffect(() => setSearch(props.filters.search ?? ''), [props.filters.search]);
    const [opened, setOpened] = useState<{ command: CatalogueCommand; type?: CatalogueRow } | null>(null);
    const returnTo = ppeReturn(url);
    const query = ppeRegisterQuery(props.filters, props.tab);
    const visit = (filters: Partial<Filters> = {}, tab = props.tab) => drafts.request(() =>
        router.get(PPE_REGISTER, Object.fromEntries(ppeRegisterQuery({ ...props.filters, ...filters }, tab)), { preserveScroll: true, preserveState: false }));
    // Links from the header and quiet rows keep the Site only, as the header counts do.
    const siteLink = (tab: string) => `${PPE_REGISTER}?${ppeRegisterQuery({ site_id: props.filters.site_id ?? null }, tab)}`;
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
    const narrowed = ['site_id', 'category', 'status', 'ppe_type_id', 'search'].some((key) => { const value = props.filters[key as keyof Filters]; return value != null && value !== ''; });
    const showRepair = props.source_integrity?.inventory === 'repair_required' || props.source_integrity?.allocations === 'repair_required' || props.inventory.data.some((row) => row.source_integrity === 'repair_required') ||
        props.allocations.data.some((row) => row.source_integrity === 'repair_required');
    const pager = (page: Paginator<unknown>): ReactNode => <>
        <p className="text-subtle">{page.total ? `Showing ${page.from ?? 1}–${page.to ?? page.data.length} of ${page.total}` : 'Showing 0 of 0'}</p>
        {page.last_page > 1 ? <LaravelPagination links={page.links} lastPage={page.last_page} /> : null}
    </>;
    const summary = props.register_summary;
    const views = [...TABS, ...OTHER_VIEWS.filter((view) => view.key === props.tab)];
    const quiet: QuietRow[] = [
        ...(props.can.manage ? [{ key: 'files', icon: FileText, title: 'Files to tidy up', sub: 'Files that didn’t finish uploading, and older files to move', href: '/health-safety/ppe/recovery' }] : []),
        { key: 'unconfirmed', icon: UserRound, title: 'Not yet confirmed by worker',
            sub: summary?.unconfirmed == null ? 'Gear handed out that the worker hasn’t confirmed yet'
                : `${summary.unconfirmed} ${summary.unconfirmed === 1 ? 'item' : 'items'} handed out that the worker hasn’t confirmed yet`, href: siteLink('alloc_unack') },
        { key: 'types', icon: LayoutGrid, title: 'Equipment types', sub: 'The kinds of gear you keep and stock levels', href: siteLink('types') },
    ];
    const lead = summary ? ppeLeadRows(summary.lead, returnTo) : [];
    const leftOver = summary?.lead_total != null ? summary.lead_total - lead.length : 0;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, ...safetyHubCrumbs('prevention', safetyCan), { title: 'Protective gear', href: PPE_REGISTER }]}>
        <Head title="Protective gear" />
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader title="Protective gear" icon={HardHat} subline="Protective gear (PPE) and equipment: stock, who has it, inspections and files"
                actions={<><PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ search: search.trim() || null }); }} placeholder="Search equipment…" ariaLabel="Search equipment" />
                    <PageHeaderGlassButton icon={Download} asChild><a href={PPE_REGISTER + '/export?' + query.toString()}>Export</a></PageHeaderGlassButton>
                    {props.can.manage && <PageHeaderPrimaryButton icon={Plus} onClick={() => open('create_inventory')}>Add equipment</PageHeaderPrimaryButton>}</>}
                strip={summary ? <RegisterBoxes boxes={[
                    { key: 'inspections_overdue', label: 'Inspections overdue', value: summary.boxes.inspections_overdue, alert: true, icon: Clock, href: siteLink('inv_inspection') },
                    { key: 'past_expiry', label: 'Past expiry date', value: summary.boxes.past_expiry, alert: true, icon: AlertTriangle, href: siteLink('inv_expiring') },
                    { key: 'fit_tests_missing', label: 'Fit tests missing', value: summary.boxes.fit_tests_missing, alert: true, icon: UserRound, href: siteLink('alloc_active') },
                    { key: 'handed_out_30d', label: 'Handed out · last 30 days', value: summary.boxes.handed_out_30d, icon: Send, href: siteLink('alloc_active') },
                ]} /> : undefined}
                rail={<RegisterRailLine rail={<SafetyWorkspaceRail hub="prevention" current="ppe" />} filters={<>
                    <PageHeaderFilterSelect label={`All sites · ${props.sites.length}`} value={props.filters.site_id ? String(props.filters.site_id) : 'all'}
                        options={[{ value: 'all', label: 'All sites' }, ...props.sites.map((site) => ({ value: String(site.id), label: site.name }))]}
                        onChange={(value) => visit({ site_id: value === 'all' ? null : Number(value) })} />
                    <RegisterMoreFilters groups={[
                        { key: 'category', label: 'Category', value: props.filters.category ?? 'all', options: [{ value: 'all', label: 'All categories' }, ...CATEGORY_OPTIONS],
                            onChange: (value) => visit({ category: value === 'all' ? null : value }) },
                        { key: 'status', label: 'Status', value: props.filters.status ?? 'all', options: [{ value: 'all', label: 'Any status' }, ...STATUS_FILTER_OPTIONS], onChange: (value) => visit({ status: value === 'all' ? null : value }) },
                        { key: 'type', label: 'Equipment type', value: props.filters.ppe_type_id ? String(props.filters.ppe_type_id) : 'all',
                            options: [{ value: 'all', label: 'Any equipment type' }, ...props.types.filter((row) => row.source_integrity !== 'repair_required').map((row) => ({ value: String(row.id), label: row.name! }))],
                            onChange: (value) => visit({ ppe_type_id: value === 'all' ? null : Number(value) }) },
                    ]} onClear={() => visit({ category: null, status: null, ppe_type_id: null })} />
                    {narrowed ? <PageHeaderFilterButton icon={X} onClick={() => drafts.request(() => router.get(PPE_REGISTER, { tab: props.tab }))}>Clear filters</PageHeaderFilterButton> : null}
                </>} />} />}
                tabs={<TierTwoTabs tabs={views.map((tab) => ({ ...tab, count: props.tabCounts[tab.key] ?? undefined }))} activeTab={props.tab} onTab={(value) => visit({}, value)}
                    testIdPrefix="ppe-register" ariaLabel="Equipment register views" panelId="ppe-register-panel"
                    // Views carry no href, so the strip renders its own buttons.
                    renderLink={() => null} />}>
                <div id="ppe-register-panel" role="tabpanel" aria-label={views.find((tab) => tab.key === props.tab)?.label ?? 'Equipment'} className="grid min-w-0 gap-5">
                {showRepair && <div role="status"><Note tone="warning">Some equipment records are damaged. You can still see them, but you can’t change them and totals may be wrong. Export won’t work for these records until an administrator fixes them.</Note></div>}
                {props.tab.startsWith('inv_') && summary ? <RegisterBlocks
                    lead={<LeadBlock rows={lead} empty="No equipment is overdue, past its expiry date or missing a fit test."
                        more={leftOver > 0 ? { text: `${leftOver} more ${leftOver === 1 ? 'needs' : 'need'} doing`, action: { label: 'See all', href: siteLink('inv_inspection') } } : null} />}
                    side={<QuietRows rows={quiet} />} /> : null}
                {props.tab === 'types'
                    ? <PpeCatalogueTable rows={typeRows} canAdd={props.can.manage} onAdd={() => open('create_type')} onCommand={open} />
                    : props.tab.startsWith('alloc')
                        ? <PpeAllocationTable rows={props.allocations.data} total={props.allocations.total} returnTo={returnTo} footer={pager(props.allocations)} />
                        : <PpeInventoryTable rows={props.inventory.data} total={props.inventory.total} returnTo={returnTo} footer={pager(props.inventory)} />}
                </div>
            </PageLayout>
            {opened && <PpeCatalogueForm key={opened.command + ':' + (opened.type?.id ?? 'new')} command={opened.command} type={opened.type} actorId={auth.user.id} onClose={close} onDone={done} />}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}
