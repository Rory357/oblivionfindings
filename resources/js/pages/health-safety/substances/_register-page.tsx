/**
 * Chemical register (board G4-14, approved by Stephan 1 Nov 2026): the G2 header — four boxes (current SDS, SDS reviews
 * overdue, no current SDS, controlled chemicals; "No trend yet" on each, the alert tone only where someone must act),
 * then one line with the Risks and prevention pages, the site filter, More filters and Find — and the register views as
 * tier-two tabs. Then one lead block ("Needs doing now": overdue SDS reviews, then chemicals with no current sheet), the
 * quiet rows (storage locations, exposures in Injuries and care) and the full table. The board-report links are gone.
 * The safe-use and wellbeing-access line and the source-repair note keep the code's wording; identifiable exposures
 * are never counted here.
 */
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import { RecordIdentity, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import type { MenuItem } from '@/components/lists';
import {
    LeadBlock,
    QuietRows,
    RegisterBlocks,
    RegisterBoxes,
    RegisterMoreFilters,
    RegisterRailLine,
    type LeadRow,
    type QuietRow,
} from '@/components/health-safety/register-kit';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderPrimaryButton, PageHeaderSearch, PageLayout } from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Note } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalIndexProps, ChemicalRegisterRow, ChemicalTab } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalHref, chemicalReturn } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Activity, Archive, ClipboardCheck, Eye, FileCheck2, FileText, FileX2, FlaskConical, MapPin, Plus, Shield, ShieldAlert, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { CHEMICAL_SHEET_LABELS, PHYSICAL_FORMS, STATUS_OPTIONS, chemicalSheetLabel, chemicalSheetTone, optionLabel } from './constants';

/** The four register views drawn under the header (G4-14); the other two open from the boxes. */
const TABS = [{ key: 'all', label: 'All chemicals', icon: FlaskConical }, { key: 'active', label: 'Active', icon: ClipboardCheck },
    { key: 'sds_expiring', label: 'SDS reviews', icon: FileText }, { key: 'inactive', label: 'Removed', icon: Archive }] as const;
const OTHER_TABS = [{ key: 'controlled', label: 'Controlled', icon: Shield }, { key: 'sds_missing', label: 'No current SDS', icon: ShieldAlert }] as const;
const TABLE_TITLE: Record<ChemicalTab, string> = { all: 'All chemicals', active: 'Active chemicals', controlled: 'Controlled chemicals',
    sds_expiring: 'SDS reviews', sds_missing: 'No current SDS', inactive: 'Inactive or removed chemicals' };
const SHEET_FILTERS = ['current', 'expiring', 'expired', 'missing', 'unknown', 'unavailable', 'not_yet_effective', 'repair_required'].map((value) => ({ value, label: CHEMICAL_SHEET_LABELS[value] }));
const SAFE_USE = 'Recorded checks help people work safely; they don’t prove a chemical is safe to use. Exposures each name the worker, so only people with wellbeing access can open them.';

