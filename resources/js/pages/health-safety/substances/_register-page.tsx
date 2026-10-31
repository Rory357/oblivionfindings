/**
 * Chemical register (approved H&S drawing pg-hsn-125 — Stephan 23 Oct 2026): one header with search, Reports and
 * Add chemical, one number box (current safety data sheets — the other numbers are the tab counts), Site, form and
 * status filters with the quieter ones under More filters, the Risks and prevention hub on the rail and the register
 * views as tier-two tabs (drawing D4); then one table.
 * A current sheet reads green so a sheet due for review or missing stands out. The safe-use and wellbeing-access
 * line and the source-repair note keep the code's wording; identifiable exposures are never counted here.
 */
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import { BOARD_REPORTS_LABEL, boardReportLinks } from '@/lib/health-safety/board-reports';
import { RecordIdentity, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import type { MenuItem } from '@/components/lists';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderSearch, PageLayout } from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { PageHeaderNumber, PageHeaderNumbers } from '@/components/page/page-header-strip';
import { Note } from '@/components/signature/blocks';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalIndexProps, ChemicalRegisterFilters, ChemicalRegisterRow, ChemicalTab } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalHref, chemicalReturn } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Archive, ClipboardCheck, Eye, FileText, FlaskConical, MapPin, Plus, Shield, ShieldAlert, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { CHEMICAL_SHEET_LABELS, PHYSICAL_FORMS, STATUS_OPTIONS, chemicalSheetLabel, chemicalSheetTone, optionLabel } from './constants';

const TABS = [{ key: 'all', label: 'All references', icon: FlaskConical }, { key: 'active', label: 'Active', icon: ClipboardCheck },
    { key: 'controlled', label: 'Controlled', icon: Shield }, { key: 'sds_expiring', label: 'Sheet reviews', icon: FileText },
    { key: 'sds_missing', label: 'Missing sheets', icon: ShieldAlert }, { key: 'inactive', label: 'Inactive or removed', icon: Archive }] as const;
const TABLE_TITLE: Record<ChemicalTab, string> = { all: 'All chemicals', active: 'Active chemicals', controlled: 'Controlled chemicals',
    sds_expiring: 'Sheet reviews', sds_missing: 'Missing sheets', inactive: 'Inactive or removed chemicals' };
const REPORTS = boardReportLinks();
const SHEET_FILTERS = ['current', 'expiring', 'expired', 'missing', 'unknown', 'unavailable', 'not_yet_effective', 'repair_required'].map((value) => ({ value, label: CHEMICAL_SHEET_LABELS[value] }));
const SAFE_USE = 'Recorded checks help people work safely; they don’t prove a chemical is safe to use. Exposures are counted above; each record names the worker, so only people with wellbeing access can open them.';

