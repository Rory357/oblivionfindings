/* eslint-disable no-restricted-syntax -- triage detail sections, timeline, harm/status tiles and
   summary panes are custom-layout bordered surfaces inside the wizard shell (not Card/Button);
   all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ClipboardList, FileText, FileWarning, Link2, Lock, ShieldCheck, User } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ErrorAttachment = { id: number; file_name: string; uploaded_by: string | null; uploaded_at: string | null };
export type ErrorRow = {
    id: number;
    ref: string;
    error_type: string;
    severity: string;
    reached_client: string | null;
    harm_level: string | null;
    open_disclosure: string | null;
    description: string | null;
    immediate_action: string | null;
    contributing_factors: string | null;
    review_notes: string | null;
    outcome: string | null;
    preventive_actions: string | null;
    close_note: string | null;
    status: string;
    reported_at: string | null;
    reviewed_at: string | null;
    closed_at: string | null;
    client_id: number | null;
    client: { id: number; first_name: string; last_name: string } | null;
    site_name: string | null;
    medication: { id: number; name: string } | null;
    incident: { id: number; ref: string } | null;
    mar_url: string | null;
    reported_by_user: { id: number; name: string } | null;
    reviewed_by_user: { id: number; name: string } | null;
    attachments: ErrorAttachment[];
};

export const ERROR_TYPES = [
    { value: 'wrong_medication', label: 'Wrong medication' },
    { value: 'wrong_client', label: 'Wrong client' },
    { value: 'wrong_dose', label: 'Wrong dose' },
    { value: 'wrong_time', label: 'Wrong time' },
    { value: 'wrong_route', label: 'Wrong route' },
    { value: 'omission', label: 'Omission' },
    { value: 'unauthorised', label: 'Unauthorised' },
    { value: 'documentation', label: 'Documentation' },
    { value: 'other', label: 'Other' },
];
export const SEVERITIES = [
    { value: 'near_miss', label: 'Near miss' },
    { value: 'minor', label: 'Minor' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'major', label: 'Major' },
    { value: 'critical', label: 'Critical' },
];
const HARM_BANDS = [
    { value: 'a_c', label: 'A–C · No harm' },
    { value: 'd_e', label: 'D–E · Temporary harm' },
    { value: 'f_g', label: 'F–G · Significant harm' },
    { value: 'h_i', label: 'H–I · Severe / death' },
];
export const typeLabel = (v: string) => ERROR_TYPES.find((t) => t.value === v)?.label ?? v;
export const severityMeta = (s: string): { label: string; cls: string } => ({
    near_miss: { label: 'Near miss', cls: 'bg-muted text-muted-foreground' },
    minor: { label: 'Minor', cls: 'bg-status-info-bg text-status-info' },
    moderate: { label: 'Moderate', cls: 'bg-status-warning-bg text-status-warning' },
    major: { label: 'Major', cls: 'bg-status-warning-bg text-status-warning' },
    critical: { label: 'Critical', cls: 'bg-status-critical-bg text-status-critical' },
}[s] ?? { label: s, cls: 'bg-muted text-muted-foreground' });
export const statusMeta = (s: string): { label: string; cls: string } => ({
    reported: { label: 'Reported', cls: 'bg-status-warning-bg text-status-warning' },
    investigating: { label: 'Investigating', cls: 'bg-status-info-bg text-status-info' },
    resolved: { label: 'Resolved', cls: 'bg-status-success-bg text-status-success' },
    closed: { label: 'Closed', cls: 'bg-muted text-muted-foreground' },
}[s] ?? { label: s, cls: 'bg-muted text-muted-foreground' });
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const STAGES = [{ id: 'reported', label: 'Reported' }, { id: 'investigating', label: 'Under review' }, { id: 'resolved', label: 'Resolved' }, { id: 'closed', label: 'Closed' }];

export type TriageAction = 'review' | 'resolve' | 'close';

// ── Triage / detail (read-only) ──────────────────────────────────────────────
export function TriageDialog({ error, onDismiss, onAction }: { error: ErrorRow; onDismiss: () => void; onAction: (a: TriageAction) => void }) {
    const stageIdx = Math.max(0, STAGES.findIndex((s) => s.id === error.status));
    const sev = severityMeta(error.severity);
    const [linking, setLinking] = useState(false);
    const viewClient = () => error.client_id && router.visit(`/operations/clients/${error.client_id}?tab=mar`);
    const viewIncident = () => error.incident && router.visit(`/incidents/${error.incident.id}`);
    // Post-report create-and-link: reuses the errors controller endpoint, which
    // creates the incident, links it and redirects into the incidents module.
    const createIncident = () => { setLinking(true); router.post(`/emar/errors/${error.id}/link-incident`, {}, { onFinish: () => setLinking(false) }); };
    const sections: [string, string | null][] = [
        ['Description', error.description],
        ['Immediate action', error.immediate_action],
        ['Contributing factors', error.contributing_factors],
        ['Review notes', error.review_notes],
        ['Outcome', error.outcome],
        ['Preventive actions', error.preventive_actions],
        ['Close-out note', error.close_note],
    ];
    return (
        <MedsWizardDialog open onClose={onDismiss} title={`Error triage · ${error.ref}`} description={`${typeLabel(error.error_type)} · ${error.client ? `${error.client.first_name} ${error.client.last_name}` : 'Unknown client'}`} railIcon={FileWarning} railTitle="Error triage" railSubtitle={error.ref} steps={[{ key: 'detail', label: 'Triage', blurb: 'Read & act', icon: FileWarning }]} stepIndex={0} onStepClick={() => {}} footer={<>
            <Button variant="ghost" onClick={onDismiss}>Close</Button>
            <div className="flex flex-wrap items-center justify-end gap-2">
                {error.status === 'reported' && <Button onClick={() => onAction('review')}><ClipboardList className="h-4 w-4" />Review</Button>}
                {(error.status === 'reported' || error.status === 'investigating') && <Button onClick={() => onAction('resolve')}><ShieldCheck className="h-4 w-4" />Resolve</Button>}
                {error.status === 'resolved' && <Button onClick={() => onAction('close')}><Lock className="h-4 w-4" />Close out</Button>}
                {error.client_id && <Button variant="outline" onClick={viewClient}><User className="h-4 w-4" />Client</Button>}
                {error.incident
                    ? <Button variant="outline" onClick={viewIncident}><Link2 className="h-4 w-4" />Incident {error.incident.ref}</Button>
                    : <Button variant="outline" onClick={createIncident} disabled={linking}><Link2 className="h-4 w-4" />Create &amp; link incident</Button>}
                {error.mar_url && <Button variant="ghost" onClick={() => router.visit(error.mar_url!)}><FileText className="h-4 w-4" />MAR</Button>}
            </div>
        </>}>
            <div className="flex flex-wrap items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sev.cls}`}>{sev.label}</span>
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusMeta(error.status).cls}`}>{statusMeta(error.status).label}</span>
                {error.reached_client && <span className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">Reached client: {error.reached_client}</span>}
                {error.harm_level && <span className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">Harm {error.harm_level.toUpperCase().replace('_', '–')}</span>}
                {error.open_disclosure && error.open_disclosure !== 'na' && <span className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">Open disclosure: {error.open_disclosure}</span>}
            </div>
            <div className="mt-4 rounded-lg border px-4">
                <SummaryRow label="Medication" value={error.medication?.name ?? '—'} />
                <SummaryRow label="Reported" value={`${fmt(error.reported_at)}${error.reported_by_user ? ` · ${error.reported_by_user.name}` : ''}`} />
                <SummaryRow label="Reviewer" value={error.reviewed_by_user?.name ?? '—'} />
                <SummaryRow label="Site" value={error.site_name ?? '—'} />
            </div>
            <div className="mt-4 flex items-center gap-1">
                {STAGES.map((s, i) => (
                    <div key={s.id} className="flex flex-1 items-center last:flex-none">
                        <div className="flex flex-col items-center gap-1">
                            <span className={`h-2.5 w-2.5 rounded-full ${i <= stageIdx ? 'bg-primary' : 'bg-border'}`} />
                            <span className={`text-[10px] ${i === stageIdx ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}>{s.label}</span>
                        </div>
                        {i < STAGES.length - 1 && <div className={`mx-1 h-0.5 flex-1 ${i < stageIdx ? 'bg-primary' : 'bg-border'}`} />}
                    </div>
                ))}
            </div>
            {error.incident && (
                <div className="mt-4 flex items-center justify-between gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/50 px-3 py-2">
                    <span className="flex items-center gap-2 text-sm text-status-critical"><Link2 className="h-4 w-4" />Linked incident {error.incident.ref}</span>
                    <button type="button" onClick={viewIncident} className="text-xs font-medium text-status-critical underline">Open incident</button>
                </div>
            )}
            {sections.filter(([, v]) => v).map(([label, v]) => (
                <div key={label} className="mt-4"><div className="mb-0.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</div><p className="whitespace-pre-wrap text-sm">{v}</p></div>
            ))}
            {error.attachments.length > 0 && (
                <div className="mt-4"><div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Supporting evidence ({error.attachments.length})</div>
                    <div className="flex flex-col gap-1">{error.attachments.map((a) => <div key={a.id} className="rounded-lg border px-3 py-1.5 text-xs">{a.file_name}{a.uploaded_by ? ` · ${a.uploaded_by}` : ''}</div>)}</div>
                </div>
            )}
        </MedsWizardDialog>
    );
}

// ── Review (2-step) ──────────────────────────────────────────────────────────
export function ReviewErrorDialog({ error, onClose }: { error: ErrorRow; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [notes, setNotes] = useState(error.review_notes ?? '');
    const [status, setStatus] = useState('investigating');
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.post(`/emar/errors/${error.id}/review`, { review_notes: notes, status }, { preserveScroll: true, onSuccess: () => { toast.success('Error reviewed'); onClose(); }, onError: () => toast.error('Review notes are required'), onFinish: () => setBusy(false) });
    };
    const valid = [notes.trim().length > 0, true];
    return (
        <MedsWizardDialog open onClose={onClose} title={`Review · ${error.ref}`} description="Record the review findings and move the error forward." railIcon={ClipboardList} railTitle="Review error" railSubtitle={error.ref} steps={[{ key: 'findings', label: 'Findings', blurb: 'Review notes', icon: ClipboardList }, { key: 'status', label: 'Status & sign', blurb: 'Move forward', icon: ShieldCheck }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(0)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 1 ? <Button onClick={() => setStep(1)} disabled={!valid[0]}>Continue</Button> : <Button onClick={submit} disabled={busy}>Save review</Button>}</>}>
            {step === 0 ? (
                <>
                    <StepHead icon={ClipboardList} title="Findings" blurb="What did the review establish?" />
                    <Field label="Review notes" required span><Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={5} placeholder="Findings, contributing factors confirmed, immediate learning." /></Field>
                </>
            ) : (
                <>
                    <StepHead icon={ShieldCheck} title="Status & sign" blurb="Where does this error go next?" />
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        {[{ v: 'investigating', l: 'Keep investigating' }, { v: 'resolved', l: 'Mark resolved' }, { v: 'reported', l: 'Back to reported' }].map((o) => (
                            <button key={o.v} type="button" onClick={() => setStatus(o.v)} className={`rounded-xl border-2 p-3 text-left text-sm ${status === o.v ? 'border-primary bg-primary/5' : 'border-border'}`}>{o.l}</button>
                        ))}
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Resolve (2-step) ─────────────────────────────────────────────────────────
export function ResolveErrorDialog({ error, onClose }: { error: ErrorRow; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [outcome, setOutcome] = useState(error.outcome ?? '');
    const [harm, setHarm] = useState(error.harm_level ?? '');
    const [preventive, setPreventive] = useState(error.preventive_actions ?? '');
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.post(`/emar/errors/${error.id}/resolve`, { outcome, preventive_actions: preventive, harm_level: harm || null }, { preserveScroll: true, onSuccess: () => { toast.success('Error resolved'); onClose(); }, onError: () => toast.error('Outcome and preventive actions are required'), onFinish: () => setBusy(false) });
    };
    const valid = [outcome.trim().length > 0, preventive.trim().length > 0];
    return (
        <MedsWizardDialog open onClose={onClose} title={`Resolve · ${error.ref}`} description="Record the outcome, harm band and preventive actions." railIcon={ShieldCheck} railTitle="Resolve error" railSubtitle={error.ref} steps={[{ key: 'outcome', label: 'Outcome', blurb: 'Result + harm', icon: ShieldCheck }, { key: 'prevent', label: 'Preventive actions', blurb: 'System learning', icon: CheckCircle2 }]} stepIndex={step} onStepClick={(i) => i < step && setStep(i)} footer={<><Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(0)} disabled={busy}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 1 ? <Button onClick={() => setStep(1)} disabled={!valid[0]}>Continue</Button> : <Button onClick={submit} disabled={busy || !valid[1]}>Resolve error</Button>}</>}>
            {step === 0 ? (
                <>
                    <StepHead icon={ShieldCheck} title="Outcome" blurb="What was the outcome, and what harm band (NCC-MERP)?" />
                    <Field label="Outcome" required span><Textarea value={outcome} onChange={(e) => setOutcome(e.target.value)} rows={4} placeholder="Clinical outcome for the client." /></Field>
                    <Field label="Harm band" span><SelectInput value={harm} onChange={setHarm} placeholder="NCC-MERP band…" options={HARM_BANDS} /></Field>
                </>
            ) : (
                <>
                    <StepHead icon={CheckCircle2} title="Preventive actions" blurb="What changes will stop this recurring?" />
                    <Field label="Preventive actions" required span><Textarea value={preventive} onChange={(e) => setPreventive(e.target.value)} rows={5} placeholder="System changes, process updates, training." /></Field>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Close-out (1-step) ───────────────────────────────────────────────────────
export function CloseErrorDialog({ error, onClose }: { error: ErrorRow; onClose: () => void }) {
    const [note, setNote] = useState('');
    const [ack, setAck] = useState(false);
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.post(`/emar/errors/${error.id}/close`, { close_note: note || null }, { preserveScroll: true, onSuccess: () => { toast.success('Error closed out'); onClose(); }, onError: () => toast.error('Could not close the error'), onFinish: () => setBusy(false) });
    };
    return (
        <MedsWizardDialog open onClose={onClose} title={`Close out · ${error.ref}`} description="Final governance sign-off for this medication error." railIcon={Lock} railTitle="Close out" railSubtitle={error.ref} steps={[{ key: 'close', label: 'Close-out', blurb: 'Sign off', icon: Lock }]} stepIndex={0} onStepClick={() => {}} footer={<><Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button><Button onClick={submit} disabled={busy || !ack}>Close out error</Button></>}>
            <StepHead icon={AlertTriangle} title="Close out" blurb="Confirm the error is fully resolved and learning is embedded." />
            <Field label="Close-out note" span><Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={4} placeholder="Confirm actions complete and shared (optional)." /></Field>
            <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="h-4 w-4 rounded border-border" />I confirm the outcome and preventive actions are complete and this error can be closed.</label>
        </MedsWizardDialog>
    );
}
