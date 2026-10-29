import { Button } from '@/components/ui/button';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { CheckCircle2, ClipboardCheck, HardHat } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { CatalogueCommand, CatalogueRow } from './_catalogue-state';
import { usePpeCatalogueCommand } from './_use-catalogue-command';
import type { PpeReceipt } from './_workspace-types';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { CATEGORY_OPTIONS, catLabel, CONDITION_OPTIONS, condLabel, INSPECTION_FREQUENCY_OPTIONS } from './ppe-shared';

type Values = { name: string; category: string; description: string; hazards_addressed: string; standards_reference: string;
    inspection_frequency: string; typical_lifespan_months: string; ppe_type_id: string; site_id: string; brand: string; model: string;
    serial_number: string; quantity: string; location: string; condition: string; purchase_date: string; expiry_date: string;
    next_inspection_due: string; documents: { file: File; kind: string; note: string }[] };
export function ppeCatalogueValues(type?: CatalogueRow): Values {
    return { name: type?.name ?? '', category: type?.category ?? '', description: type?.description ?? '', hazards_addressed: type?.hazards_addressed ?? '',
        standards_reference: type?.standards_reference ?? '', inspection_frequency: type?.inspection_frequency ?? '', typical_lifespan_months: String(type?.typical_lifespan_months ?? ''),
        ppe_type_id: '', site_id: '', brand: '', model: '', serial_number: '', quantity: '1', location: '', condition: 'good',
        purchase_date: '', expiry_date: '', next_inspection_due: '', documents: [] };
}
export function ppeCataloguePayload(command: CatalogueCommand, values: Values): Record<string, unknown> {
    const nullable = (value: string) => value === '' ? null : value;
    if (command === 'activate_type' || command === 'deactivate_type') return { active: command === 'activate_type' };
    if (command === 'create_inventory') return { ppe_type_id: values.ppe_type_id, site_id: values.site_id, brand: nullable(values.brand), model: nullable(values.model),
        serial_number: nullable(values.serial_number), quantity: values.quantity, location: nullable(values.location), condition: values.condition,
        purchase_date: nullable(values.purchase_date), expiry_date: nullable(values.expiry_date), next_inspection_due: nullable(values.next_inspection_due), documents: values.documents };
    return { name: values.name, category: values.category, description: nullable(values.description), hazards_addressed: nullable(values.hazards_addressed),
        standards_reference: nullable(values.standards_reference), inspection_frequency: nullable(values.inspection_frequency), typical_lifespan_months: nullable(values.typical_lifespan_months) };
}
const STEPS = [{ key: 'facts', label: 'Details', blurb: 'What’s changing', icon: HardHat },
    { key: 'review', label: 'Check and save', blurb: 'Check your entries', icon: ClipboardCheck }];
const LABELS: Record<CatalogueCommand, string> = { create_inventory: 'Add equipment', create_type: 'Add equipment type', update_type: 'Edit equipment type',
    activate_type: 'Start using this type again', deactivate_type: 'Stop using this type' };
/** Plain names for the saved fields, so the check step never shows a field key. */
const FIELD_LABELS: Record<string, string> = { ppe_type_id: 'Equipment type', site_id: 'Site', brand: 'Brand', model: 'Model', serial_number: 'Serial number',
    quantity: 'Quantity', location: 'Storage location', condition: 'Condition', purchase_date: 'Purchase date', expiry_date: 'Expiry date',
    next_inspection_due: 'Next inspection due', documents: 'Files to add', name: 'Type name', category: 'Category', description: 'Description',
    hazards_addressed: 'Hazards it protects against', standards_reference: 'Standard it meets', inspection_frequency: 'How often to inspect',
    typical_lifespan_months: 'Typical lifespan in months', active: 'In use' };
