import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTimeLong } from '@/lib/datetime';
import axios from 'axios';
import { useEffect, useId, useRef, useState } from 'react';
import {
    confirmsRecordingWindow, recordingChoiceKey, recordingWindowInterval, validRecordingPreview,
    type RecordingPreview, type RecordingWindowCommand,
} from './recording-window-command';

type DraftState = { dirty: boolean; busy: boolean; uncertain: boolean };
type Props = {
    preview?: RecordingPreview | null;
    actorId: number;
    incidentId: number;
    refreshing?: boolean;
    onDraftStateChange: (state: DraftState) => void;
    onSaved: () => void;
    onRefresh: () => void;
    requestDiscard: (action: () => void) => void;
};

/** Account/incident changes discard sensitive memory and abort the old request. */
export function RecordingWindowEditor(props: Props) {
    const identity = `${props.actorId}:${props.incidentId}`;
    const retained = useRef<{ identity: string; preview: RecordingPreview } | null>(null);
    if (retained.current?.identity !== identity) retained.current = null;
    const available = validRecordingPreview(props.preview, props.actorId, props.incidentId);
    if (available) retained.current = { identity, preview: props.preview! };
    if (!retained.current) {
        return <p role="alert" className="text-subtle text-muted-foreground">Reload the incident to check current evidence access.</p>;
    }
    // A malformed same-account refresh hides choices, but must not erase a command
    // whose response was lost. Only an account/incident change destroys that owner.
    return <WindowEditor key={identity} {...props} preview={retained.current.preview} previewAvailable={available} />;
}

