/**
 * #15 — an exposure saved with a link to an incident before the app checked links (boards 15-1 to 15-3, approved by
 * Stephan 2 Nov 2026). The Connections tab shows the "Earlier link to an incident" card; an exposure manager can record a
 * later review (it belongs to this incident / it isn't linked / can't tell yet) with why, notes and one optional file.
 * The review sits beside the old record and never changes it. Readers without hazards.manage see whether it was
 * reviewed, nothing more. Buttons are outline: the record's one filled button stays in its Progress card.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { StatusBadge } from '@/components/ui/status-badge';
import { Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { formatDateOnly, formatDateTime, toDateInput, WORKER_TIMEZONE } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Check, FileText, Info, PencilLine, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export type EarlierLinkDecision = 'reviewed_association' | 'reviewed_no_association' | 'unresolved';
type IncidentRef = { id: number | null; reference: string | null; title: string | null; occurred_at: string | null; readable: boolean };
export type EarlierLink = {
    state: 'unreviewed' | 'reviewed';
    manager: boolean;
    expected_version: number | null;
    linked_incident: IncidentRef | null;
    reviews: { id: number; revision: number; decision: EarlierLinkDecision; reviewed_at: string | null; recorded_at: string | null;
        reviewer: string | null; reason: string | null; notes: string | null; incident: IncidentRef | null }[];
    files: { id: number | null; name: string; size: number | null; description: string | null; recorded_at: string | null; reviewer: string | null; downloadable: boolean }[];
    can: { record_review: boolean; add_file: boolean };
};

export const DECISION_WORDS: Record<EarlierLinkDecision, string> = {
    reviewed_association: 'Belongs to this incident',
    reviewed_no_association: 'Isn’t linked to an incident',
    unresolved: 'Can’t tell yet',
};
const DECISION_TONE: Record<EarlierLinkDecision, 'success' | 'neutral' | 'warning'> = {
    reviewed_association: 'success', reviewed_no_association: 'neutral', unresolved: 'warning',
};
const TILES: { value: EarlierLinkDecision; title: string; body: string; icon: typeof Check }[] = [
    { value: 'reviewed_association', title: 'It belongs to this incident', body: 'Pick the incident it belongs to', icon: Check },
    { value: 'reviewed_no_association', title: 'It isn’t linked to an incident', body: 'The exposure stands on its own', icon: X },
    { value: 'unresolved', title: 'Can’t tell yet', body: 'Leave it unconfirmed for now', icon: Info },
];
const ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx';

/** "Earlier provenance unconfirmed" until a later review is recorded. */
export function earlierLinkChip(link: EarlierLink | null | undefined): string | null {
    if (!link) return null;
    return link.state === 'reviewed' ? 'Earlier link reviewed' : 'Earlier provenance unconfirmed';
}

function incidentWords(incident: IncidentRef | null): string {
    if (!incident) return 'None';
    if (!incident.readable) return 'An incident you can’t open';
    return [incident.reference, incident.title, incident.occurred_at ? formatDateOnly(toDateInput(incident.occurred_at)) : null].filter(Boolean).join(' · ');
}