const FREQUENCY_LABEL = (value: string) => INSPECTION_FREQUENCY_OPTIONS.find((option) => option.value === value)?.label ?? humaniseHsValue(value);
const FILE_KINDS: Record<string, string> = { document: 'Document', photo: 'Photo' };
export function PpeCatalogueForm({ command, actorId, type, onClose, onDone }: {
    command: CatalogueCommand; actorId: number; type?: CatalogueRow; onClose: () => void; onDone: (receipt: PpeReceipt) => void;
}) {
    const id = useId(); const [step, setStep] = useState(0);
    const source = usePpeCatalogueCommand(ppeCatalogueValues(type), command, actorId, type);
    const checkOnMount = useRef(source.checkCurrent); checkOnMount.current = source.checkCurrent;
    useEffect(() => { void checkOnMount.current(); }, []);
    const { form, change } = source; const f = form.data;
    const locked = source.busy || source.unresolved || !!source.receipt;
    const input = (key: keyof Omit<Values, 'documents'>, label: string, inputType = 'text', required = false, maxLength = 255) =>
        <Field label={label} htmlFor={`${id}-${key}`} required={required} error={form.errors[key]}><Input id={`${id}-${key}`} className="frontline-tap" disabled={locked}
            type={inputType} required={required} maxLength={maxLength} value={f[key]} onChange={(event) => change(key, event.target.value)} /></Field>;
    const area = (key: 'description' | 'hazards_addressed', label: string) => <Field label={label} htmlFor={`${id}-${key}`} error={form.errors[key]}><Textarea id={`${id}-${key}`} className="frontline-tap" rows={3} maxLength={2000} value={f[key]} disabled={locked} onChange={(event) => change(key, event.target.value)} /></Field>;
    const select = (key: 'category' | 'ppe_type_id' | 'site_id' | 'condition' | 'inspection_frequency', label: string, choices: { value: string; label: string }[], required = false) =>
        <Field label={label} htmlFor={`${id}-${key}`} required={required} error={form.errors[key]}><SelectInput id={`${id}-${key}`} frontline disabled={locked}
            value={f[key]} placeholder={`Choose ${label.toLowerCase()}`} options={choices} onChange={(value) => change(key, value)} /></Field>;
    const currentChoices = command !== 'create_inventory' || (!!source.options && source.options.sites.some((site) => String(site.id) === f.site_id) && source.options.types.some((row) => String(row.id) === f.ppe_type_id));
    const fields = command === 'create_inventory' ? <div className="space-y-5"><div className="grid gap-4 sm:grid-cols-2">
        {select('ppe_type_id', 'Equipment type', (source.options?.types ?? []).map((row) => ({ value: String(row.id), label: row.name })), true)}
        {select('site_id', 'Site', (source.options?.sites ?? []).map((row) => ({ value: String(row.id), label: row.name })), true)}
        {input('brand', 'Brand')}{input('model', 'Model')}{input('serial_number', 'Serial number')}{input('quantity', 'Quantity', 'number', true)}
        {input('location', 'Storage location')}{select('condition', 'Condition', CONDITION_OPTIONS.map((value) => ({ value, label: condLabel(value) })), true)}
        {input('purchase_date', 'Purchase date', 'date')}{input('expiry_date', 'Expiry date', 'date')}{input('next_inspection_due', 'Next inspection due', 'date')}
    </div><p className="text-subtle">Quantity is how many are in this batch. If you choose Unsafe to use, the stock goes on hold until someone decides what to do with it.</p>
        <Field label="Files" labelId={`${id}-evidence`} error={form.errors.documents}><FileDropzone multiple disabled={locked} aria-labelledby={`${id}-evidence`} hint="Up to 20 files · 20 MB each"
            onFiles={(files) => change('documents', [...f.documents, ...files.map((file) => ({ file, kind: file.type.startsWith('image/') ? 'photo' : 'document', note: '' }))])} /></Field>
        {f.documents.map((document, index) => <div key={index} className="space-y-3 rounded-xl border border-border p-4"><p className="text-subtle break-words">{document.file.name} · {formatFileSize(document.file.size)}</p>
            <Field label="Type of file" htmlFor={`${id}-kind-${index}`}><Input id={`${id}-kind-${index}`} className="frontline-tap" disabled={locked} maxLength={30} value={document.kind} onChange={(event) => change('documents', f.documents.map((doc, pos) => pos === index ? { ...doc, kind: event.target.value } : doc))} /></Field>
            <Field label="Note about this file" htmlFor={`${id}-note-${index}`}><Textarea id={`${id}-note-${index}`} className="frontline-tap" disabled={locked} maxLength={1000} value={document.note} onChange={(event) => change('documents', f.documents.map((doc, pos) => pos === index ? { ...doc, note: event.target.value } : doc))} /></Field>
            <Button type="button" className="frontline-tap" variant="outline" disabled={locked} onClick={() => change('documents', f.documents.filter((_, pos) => pos !== index))}>Remove file</Button></div>)}
    </div> : ['activate_type', 'deactivate_type'].includes(command) ? <p className="text-subtle">{command === 'activate_type' ? 'People can add and issue this type again.' : 'No new stock or issuing for this type. Items you already have, and their history, stay.'}</p> :
        <div className="grid gap-4 sm:grid-cols-2">{input('name', 'Type name', 'text', true)}{select('category', 'Category', CATEGORY_OPTIONS, true)}{input('standards_reference', 'Standard it meets (for example AS/NZS 1716)')}
            {select('inspection_frequency', 'How often to inspect', INSPECTION_FREQUENCY_OPTIONS)}{input('typical_lifespan_months', 'Typical lifespan in months', 'number')}
            <div className="sm:col-span-2 space-y-4">{area('description', 'Description')}{area('hazards_addressed', 'Hazards it protects against')}<p className="text-subtle">This is what the supplier says. It doesn’t prove the equipment is right for the job.</p></div></div>;
    const payload = ppeCataloguePayload(command, f);
    const valueLabel = (key: string, value: unknown) => key === 'ppe_type_id' ? source.options?.types.find((row) => String(row.id) === f.ppe_type_id)?.name ?? 'Choose an equipment type' :
        key === 'site_id' ? source.options?.sites.find((site) => String(site.id) === f.site_id)?.name ?? 'Choose a site' :
        key === 'documents' ? f.documents.map((document) => `${document.file.name} · ${FILE_KINDS[document.kind] ?? humaniseHsValue(document.kind)}${document.note ? ` · ${document.note}` : ''}`).join('\n') || 'No file added' :
        key === 'category' && typeof value === 'string' ? catLabel(value) : key === 'condition' && typeof value === 'string' ? condLabel(value) :
        key === 'inspection_frequency' && typeof value === 'string' ? FREQUENCY_LABEL(value) :
        value == null || value === '' ? 'Not recorded' : typeof value === 'boolean' ? value ? 'Yes' : 'No' : typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDateOnly(value) : String(value);
    const saved = source.receipt && <div role="status" className="space-y-4"><p className="flex items-center gap-2 text-status-success"><CheckCircle2 aria-hidden="true" className="size-5" />{source.receipt.version === source.receipt.expected_version ? 'Nothing needed to change' : 'Saved'}</p>
        <Button type="button" className="frontline-tap" onClick={() => onDone(source.receipt!)}>Open what you saved</Button></div>;
    return <WizardShell frontline open onClose={onClose} title={LABELS[command]} description="Fill in the details, check them, then save." railIcon={HardHat} railTitle={LABELS[command]} railSub={type?.name ?? 'PPE & equipment'}
        steps={STEPS.map((item) => ({ ...item, disabled: source.busy || !!source.receipt }))} stepIndex={step} onStepClick={setStep} pct={null}
        footerStart={<Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={onClose}>Cancel</Button>}
        footerEnd={source.receipt ? null : <>{step > 0 && <Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={() => setStep(0)}>Back</Button>}
            {step === 0 ? <Button type="button" className="frontline-tap" disabled={source.busy || !source.allowed} onClick={() => setStep(1)}>Check your entries</Button> :
                <Button type="button" className="frontline-tap" disabled={locked || !source.allowed || source.stale || !currentChoices} onClick={() => void source.save(payload, command === 'create_inventory' && f.documents.length > 0)}>{source.saving ? 'Saving…' : 'Save'}</Button>}</>}
        success={saved || undefined}>
        <div className="space-y-5">
            {Object.keys(form.errors).length > 0 && <div role="alert" className="space-y-2 rounded-xl border border-status-critical bg-status-critical-bg p-4"><p className="font-medium">Check these entries</p><ul className="list-inside list-disc text-subtle">{Object.entries(form.errors).map(([key, value]) => <li key={key}>{String(value)}</li>)}</ul></div>}
            {!source.receipt && <><div className="space-y-3">
                {!source.allowed && <p role="status" className="text-subtle">Select Check for changes before saving. Your entries are kept. Only people who manage PPE can add or change equipment and equipment types — ask your health and safety manager if you need to.</p>}
                {source.failure && <p role="alert" className="text-subtle text-status-critical">{source.failure}</p>}
                {source.stale && <p role="alert" className="text-subtle text-status-warning">Someone else changed this while you were working. Check the latest details, then choose Use latest details to save your changes.</p>}
                <div className="flex flex-wrap gap-3"><Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={() => void source.checkCurrent()}>{source.checking ? 'Checking…' : 'Check for changes'}</Button>
                    {source.stale && <Button type="button" className="frontline-tap" variant="outline" disabled={source.busy || !source.reviewAvailable} onClick={() => { source.useCurrent(); setStep(1); }}>Use latest details</Button>}</div>
                {source.reviewAvailable && source.currentType && <ReviewCard icon={ClipboardCheck} title="Latest saved details"><ReviewRow label="Name" value={source.currentType.name} /><ReviewRow label="Category" value={catLabel(source.currentType.category)} /><ReviewRow label="Description" value={source.currentType.description ?? 'Not recorded'} /><ReviewRow label="Hazards it protects against" value={source.currentType.hazards_addressed ?? 'Not recorded'} /><ReviewRow label="Standard it meets" value={source.currentType.standards_reference ?? 'Not recorded'} /><ReviewRow label="How often to inspect" value={source.currentType.inspection_frequency ? FREQUENCY_LABEL(source.currentType.inspection_frequency) : 'Not recorded'} /><ReviewRow label="Typical lifespan" value={source.currentType.typical_lifespan_months == null ? 'Not recorded' : `${source.currentType.typical_lifespan_months} months`} /><ReviewRow label="Status" value={source.currentType.is_active ? 'In use' : 'No longer used'} /></ReviewCard>}
            </div>{source.allowed && !source.checkFailed && (step === 0 ? fields : <ReviewCard icon={ClipboardCheck} title="Your changes" onEdit={() => setStep(0)}>{Object.entries(payload).map(([key, value]) => <ReviewRow key={key} label={FIELD_LABELS[key] ?? humaniseHsValue(key)} value={valueLabel(key, value)} />)}</ReviewCard>)}</>}
            {saved}
        </div>
    </WizardShell>;
}
