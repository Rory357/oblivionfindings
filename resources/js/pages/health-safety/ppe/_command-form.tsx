import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import type { PpeCommand } from '@/lib/health-safety/ppe-navigation';
import { CheckCircle2, ClipboardCheck, HardHat, Paperclip } from 'lucide-react';
import { useId, useState } from 'react';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { PPE_COMMAND_LABELS } from './_command-state';
import { ppeCommandRequest, ppeFormValues, type PpeFormValues } from './_command-values';
import { PpePeoplePicker } from './_people-picker';
import { usePpeCommand } from './_use-command';
import type { PpeRecord } from './_workspace-types';
import { CONDITION_OPTIONS, condLabel, statusLabel } from './ppe-shared';

const STEPS = [{ key: 'facts', label: 'Details', blurb: 'What’s changing', icon: ClipboardCheck },
    { key: 'review', label: 'Check and save', blurb: 'Check your entries', icon: CheckCircle2 }];
/** Plain names for the saved fields, so the check step never shows a field key. */
const FIELD_LABELS: Record<string, string> = { user_id: 'Worker', fit_test_completed: 'Fit test done', fit_test_date: 'Fit test date', fit_test_result: 'Fit test result',
    training_completed: 'Training done', training_date: 'Training date', acknowledged: 'Worker confirmed they received it', documents: 'Files to add',
    next_inspection_due: 'Next inspection due', condition: 'Condition', condition_after: 'Condition after inspection', alt_text: 'Image description',
    brand: 'Brand', model: 'Model', serial_number: 'Serial number', quantity: 'Quantity', location: 'Storage location', purchase_date: 'Purchase date',
    expiry_date: 'Expiry date', disposal_method: 'Disposal method', status: 'Status', reason: 'Reason', kind: 'Type of file', notes: 'Notes', file: 'File',
    result: 'Inspection result', findings: 'Inspection findings', action_taken: 'Action taken' };