export default function ChemicalRegister(props: ChemicalIndexProps) {
    const { filters, rows, hero, sites, can, tabCounts } = props;
    const safetyCan = usePage<SharedData>().props.auth.can;
    const exposures = props.exposure_summary;
    const summary = props.register_summary;
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
        { key: 'sds', label: 'SDS', nowrap: true, cell: (row) => <StatusBadge variant={chemicalSheetTone(row.sds_state)}>{chemicalSheetLabel(row.sds_state)}</StatusBadge> },
        { key: 'storage', label: 'Storage locations', nowrap: true, cell: (row) => row.storage_count ?? <span className="text-muted-foreground">Not available</span> },
    ];
    const pages = [rows.current_page - 1, rows.current_page, rows.current_page + 1].filter((page) => page > 0 && page <= rows.last_page);
    const links = [{ url: rows.current_page > 1 ? filtered({ ...filters, page: rows.current_page - 1 }) : null, label: '&laquo; Previous', active: false },
        ...pages.map((page) => ({ url: filtered({ ...filters, page }), label: String(page), active: page === rows.current_page })),
        { url: rows.current_page < rows.last_page ? filtered({ ...filters, page: rows.current_page + 1 }) : null, label: 'Next &raquo;', active: false }];
    const from = rows.data.length ? (rows.current_page - 1) * rows.per_page + 1 : 0; const to = from ? from + rows.data.length - 1 : 0;
    const narrowed = Boolean(filters.q || filters.site_id || filters.physical_form || filters.status || filters.controlled || filters.sds_state);
    const footer: ReactNode = <>
        <p className="text-subtle">{rows.total ? `Showing ${from}–${to} of ${rows.total}` : 'Showing 0 of 0'}</p>
        {rows.last_page > 1 ? <LaravelPagination links={links} lastPage={rows.last_page} /> : null}
    </>;
    const views = [...TABS, ...OTHER_TABS.filter((tab) => tab.key === filters.tab)];
    // Lead rows (G4-14): only managers get the button; everyone else is told who does it.
    const lead: LeadRow[] = (summary?.lead ?? []).map((row) => {
        const title = row.name ?? `Chemical reference ${row.id}`;
        const overdue = row.sds_state === 'expired';
        return {
            key: row.id,
            title,
            sub: overdue ? 'SDS review overdue' : 'No current safety data sheet',
            chip: { label: overdue ? 'SDS review overdue' : 'No current SDS', tone: 'critical' },
            action: can.manage
                ? { label: overdue ? 'Review SDS' : 'Add SDS', href: chemicalHref(row.id, { section: 'sds', action: overdue ? undefined : 'add_sds', returnTo }), ariaLabel: `${overdue ? 'Review SDS' : 'Add SDS'}: ${title}` }
                : null,
            who: can.manage ? null : 'Health and safety update it',
        };
    });
    const quiet: QuietRow[] = [
        ...(hero.live.storage_locations !== null ? [{ key: 'storage', icon: MapPin, title: 'Storage locations', sub: 'Where each chemical is kept, by site', aside: String(hero.live.storage_locations), href: scopeHref('active') }] : []),
        ...(exposures ? [{ key: 'exposures', icon: Activity, title: 'Exposures', elsewhere: true, sub: `Injuries and care · ${exposures.exposures} in the last ${exposures.period_days} days`, href: exposures.exposures_href ?? CHEMICAL_EXPOSURES }] : []),
    ];
    const leftOver = summary?.lead_total != null ? summary.lead_total - lead.length : 0;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, ...safetyHubCrumbs('prevention', safetyCan), { title: 'Chemical register', href: CHEMICAL_REGISTER }]}>
        <Head title="Chemical register" /><PageLayout hero={<PageHeader title="Chemical register" icon={FlaskConical} subline="Chemicals, their safety data sheets (SDS) and where each is stored"
            actions={<><PageHeaderSearch value={search} onChange={setSearch} ariaLabel="Search chemical references" placeholder="Search chemicals…" onKeyDown={(event) => { if (event.key === 'Enter') go({ q: search, page: 1 }); }} />
                {can.create && <PageHeaderPrimaryButton icon={Plus} onClick={() => router.get(createHref)}>Add chemical</PageHeaderPrimaryButton>}</>}
            strip={<RegisterBoxes boxes={[
                { key: 'sds_current', label: 'Current SDS', value: hero.live.sds_current, icon: FileCheck2, href: scopeHref('active', { sds_state: 'current' }) },
                { key: 'sds_overdue', label: 'SDS reviews overdue', value: summary?.sds_overdue ?? null, alert: true, icon: FileText, href: scopeHref('sds_expiring') },
                { key: 'sds_missing', label: 'No current SDS', value: hero.attention.sds_missing, alert: true, icon: FileX2, href: scopeHref('sds_missing') },
                { key: 'controlled', label: 'Controlled chemicals', value: hero.live.controlled, icon: Shield, href: scopeHref('controlled') },
            ]} />}
            rail={<RegisterRailLine rail={<SafetyWorkspaceRail hub="prevention" current="chemicals" />} filters={<>
                <PageHeaderFilterSelect icon={MapPin} label={`All sites · ${sites.length}`} value={String(filters.site_id ?? 'all')} options={[{ value: 'all', label: 'All sites' }, ...sites.map((site) => ({ value: String(site.id), label: site.name }))]} onChange={(value) => go({ site_id: value === 'all' ? null : Number(value), page: 1 })} />
                <RegisterMoreFilters groups={[
                    { key: 'form', label: 'Form', value: filters.physical_form ?? 'all', options: [{ value: 'all', label: 'All forms' }, ...PHYSICAL_FORMS], onChange: (value) => go({ physical_form: value === 'all' ? null : value, page: 1 }) },
                    { key: 'status', label: 'Status', value: filters.status ?? 'all', options: [{ value: 'all', label: 'All statuses' }, ...STATUS_OPTIONS], onChange: (value) => go({ status: value === 'all' ? null : value, page: 1 }) },
                    { key: 'controlled', label: 'Controlled', value: filters.controlled ?? 'all', options: [{ value: 'all', label: 'All chemicals' }, { value: 'controlled', label: 'Controlled' }, { value: 'standard', label: 'Standard' }], onChange: (value) => go({ controlled: value === 'all' ? null : value, page: 1 }) },
                    { key: 'sds', label: 'Safety data sheet', value: filters.sds_state ?? 'all', options: [{ value: 'all', label: 'All sheet states' }, ...SHEET_FILTERS], onChange: (value) => go({ sds_state: value === 'all' ? null : value, page: 1 }) },
                ]} onClear={() => go({ physical_form: null, status: null, controlled: null, sds_state: null, page: 1 })} />
                {narrowed ? <PageHeaderFilterButton icon={X} onClick={() => go({ q: null, site_id: null, physical_form: null, status: null, controlled: null, sds_state: null, page: 1 })}>Clear filters</PageHeaderFilterButton> : null}
            </>} />} />}
            tabs={<TierTwoTabs tabs={views.map((tab) => ({ ...tab, count: tabCounts[tab.key] ?? undefined }))} activeTab={filters.tab} onTab={(tab) => go({ tab, page: 1 })}
                testIdPrefix="chemical-register" ariaLabel="Chemical register views" panelId="chemical-register-panel"
                // Views carry no href, so the strip renders its own buttons.
                renderLink={() => null} />}>
            <div id="chemical-register-panel" role="tabpanel" aria-label={TABLE_TITLE[filters.tab] ?? 'Chemicals'} className="grid min-w-0 gap-5">
                {props.source_integrity === 'repair_required' && <div role="status"><Note tone="warning">Some source history needs review. Those records remain visible with their details and changes withheld. A missing measure is unconfirmed.</Note></div>}
                {summary ? <RegisterBlocks
                    lead={<LeadBlock sub="SDS reviews overdue first" rows={lead} empty={summary.lead_total === null ? 'These counts can’t be confirmed until the damaged records are fixed.' : 'No SDS reviews are overdue and every active chemical has a current sheet.'}
                        more={leftOver > 0 ? { text: `${leftOver} more ${leftOver === 1 ? 'needs' : 'need'} doing`, action: { label: 'See all', href: scopeHref('sds_expiring') } } : null} />}
                    side={quiet.length ? <QuietRows rows={quiet} /> : undefined} /> : null}
                <RecordTable title={TABLE_TITLE[filters.tab] ?? 'Chemicals'} sub={SAFE_USE}
                    ariaLabel="Chemical references" columns={columns} rows={rows.data} rowKey={(row) => row.id} onOpen={(row) => router.get(recordHref(row))}
                    actionsFor={actions} menuTitle={name} menuIcon={FlaskConical} footer={rows.data.length ? footer : undefined}
                    empty={<p role="status" className="sg-quiet px-4 py-6"><span>No admitted chemical references match these filters.</span></p>} />
            </div>
        </PageLayout>
    </AppLayout>;
}
