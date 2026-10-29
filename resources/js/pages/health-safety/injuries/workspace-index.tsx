/**
 * Workplace injuries (approved H&S drawings pg-hsn-86, 86b — Stephan 23 Oct
 * 2026): one header with the register filters (the quieter ones under More
 * filters) and the views on the rail, then the injuries as a table with each
 * injury's five stage dots. The WorkSafe wording keeps its meaning: the flag
 * only says someone marked the injury notifiable.
 */
import { severityLabel, statusLabel } from '@/components/health-safety/injury-constants';
import { InjuryRegisterList, type InjuryRegisterRow } from '@/components/health-safety/injury-register-list';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageLayout } from '@/components/page';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Field, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryReturn } from '@/lib/health-safety/injury-navigation';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, HeartPulse, Lock, Plus, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';

type Filters = { q: string; site_id: number | null; severity: string | null; status: string | null; treatment: string | null; acc_open: boolean | null; worksafe: boolean | null; period: string; from: string | null; to: string | null; type: string | null; body_part: string | null };
type Props = { injuries: { data: InjuryRegisterRow[]; total: number; links: { url: string | null; label: string; active: boolean }[]; last_page: number }; tab: string; tabCounts: Record<string, number>; filters: Filters; sites: { id: number; name: string }[]; options: { injury_types: Record<string, string>; treatments: Record<string, string> | null; severities: string[]; statuses: string[]; periods: string[] }; medical_access: 'readable' | 'restricted'; can: { create: boolean; read_medical: boolean } };
const TABS = [{ key: 'all', label: 'All injuries' }, { key: 'reported', label: 'Reported' }, { key: 'under_treatment', label: 'Under treatment' }, { key: 'return_to_work', label: 'Return to work' }, { key: 'recovered', label: 'Recovered' }, { key: 'closed', label: 'Closed' }, { key: 'worksafe', label: 'Marked notifiable' }, { key: 'acc', label: 'ACC claim recorded' }];
const PERIODS: Record<string, string> = { all: 'All time', week: 'Last 7 days', '30d': 'Last 30 days', quarter: 'Last 3 months', year: 'Last 12 months' };
function query(filters: Filters, tab: string) {
    const value = new URLSearchParams({ tab });
    Object.entries(filters).forEach(([key, item]) => { if (item !== null && item !== '') value.set(key, typeof item === 'boolean' ? (item ? '1' : '0') : String(item)); });
    return value.toString();
}
export default function InjuryRegister({ injuries, tab, tabCounts, filters, sites, options, medical_access, can }: Props) {
    const { url } = usePage();
    const [search, setSearch] = useState(filters.q);
    const [extra, setExtra] = useState({ from: filters.from ?? '', to: filters.to ?? '', body_part: filters.body_part ?? '' });
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const returnTo = injuryReturn(url);
    const visit = (change: Partial<Filters>, nextTab = tab) => router.get(INJURY_REGISTER, Object.fromEntries(new URLSearchParams(query({ ...filters, ...change }, nextTab))), { preserveState: false, preserveScroll: true });
    const value = (key: keyof Filters) => filters[key] == null ? 'all' : typeof filters[key] === 'boolean' ? (filters[key] ? '1' : '0') : String(filters[key]);
    const set = (key: keyof Filters, next: string) => visit({ [key]: next === 'all' ? null : key === 'site_id' ? Number(next) : ['worksafe', 'acc_open'].includes(key) ? next === '1' : next });
    const filter = (key: keyof Filters, label: string, choices: { value: string; label: string }[]) => <PageHeaderFilterSelect label={label} value={value(key)} options={[{ value: 'all', label }, ...choices]} onChange={(next) => set(key, next)} />;
    const more = (key: keyof Filters, label: string, all: string, choices: { value: string; label: string }[]) => <Field label={label}>
        <SelectInput ariaLabel={label} placeholder={all} value={value(key)} options={[{ value: 'all', label: all }, ...choices]} onChange={(next) => { setAdvancedOpen(false); set(key, next); }} />
    </Field>;
    const moreActive = [filters.status, filters.type, filters.treatment, filters.worksafe, filters.acc_open, filters.from, filters.to, filters.body_part].filter((item) => item !== null && item !== '').length;
    const filtered = !!filters.q || !!filters.site_id || !!filters.severity || (filters.period && filters.period !== 'all') || moreActive > 0;
    const currentQuery = query(filters, tab);
    const restricted = medical_access === 'restricted';
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' }, { title: 'Workplace injuries', href: INJURY_REGISTER }]}>
        <Head title="Workplace injuries" />
        <PageLayout hero={<PageHeader title="Workplace injuries" icon={HeartPulse} subline="Staff injuries, who is coordinating and the recovery work" actions={<>
            <PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ q: search }); }} placeholder="Find an injury…" ariaLabel="Search workplace injuries" />
            <PageHeaderGlassButton icon={Download} asChild><a href={`${INJURY_REGISTER}/export?${currentQuery}`}>Export to spreadsheet (CSV)</a></PageHeaderGlassButton>
            {can.create && <PageHeaderPrimaryButton icon={Plus} asChild><Link href={`${INJURY_REGISTER}/create?return_to=${encodeURIComponent(returnTo)}`}>Report injury</Link></PageHeaderPrimaryButton>}
        </>} filters={<>
            {filter('site_id', 'All sites', sites.map((site) => ({ value: String(site.id), label: site.name })))}
            {filter('period', 'All time', options.periods.filter((item) => item !== 'all').map((item) => ({ value: item, label: PERIODS[item] ?? humaniseHsValue(item) })))}
            {filter('severity', 'Any severity', options.severities.map((item) => ({ value: item, label: severityLabel(item) })))}
            <Popover open={advancedOpen} onOpenChange={setAdvancedOpen}><PopoverTrigger asChild><PageHeaderFilterButton icon={SlidersHorizontal} active={moreActive > 0}>{moreActive > 0 ? `More filters · ${moreActive}` : 'More filters'}</PageHeaderFilterButton></PopoverTrigger>
                <PopoverContent className="grid w-80 max-w-[calc(100vw-2rem)] gap-3" align="start">
                    {more('status', 'Status', 'All statuses', options.statuses.map((item) => ({ value: item, label: statusLabel(item) })))}
                    {more('type', 'Injury type', 'All injury types', Object.entries(options.injury_types).map(([item, label]) => ({ value: item, label })))}
                    {options.treatments && more('treatment', 'Treatment', 'All treatments', Object.entries(options.treatments).map(([item, label]) => ({ value: item, label })))}
                    {more('worksafe', 'Notifiable to WorkSafe', 'Any', [{ value: '1', label: 'Marked notifiable' }, { value: '0', label: 'Not marked notifiable' }])}
                    {more('acc_open', 'ACC claim', 'Any', [{ value: '1', label: 'ACC claim recorded, injury still open' }, { value: '0', label: 'No ACC claim on an open injury' }])}
                    <Field label="From injury date"><Input type="date" value={extra.from} onChange={(event) => setExtra({ ...extra, from: event.target.value })} /></Field>
                    <Field label="To injury date"><Input type="date" value={extra.to} onChange={(event) => setExtra({ ...extra, to: event.target.value })} /></Field>
                    <Field label="Body part"><Input maxLength={200} value={extra.body_part} onChange={(event) => setExtra({ ...extra, body_part: event.target.value })} /></Field>
                    <Button onClick={() => { setAdvancedOpen(false); visit({ from: extra.from || null, to: extra.to || null, body_part: extra.body_part || null }); }}>Apply filters</Button>
                </PopoverContent></Popover>
            {filtered && <PageHeaderFilterButton icon={X} onClick={() => router.get(INJURY_REGISTER)}>Clear filters</PageHeaderFilterButton>}
        </>} rail={<PageHeaderRail items={TABS.map((item) => ({ ...item, count: tabCounts[item.key] }))} value={tab} onSelect={(next) => visit({}, next)} ariaLabel="Injury views" />} />}>
            <div className="grid min-w-0 gap-5">
                {restricted && <Note icon={Lock}>You can see the injury details. Medical details are hidden — ask a health and safety manager if you need them.</Note>}
                <InjuryRegisterList rows={injuries.data} total={injuries.total} returnTo={returnTo} medical={!restricted && can.read_medical}
                    sub="Newest first · ‘Notifiable’ means someone marked it for WorkSafe — open the injury to see the decision"
                    footer={<><p className="text-subtle">Showing {injuries.data.length} of {injuries.total}</p>{injuries.last_page > 1 ? <LaravelPagination links={injuries.links} lastPage={injuries.last_page} /> : null}</>} />
            </div>
        </PageLayout>
    </AppLayout>;
}
