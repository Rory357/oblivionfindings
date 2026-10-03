import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardSuccessPane } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { Check, Download, FileText, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonPicker } from './_person-picker';
import { reportRequest, type ExportOption, type ExportContext } from './_types';

const steps = [{ key: 'scope', label: 'Choose records', blurb: 'Confirm the person, house and period.', icon: FileText }, { key: 'purpose', label: 'Purpose', blurb: 'Record why this file is needed.', icon: ShieldCheck }, { key: 'review', label: 'Review', blurb: 'Check the selections before downloading.', icon: Check }];

export function ExportDialog({ option, props, onClose, online }: { option: ExportOption; props: ExportContext; onClose: () => void; online: boolean }) {
    const [step, setStep] = useState(0), [site, setSite] = useState(props.filters.site_id), [person, setPerson] = useState(props.filters.client_id), [from, setFrom] = useState<string | null>(props.filters.date_from), [to, setTo] = useState<string | null>(props.filters.date_to), [purpose, setPurpose] = useState(''), [detail, setDetail] = useState(''), [medicine, setMedicine] = useState(''), [medicines, setMedicines] = useState<{ value: string; label: string }[]>([]), [includeInError, setIncludeInError] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [done, setDone] = useState(false);
    const scopeReady = Boolean(from && to && from <= to && (option.type !== 'mar' || person) && (option.type !== 'round_sheet' || site && from === to) && (option.type !== 'cd_register' || medicine));
    const purposeReady = Boolean(purpose && (purpose !== 'other' || detail.trim().length >= 3));
    const choosePerson = (id: number | null) => { setPerson(id); setMedicine(''); setMedicines([]); setError(''); };
    useEffect(() => {
        if (option.type !== 'cd_register' || !person) return;
        const abort = new AbortController();
        fetch(`/emar/reports/medicines?client_id=${person}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: abort.signal })
            .then(async (response) => { if (!response.ok) throw new Error('Medicines could not be loaded. Choose the person again to retry.'); return response.json(); })
            .then((body) => { if (!abort.signal.aborted) setMedicines(body.medicines); })
            .catch((e) => { if (!abort.signal.aborted) setError(e.message); });
        return () => abort.abort();
    }, [option.type, person]);
    async function download() {
        if (!online || busy) return;
        setBusy(true); setError('');
        try {
            const response = await reportRequest('/emar/reports/export', { type: option.type, site_id: site, client_id: person, medication_id: medicine || null, period: 'custom', date_from: from, date_to: to, purpose, purpose_detail: detail, include_in_error: includeInError, kind: props.filters.sub === 'exports' ? 'export.created' : props.filters.kind || null, q: props.filters.q });
            const blob = await response.blob(); const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a'); anchor.href = url; anchor.download = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] ?? `medication-${option.type}.${option.format.toLowerCase()}`;
            document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 60_000); setDone(true);
        } catch (e) { setError(e instanceof Error ? e.message : 'The file could not be made. Your selections have been kept.'); }
        finally { setBusy(false); }
    }
    return <WizardShell open onClose={() => !busy && onClose()} title={`Make ${option.label}`} description="Choose the records and record a purpose before downloading." railIcon={Download} railTitle={option.label} railSub={option.format} steps={steps} stepIndex={step} onStepClick={(n) => !busy && n < step && setStep(n)} pct={step === 0 ? (scopeReady ? 33 : 0) : step === 1 ? (purposeReady ? 67 : 33) : 100}
        footerStart={<Button variant="outline" onClick={() => step ? setStep(step - 1) : onClose()} disabled={busy}>{step ? 'Back' : 'Cancel'}</Button>}
        footerEnd={step < 2 ? <Button onClick={() => setStep(step + 1)} disabled={busy || (step === 0 ? !scopeReady : !purposeReady)}>Continue</Button> : <Button onClick={download} disabled={busy || !online || !option.allowed}>{busy ? 'Preparing file…' : 'Make file'}</Button>}
        success={done ? <WizardSuccessPane title="File ready" blurb="The download has started and its purpose has been recorded in the audit trail." actions={<Button onClick={onClose}>Done</Button>} /> : undefined}>
        <div className="space-y-5 p-5">
            {!online && <p className="text-subtle" role="status">You’re offline. Your selections are kept; reconnect before making the file.</p>}
            {error && <p className="text-subtle text-status-critical" role="alert">{error}</p>}
            {step === 0 && <><p className="text-subtle">{option.description}</p><div className="space-y-2"><Label>House</Label><RecordPicker label="House" value={site ? String(site) : ''} options={[{ value: '', label: 'All permitted houses' }, ...props.sites.map((s) => ({ value: String(s.id), label: s.name }))]} onChange={(v) => { setSite(v ? Number(v) : null); setPerson(null); setMedicine(''); setMedicines([]); }} /></div>
                {!props.finance && option.type !== 'round_sheet' && <div className="space-y-2"><Label>Person</Label><PersonPicker value={person} onChange={choosePerson} siteId={site} initial={props.people} /></div>}
                {option.type === 'cd_register' && <div className="space-y-2"><Label>Controlled medicine</Label><RecordPicker label="Controlled medicine" value={medicine} options={medicines} onChange={setMedicine} disabled={!person} /></div>}
                <div className="space-y-2"><Label>NZ calendar period</Label><p className="text-subtle">{formatDateOnly(from)} to {formatDateOnly(to)}</p><LeaveCalendarRange start={from} end={to} onChange={(start, end) => { setFrom(start); setTo(option.type === 'round_sheet' ? start : end); }} required /></div>
                {option.type === 'errors' && <Label className="frontline-tap flex items-center gap-2"><Checkbox checked={includeInError} onCheckedChange={(v) => setIncludeInError(v === true)} />Include records marked in error</Label>}
            </>}
            {step === 1 && <><div className="space-y-2"><Label>Why is this file needed?</Label><RecordPicker label="Purpose" value={purpose} options={Object.entries(props.purposes).map(([value, label]) => ({ value, label }))} onChange={setPurpose} /></div>{purpose === 'other' && <div className="space-y-2"><Label htmlFor="export-purpose">Describe the purpose</Label><Textarea id="export-purpose" value={detail} onChange={(e) => setDetail(e.target.value)} maxLength={500} /></div>}<p className="text-subtle">The purpose, your name and the time of export are added to the audit trail.</p></>}
            {step === 2 && <><ReviewCard icon={FileText} title="Records" onEdit={() => setStep(0)}><ReviewRow label="File" value={`${option.label} · ${option.format}`} /><ReviewRow label="House" value={props.sites.find((s) => s.id === site)?.name ?? 'All permitted houses'} /><ReviewRow label="Person" value={props.people.find((p) => p.id === person)?.name ?? (person ? 'Selected person' : 'All permitted people')} /><ReviewRow label="Period" value={`${formatDateOnly(from)} – ${formatDateOnly(to)}`} />{option.type === 'cd_register' && <ReviewRow label="Medicine" value={medicines.find((m) => m.value === medicine)?.label} />}{option.type === 'errors' && <ReviewRow label="In-error records" value={includeInError ? 'Included and labelled' : 'Excluded'} />}</ReviewCard><ReviewCard icon={ShieldCheck} title="Purpose" onEdit={() => setStep(1)}><ReviewRow label="Purpose" value={purpose === 'other' ? detail : props.purposes[purpose]} /></ReviewCard></>}
        </div>
    </WizardShell>;
}
