import { EntityCard, EntityContextMenu, EntityTable, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { PageHeader, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageLayout } from '@/components/page';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalIndexProps, ChemicalRegisterRow, ChemicalTab } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalHref, chemicalReturn } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Archive, ClipboardCheck, Eye, FileText, FlaskConical, MapPin, Plus, Shield, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PHYSICAL_FORMS, STATUS_OPTIONS } from './constants';

const TABS = [{ key: 'all', label: 'All references', icon: FlaskConical }, { key: 'active', label: 'Active', icon: ClipboardCheck },
    { key: 'controlled', label: 'Controlled', icon: Shield }, { key: 'sds_expiring', label: 'Sheet reviews', icon: FileText },
    { key: 'sds_missing', label: 'Missing sheets', icon: ShieldAlert }, { key: 'inactive', label: 'Inactive or removed', icon: Archive }] as const;
const REPORTS = [{ label: 'Board summary', href: '/health-safety/reports/board-summary' }, { label: 'WorkSafe register', href: '/health-safety/reports/worksafe-register' },
    { label: 'Investigation outcomes', href: '/health-safety/reports/investigation-outcomes' }, { label: 'Corrective-action traceability', href: '/health-safety/reports/corrective-action-traceability' }, { label: 'Risk-assessment register', href: '/health-safety/reports/risk-assessment-register' }];
const sdsLabel = (state: string) => ({ current: 'Recorded current', expiring: 'Review approaching', expired: 'Review overdue', missing: 'No current sheet', unknown: 'Currency unknown',
    ambiguous: 'Current sheet unconfirmed', unavailable: 'File unavailable', not_yet_effective: 'Not yet effective', repair_required: 'Source needs review' })[state] ?? 'State unconfirmed';
