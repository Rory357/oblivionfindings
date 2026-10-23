import { EntityCard, EntityContextMenu, EntityTable, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { PageHeader, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { exposureTimeChoices, exposureTimeInput, exposureTimeInstant } from '@/lib/health-safety/chemical-exposure-form';
import { CHEMICAL_EXPOSURES, exposureHref, exposureReturn } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureIndexProps } from '@/lib/health-safety/chemical-exposure-read-contract';
import { CHEMICAL_REGISTER } from '@/lib/health-safety/chemical-navigation';
import { Head, router } from '@inertiajs/react';
import { Eye, FlaskConical, LockKeyhole, MapPin, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Row = NonNullable<ExposureIndexProps['records']>[number];
function readable(row: Row): row is Extract<Row, { worker: unknown }> { return row.attribution_state !== 'repair_required' && 'worker' in row; }
export default function ChemicalExposureIndex({ exposure_access, records, counts, page, filters, sites, can, source_integrity, count_integrity }: ExposureIndexProps) {
    const admitted = exposure_access === 'readable'; const restricted = exposure_access === 'restricted'; const rows = admitted ? records ?? [] : [];
    const pendingCount = count_integrity && count_integrity.awaiting_medical_outcome !== 'confirmed' ? null : counts?.awaiting_medical_outcome;
    const context = useEntityContextMenu<Row>(); const current = context.ctx ? rows.find((row) => row.id === context.ctx?.record.id) : null;
    const [from, setFrom] = useState(exposureTimeInput(filters?.from ?? null)); const [to, setTo] = useState(exposureTimeInput(filters?.to ?? null));
    const [offsets, setOffsets] = useState({ from: '', to: '' }); const [error, setError] = useState<string | null>(null);
    const note = useRef<HTMLDivElement>(null);
    useEffect(() => { setFrom(exposureTimeInput(filters?.from ?? null)); setTo(exposureTimeInput(filters?.to ?? null)); setOffsets({ from: '', to: '' }); }, [filters?.from, filters?.to]);
    useEffect(() => { if (context.ctx && !current) context.close(); }, [context.ctx, current, context.close]);
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
    const applyTimes = () => {
        const start = from ? exposureTimeInstant(from, offsets.from) : null; const end = to ? exposureTimeInstant(to, offsets.to) : null;
        if (from && !start || to && !end) { setError('Choose a real date and time, including the offset for repeated clock times.'); return; }
        if (start && end && new Date(start).getTime() > new Date(end).getTime()) { setError('The end must be at or after the start.'); return; }
        setError(null); go({ from: start, to: end, page: 1 });
    };
    const timeField = (key: 'from' | 'to', value: string, setValue: (next: string) => void) => <div className="space-y-2"><Field label={key === 'from' ? 'Exposure from' : 'Exposure through'}>
        <Input type="datetime-local" step={1} className="frontline-tap" value={value} onChange={(event) => { setValue(event.target.value); setOffsets((old) => ({ ...old, [key]: '' })); }} /></Field>
        {exposureTimeChoices(value).length > 1 && <Field label={`${key === 'from' ? 'Start' : 'End'} clock offset`}><SelectInput frontline ariaLabel={`${key} clock offset`}
            value={offsets[key]} onChange={(next) => setOffsets((old) => ({ ...old, [key]: next }))} options={exposureTimeChoices(value).map((offset) => ({ value: offset, label: 'UTC' + offset }))} /></Field>}
    </div>;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Private exposures', href: CHEMICAL_EXPOSURES }]}>
        <Head title="Private chemical exposures" /><PageLayout hero={<PageHeader frontline title="Private chemical exposures" icon={LockKeyhole} subline="Identifiable worker records · safety and private wellbeing access"
            actions={can.capture ? <PageHeaderGlassButton icon={FlaskConical} onClick={() => router.get(CHEMICAL_REGISTER)}>Choose a chemical to report exposure</PageHeaderGlassButton> : undefined}
            meters={<>
                <PageHeaderMeterBlock label="Recorded exposures" {...(admitted ? { href: returnTo } : { onClick: () => note.current?.focus() })}><PageHeaderMeterBig>{admitted ? counts?.exposures ?? 'Unconfirmed' : restricted ? 'Restricted' : 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>{admitted ? 'Current approved Site and filter scope' : restricted ? 'Private wellbeing access required' : 'Original source access could not be confirmed'}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                {admitted && <PageHeaderMeterBlock label="Medical outcomes" onClick={() => note.current?.focus()}><PageHeaderMeterBig>{pendingCount ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>{count_integrity?.awaiting_medical_outcome === 'reported_answer_unknown' ? 'Care answers need clarification' : 'Missing recorded outcomes · review the list'}</PageHeaderMeterCaption></PageHeaderMeterBlock>}
            </>}
            filters={admitted ? <PageHeaderFilterSelect icon={MapPin} label="Site" value={String(filters?.site_id ?? 'all')} options={[{ value: 'all', label: 'All approved Sites' }, ...(sites ?? []).map((site) => ({ value: String(site.id), label: site.name }))]}
                onChange={(value) => go({ site_id: value === 'all' ? null : Number(value), page: 1 })} /> : undefined} />}>
            <div className="space-y-5"><div ref={note} tabIndex={-1} className="space-y-2 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <p role="status" className="text-subtle">{admitted ? 'A recorded exposure or review does not confirm medical recovery. Check the recorded outcome and follow-ups on each private record.' : restricted ? 'Identifiable exposures require safety and private wellbeing access. Saved identities, facts and counts are withheld.' : source_integrity === 'association_unconfirmed' ? 'The original linked-record association is unconfirmed. Saved identities, facts and totals are withheld until its source can be reviewed.' : source_integrity === 'original_context_restricted' ? 'Current access to the original linked record could not be confirmed. Saved identities, facts and totals are withheld.' : 'The saved source could not be confirmed. Saved identities, facts and totals are withheld while its history needs review.'}</p>
                {admitted && source_integrity === 'repair_required' && <p className="text-subtle text-status-warning">Some source history needs review. Affected details and totals are unconfirmed.</p>}
                {admitted && count_integrity?.awaiting_medical_outcome === 'reported_answer_unknown' && <p className="text-subtle text-status-warning">Some records without an outcome have an unknown or unconfirmed care answer. The number awaiting an outcome cannot yet be confirmed.</p>}
            </div>
                {admitted && <>
                    <div className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">{timeField('from', from, setFrom)}{timeField('to', to, setTo)}<Button type="button" variant="outline" className="frontline-tap" onClick={applyTimes}>Apply exposure times</Button></div>
                    {error && <p role="alert" className="text-subtle text-status-critical">{error}</p>}
                    <div className="flex flex-wrap gap-3">{filters?.worker_id && <Button variant="outline" className="frontline-tap" onClick={() => go({ worker_id: null, page: 1 })}>Clear worker filter</Button>}
                        {filters?.substance_id && <Button variant="outline" className="frontline-tap" onClick={() => go({ substance_id: null, page: 1 })}>Clear chemical filter</Button>}
                        {(filters?.from || filters?.to) && <Button variant="outline" className="frontline-tap" onClick={() => go({ from: null, to: null, page: 1 })}>Clear time range</Button>}</div>
                    {records === null ? <p className="text-subtle text-status-warning">The saved collection cannot currently be confirmed.</p> : !rows.length ? <p className="text-subtle">No admitted exposure records match these filters.</p> : <>
                        <ul className="space-y-3 md:hidden">{rows.map((row) => <li key={row.id}><EntityCard name={name(row)} icon={LockKeyhole} href={rowHref(row)} actions={actions(row)} chips={state(row)}
                            subline={readable(row) ? `${row.substance.name} · ${row.site.name} · ${formatDateTime(row.exposed_at)}` : 'Saved details withheld'} onContextMenu={(event) => context.open(event, row)} /></li>)}</ul>
                        <div className="hidden md:block"><EntityTable rows={rows} rowKey={(row) => row.id} identityLabel="Worker" identity={(row) => ({ icon: LockKeyhole, name: name(row), subline: readable(row) ? row.substance.name : 'Saved details withheld' })}
                            wrapIdentity columns={[{ key: 'site', label: 'Site', width: '1fr', cell: (row) => readable(row) ? row.site.name : 'Withheld' },
                                { key: 'time', label: 'Exposure time', width: '1.5fr', cell: (row) => readable(row) ? formatDateTime(row.exposed_at) : 'Withheld' },
                                { key: 'outcome', label: 'Medical outcome', width: '1.5fr', cell: (row) => readable(row) ? row.medical_outcome || 'Not recorded' : 'Withheld' },
                                { key: 'state', label: 'Source state', width: '1.5fr', cell: state }]} actionsFor={actions} onRowContextMenu={(event, row) => context.open(event, row)} /></div>
                    </>}
                    {page && <nav aria-label="Exposure pages" className="flex flex-wrap items-center gap-3"><Button variant="outline" className="frontline-tap" disabled={page.current_page <= 1} onClick={() => go({ page: page.current_page - 1 })}>Previous page</Button>
                        <p className="text-caption text-muted-foreground">Page {page.current_page}{page.last_page === null ? ' · total unconfirmed' : ` of ${page.last_page}`}</p>
                        <Button variant="outline" className="frontline-tap" disabled={!page.has_more} onClick={() => go({ page: page.current_page + 1 })}>Next page</Button></nav>}
                </>}
            </div>{context.ctx && current && admitted && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} title={name(current)} icon={LockKeyhole} items={actions(current)} onClose={context.close} />}
        </PageLayout>
    </AppLayout>;
}
