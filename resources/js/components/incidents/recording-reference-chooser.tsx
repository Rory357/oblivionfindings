import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    matchesRecordingRequest, recordingBase, recordingDetail, recordingPage, recordingRecoveryBase,
    type RecordingDetail, type RecordingPage, type RecordingRequest,
} from '@/pages/security-devices/cctv/_recording-contract';
import { cctvRecordingDetailJson, cctvRecordingJson } from '@/pages/security-devices/cctv/live-transport';
import { sourceText } from '@/pages/security-devices/cctv/_source-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { attachmentBasis, confirmsRecordingAttachment, referenceMatchesWindow, type RecordingAttachmentCommand } from './recording-attachment-command';
import type { RecordingReferenceEvidenceData } from './recording-reference-evidence';
import { recordingChoiceKey, type RecordingChoice, type RecordingPreview } from './recording-window-command';

type Props = {
    actorId: number; incidentId: number; preview?: RecordingPreview; evidence?: RecordingReferenceEvidenceData;
    refreshing?: boolean;
    onDraftStateChange: (state: { dirty: boolean; busy: boolean; uncertain: boolean }) => void;
    requestDiscard: (run: () => void) => void; onSaved: () => void; onRefresh: () => void;
};
/** Separate in-memory owners for a Devices request and an incident attachment. */
export function RecordingReferenceChooser(props: Props) {
    return <Chooser key={`${props.actorId}:${props.incidentId}`} {...props} />;
}
function Chooser({ actorId, incidentId, preview, evidence, refreshing = false, onDraftStateChange, requestDiscard, onSaved, onRefresh }: Props) {
    const current = attachmentBasis(preview, evidence, actorId, incidentId);
    const [basis, setBasis] = useState(current);
    const [source, setSource] = useState<RecordingChoice | null>(null);
    const [page, setPage] = useState<RecordingPage | null>(null);
    const [detail, setDetail] = useState<RecordingDetail | null>(null);
    const [createPurpose, setCreatePurpose] = useState('');
    const [linkPurpose, setLinkPurpose] = useState('');
    const [createRequest, setCreateRequest] = useState<RecordingRequest | null>(null);
    const [attachRequest, setAttachRequest] = useState<RecordingAttachmentCommand | null>(null);
    const [busy, setBusy] = useState(false);
    const [refused, setRefused] = useState(false);
    const [incidentRefusal, setIncidentRefusal] = useState<RecordingPreview | null>(null);
    const [saved, setSaved] = useState(false);
    const [message, setMessage] = useState('');
    const controller = useRef<AbortController | null>(null);
    const currentKey = JSON.stringify(current), basisKey = JSON.stringify(basis);
    const latestKey = useRef(currentKey); latestKey.current = currentKey;
    const latestPreview = useRef(preview); latestPreview.current = preview;
    const valid = current !== null && currentKey === basisKey;
    const uncertain = createRequest !== null || attachRequest !== null;
    const dirty = !saved && !!(source || createPurpose || linkPurpose || uncertain);
    const notified = useRef(false);
    useEffect(() => () => controller.current?.abort(), []);
    useEffect(() => { onDraftStateChange({ dirty, busy, uncertain }); }, [dirty, busy, uncertain, onDraftStateChange]);
    useEffect(() => () => onDraftStateChange({ dirty: false, busy: false, uncertain: false }), [onDraftStateChange]);
    useEffect(() => {
        if (saved && !notified.current) { notified.current = true; onSaved(); }
    }, [saved, onSaved]);
    useEffect(() => {
        // The private command survives a scope change; old labels and purpose do not remain on screen.
        setPage(null); setDetail(null);
    }, [currentKey]);
    const blocked = busy || refreshing;
    const incidentRefused = incidentRefusal !== null && (preview === incidentRefusal || !valid);
    const canShow = valid && !refused && !incidentRefused && !saved;
    const sourceCurrent = !!page && !!source && page.camera.site.id === source.site_id &&
        page.source?.recorder_device_id === source.recorder.id && page.current_source.state === 'ready';
    const duration = basis ? (Date.parse(basis.end) - Date.parse(basis.start)) / 1000 : 0;
    const canCreate = canShow && sourceCurrent && page !== null && page.permissions.can_create && !!page.create_href &&
        !!basis && duration <= page.window_limits.max_seconds && Date.parse(basis.start) <= Date.parse(page.server_now);
    const acceptText = (value: string) => {
        const text = value.trim();
        if (!sourceText(text, 10, 500) || /[\uD800-\uDFFF]/u.test(text))
            throw Error('Explain the purpose in 10 to 500 characters on one line, without hidden formatting characters.');
        return text;
    };
    const reset = () => {
        if (blocked || uncertain || !current) return;
        setBasis(current); setSource(null); setPage(null); setDetail(null);
        setCreatePurpose(''); setLinkPurpose(''); setRefused(false); setIncidentRefusal(null); setSaved(false); setMessage('');
        notified.current = false;
    };
    const read = async (choice: RecordingChoice, href?: string, uuid?: string) => {
        if (controller.current || blocked || !valid || saved || !basis) return;
        const run = new AbortController(); controller.current = run; setBusy(true); setMessage('');
        const key = currentKey;
        // A page refresh never erases a private original command.
        setPage(null); setDetail(null);
        try {
            if (uuid) {
                const result = recordingDetail(await cctvRecordingDetailJson(uuid, run.signal), actorId, choice.camera.id, uuid);
                if (!referenceMatchesWindow(result, basis, choice)) throw Error('Reference does not match the approved period and source.');
                if (!run.signal.aborted && latestKey.current === key) { setDetail(result); setRefused(false); }
            } else {
                const result = recordingPage(await cctvRecordingJson(choice.camera.id, actorId,
                    href ?? recordingBase(choice.camera.id), run.signal), actorId, choice.camera.id);
                if (result.camera.site.id !== choice.site_id) throw Error('Camera site changed.');
                if (!run.signal.aborted && latestKey.current === key) { setPage(result); setRefused(false); }
            }
        } catch {
            if (!run.signal.aborted) { setRefused(true); setMessage('Current reference access could not be confirmed. Refresh the incident and camera choices.'); }
        } finally { if (controller.current === run) { controller.current = null; setBusy(false); } }
    };
    const choose = (choice: RecordingChoice) => {
        if (blocked || uncertain || !canShow) return;
        const run = () => {
            setSource(choice); setDetail(null); setPage(null); setCreatePurpose(''); setLinkPurpose('');
            void read(choice);
        };
        if (source || createPurpose || linkPurpose) requestDiscard(run); else run();
    };
    const create = async (recover: boolean) => {
        if (controller.current || blocked || saved || attachRequest || !source || !basis ||
            (!recover && !canCreate) || (recover && !createRequest)) return;
        let original = createRequest;
        if (!original) {
            try {
                if (!page?.source) return;
                original = { expected_actor_id: actorId, request_key: crypto.randomUUID(),
                    expected_source_revision: page.source.revision, requested_start_at: basis.start,
                    requested_end_at: basis.end, purpose: acceptText(createPurpose) };
            } catch (error) { setMessage((error as Error).message); return; }
        }
        const command = original, run = new AbortController();
        controller.current = run; setCreateRequest(command); setBusy(true); setMessage('');
        try {
            const value = await cctvRecordingJson(source.camera.id, actorId, recover
                ? `${recordingRecoveryBase}/${command.request_key}?expected_actor_id=${command.expected_actor_id}`
                : recordingBase(source.camera.id), run.signal, recover ? undefined : command);
            const result = recordingDetail(value, actorId, source.camera.id);
            if (!matchesRecordingRequest(result, command, source.recorder.id) || !referenceMatchesWindow(result, basis, source))
                throw Error('Unconfirmed original reference');
            if (run.signal.aborted) return;
            setCreateRequest(null);
            if (latestKey.current === basisKey) { setDetail(result); setRefused(false); }
            setMessage('Camera reference created. It is not linked to this incident yet. Review it and choose Link reference.');
        } catch {
            if (!run.signal.aborted) {
                setPage(null); setDetail(null); setRefused(true);
                setMessage('Reference creation is not confirmed. Check its original result before creating another. It has not been confirmed as linked to this incident.');
            }
        } finally { if (controller.current === run) { controller.current = null; setBusy(false); } }
    };
    const attach = async (recover: boolean) => {
        if (controller.current || blocked || saved || createRequest || !basis || !source ||
            (!recover && (!canShow || (!attachRequest && !detail))) || (recover && !attachRequest)) return;
        let original = attachRequest;
        if (!original) {
            try {
                if (!detail || !referenceMatchesWindow(detail, basis, source)) throw Error('Review the current reference.');
                original = { expected_actor_id: actorId, idempotency_key: crypto.randomUUID(),
                    expected_incident_evidence_version: basis.version, evidence_window_revision: basis.revision,
                    reference_uuid: detail.reference.reference_uuid, purpose: acceptText(linkPurpose) };
            } catch (error) { setMessage((error as Error).message); return; }
        }
        const command = original, run = new AbortController();
        controller.current = run; setAttachRequest(command); setBusy(true); setMessage('');
        try {
            const config = { signal: run.signal, timeout: 15000, headers: { Accept: 'application/json' } };
            const response = recover
                ? await axios.get(`/incidents/${incidentId}/recording-attachment-commands/${command.idempotency_key}?expected_actor_id=${command.expected_actor_id}`, config)
                : await axios.post(`/incidents/${incidentId}/recording-attachments`, command, config);
            if (run.signal.aborted) return;
            if (!(recover ? response.status === 200 : [200, 201].includes(response.status)) ||
                !confirmsRecordingAttachment(response.data, command, basis)) throw Error('Unconfirmed attachment');
            setAttachRequest(null); setSaved(true); setPage(null); setDetail(null);
            setMessage('Reference linked to the incident. This does not confirm that footage exists or has been preserved.');
        } catch {
            if (!run.signal.aborted) {
                setPage(null); setDetail(null); setRefused(true);
                // A Devices refresh cannot restore incident authority. Retain the
                // exact refused preview until a fresh incident response replaces it.
                setIncidentRefusal(latestPreview.current ?? null);
                setMessage('The incident link is not confirmed. Check the original link result or refresh access before retrying the same submission.');
            }
        } finally { if (controller.current === run) { controller.current = null; setBusy(false); } }
    };
    const matching = page?.references.items.filter((item) => item.recorder.id === source?.recorder.id &&
        item.requested_start_at === basis?.start && item.requested_end_at === basis?.end) ?? [];
    return <section aria-label="Link a recording reference" aria-busy={busy} className="space-y-4 rounded-lg border border-border p-4">
        <div className="space-y-2">
            <h3 className="text-section-title">Link a recording reference</h3>
            <p className="text-subtle text-muted-foreground">Choose an existing reference or create one for the exact approved period, then link it here. A reference does not retrieve, verify or preserve footage.</p>
        </div>
        {!valid ? <p role="status" className="text-subtle text-muted-foreground">Review the current evidence window and camera access before linking references. Any unconfirmed submission remains privately in this tab.</p> : null}
        {canShow && basis ? <>
            <p className="text-subtle">Approved period (NZ time): {formatDateTimeLong(basis.start)} – {formatDateTimeLong(basis.end)}</p>
            <div className="flex flex-wrap gap-2" aria-label="Approved cameras">
                {basis.sources.map((choice) => <Button key={recordingChoiceKey(choice)} type="button" size="sm"
                    variant={source && recordingChoiceKey(source) === recordingChoiceKey(choice) ? 'secondary' : 'outline'}
                    aria-pressed={!!source && recordingChoiceKey(source) === recordingChoiceKey(choice)} className="frontline-hit min-w-0 max-w-full"
                    disabled={blocked || uncertain} onClick={() => choose(choice)}><span className="truncate">{choice.camera.name} · {choice.recorder.name}</span></Button>)}
            </div>
            {source ? <p className="break-words text-subtle">{source.camera.name} · {source.recorder.name}</p> : null}
            {!basis.sources.length ? <p className="text-subtle text-muted-foreground">No currently readable camera is included in this window.</p> : null}
            {page && !uncertain ? <div className="space-y-3">
                <h4 className="font-medium">Existing references for this period</h4>
                {matching.length ? <ul className="space-y-2">{matching.map((item) => <li key={item.reference_uuid}>
                    <Button type="button" variant="outline" size="sm" className="frontline-hit min-w-0 max-w-full" disabled={blocked}
                        onClick={() => source && void read(source, undefined, item.reference_uuid)}>
                        <span className="truncate">Review reference created {formatDateTimeLong(item.created_at)}</span>
                    </Button>
                </li>)}</ul> : <p className="text-subtle text-muted-foreground">No exact match on this page. Check other pages or create a reference for the approved period.</p>}
                <LaravelPagination ariaLabel="Available recording references" cursor={{
                    previousHref: page.references.previous_href, nextHref: page.references.next_href,
                    pending: blocked, onPageChange: (href) => { if (source) void read(source, href); },
                }} />
                {canCreate && !detail ? <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); void create(false); }}>
                    <label className="block space-y-2 text-subtle">Purpose of the new camera reference
                        <Textarea value={createPurpose} disabled={blocked} onChange={(event) => setCreatePurpose(event.target.value)} rows={3} />
                    </label>
                    <p className="text-caption text-muted-foreground">Use one line of 10 to 500 characters.</p>
                    <p className="text-caption text-muted-foreground">The period is fixed to this incident window. Creation and incident linking are separate steps.</p>
                    <Button type="submit" size="sm" className="frontline-hit" disabled={blocked}>Create camera reference</Button>
                </form> : !detail ? <p className="text-subtle text-muted-foreground">A new reference is unavailable for this camera, your access or this period. Existing matching references can still be reviewed.</p> : null}
            </div> : null}
            {detail && !uncertain ? <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); void attach(false); }}>
                <h4 className="font-medium">Selected reference</h4>
                <p className="break-words text-subtle">{detail.reference.purpose}</p>
                <p className="text-caption text-muted-foreground">Created by {detail.reference.created_by.name} · {formatDateTimeLong(detail.reference.created_at)} · Reference only</p>
                <label className="block space-y-2 text-subtle">Why this reference belongs with the incident
                    <Textarea value={linkPurpose} disabled={blocked} onChange={(event) => setLinkPurpose(event.target.value)} rows={3} />
                </label>
                <p className="text-caption text-muted-foreground">Use one line of 10 to 500 characters.</p>
                <Button type="submit" size="sm" className="frontline-hit" disabled={blocked}>Link reference</Button>
            </form> : null}
        </> : null}
        {message ? <p role="status" className="text-subtle">{message}</p> : null}
        <div className="flex flex-wrap gap-3">
            {createRequest ? <>
                <Button type="button" size="sm" className="frontline-hit" disabled={blocked} onClick={() => void create(true)}>Check reference creation</Button>
                <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked || !canCreate} onClick={() => void create(false)}>Retry same reference creation</Button>
            </> : null}
            {attachRequest ? <>
                <Button type="button" size="sm" className="frontline-hit" disabled={blocked} onClick={() => void attach(true)}>Check incident link</Button>
                <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked || !canShow} onClick={() => void attach(false)}>Retry same incident link</Button>
            </> : null}
            {source && valid && !saved ? <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked}
                onClick={() => void read(source)}>Refresh camera references</Button> : null}
            <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked} onClick={onRefresh}>Refresh incident access</Button>
            {current && (!valid || saved) && !uncertain ? <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked}
                onClick={() => dirty ? requestDiscard(reset) : reset()}>Review current window</Button> : null}
        </div>
    </section>;
}