export default function ChemicalRegister(props: ChemicalIndexProps) {
    const { filters, rows, hero, sites, can, tabCounts } = props; const { props: shared } = usePage<SharedData>();
    const safetyCan = usePage<SharedData>().props.auth.can;
    const exposures = props.exposure_summary;
    const [search, setSearch] = useState(filters.q);
    useEffect(() => setSearch(filters.q), [filters.q]);
    const filtered = (values: Record<string, string | number | null | undefined>) => {
        const query = new URLSearchParams(); for (const [key, value] of Object.entries(values)) if (value !== null && value !== undefined && value !== '') query.set(key, String(value));
        return chemicalReturn(`${CHEMICAL_REGISTER}${query.size ? `?${query}` : ''}`);
    };
    const returnTo = filtered({ ...filters, page: rows.current_page });
    const go = (patch: Record<string, string | number | null>) => router.get(filtered({ ...filters, ...patch }), {}, { preserveState: true, preserveScroll: true });
    const scopeHref = (tab: ChemicalTab, patch: Record<string, string | number> = {}) => filtered({ tab, site_id: filters.site_id, ...patch });
    const recordHref = (row: ChemicalRegisterRow) => chemicalHref(row.id, { returnTo });
    const createHref = `${CHEMICAL_REGISTER}/create?return_to=${encodeURIComponent(returnTo)}`;
    const damaged = (row: ChemicalRegisterRow) => row.source_integrity === 'repair_required';
    const actions = (row: ChemicalRegisterRow): MenuItem[] => [{ label: damaged(row) ? 'Review chemical source' : 'Open chemical record', icon: Eye, onClick: () => router.get(recordHref(row)) }];
    const name = (row: ChemicalRegisterRow) => damaged(row) ? 'Chemical source needs review' : row.name ?? `Chemical reference ${row.id}`;
    const withheld = <span className="text-muted-foreground">Withheld</span>;
    const columns: RecordTableColumn<ChemicalRegisterRow>[] = [
        { key: 'chemical', label: 'Chemical', className: 'min-w-[15rem]', cell: (row) => <RecordIdentity title={name(row)}
            sub={damaged(row) ? 'Details withheld' : row.common_name ?? row.hsno_classification ?? 'Classification not recorded'} /> },
        { key: 'form', label: 'Form', nowrap: true, cell: (row) => damaged(row) ? withheld : optionLabel(PHYSICAL_FORMS, row.physical_form) },
        { key: 'status', label: 'Status', nowrap: true, cell: (row) => <StatusBadge variant={damaged(row) ? 'warning' : 'neutral'}>{damaged(row) ? 'Source needs review' : optionLabel(STATUS_OPTIONS, row.status, 'Status unconfirmed')}</StatusBadge> },
        { key: 'sds', label: 'Safety data sheet', nowrap: true, cell: (row) => <StatusBadge variant={chemicalSheetTone(row.sds_state)}>{chemicalSheetLabel(row.sds_state)}</StatusBadge> },
        { key: 'storage', label: 'Storage locations', nowrap: true, cell: (row) => row.storage_count ?? <span className="text-muted-foreground">Not available</span> },
    ];
    const pages = [rows.current_page - 1, rows.current_page, rows.current_page + 1].filter((page) => page > 0 && page <= rows.last_page);
    const links = [{ url: rows.current_page > 1 ? filtered({ ...filters, page: rows.current_page - 1 }) : null, label: '&laquo; Previous', active: false },
        ...pages.map((page) => ({ url: filtered({ ...filters, page }), label: String(page), active: page === rows.current_page })),
        { url: rows.current_page < rows.last_page ? filtered({ ...filters, page: rows.current_page + 1 }) : null, label: 'Next &raquo;', active: false }];
    const from = rows.data.length ? (rows.current_page - 1) * rows.per_page + 1 : 0; const to = from ? from + rows.data.length - 1 : 0;
    const moreActive = [filters.controlled, filters.sds_state].filter(Boolean).length;
    const narrowed = Boolean(filters.q || filters.site_id || filters.physical_form || filters.status || moreActive);
    const footer: ReactNode = <>
        <p className="text-subtle">Showing {rows.data.length} of {rows.total}</p>
        {rows.last_page > 1 ? <LaravelPagination links={links} lastPage={rows.last_page} /> : null}
    </>;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, ...safetyHubCrumbs('prevention', safetyCan), { title: 'Chemical register', href: CHEMICAL_REGISTER }]}>
        <Head title="Chemical register" /><PageLayout hero={<PageHeader title="Chemical register" icon={FlaskConical} subline="Chemicals, their safety data sheets and where each is stored"
            actions={<><PageHeaderSearch value={search} onChange={setSearch} ariaLabel="Search chemical references" placeholder="Search references…" onKeyDown={(event) => { if (event.key === 'Enter') go({ q: search, page: 1 }); }} />
                {shared.auth.can?.governance?.view && <DropdownMenu><DropdownMenuTrigger asChild><PageHeaderGlassButton icon={FileText}>{BOARD_REPORTS_LABEL}</PageHeaderGlassButton></DropdownMenuTrigger>
                <DropdownMenuContent align="end">{REPORTS.map((report) => <DropdownMenuItem key={report.key} asChild><Link href={report.href}>{report.label}</Link></DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu>}
                {can.create && <PageHeaderPrimaryButton icon={Plus} onClick={() => router.get(createHref)}>Add chemical</PageHeaderPrimaryButton>}</>}
            strip={<PageHeaderNumbers>
                <PageHeaderNumber value={hero.live.sds_current ?? 'Not available'} label="Current safety data sheets" caption="Active chemicals" href={scopeHref('active', { sds_state: 'current' })} />
                {exposures ? <>
                    <PageHeaderNumber value={exposures.exposures} label="Exposures" caption={`Last ${exposures.period_days} days`} href={exposures.exposures_href ?? CHEMICAL_EXPOSURES} />
                    <PageHeaderNumber value={exposures.awaiting_outcome} label="Awaiting a medical outcome" caption="Medical help was sought" href={exposures.exposures_href ?? CHEMICAL_EXPOSURES} />
                    <PageHeaderNumber value={exposures.worksafe_awaiting} label="WorkSafe notification to make" caption="Exposure events" href="/health-safety/events?tab=worksafe_pending" />
                </> : null}
            </PageHeaderNumbers>}
            filters={<>
                <PageHeaderFilterSelect icon={MapPin} label="All sites" value={String(filters.site_id ?? 'all')} options={[{ value: 'all', label: 'All sites' }, ...sites.map((site) => ({ value: String(site.id), label: site.name }))]} onChange={(value) => go({ site_id: value === 'all' ? null : Number(value), page: 1 })} />
                <PageHeaderFilterSelect label="All forms" value={filters.physical_form ?? 'all'} options={[{ value: 'all', label: 'All forms' }, ...PHYSICAL_FORMS]} onChange={(value) => go({ physical_form: value === 'all' ? null : value, page: 1 })} />
                <PageHeaderFilterSelect label="All statuses" value={filters.status ?? 'all'} options={[{ value: 'all', label: 'All statuses' }, ...STATUS_OPTIONS]} onChange={(value) => go({ status: value === 'all' ? null : value, page: 1 })} />
                <MoreFilters filters={filters} active={moreActive} onChange={(patch) => go({ ...patch, page: 1 })} />
                {narrowed ? <PageHeaderFilterButton icon={X} onClick={() => go({ q: null, site_id: null, physical_form: null, status: null, controlled: null, sds_state: null, page: 1 })}>Clear filters</PageHeaderFilterButton> : null}
            </>} rail={<SafetyWorkspaceRail hub="prevention" current="chemicals" />} />}
            tabs={<TierTwoTabs tabs={TABS.map((tab) => ({ ...tab, count: tabCounts[tab.key] ?? undefined }))} activeTab={filters.tab} onTab={(tab) => go({ tab, page: 1 })}
                testIdPrefix="chemical-register" ariaLabel="Chemical register views" panelId="chemical-register-panel"
                // Views carry no href, so the strip renders its own buttons.
                renderLink={() => null} />}>
            <div id="chemical-register-panel" role="tabpanel" aria-label={TABLE_TITLE[filters.tab] ?? 'Chemicals'} className="grid min-w-0 gap-5">
                {props.source_integrity === 'repair_required' && <div role="status"><Note tone="warning">Some source history needs review. Those records remain visible with their details and changes withheld. A missing measure is unconfirmed.</Note></div>}
                <RecordTable title={TABLE_TITLE[filters.tab] ?? 'Chemicals'} sub={`${rows.data.length ? `Showing ${from}–${to} of ${rows.total} · ` : ''}${SAFE_USE}`}
                    ariaLabel="Chemical references" columns={columns} rows={rows.data} rowKey={(row) => row.id} onOpen={(row) => router.get(recordHref(row))}
                    actionsFor={actions} menuTitle={name} menuIcon={FlaskConical} footer={rows.data.length ? footer : undefined}
                    empty={<p role="status" className="sg-quiet px-4 py-6"><span>No admitted chemical references match these filters.</span></p>} />
            </div>
        </PageLayout>
    </AppLayout>;
}

/** Controlled and sheet state — the quieter filters. */
function MoreFilters({ filters, active, onChange }: { filters: ChemicalRegisterFilters; active: number; onChange: (patch: Record<string, string | null>) => void }) {
    const select = (key: 'controlled' | 'sds_state', label: string, all: string, options: { value: string; label: string }[]) => <div className="grid gap-1.5">
        <span className="text-caption font-semibold">{label}</span>
        <SelectInput ariaLabel={label} placeholder={all} value={String(filters[key] ?? 'all')} onChange={(value) => onChange({ [key]: value === 'all' ? null : value })} options={[{ value: 'all', label: all }, ...options]} />
    </div>;
    return <Popover>
        <PopoverTrigger asChild><PageHeaderFilterButton icon={SlidersHorizontal} active={active > 0}>{active > 0 ? `More filters · ${active}` : 'More filters'}</PageHeaderFilterButton></PopoverTrigger>
        <PopoverContent align="start" className="grid w-72 max-w-[calc(100vw-2rem)] gap-3">
            {select('controlled', 'Controlled', 'All references', [{ value: 'controlled', label: 'Controlled' }, { value: 'standard', label: 'Standard' }])}
            {select('sds_state', 'Safety data sheet', 'All sheet states', SHEET_FILTERS)}
        </PopoverContent>
    </Popover>;
}
