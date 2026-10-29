/**
 * Private chemical exposures (approved H&S drawings pg-hsn-128, 128b — Stephan 23 Oct 2026): one header with the
 * capture entry, the exposure and missing-outcome numbers, Site and exposure-time filters; then the recovery line
 * and one table where "No outcome yet" reads as an amber badge.
 *
 * Exposure records are health information. Rows, Site choices, counts and filters show only when the server admits
 * private wellbeing access (`exposure_access === 'readable'`, which needs hr.wellbeing.view); otherwise only the
 * restricted or unavailable line shows. The privacy, recovery and access lines keep the code's wording.
 */
import { RecordIdentity, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import type { MenuItem } from '@/components/lists';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderPrimaryButton, PageLayout } from '@/components/page';
import { PageHeaderNumber, PageHeaderNumbers } from '@/components/page/page-header-strip';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { exposureTimeChoices, exposureTimeInput, exposureTimeInstant } from '@/lib/health-safety/chemical-exposure-form';
import { CHEMICAL_EXPOSURES, exposureHref, exposureReturn } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureIndexProps } from '@/lib/health-safety/chemical-exposure-read-contract';
import { CHEMICAL_REGISTER } from '@/lib/health-safety/chemical-navigation';
import { Head, router } from '@inertiajs/react';
import { CalendarClock, Eye, FlaskConical, Info, LockKeyhole, MapPin, RefreshCw, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Row = NonNullable<ExposureIndexProps['records']>[number];
function readable(row: Row): row is Extract<Row, { worker: unknown }> { return row.attribution_state !== 'repair_required' && 'worker' in row; }
export default function ChemicalExposureIndex({ exposure_access, records, counts, page, filters, sites, can, source_integrity, count_integrity }: ExposureIndexProps) {
    const admitted = exposure_access === 'readable'; const restricted = exposure_access === 'restricted'; const rows = admitted ? records ?? [] : [];
    const pendingCount = count_integrity && count_integrity.awaiting_medical_outcome !== 'confirmed' ? null : counts?.awaiting_medical_outcome;
    const note = useRef<HTMLDivElement>(null);
    const filterHref = (patch: Record<string, string | number | null> = {}) => {
        const query = new URLSearchParams(); for (const [key, value] of Object.entries({ ...filters, ...patch })) if (value !== null && value !== '') query.set(key, String(value));
        return exposureReturn(`${CHEMICAL_EXPOSURES}${query.size ? '?' + query : ''}`);
    };
    const returnTo = filterHref(); const go = (patch: Record<string, string | number | null>) => router.get(filterHref(patch), {}, { preserveState: true, preserveScroll: true });
    const rowHref = (row: Row) => row.href === `${CHEMICAL_EXPOSURES}/${row.id}` ? exposureHref(row.id, { returnTo }) : undefined;
    const name = (row: Row) => readable(row) ? row.worker.name : 'Exposure source needs review';
    const state = (row: Row) => <StatusBadge variant={row.attribution_state === 'recorded' ? 'neutral' : 'warning'}>{row.attribution_state === 'recorded' ? 'Recorded source' : row.attribution_state === 'legacy_unknown' ? 'Earlier provenance unconfirmed' : 'Details withheld'}</StatusBadge>;
    const actions = (row: Row): MenuItem[] => [
        ...(rowHref(row) ? [{ label: readable(row) ? 'Open private exposure' : 'Review source access', icon: Eye, onClick: () => router.get(rowHref(row)!) }] : []),
        ...(readable(row) ? [{ label: 'View exposures for this worker', icon: UserRound, onClick: () => go({ worker_id: row.worker.id, page: 1 }) },
            { label: 'View exposures to this chemical', icon: FlaskConical, onClick: () => go({ substance_id: row.substance.id, page: 1 }) }] : []),
    ];
    const withheld = <span className="text-muted-foreground">Withheld</span>;
    const columns: RecordTableColumn<Row>[] = [
        { key: 'worker', label: 'Worker', className: 'min-w-[14rem]', cell: (row) => <RecordIdentity title={name(row)} sub={readable(row) ? row.substance.name : 'Saved details withheld'} /> },
        { key: 'site', label: 'Site', nowrap: true, cell: (row) => readable(row) ? row.site.name : withheld },
        { key: 'when', label: 'When', nowrap: true, cell: (row) => !readable(row) ? withheld : row.exposed_at && toDateInput(row.exposed_at)
            ? <RecordIdentity title={formatDateOnly(toDateInput(row.exposed_at))} sub={formatTime(row.exposed_at)} /> : <span className="text-muted-foreground">Not recorded</span> },
        { key: 'outcome', label: 'Medical outcome', className: 'min-w-[14rem]', cell: (row) => !readable(row) ? withheld
            : row.medical_outcome ? <span className="whitespace-pre-wrap break-words">{row.medical_outcome}</span> : <StatusBadge variant="warning">No outcome yet</StatusBadge> },
        { key: 'state', label: 'Record', nowrap: true, cell: state },
    ];
    const noteTarget = { onClick: () => note.current?.focus() };
    const timeSet = Boolean(filters?.from || filters?.to);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' }, { title: 'Private exposures', href: CHEMICAL_EXPOSURES }]}>
        <Head title="Private chemical exposures" /><PageLayout hero={<PageHeader title="Private chemical exposures" icon={LockKeyhole} subline="Identifiable worker records · safety and private wellbeing access"
            actions={can.capture || can.recover ? <>
                {can.recover ? <PageHeaderGlassButton icon={RefreshCw} onClick={() => router.get(CHEMICAL_EXPOSURES + '/recovery')}>Unfinished uploads</PageHeaderGlassButton> : null}
                {can.capture ? <PageHeaderPrimaryButton icon={FlaskConical} onClick={() => router.get(CHEMICAL_REGISTER)}>Choose a chemical to report exposure</PageHeaderPrimaryButton> : null}
            </> : undefined}
            strip={<PageHeaderNumbers>
                <PageHeaderNumber label="Exposures" value={admitted ? counts?.exposures ?? 'Unconfirmed' : restricted ? 'Restricted' : 'Unconfirmed'}
                    caption={admitted ? 'Current approved Site and filter scope' : restricted ? 'Private wellbeing access required' : 'Original source access could not be confirmed'}
                    {...(admitted ? { href: returnTo } : noteTarget)} />
                {admitted ? <PageHeaderNumber label="No outcome yet" value={pendingCount ?? 'Unconfirmed'} tone={pendingCount ? 'warning' : undefined}
                    caption={count_integrity?.awaiting_medical_outcome === 'reported_answer_unknown' ? 'Care answers need clarification' : 'Missing recorded outcomes · review the list'} {...noteTarget} /> : null}
            </PageHeaderNumbers>}
            filters={admitted ? <>
                <PageHeaderFilterSelect icon={MapPin} label="All sites" value={String(filters?.site_id ?? 'all')} options={[{ value: 'all', label: 'All sites' }, ...(sites ?? []).map((site) => ({ value: String(site.id), label: site.name }))]}
                    onChange={(value) => go({ site_id: value === 'all' ? null : Number(value), page: 1 })} />
                <ExposureTimeFilter from={filters?.from ?? null} to={filters?.to ?? null} active={timeSet} onApply={(from, to) => go({ from, to, page: 1 })} />
                {filters?.worker_id ? <PageHeaderFilterButton icon={X} active onClick={() => go({ worker_id: null, page: 1 })}>Clear worker filter</PageHeaderFilterButton> : null}
                {filters?.substance_id ? <PageHeaderFilterButton icon={X} active onClick={() => go({ substance_id: null, page: 1 })}>Clear chemical filter</PageHeaderFilterButton> : null}
                {timeSet ? <PageHeaderFilterButton icon={X} onClick={() => go({ from: null, to: null, page: 1 })}>Clear time range</PageHeaderFilterButton> : null}
            </> : undefined} />}>
            <div className="grid min-w-0 gap-5">
                <div className="sg-note"><Info aria-hidden="true" className="size-4" />
                    <div ref={note} tabIndex={-1} className="grid min-w-0 gap-1 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">
                        <p role="status">{admitted ? 'A recorded exposure or review does not confirm medical recovery. Check the recorded outcome and follow-ups on each private record.' : restricted ? 'Identifiable exposures require safety and private wellbeing access. Saved identities, facts and counts are withheld.' : source_integrity === 'association_unconfirmed' ? 'The original linked-record association is unconfirmed. Saved identities, facts and totals are withheld until its source can be reviewed.' : source_integrity === 'original_context_restricted' ? 'Current access to the original linked record could not be confirmed. Saved identities, facts and totals are withheld.' : 'The saved source could not be confirmed. Saved identities, facts and totals are withheld while its history needs review.'}</p>
                        {admitted && source_integrity === 'repair_required' && <p className="text-status-warning-foreground">Some source history needs review. Affected details and totals are unconfirmed.</p>}
                        {admitted && count_integrity?.awaiting_medical_outcome === 'reported_answer_unknown' && <p className="text-status-warning-foreground">Some records without an outcome have an unknown or unconfirmed care answer. The number awaiting an outcome cannot yet be confirmed.</p>}
                    </div>
                </div>
                {admitted && (records === null ? <p role="status" className="text-subtle text-status-warning">The saved collection cannot currently be confirmed.</p>
                    : <RecordTable title="Exposures" sub="Right-click a row to see all exposures for that worker or chemical" ariaLabel="Private exposure records"
                        columns={columns} rows={rows} rowKey={(row) => row.id} onOpen={(row) => { const target = rowHref(row); if (target) router.get(target); }} canOpen={(row) => Boolean(rowHref(row))}
                        actionsFor={actions} menuTitle={name} menuIcon={LockKeyhole}
                        empty={<p className="sg-quiet px-4 py-6"><span>No admitted exposure records match these filters.</span></p>}
                        footer={page ? <nav aria-label="Exposure pages" className="flex w-full flex-wrap items-center justify-between gap-3">
                            <p className="text-subtle">Page {page.current_page}{page.last_page === null ? ' · total unconfirmed' : ` of ${page.last_page}`}</p>
                            <span className="flex gap-2"><Button variant="outline" size="sm" disabled={page.current_page <= 1} onClick={() => go({ page: page.current_page - 1 })}>Previous page</Button>
                                <Button variant="outline" size="sm" disabled={!page.has_more} onClick={() => go({ page: page.current_page + 1 })}>Next page</Button></span></nav> : undefined} />)}
            </div>
        </PageLayout>
    </AppLayout>;
}

/** The exposure-time range: exact date-times with the clock offset when a time repeats at a daylight-saving change. */
function ExposureTimeFilter({ from: savedFrom, to: savedTo, active, onApply }: { from: string | null; to: string | null; active: boolean; onApply: (from: string | null, to: string | null) => void }) {
    const [from, setFrom] = useState(exposureTimeInput(savedFrom)); const [to, setTo] = useState(exposureTimeInput(savedTo));
    const [offsets, setOffsets] = useState({ from: '', to: '' }); const [error, setError] = useState<string | null>(null);
    useEffect(() => { setFrom(exposureTimeInput(savedFrom)); setTo(exposureTimeInput(savedTo)); setOffsets({ from: '', to: '' }); }, [savedFrom, savedTo]);
    const apply = () => {
        const start = from ? exposureTimeInstant(from, offsets.from) : null; const end = to ? exposureTimeInstant(to, offsets.to) : null;
        if (from && !start || to && !end) { setError('Choose a real date and time, including the offset for repeated clock times.'); return; }
        if (start && end && new Date(start).getTime() > new Date(end).getTime()) { setError('The end must be at or after the start.'); return; }
        setError(null); onApply(start, end);
    };
    const field = (key: 'from' | 'to', value: string, setValue: (next: string) => void) => <div className="grid gap-2">
        <Field label={key === 'from' ? 'Exposure from' : 'Exposure through'}>
            <Input type="datetime-local" step={1} value={value} onChange={(event) => { setValue(event.target.value); setOffsets((old) => ({ ...old, [key]: '' })); }} /></Field>
        {exposureTimeChoices(value).length > 1 && <Field label={`${key === 'from' ? 'Start' : 'End'} clock offset`}><SelectInput ariaLabel={`${key} clock offset`} placeholder="Choose the clock offset"
            value={offsets[key]} onChange={(next) => setOffsets((old) => ({ ...old, [key]: next }))} options={exposureTimeChoices(value).map((offset) => ({ value: offset, label: 'UTC' + offset }))} /></Field>}
    </div>;
    return <Popover>
        <PopoverTrigger asChild><PageHeaderFilterButton icon={CalendarClock} active={active}>{active ? 'Exposure time · set' : 'Any exposure time'}</PageHeaderFilterButton></PopoverTrigger>
        <PopoverContent align="start" className="grid w-80 max-w-[calc(100vw-2rem)] gap-3">
            {field('from', from, setFrom)}{field('to', to, setTo)}
            {error && <p role="alert" className="text-subtle text-status-critical">{error}</p>}
            <Button type="button" variant="outline" onClick={apply}>Apply exposure times</Button>
        </PopoverContent>
    </Popover>;
}
