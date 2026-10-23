import { severityLabel, statusLabel } from '@/components/health-safety/injury-constants';
import { InjuryRegisterList, type InjuryRegisterRow } from '@/components/health-safety/injury-register-list';
import { PageHeader, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Field } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryReturn } from '@/lib/health-safety/injury-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, HeartPulse, Plus, Search, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

type Filters = { q: string; site_id: number | null; severity: string | null; status: string | null; treatment: string | null; acc_open: boolean | null; worksafe: boolean | null; period: string; from: string | null; to: string | null; type: string | null; body_part: string | null };
type Props = { injuries: { data: InjuryRegisterRow[]; total: number; links: { url: string | null; label: string; active: boolean }[]; last_page: number }; tab: string; tabCounts: Record<string, number>; filters: Filters; sites: { id: number; name: string }[]; options: { injury_types: Record<string, string>; treatments: Record<string, string> | null; severities: string[]; statuses: string[]; periods: string[] }; medical_access: 'readable' | 'restricted'; can: { create: boolean; read_medical: boolean } };
const TABS = [{ key: 'all', label: 'All injuries' }, { key: 'reported', label: 'Reported' }, { key: 'under_treatment', label: 'Treatment' }, { key: 'return_to_work', label: 'Recovery' }, { key: 'recovered', label: 'Recovered' }, { key: 'closed', label: 'Closed' }, { key: 'worksafe', label: 'WorkSafe flag' }, { key: 'acc', label: 'Claims recorded' }];
const METERS = TABS.filter((item) => ['all', 'reported', 'under_treatment', 'return_to_work', 'recovered', 'worksafe'].includes(item.key));
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
    const filter = (key: keyof Filters, label: string, choices: { value: string; label: string }[]) => <PageHeaderFilterSelect label={label} value={filters[key] == null ? 'all' : typeof filters[key] === 'boolean' ? (filters[key] ? '1' : '0') : String(filters[key])} options={choices} onChange={(value) => visit({ [key]: value === 'all' ? null : key === 'site_id' ? Number(value) : ['worksafe', 'acc_open'].includes(key) ? value === '1' : value })} />;
    const currentQuery = query(filters, tab);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Workplace injuries', href: INJURY_REGISTER }]}>
        <Head title="Workplace injuries" />
        <PageLayout hero={<PageHeader frontline title="Workplace injuries" icon={HeartPulse} subline="Injury facts, named coordination and connected recovery work" mobileSummary={`${injuries.total} matching injuries`} actions={<>
            <PageHeaderSearch value={search} onChange={setSearch} onKeyDown={(event) => { if (event.key === 'Enter') visit({ q: search }); }} placeholder="Find an injury…" ariaLabel="Search workplace injuries" />
            <PageHeaderGlassButton icon={Search} onClick={() => visit({ q: search })}>Search</PageHeaderGlassButton>
            <PageHeaderGlassButton icon={Download} asChild><a href={`${INJURY_REGISTER}/export?${currentQuery}`}>Export</a></PageHeaderGlassButton>
            {can.create && <PageHeaderPrimaryButton icon={Plus} asChild><Link href={`${INJURY_REGISTER}/create?return_to=${encodeURIComponent(returnTo)}`}>Report injury</Link></PageHeaderPrimaryButton>}
        </>} meters={<>{METERS.map((item) => <PageHeaderMeterBlock key={item.key} label={item.label} href={`${INJURY_REGISTER}?${query(filters, item.key)}`}><PageHeaderMeterBig>{tabCounts[item.key] ?? 0}</PageHeaderMeterBig><PageHeaderMeterCaption>{item.key === 'worksafe' ? 'Recorded flag · check the linked decision' : 'Within these filters'}</PageHeaderMeterCaption></PageHeaderMeterBlock>)}</>} filters={<>
            {filter('site_id', 'Site', sites.map((site) => ({ value: String(site.id), label: site.name })))}
            {filter('severity', 'Severity', options.severities.map((value) => ({ value, label: severityLabel(value) })))}
            {filter('status', 'Status', options.statuses.map((value) => ({ value, label: statusLabel(value) })))}
            {filter('type', 'Injury type', Object.entries(options.injury_types).map(([value, label]) => ({ value, label })))}
            {filter('period', 'Period', options.periods.map((value) => ({ value, label: ({ all: 'All time', week: 'This week', '30d': 'Last 30 days', quarter: 'This quarter', year: 'This year' } as Record<string, string>)[value] ?? value })))}
            {options.treatments && filter('treatment', 'Treatment', Object.entries(options.treatments).map(([value, label]) => ({ value, label })))}
            {filter('worksafe', 'WorkSafe flag', [{ value: '1', label: 'Flag recorded' }, { value: '0', label: 'Flag not recorded' }])}
            {filter('acc_open', 'Claim record', [{ value: '1', label: 'Recorded claim · injury open' }, { value: '0', label: 'No open claim record' }])}
            <Popover open={advancedOpen} onOpenChange={setAdvancedOpen}><PopoverTrigger asChild><PageHeaderGlassButton icon={SlidersHorizontal}>More filters</PageHeaderGlassButton></PopoverTrigger><PopoverContent className="w-80 max-w-[calc(100vw-2rem)] space-y-4" align="start">
                <Field label="From injury date"><Input className="frontline-tap" type="date" value={extra.from} onChange={(event) => setExtra({ ...extra, from: event.target.value })} /></Field>
                <Field label="To injury date"><Input className="frontline-tap" type="date" value={extra.to} onChange={(event) => setExtra({ ...extra, to: event.target.value })} /></Field>
                <Field label="Body part"><Input className="frontline-tap" maxLength={200} value={extra.body_part} onChange={(event) => setExtra({ ...extra, body_part: event.target.value })} /></Field>
                <Button className="frontline-tap" onClick={() => { setAdvancedOpen(false); visit({ from: extra.from || null, to: extra.to || null, body_part: extra.body_part || null }); }}>Apply filters</Button>
            </PopoverContent></Popover>
            <PageHeaderGlassButton onClick={() => router.get(INJURY_REGISTER)}>Clear filters</PageHeaderGlassButton>
        </>} rail={<PageHeaderRail items={TABS.map((item) => ({ ...item, count: tabCounts[item.key] }))} value={tab} onSelect={(value) => visit({}, value)} ariaLabel="Injury views" />} />}>
            <InjuryRegisterList rows={injuries.data} total={injuries.total} returnTo={returnTo} medical={medical_access === 'readable' && can.read_medical} />
            <LaravelPagination links={injuries.links} lastPage={injuries.last_page} />
            {medical_access === 'restricted' && <p className="text-caption text-muted-foreground">This view shows injury facts. Medical information requires separate access.</p>}
        </PageLayout>
    </AppLayout>;
}