const friendly = (key: string) => FIELD_LABELS[key] ?? humaniseHsValue(key);
/** Inspection results people choose from. */
const RESULT_CHOICES: Record<string, string> = { pass: 'Pass', fail: 'Fail', needs_repair: 'Needs repair', condemned: 'Take out of use (condemned)' };
/** Suggested file types; the stored value stays the short key. */
const FILE_KINDS: Record<string, string> = { document: 'Document', photo: 'Photo', inspection_photo: 'Inspection photo', inspection_report: 'Inspection report' };
const choiceLabel = (key: string, value: string) => key === 'result' ? RESULT_CHOICES[value] ?? humaniseHsValue(value) : key === 'kind' ? FILE_KINDS[value] ?? humaniseHsValue(value) : condLabel(value);
export function PpeCommandForm({ record, command, actorId, attachment, active = true, onCancel, onDone }: {
    record: PpeRecord; command: PpeCommand; actorId: number; attachment?: number | null; active?: boolean; onCancel: () => void; onDone: () => void;
}) {
    const id = useId(); const [step, setStep] = useState(0); const [workerName, setWorkerName] = useState('');
    const [choicesValid, setChoicesValid] = useState(false);
    const source = usePpeCommand({ initial: ppeFormValues(record), record, command, actorId, attachment });
    const { form, change } = source; const f = form.data; const locked = source.busy || source.unresolved || !!source.receipt;
    const title = PPE_COMMAND_LABELS[command];
    const input = (key: keyof PpeFormValues, label: string, type = 'text', required = false, maxLength = 255) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}><Input id={`${id}-${key}`} className="frontline-tap" type={type} required={required} maxLength={maxLength} disabled={locked} value={String(f[key] ?? '')} onChange={(event) => change(key, event.target.value as never)} /></Field>;
    const area = (key: 'notes' | 'reason' | 'findings' | 'action_taken', label: string, required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={form.errors[key]}><Textarea id={`${id}-${key}`} rows={3} className="frontline-tap" required={required} maxLength={key === 'notes' ? 1000 : 2000} disabled={locked} value={f[key]} onChange={(event) => change(key, event.target.value)} /></Field>;
    const select = (key: 'condition' | 'condition_after' | 'result' | 'kind', label: string, values: readonly string[], required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={form.errors[key]}><SelectInput id={`${id}-${key}`} frontline disabled={locked} value={f[key]} placeholder={`Choose ${label.toLowerCase()}`} options={values.map((value) => ({ value, label: choiceLabel(key, value) }))} onChange={(value) => change(key, value)} /></Field>;
    const check = (key: 'fit_test_completed' | 'training_completed' | 'acknowledged', label: string) => <div className="flex items-center gap-3"><Checkbox id={`${id}-${key}`} className="frontline-hit" checked={f[key]} disabled={locked} onCheckedChange={(value) => change(key, value === true)} /><label htmlFor={`${id}-${key}`} className="frontline-hit text-subtle cursor-pointer">{label}</label></div>;
    const documents = <div className="space-y-3"><Field label="Files" error={form.errors.documents} labelId={`${id}-documents-label`}><FileDropzone multiple disabled={locked} aria-labelledby={`${id}-documents-label`} hint="Up to 20 files · 20 MB each" onFiles={(files) => change('documents', [...f.documents, ...files.map((file) => ({ file, kind: file.type.startsWith('image/') ? 'inspection_photo' : 'inspection_report', note: '' }))])} /></Field>{f.documents.map((doc, index) => <div key={index} className="space-y-3 rounded-xl border border-border p-4"><p className="text-subtle break-words">{doc.file.name} · {formatFileSize(doc.file.size)}</p><Field label="Type of file" htmlFor={`${id}-doc-kind-${index}`}><Input id={`${id}-doc-kind-${index}`} className="frontline-tap" value={doc.kind} maxLength={30} disabled={locked} onChange={(event) => change('documents', f.documents.map((item, pos) => pos === index ? { ...item, kind: event.target.value } : item))} /></Field><Field label="Note about this file" htmlFor={`${id}-doc-note-${index}`}><Textarea id={`${id}-doc-note-${index}`} className="frontline-tap" value={doc.note} maxLength={1000} disabled={locked} onChange={(event) => change('documents', f.documents.map((item, pos) => pos === index ? { ...item, note: event.target.value } : item))} /></Field><Button type="button" className="frontline-tap" variant="outline" disabled={locked} onClick={() => change('documents', f.documents.filter((_, pos) => pos !== index))}>Remove file</Button></div>)}</div>;
    const body = command === 'edit' ? <div className="grid gap-4 sm:grid-cols-2">
        {input('brand', 'Brand')}{input('model', 'Model')}{input('serial_number', 'Serial number')}{input('quantity', 'Quantity', 'number', true)}{input('location', 'Storage location')}{select('condition', 'Condition', CONDITION_OPTIONS, true)}
        {input('purchase_date', 'Purchase date', 'date')}{input('expiry_date', 'Expiry date', 'date')}{input('next_inspection_due', 'Next inspection due', 'date')}
        <p className="text-subtle sm:col-span-2">Quantity is how many are in this batch. To change whether it can be used, use Place on hold, Issue PPE, Take out of use or Record disposal.</p>
    </div> : command === 'allocate' ? <>
        <Field label="Worker at this site" htmlFor={`${id}-worker`} required error={form.errors.user_id}><PpePeoplePicker id={`${id}-worker`} siteId={record.detail.command_context.site_id} value={f.user_id} disabled={locked} onValidity={setChoicesValid} onChange={(value, person) => { change('user_id', value); setWorkerName(person.name); }} /></Field>
        {workerName && <p className="text-subtle">Worker chosen: {workerName}</p>}
        {check('fit_test_completed', 'Fit test done')}{f.fit_test_completed && <>{input('fit_test_date', 'Fit test date', 'date', true)}{input('fit_test_result', 'Fit test result')}</>}
        {check('training_completed', 'Training done')}{f.training_completed && input('training_date', 'Training date', 'date', true)}
        {check('acknowledged', 'Worker confirmed they received it')}{area('notes', 'Issue notes')}
        <p className="text-subtle">This records what you were told. It doesn’t prove the worker is trained, that a mask fits, or that they’re fit for work.</p>
    </> : command === 'inspect' ? <>
        {select('result', 'Inspection result', ['pass', 'fail', 'needs_repair', 'condemned'], true)}{select('condition_after', 'Condition after inspection', CONDITION_OPTIONS)}
        {area('findings', 'Inspection findings')}{area('action_taken', 'Action taken')}{input('next_inspection_due', 'Next inspection due', 'date')}{documents}
        <p className="text-subtle">If it fails, it goes on hold. If it was on hold and now passes, use Return to use afterwards.</p>
    </> : command === 'upload_evidence' ? <>
        <Field label="File" required labelId={`${id}-file-label`} error={form.errors.file}><FileDropzone multiple={false} disabled={locked} aria-labelledby={`${id}-file-label`} hint="Up to 20 MB" onFiles={(files) => change('file', files[0] ?? null)} /></Field>
        {f.file && <div className="space-y-3"><p className="text-subtle break-words">{f.file.name} · {formatFileSize(f.file.size)}</p><Button type="button" className="frontline-tap" variant="outline" disabled={locked} onClick={() => change('file', null)}>Remove file</Button></div>}
        {input('kind', 'Type of file (for example photo or report)', 'text', false, 30)}{area('notes', 'Note about this file')}{input('alt_text', 'Image description')}
    </> : command === 'return' ? <>{select('condition', 'Condition on return', CONDITION_OPTIONS, true)}{area('notes', 'Return notes')}<p className="text-subtle">Returning it doesn’t take it off hold. If someone else also has it, it stays issued to them.</p></> : command === 'acknowledge' ? <p className="text-subtle">Confirm the worker has received this item. This doesn’t confirm it fits or that they know how to use it.</p> : <>
        {command === 'dispose' && input('disposal_method', 'Disposal method', 'text', true)}
        {command === 'relocate_evidence' && <p className="text-subtle">Moves this older file to secure storage. Who added it and when stays the same. The old copy is deleted separately, from Files to tidy up.</p>}
        {command === 'remove_evidence' && <p className="text-subtle">Removes this file from the record. The history will still show it was added and removed.</p>}
        {area('reason', command === 'dispose' ? 'Anything else about the disposal' : 'Why are you making this change?', command !== 'dispose')}
        {command === 'release' && <p className="text-subtle">You can only return it to use once it has passed an inspection and isn’t issued to anyone. We check again when you save.</p>}
    </>;
    const request = ppeCommandRequest(record, command, f, attachment);
    const review = <ReviewCard icon={ClipboardCheck} title="Your changes" onEdit={() => setStep(0)}>{Object.entries(request.values).map(([key, value]) => <ReviewRow key={key} label={friendly(key)} value={key === 'user_id' ? workerName || 'Choose a worker' : key === 'file' ? f.file?.name ?? 'No file added' : key === 'documents' ? f.documents.map((item) => `${item.file.name} · ${choiceLabel('kind', item.kind)}${item.note ? ` · ${item.note}` : ''}`).join('\n') || 'No file added' : typeof value === 'boolean' ? value ? 'Yes' : 'No' : value == null || value === '' ? 'Not recorded' : typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDateOnly(value) : key === 'condition' || key === 'condition_after' || key === 'result' || key === 'kind' ? choiceLabel(key, String(value)) : String(value)} />)}</ReviewCard>;
    const status = source.receipt ? <div role="status" className="space-y-4"><p className="flex items-center gap-2 text-status-success"><CheckCircle2 aria-hidden="true" className="size-5" />{source.receipt.version === source.receipt.expected_version ? 'Nothing needed to change' : 'Saved'}</p>{command === 'remove_evidence' && <p className="text-subtle">File removed from this record.</p>}<Button type="button" className="frontline-tap" onClick={onDone}>Back to the item</Button></div> : <div className="space-y-3">
        {!source.allowed && <p role="alert" className="text-subtle text-status-warning">You can’t make this change now — someone may have changed the item, or you don’t have permission. Ask your health and safety manager. Your entries are kept.</p>}
        {source.failure && <p role="alert" className="text-subtle text-status-critical">{source.failure}</p>}
        {source.stale && <p role="alert" className="text-subtle text-status-warning">Someone else changed this while you were working. Check the latest details, then choose Use latest details to save your changes.</p>}
        <div className="flex flex-wrap gap-3"><Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={() => void source.checkCurrent()}>{source.checking ? 'Checking…' : 'Check for changes'}</Button>{source.stale && <Button type="button" className="frontline-tap" variant="outline" disabled={source.busy || !source.reviewAvailable} onClick={() => { source.useCurrent(); setStep(1); }}>Use latest details</Button>}</div>
        {source.reviewAvailable && <details className="rounded-xl border border-border p-4"><summary className="frontline-hit text-subtle cursor-pointer focus-visible:ring-2 focus-visible:ring-ring">See latest details</summary><div className="mt-3 space-y-2 text-subtle">{source.current.kind === 'inventory' && <><p>Brand: {source.current.detail.brand ?? 'Not recorded'}</p><p>Model: {source.current.detail.model ?? 'Not recorded'}</p><p>Serial number: {source.current.detail.serial_number ?? 'Not recorded'}</p><p>Storage location: {source.current.detail.location ?? 'Not recorded'}</p><p>Purchase date: {source.current.detail.purchase_date ? formatDateOnly(source.current.detail.purchase_date) : 'Not recorded'}</p><p>Next inspection due: {source.current.detail.next_inspection_due ? formatDateOnly(source.current.detail.next_inspection_due) : 'Not recorded'}</p><p>Status: {statusLabel(source.current.detail.status)}</p><p>Condition: {condLabel(source.current.detail.condition)}</p><p>Quantity: {source.current.detail.quantity}</p><p>Expiry: {source.current.detail.expiry_date ? formatDateOnly(source.current.detail.expiry_date) : 'Not recorded'}</p></>}{source.current.kind === 'allocations' && <><p>Worker: {source.current.detail.user?.name ?? 'Not available'}</p><p>Received: {source.current.detail.acknowledged ? 'Yes' : 'Not yet confirmed by the worker'}</p><p>Returned: {source.current.detail.returned_at ? 'Yes' : 'Still issued'}</p></>}</div></details>}
    </div>;
    const errors = Object.entries(form.errors as Record<string, string>);
    const notices = errors.length > 0 && <div role="alert" className="space-y-2 rounded-xl border border-status-critical bg-status-critical-bg p-4"><p className="font-medium">Check these entries</p><ul className="list-inside list-disc text-subtle">{errors.map(([key, message]) => <li key={key}>{message}</li>)}</ul></div>;
    const save = <Button type="button" className="frontline-tap" disabled={locked || !source.allowed || source.stale || source.checkFailed || (command === 'allocate' && (!choicesValid || !f.user_id))} onClick={() => void source.save(request.method, request.href, request.values)}>{source.saving ? 'Saving…' : 'Save'}</Button>;
    if (['edit', 'allocate', 'inspect', 'upload_evidence'].includes(command)) return <WizardShell frontline open={active} onClose={onCancel} title={title} description="Fill in the details, check them, then save." railIcon={command === 'upload_evidence' ? Paperclip : HardHat} railTitle={title} railSub={(record.kind === 'inventory' || record.kind === 'allocations' ? record.detail.ppe_type?.name : null) ?? `Equipment · Ref ${record.detail.command_context.root_id}`} steps={STEPS.map((item) => ({ ...item, disabled: source.busy || !!source.receipt }))} stepIndex={step} onStepClick={setStep} pct={null} footerStart={<Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={onCancel}>Cancel</Button>} footerEnd={source.receipt ? null : <>{step > 0 && <Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={() => setStep(0)}>Back</Button>}{step === 0 ? <Button type="button" className="frontline-tap" disabled={source.busy} onClick={() => setStep(1)}>Check your entries</Button> : save}</>} success={source.receipt ? status : undefined}>
        <div className="space-y-5">{notices}{!source.receipt && source.allowed && !source.checkFailed && (step === 0 ? body : review)}{status}</div>
    </WizardShell>;
    return <section hidden={!active} className="space-y-5" aria-label={title}><h2 className="text-section-title">{title}</h2>{notices}{!source.receipt && source.allowed && !source.checkFailed && body}{status}{!source.receipt && <div className="flex flex-wrap gap-3">{save}<Button type="button" className="frontline-tap" variant="outline" disabled={source.busy} onClick={onCancel}>Cancel</Button></div>}</section>;
}