export default function ChemicalRegister(props: ChemicalIndexProps) {
    const { filters, rows, hero, sites, can, tabCounts } = props; const { props: shared } = usePage<SharedData>();
    const [search, setSearch] = useState(filters.q); const context = useEntityContextMenu<ChemicalRegisterRow>();
    const current = context.ctx ? rows.data.find((row) => row.id === context.ctx?.record.id) : null;
    useEffect(() => setSearch(filters.q), [filters.q]);
    useEffect(() => { if (context.ctx && !current) context.close(); }, [context.ctx, current, context.close]);
    const filtered = (values: Record<string, string | number | null | undefined>) => {
        const query = new URLSearchParams(); for (const [key, value] of Object.entries(values)) if (value !== null && value !== undefined && value !== '') query.set(key, String(value));
        return chemicalReturn(`${CHEMICAL_REGISTER}${query.size ? `?${query}` : ''}`);
    };
    const returnTo = filtered({ ...filters, page: rows.current_page });
    const go = (patch: Record<string, string | number | null>) => router.get(filtered({ ...filters, ...patch }), {}, { preserveState: true, preserveScroll: true });
    const scopeHref = (tab: ChemicalTab, patch: Record<string, string | number> = {}) => filtered({ tab, site_id: filters.site_id, ...patch });
    const recordHref = (row: ChemicalRegisterRow) => chemicalHref(row.id, { returnTo });
    const createHref = `${CHEMICAL_REGISTER}/create?return_to=${encodeURIComponent(returnTo)}`;
    const actions = (row: ChemicalRegisterRow): MenuItem[] => [{ label: row.source_integrity === 'repair_required' ? 'Review chemical source' : 'Open chemical record', icon: Eye, onClick: () => router.get(recordHref(row)) }];
    const state = (row: ChemicalRegisterRow) => <StatusBadge variant={row.source_integrity === 'repair_required' ? 'warning' : 'neutral'}>{row.source_integrity === 'repair_required' ? 'Source needs review' : row.status ?? 'Status unconfirmed'}</StatusBadge>;
    const sheet = (row: ChemicalRegisterRow) => <StatusBadge variant={row.sds_state === 'current' ? 'neutral' : 'warning'}>{sdsLabel(row.sds_state)}</StatusBadge>;
    const name = (row: ChemicalRegisterRow) => row.source_integrity === 'repair_required' ? 'Chemical source needs review' : row.name ?? `Chemical reference ${row.id}`;
    const pages = [rows.current_page - 1, rows.current_page, rows.current_page + 1].filter((page) => page > 0 && page <= rows.last_page);
    const links = [{ url: rows.current_page > 1 ? filtered({ ...filters, page: rows.current_page - 1 }) : null, label: '&laquo; Previous', active: false },
        ...pages.map((page) => ({ url: filtered({ ...filters, page }), label: String(page), active: page === rows.current_page })),
        { url: rows.current_page < rows.last_page ? filtered({ ...filters, page: rows.current_page + 1 }) : null, label: 'Next &raquo;', active: false }];
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Chemical register', href: CHEMICAL_REGISTER }]}>
        <Head title="Chemical register" /><PageLayout hero={<PageHeader frontline title="Chemical register" icon={FlaskConical} subline="Chemical references, safety data sheets and Site storage"
            search={<PageHeaderSearch value={search} onChange={setSearch} ariaLabel="Search chemical references" placeholder="Search references…" onKeyDown={(event) => { if (event.key === 'Enter') go({ q: search, page: 1 }); }} />}
            actions={<>{shared.auth.can?.governance?.view && <DropdownMenu><DropdownMenuTrigger asChild><PageHeaderGlassButton icon={FileText}>Board exports</PageHeaderGlassButton></DropdownMenuTrigger>
                <DropdownMenuContent align="end">{REPORTS.map((report) => <DropdownMenuItem key={report.href} asChild><a href={report.href}>{report.label}</a></DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu>}
                {can.create && <PageHeaderPrimaryButton icon={Plus} onClick={() => router.get(createHref)}>Add reference</PageHeaderPrimaryButton>}</>}
            meters={<>
                <PageHeaderMeterBlock label="Active references" href={scopeHref('active')}><PageHeaderMeterBig>{hero.live.active ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Current Site scope</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Controlled references" href={scopeHref('controlled')}><PageHeaderMeterBig>{hero.live.controlled ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Active or inactive references</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Recorded current sheets" href={scopeHref('active', { sds_state: 'current' })}><PageHeaderMeterBig>{hero.live.sds_current ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Active references · recorded dates</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Sheet reviews" href={scopeHref('sds_expiring')}><PageHeaderMeterBig>{hero.attention.sds_expiring ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Approaching or overdue reviews</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Missing current sheets" href={scopeHref('sds_missing')}><PageHeaderMeterBig>{hero.attention.sds_missing ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>Active references</PageHeaderMeterCaption></PageHeaderMeterBlock>
            </>}
            filters={<>
                <PageHeaderFilterSelect icon={MapPin} label="Site" value={String(filters.site_id ?? 'all')} options={[{ value: 'all', label: 'All approved Sites' }, ...sites.map((site) => ({ value: String(site.id), label: site.name }))]} onChange={(value) => go({ site_id: value === 'all' ? null : Number(value), page: 1 })} />
                <PageHeaderFilterSelect label="Physical form" value={filters.physical_form ?? 'all'} options={[{ value: 'all', label: 'All forms' }, ...PHYSICAL_FORMS]} onChange={(value) => go({ physical_form: value === 'all' ? null : value, page: 1 })} />
                <PageHeaderFilterSelect label="Reference status" value={filters.status ?? 'all'} options={[{ value: 'all', label: 'All statuses' }, ...STATUS_OPTIONS]} onChange={(value) => go({ status: value === 'all' ? null : value, page: 1 })} />
                <PageHeaderFilterSelect label="Controlled" value={filters.controlled ?? 'all'} options={[{ value: 'all', label: 'All references' }, { value: 'controlled', label: 'Controlled' }, { value: 'standard', label: 'Standard' }]} onChange={(value) => go({ controlled: value === 'all' ? null : value, page: 1 })} />
                <PageHeaderFilterSelect label="Sheet state" value={filters.sds_state ?? 'all'} options={[{ value: 'all', label: 'All sheet states' }, ...['current', 'expiring', 'expired', 'missing', 'unknown', 'unavailable', 'not_yet_effective', 'repair_required'].map((value) => ({ value, label: sdsLabel(value) }))]} onChange={(value) => go({ sds_state: value === 'all' ? null : value, page: 1 })} />
            </>} rail={<PageHeaderRail items={TABS.map((tab) => ({ ...tab, ...(tabCounts[tab.key] === null ? {} : { count: tabCounts[tab.key] }) }))} value={filters.tab} onSelect={(tab) => go({ tab, page: 1 })} ariaLabel="Chemical register views" />} />}>
            <div className="space-y-4">
                {props.source_integrity === 'repair_required' && <p role="status" className="text-subtle text-status-warning">Some source history needs review. Those records remain visible with their details and changes withheld. A missing measure is unconfirmed.</p>}
                <p className="text-subtle">References and recorded checks support safe work; they do not certify safe use. Identifiable worker exposures require private wellbeing access and are not counted here.</p>
                {!rows.data.length ? <p className="text-subtle">No admitted chemical references match these filters.</p> : <>
                    <ul className="space-y-3 md:hidden">{rows.data.map((row) => <li key={row.id}><EntityCard name={name(row)} icon={FlaskConical} href={recordHref(row)} actions={actions(row)}
                        subline={row.source_integrity === 'repair_required' ? 'Details withheld' : `${row.physical_form ?? 'Form not recorded'} · ${row.hsno_classification ?? 'Classification not recorded'}`}
                        chips={state(row)} alerts={sheet(row)} onContextMenu={(event) => context.open(event, row)} /></li>)}</ul>
                    <div className="hidden md:block"><EntityTable rows={rows.data} rowKey={(row) => row.id} identityLabel="Chemical reference" identity={(row) => ({ icon: FlaskConical, name: name(row),
                        subline: row.source_integrity === 'repair_required' ? 'Details withheld' : row.common_name ?? row.hsno_classification ?? 'Classification not recorded' })} hrefFor={recordHref} wrapIdentity
                        columns={[{ key: 'form', label: 'Physical form', width: '1fr', cell: (row) => row.source_integrity === 'repair_required' ? 'Withheld' : row.physical_form ?? 'Not recorded' },
                            { key: 'state', label: 'Reference status', width: '1fr', cell: state }, { key: 'sds', label: 'Sheet state', width: '1.4fr', cell: sheet },
                            { key: 'storage', label: 'Storage locations', width: '1fr', cell: (row) => row.storage_count ?? 'Unconfirmed' }]} actionsFor={actions} onRowContextMenu={(event, row) => context.open(event, row)} /></div>
                </>}<LaravelPagination links={links} lastPage={rows.last_page} />
            </div>{context.ctx && current && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} title={name(current)} icon={FlaskConical} items={actions(current)} onClose={context.close} />}
        </PageLayout>
    </AppLayout>;
}