function WindowEditor({ preview, previewAvailable, actorId, incidentId, refreshing = false, onDraftStateChange, onSaved, onRefresh, requestDiscard }:
    Omit<Props, 'preview'> & { preview: RecordingPreview; previewAvailable: boolean }) {
    const fieldId = useId();
    const [basis, setBasis] = useState(preview);
    const [before, setBefore] = useState(String(preview.policy.rule?.before_seconds ?? ''));
    const [after, setAfter] = useState(String(preview.policy.rule?.after_seconds ?? ''));
    const [selected, setSelected] = useState<string[]>([]);
    const [reason, setReason] = useState('');
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [submitted, setSubmitted] = useState<RecordingWindowCommand | null>(null);
    const [message, setMessage] = useState('');
    const [refusalBasis, setRefusalBasis] = useState<RecordingPreview | null>(null);
    const request = useRef<AbortController | null>(null);
    const savedNotified = useRef(false);
    const latest = useRef({ preview, actorId, incidentId });
    latest.current = { preview, actorId, incidentId };
    useEffect(() => () => request.current?.abort(), []);
    useEffect(() => {
        onDraftStateChange({ dirty, busy, uncertain: submitted !== null });
    }, [dirty, busy, submitted, onDraftStateChange]);
    useEffect(() => () => onDraftStateChange({ dirty: false, busy: false, uncertain: false }), [onDraftStateChange]);
    useEffect(() => {
        if (saved && !savedNotified.current) { savedNotified.current = true; onSaved(); }
    }, [saved, onSaved]);

    const stale = preview.incident_evidence_version !== basis.incident_evidence_version ||
        preview.active_window_revision !== basis.active_window_revision ||
        JSON.stringify(preview.policy) !== JSON.stringify(basis.policy) ||
        JSON.stringify(preview.sources) !== JSON.stringify(basis.sources);
    const refused = refusalBasis !== null && (preview === refusalBasis || !preview.permissions.can_record);
    const canRecord = previewAvailable && preview.permissions.can_record && !refused;
    const sourceScopeChanged = JSON.stringify(preview.sources) !== JSON.stringify(basis.sources);
    const rule = basis.policy.rule;
    const blocked = busy || refreshing || saved || stale || !canRecord || submitted !== null || !rule;
    let period: ReturnType<typeof recordingWindowInterval> | null = null;
    try { period = recordingWindowInterval({ ...basis, permissions: preview.permissions }, before, after); } catch { /* Inline validation occurs on submission. */ }
    const adoptCurrent = () => {
        if (busy || submitted) return;
        setBasis(preview); setBefore(String(preview.policy.rule?.before_seconds ?? ''));
        setAfter(String(preview.policy.rule?.after_seconds ?? ''));
        setSelected([]); setReason(''); setSaved(false); setDirty(false); setMessage('');
        savedNotified.current = false;
    };
    const send = async (recover: boolean) => {
        if (request.current || busy || refreshing || saved || (!recover && (refused || !previewAvailable)) || (!submitted && blocked)) return;
        let original = submitted;
        if (!original) {
            try {
                if (!rule || basis.policy.revision === null) throw new Error('The evidence rule is unavailable.');
                const trimmed = reason.trim();
                if (Array.from(trimmed).length < 10 || Array.from(trimmed).length > 500)
                    throw new Error('Explain the review period and camera selection in 10 to 500 characters.');
                const sources = basis.sources.items.filter((source) => selected.includes(recordingChoiceKey(source)));
                if (sources.length !== selected.length || sources.some((source) => !source.can_record_window))
                    throw new Error('Review the current camera selection.');
                original = {
                    expected_actor_id: actorId, idempotency_key: crypto.randomUUID(),
                    expected_incident_evidence_version: basis.incident_evidence_version,
                    expected_policy_revision: basis.policy.revision,
                    ...recordingWindowInterval({ ...basis, permissions: preview.permissions }, before, after),
                    approved_sources: sources.map((source) => ({
                        camera_id: source.camera.id, recorder_device_id: source.recorder.id, site_id: source.site_id,
                    })),
                    reason: trimmed,
                };
            } catch (error) {
                setMessage(error instanceof Error ? error.message : 'Review the evidence window.');
                return;
            }
        }
        const command = original;
        const controller = new AbortController();
        request.current = controller;
        setSubmitted(command); setBusy(true); setMessage('');
        const accepts = () => !controller.signal.aborted &&
            latest.current.actorId === actorId && latest.current.incidentId === incidentId;
        try {
            const config = { headers: { Accept: 'application/json' }, signal: controller.signal, timeout: 15000 };
            const response = recover
                ? await axios.get(`/incidents/${incidentId}/evidence-window-commands/${command.idempotency_key}?expected_actor_id=${command.expected_actor_id}`, config)
                : await axios.post(`/incidents/${incidentId}/evidence-windows`, command, config);
            if (!accepts()) return;
            if (!(recover ? response.status === 200 : [200, 201].includes(response.status)) ||
                !confirmsRecordingWindow(response.data, command, incidentId, rule?.identifier ?? ''))
                throw new Error('Unconfirmed response');
            setSubmitted(null); setDirty(false); setSaved(true);
            setMessage('Evidence window saved. Refresh the incident to review its current window and link references.');
            setRefusalBasis(null);
        } catch (error) {
            if (!accepts()) return;
            if (axios.isAxiosError(error) && [401, 403, 404, 419].includes(error.response?.status ?? 0)) {
                setRefusalBasis(latest.current.preview);
                setMessage('Your access or session needs checking. The original submission remains in this tab; its save is not confirmed.');
            } else {
                setMessage('This save is not confirmed. Check its result or retry the same submission before changing it.');
            }
        } finally {
            if (request.current === controller) { request.current = null; setBusy(false); }
        }
    };

    return <form aria-label="Record evidence window" className="space-y-4 rounded-lg border border-border p-4"
        onSubmit={(event) => { event.preventDefault(); void send(false); }}>
        <div className="space-y-2">
            <h3 className="text-section-title">Review period and cameras</h3>
            <p className="text-subtle text-muted-foreground">Record the period to review and the cameras included. This does not retrieve or preserve footage. Earlier windows and links remain in the history.</p>
        </div>
        {!canRecord ? <p role="alert" className="text-subtle text-muted-foreground">You cannot record a new evidence window with your current access.</p> : null}
        {!previewAvailable ? <p role="alert" className="text-subtle text-muted-foreground">Current choices could not be verified. Any original submission is retained privately in this tab; refresh access or check its save result.</p> : null}
        {stale ? <p role="alert" className="text-subtle text-muted-foreground">Incident details or camera choices changed. Check any pending save before reviewing the latest choices.</p> : null}
        {canRecord && !sourceScopeChanged && rule ? <fieldset disabled={blocked} className="space-y-4">
            <legend className="sr-only">Evidence window details</legend>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="space-y-2 text-subtle">Seconds before the incident
                    <Input className="frontline-tap" type="number" min={rule.before_seconds} step={1} max={preview.permissions.can_expand ? rule.max_before_seconds : rule.before_seconds}
                        value={before} onChange={(event) => { setBefore(event.target.value); setDirty(true); }} />
                </label>
                <label className="space-y-2 text-subtle">Seconds after the incident
                    <Input className="frontline-tap" type="number" min={rule.after_seconds} step={1} max={preview.permissions.can_expand ? rule.max_after_seconds : rule.after_seconds}
                        value={after} onChange={(event) => { setAfter(event.target.value); setDirty(true); }} />
                </label>
            </div>
            <p className="text-caption text-muted-foreground">60 seconds = 1 minute. {preview.permissions.can_expand
                ? 'You can extend the period within the configured limits; explain the extension below.'
                : 'Use the standard review period. Extending it requires additional permission.'}</p>
            {period ? <p className="text-subtle">Review period (NZ time): {formatDateTimeLong(period.requested_start_at)} – {formatDateTimeLong(period.requested_end_at)}</p> : null}
            <fieldset className="space-y-2">
                <legend className="text-subtle font-medium">Cameras to include</legend>
                {basis.sources.items.filter((source) => source.can_record_window).map((source) => {
                    const key = recordingChoiceKey(source);
                    return <label key={key} htmlFor={`${fieldId}-${key}`} className="frontline-tap flex cursor-pointer items-center gap-3 rounded-md border border-border px-3 py-2 text-subtle">
                        <Checkbox id={`${fieldId}-${key}`} className="shrink-0"
                            checked={selected.includes(key)} onCheckedChange={(checked) => {
                                setDirty(true); setSelected((current) => checked === true ? [...current, key] : current.filter((item) => item !== key));
                            }} />
                        <span className="min-w-0 break-words">{source.camera.name}<span className="block text-caption text-muted-foreground">{source.recorder.name}</span></span>
                    </label>;
                })}
                {!basis.sources.complete ? <p className="text-caption text-muted-foreground">This is a limited list of eligible cameras. Saving includes only your explicit selections.</p> : null}
                <p className="text-caption text-muted-foreground">{selected.length ? `${selected.length} selected.` : 'No cameras selected. Saving records a review period without camera access or links.'}</p>
            </fieldset>
            <label className="block space-y-2 text-subtle">Reason for this period and selection
                <Textarea value={reason} onChange={(event) => { setReason(event.target.value); setDirty(true); }} rows={3} />
            </label>
        </fieldset> : null}
        {message ? <p role="status" className="text-subtle">{message}</p> : null}
        <div className="flex flex-wrap gap-3">
            {submitted ? <>
                <Button type="button" size="sm" className="frontline-hit" disabled={busy || refreshing} onClick={() => void send(true)}>Check save result</Button>
                <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={busy || refreshing || refused || !previewAvailable} onClick={() => void send(false)}>Retry same submission</Button>
            </> : <Button type="submit" size="sm" className="frontline-hit" disabled={blocked}>Record evidence window</Button>}
            {(stale || saved) && !submitted ? <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={!previewAvailable || busy || refreshing || saved && !stale}
                onClick={() => dirty ? requestDiscard(adoptCurrent) : adoptCurrent()}>Review current choices</Button> : null}
            {(!previewAvailable || refused || saved || submitted !== null) ? <Button type="button" variant="outline" size="sm" className="frontline-hit"
                disabled={busy || refreshing} onClick={onRefresh}>Refresh access and choices</Button> : null}
        </div>
    </form>;
}