function Rows({ rows }: { rows: [string, React.ReactNode][] }) {
    return (
        <dl className="grid gap-x-4 gap-y-2 px-[18px] pb-4 text-sm sm:grid-cols-[9.5rem_minmax(0,1fr)]">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-caption text-muted-foreground">{label}</dt>
                    <dd className="min-w-0">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function ExposureEarlierLink({ link, exposureId }: { link: EarlierLink; exposureId: number }) {
    const [open, setOpen] = useState(false);
    const latest = link.reviews[0];
    const earlier = link.reviews.slice(1);
    const action = link.can.record_review && !open ? { label: latest ? 'Record a new review' : 'Record a review', onClick: () => setOpen(true) } : null;
    return (
        <div className="grid gap-5">
            {open ? <EarlierLinkReviewForm link={link} exposureId={exposureId} onClose={() => setOpen(false)} /> : null}
            <Surface title="Earlier link to an incident" sub={latest ? 'Reviewed later' : 'Recorded before link checks existed'}>
                {action ? (
                    <div className="-mt-1 flex justify-end px-[18px] pb-2">
                        <Button type="button" variant="outline" size="sm" onClick={action.onClick}><PencilLine aria-hidden="true" />{action.label}</Button>
                    </div>
                ) : null}
                {latest ? (
                    <Rows rows={[
                        ['Latest review', <span key="d" className="flex flex-wrap items-center gap-2"><StatusBadge variant={DECISION_TONE[latest.decision]}>{DECISION_WORDS[latest.decision]}</StatusBadge>
                            {latest.decision === 'reviewed_association' ? <span>{incidentWords(latest.incident)}</span> : null}</span>],
                        ['By', [latest.reviewer ?? 'Not recorded', latest.reviewed_at ? formatDateOnly(toDateInput(latest.reviewed_at)) : null].filter(Boolean).join(' · ')],
                        ['Why', latest.reason ?? (latest.decision === 'reviewed_association' ? 'Not recorded'
                            : <span key="w" className="text-muted-foreground">Kept on file. A review’s own words show once a review confirms the link.</span>)],
                        ...(latest.notes ? [['Notes', latest.notes] as [string, React.ReactNode]] : []),
                        ...(link.files.length ? [['Files', <ul key="f" className="grid gap-1">{link.files.map((file, index) => (
                            <li key={file.id ?? `file-${index}`} className="flex items-center gap-2">
                                <FileText className="size-4 text-muted-foreground" aria-hidden="true" />
                                {file.downloadable && file.id ? (
                                    <a className="text-primary underline-offset-4 hover:underline" href={`${CHEMICAL_EXPOSURES}/${exposureId}/earlier-link/files/${file.id}/download`}>{file.name}</a>
                                ) : <span>{file.name}</span>}
                                {file.size ? <span className="text-caption text-muted-foreground">{formatFileSize(file.size)}</span> : null}
                            </li>
                        ))}</ul>] as [string, React.ReactNode]] : []),
                        ['The old record', <span key="o" className="text-muted-foreground">Still says the link wasn’t confirmed when it was saved. This review sits beside it.</span>],
                    ]} />
                ) : (
                    <Rows rows={[
                        ['Linked incident', incidentWords(link.linked_incident)],
                        ...(link.manager ? [['What we know', 'This exposure was saved with that link before the app checked links. The old record doesn’t say who made the link or why, so it isn’t confirmed.'] as [string, React.ReactNode]] : []),
                        ['Review', <StatusBadge key="r" variant={link.state === 'reviewed' ? 'neutral' : 'warning'}>{link.state === 'reviewed' ? 'Reviewed' : 'Not reviewed yet'}</StatusBadge>],
                    ]} />
                )}
                {!link.manager ? (
                    <p className="text-caption flex items-center gap-2 px-[18px] pb-4 text-muted-foreground"><Info className="size-4" aria-hidden="true" />A health and safety manager can review this link.</p>
                ) : null}
                {earlier.length ? (
                    <div className="border-t border-border">
                        <p className="text-caption bg-muted/60 px-[18px] py-2 font-semibold text-muted-foreground">Earlier reviews</p>
                        <ul>
                            {earlier.map((review) => (
                                <li key={review.id} className="grid grid-cols-[6.5rem_minmax(0,1fr)_auto] items-center gap-3 border-t border-border px-[18px] py-3 text-sm first:border-t-0">
                                    <span className="text-caption text-muted-foreground">{review.recorded_at ? formatDateTime(review.recorded_at) : '—'}</span>
                                    <span className="grid min-w-0"><b className="font-semibold">{DECISION_WORDS[review.decision]}</b>
                                        <span className="text-caption truncate text-muted-foreground">{[review.reviewer, review.reason].filter(Boolean).join(' · ')}</span></span>
                                    <StatusBadge variant="neutral">Replaced</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </Surface>
        </div>
    );
}

function uuid(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); });
}

/** Today in the worker's zone, as YYYY-MM-DD. */
function today(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: WORKER_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

type IncidentChoice = { id: number; reference: string; title: string | null; occurred_at: string | null };

/** Incidents at the exposure's own Site that this manager can open (the review proves the picked one again). */
function useIncidentChoices(exposureId: number, enabled: boolean, search: string) {
    const [state, setState] = useState<{ key: string; items: IncidentChoice[]; error: boolean } | null>(null);
    const key = `${exposureId}:${search}`;
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            axios.get(`${CHEMICAL_EXPOSURES}/${exposureId}/earlier-link/incidents`, { params: { search }, headers: { Accept: 'application/json' }, signal: controller.signal })
                .then(({ data }) => { if (!controller.signal.aborted) setState({ key, items: Array.isArray(data?.items) ? data.items : [], error: false }); })
                .catch(() => { if (!controller.signal.aborted) setState({ key, items: [], error: true }); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [enabled, exposureId, search, key]);
    const current = state?.key === key ? state : null;
    return { items: current?.items ?? [], loading: enabled && current === null, error: current?.error ?? false };
}

function EarlierLinkReviewForm({ link, exposureId, onClose }: { link: EarlierLink; exposureId: number; onClose: () => void }) {
    const [decision, setDecision] = useState<EarlierLinkDecision | null>(null);
    const [incident, setIncident] = useState('');
    const [day, setDay] = useState(today());
    const [reason, setReason] = useState('');
    const [notes, setNotes] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    // One key per draft, so saving twice by accident records it once.
    const [keys] = useState(() => ({ review: uuid(), file: uuid() }));
    const [incidentSearch, setIncidentSearch] = useState('');
    const choices = useIncidentChoices(exposureId, decision === 'reviewed_association', incidentSearch);

    const submit = () => {
        const invalid: Record<string, string> = {};
        if (!decision) invalid.decision = 'Choose what the review found.';
        if (decision === 'reviewed_association' && !incident) invalid.incident_id = 'Pick the incident it belongs to.';
        if (!reason.trim()) invalid.reason = 'Say what you checked.';
        if (!day) invalid.reviewed_at = 'Choose the day of the review.';
        else if (day > today()) invalid.reviewed_at = 'The review can’t be in the future.';
        setErrors(invalid);
        if (Object.keys(invalid).length || !decision) return;
        // Noon in the worker's zone keeps the chosen day whatever the offset.
        const reviewedAt = new Date(`${day}T12:00:00`).toISOString().replace(/\.\d{3}Z$/, 'Z');
        const version = link.expected_version ?? 0;
        setSaving(true);
        router.post(`${CHEMICAL_EXPOSURES}/${exposureId}/earlier-link/reviews`, {
            idempotency_key: keys.review, expected_version: version, decision, reviewed_at: reviewedAt, reason: reason.trim(),
            ...(notes.trim() ? { notes: notes.trim() } : {}), ...(decision === 'reviewed_association' ? { incident_id: Number(incident) } : {}),
        }, {
            preserveScroll: true,
            onError: (bag) => { setErrors(bag as Record<string, string>); setSaving(false); },
            onSuccess: () => {
                if (!file) { setSaving(false); onClose(); return; }
                router.post(`${CHEMICAL_EXPOSURES}/${exposureId}/earlier-link/files`, { idempotency_key: keys.file, expected_version: version + 1, file },
                    { forceFormData: true, preserveScroll: true,
                        onError: (bag) => { setErrors({ file: Object.values(bag)[0] ?? 'The file couldn’t be added. The review is saved; add the file again.' }); setSaving(false); },
                        onSuccess: () => { setSaving(false); onClose(); } });
            },
        });
    };

    return (
        <Surface title="Review the earlier link" sub="Your review is added beside the old record. The old record isn’t changed.">
            <div className="grid gap-4 px-[18px] pb-[18px]">
                <Field label="What did you find?" required error={errors.decision}>
                    <div role="radiogroup" aria-label="What did you find?" className="grid gap-2 md:grid-cols-3">
                        {TILES.map((tile) => {
                            const picked = decision === tile.value;
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- a choice tile in a radio group (custom layout), not a form button.
                                <button key={tile.value} type="button" role="radio" aria-checked={picked} onClick={() => setDecision(tile.value)}
                                    className={cn('frontline-tap flex items-start gap-3 rounded-lg border px-3 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        picked ? 'border-primary bg-accent' : 'border-border hover:bg-muted/60')}>
                                    <tile.icon className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                                    <span className="grid"><b className="text-sm font-semibold">{tile.title}</b><span className="text-caption text-muted-foreground">{tile.body}</span></span>
                                </button>
                            );
                        })}
                    </div>
                </Field>
                <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_14rem]">
                    {decision === 'reviewed_association' ? (
                        <Field label="Incident" required error={errors.incident_id} hint="Only incidents at this exposure’s Site that you can open">
                            <div className="grid gap-2">
                                <Input value={incidentSearch} maxLength={100} onChange={(event) => setIncidentSearch(event.target.value)} placeholder="Find an incident by reference or title" aria-label="Find an incident by reference or title" />
                                {choices.error ? (
                                    <p role="status" className="text-subtle text-status-warning">Incidents couldn’t be loaded. Check your access and try again.</p>
                                ) : (
                                    <SelectInput value={incident} onChange={setIncident} placeholder={choices.loading ? 'Loading incidents…' : choices.items.length ? 'Choose an incident' : 'No incidents you can open match'} ariaLabel="Incident"
                                        options={choices.items.map((row) => ({ value: String(row.id), label: [row.reference, row.title, row.occurred_at ? formatDateOnly(toDateInput(row.occurred_at)) : null].filter(Boolean).join(' · ') }))} />
                                )}
                            </div>
                        </Field>
                    ) : <div className="hidden md:block" />}
                    <Field label="Reviewed on" required error={errors.reviewed_at}>
                        <DatePicker id={`earlier-link-day-${exposureId}`} label="Reviewed on" value={day} onChange={setDay} invalid={!!errors.reviewed_at} />
                    </Field>
                </div>
                <Field label="Why — what you checked" required error={errors.reason}>
                    <Textarea value={reason} maxLength={5000} onChange={(event) => setReason(event.target.value)} rows={3} />
                </Field>
                <Field label="Notes" error={errors.notes} hint="Optional">
                    <Textarea value={notes} maxLength={5000} onChange={(event) => setNotes(event.target.value)} rows={2} />
                </Field>
                <Field label="What you checked, as a file" error={errors.file} hint="Optional · photos, PDFs or Word files, up to 10 MB">
                    {file ? (
                        <div className="flex items-center gap-3 rounded-lg border border-border px-3 py-2 text-sm">
                            <FileText className="size-4 text-muted-foreground" aria-hidden="true" />
                            <span className="min-w-0 truncate font-medium">{file.name}</span>
                            <span className="text-caption text-muted-foreground">{formatFileSize(file.size)}</span>
                            <Button type="button" variant="ghost" size="sm" className="ml-auto" onClick={() => setFile(null)}>Remove</Button>
                        </div>
                    ) : (
                        <FileDropzone accept={ACCEPT} multiple={false} title="Drop a file here, or choose one" hint="Photos, PDFs or Word files" onFiles={(files) => setFile(files[0] ?? null)} />
                    )}
                </Field>
                {errors.command || errors.expected_version || errors.source ? (
                    <p role="alert" className="text-subtle text-status-warning">{errors.expected_version ? 'Someone else reviewed this link meanwhile. Close the form to see their review.' : errors.command ?? errors.source}</p>
                ) : null}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
                    <Button type="button" onClick={submit} disabled={saving}><Check aria-hidden="true" />{saving ? 'Saving…' : 'Save review'}</Button>
                </div>
            </div>
        </Surface>
    );
}
