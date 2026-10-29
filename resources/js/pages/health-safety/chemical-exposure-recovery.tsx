import { PageHeader, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageLayout } from '@/components/page';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { SafetyFormDraftContext, useSafetyDraftState, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FileCheck2, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Stage = { id: number; state: string; accepted: boolean; recorded_at?: string | null; can_retry: boolean };
type Cleanup = { id: number; state: string; removed_from_record: true; recorded_at?: string | null; can_retry: boolean };
type Collection<T> = { attempts: T[]; next_cursor: number | null };
type Props = { can: { inspect: boolean; retry: boolean } };
const BASE = `${CHEMICAL_EXPOSURES}/recovery`;
const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
function stage(value: unknown): value is Stage {
    return object(value) && positive(value.id) && typeof value.state === 'string' && typeof value.accepted === 'boolean' && typeof value.can_retry === 'boolean'
        && (!value.accepted || value.state === 'accepted' && value.can_retry === false);
}
function cleanup(value: unknown): value is Cleanup {
    return object(value) && positive(value.id) && typeof value.state === 'string' && value.removed_from_record === true && typeof value.can_retry === 'boolean'
        && (value.state !== 'complete' || value.can_retry === false);
}
function collection<T>(value: unknown, valid: (row: unknown) => row is T): value is Collection<T> {
    return object(value) && Array.isArray(value.attempts) && value.attempts.every(valid) && (value.next_cursor === null || positive(value.next_cursor));
}
export default function ChemicalExposureRecovery(props: Props) {
    const { props: shared } = usePage<SharedData>(); return <RecoveryPage key={shared.auth.user.id} {...props} />;
}
function RecoveryPage(props: Props) {
    const drafts = useSafetyWorkspaceDrafts();
    return <SafetyFormDraftContext.Provider value={drafts.register}><RecoveryWorkspace {...props} />{drafts.confirmation}</SafetyFormDraftContext.Provider>;
}
function RecoveryWorkspace({ can }: Props) {
    const [view, setView] = useState<'staging' | 'cleanups'>('staging'); const [withdrawn, setWithdrawn] = useState(false);
    const [stages, setStages] = useState<Collection<Stage> | null>(null); const [cleanups, setCleanups] = useState<Collection<Cleanup> | null>(null);
    const [cursors, setCursors] = useState({ staging: 0, cleanups: 0 }); const [nonce, setNonce] = useState(0);
    const [loadedKey, setLoadedKey] = useState<string | null>(null); const [busy, setBusy] = useState(false); const [uncertain, setUncertain] = useState(false);
    const [message, setMessage] = useState<string | null>(null); const request = useRef<AbortController | null>(null);
    // A clean-up deletes stored bytes for good, so it asks first.
    const [pendingCleanup, setPendingCleanup] = useState<{ kind: 'stage_cleanup' | 'removed_cleanup'; id: number } | null>(null);
    const allowed = can.inspect && !withdrawn; const key = `${cursors.staging}:${cursors.cleanups}:${nonce}`;
    const activeRows = loadedKey === key && allowed; const currentStages = activeRows ? stages : null; const currentCleanups = activeRows ? cleanups : null;
    useSafetyDraftState({ dirty: uncertain, busy, uncertain });
    const denied = useCallback((error: unknown) => { if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) setWithdrawn(true); }, []);
    useEffect(() => {
        if (!allowed) { request.current?.abort(); request.current = null; return; }
        const controller = new AbortController(); request.current = controller; setBusy(true); setLoadedKey(null); setMessage(null);
        const config = (after: number) => ({ headers: { Accept: 'application/json' }, params: after ? { after_id: after } : {}, signal: controller.signal });
        void Promise.all([axios.get(`${BASE}/staging`, config(cursors.staging)), axios.get(`${BASE}/cleanups`, config(cursors.cleanups))]).then(([a, b]) => {
            if (controller.signal.aborted) return;
            if (!collection(a.data, stage) || !collection(b.data, cleanup)) throw new Error('Recovery sources could not be confirmed.');
            setStages(a.data); setCleanups(b.data); setLoadedKey(key); setUncertain(false);
        }).catch((error: unknown) => { if (!controller.signal.aborted) { denied(error); setMessage('Recovery records could not be confirmed. Check current access before trying again.'); } })
            .finally(() => { if (request.current === controller) { request.current = null; if (!controller.signal.aborted) setBusy(false); } });
        return () => { controller.abort(); if (request.current === controller) request.current = null; };
    }, [allowed, key, cursors.staging, cursors.cleanups, denied]);
    const refresh = () => { if (!request.current && allowed) setNonce((value) => value + 1); };
    const run = async (kind: 'inspect' | 'bind' | 'stage_cleanup' | 'removed_cleanup', id: number) => {
        if (!activeRows || request.current || uncertain || !positive(id) || (!can.retry && kind !== 'inspect')) return;
        const row = kind === 'removed_cleanup' ? currentCleanups?.attempts.find((item) => item.id === id) : currentStages?.attempts.find((item) => item.id === id);
        if (!row || kind === 'bind' && (!('accepted' in row) || !row.accepted) || ['stage_cleanup', 'removed_cleanup'].includes(kind) && (!row.can_retry || row.state === 'cleaned')) return;
        const controller = new AbortController(); request.current = controller; setBusy(true); setMessage(null);
        const path = kind === 'removed_cleanup' ? `${BASE}/cleanups/${id}/retry` : `${BASE}/staging/${id}${kind === 'inspect' ? '' : kind === 'bind' ? '/bind-acceptance' : '/retry-cleanup'}`;
        try {
            const config = { headers: { Accept: 'application/json' }, signal: controller.signal };
            const response = kind === 'inspect' ? await axios.get(path, config) : await axios.post(path, {}, config);
            if (controller.signal.aborted) return;
            const result = kind === 'removed_cleanup' ? response.data?.cleanup : response.data?.staging;
            if (!(kind === 'removed_cleanup' ? cleanup(result) : stage(result)) || result.id !== id) throw new Error('The recovery result did not match this record.');
            if (kind === 'removed_cleanup') setCleanups((previous) => previous && ({ ...previous, attempts: previous.attempts.map((item) => item.id === id ? result as Cleanup : item) }));
            else setStages((previous) => previous && ({ ...previous, attempts: previous.attempts.map((item) => item.id === id ? result as Stage : item) }));
            setMessage(kind === 'inspect' ? result.accepted ? 'Original upload acceptance confirmed. Reconnect its accepted evidence before checking the saved record.' : 'Original upload acceptance is unconfirmed. Staged bytes do not certify a saved attachment.'
                : kind === 'bind' ? 'Accepted evidence association recorded. Current saved access is checked separately.' : result.state === 'complete' || result.state === 'cleaned' ? 'This file cleanup is confirmed.' : 'Physical file cleanup remains pending.');
        } catch (error) { if (!controller.signal.aborted) { denied(error); setUncertain(kind !== 'inspect'); setMessage(kind === 'inspect' ? 'Original acceptance could not be confirmed.' : 'The recovery response is unconfirmed. Refresh the recorded state before another change.'); } }
        finally { if (request.current === controller) { request.current = null; if (!controller.signal.aborted) setBusy(false); } }
    };
    const rows = view === 'staging' ? currentStages?.attempts : currentCleanups?.attempts;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title: 'Evidence recovery', href: BASE }]}>
        <Head title="Private evidence recovery" /><PageLayout hero={<PageHeader frontline variant="profile" title="Private evidence recovery" icon={ShieldCheck} backHref={CHEMICAL_EXPOSURES}
            subline="Your original upload and removal attempts · current private source access"
            actions={allowed ? <PageHeaderGlassButton icon={RefreshCw} onClick={refresh}>Check recorded recovery state</PageHeaderGlassButton> : undefined}
            meters={allowed ? <>
                <PageHeaderMeterBlock label="Upload attempts" onClick={() => setView('staging')}><PageHeaderMeterBig>{currentStages?.attempts.length ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>On this page · not a saved evidence count</PageHeaderMeterCaption></PageHeaderMeterBlock>
                <PageHeaderMeterBlock label="Removal cleanups" onClick={() => setView('cleanups')}><PageHeaderMeterBig>{currentCleanups?.attempts.length ?? 'Unconfirmed'}</PageHeaderMeterBig><PageHeaderMeterCaption>On this page · physical cleanup checked separately</PageHeaderMeterCaption></PageHeaderMeterBlock>
            </> : undefined} rail={<PageHeaderRail items={[{ key: 'staging', label: 'Upload attempts', icon: FileCheck2 }, { key: 'cleanups', label: 'Removal cleanups', icon: Trash2 }]}
                value={view} onSelect={setView} ariaLabel="Evidence recovery views" />} />}>
            <div className="space-y-4"><p role="status" className="text-subtle">{allowed ? 'A staged file is not a saved attachment. Removing evidence from a record and physically cleaning its bytes are separate steps.' : 'Current private source access is unavailable. Recovery details and changes are withheld.'}</p>
                {allowed && message && <p role="status" className="text-subtle text-status-warning">{message}</p>}
                {allowed && busy && <p role="status" className="text-subtle">Checking the original recovery record…</p>}
                {allowed && rows && (!rows.length ? <p className="text-subtle">No admitted recovery attempts are available on this page.</p> : <ul className="space-y-4">{rows.map((row) => <li key={row.id} className="space-y-3 rounded-lg border border-border bg-card p-4">
                    <h2 className="text-section-title">{view === 'staging' ? 'Upload' : 'Removal cleanup'} reference {row.id}</h2>
                    <StatusBadge variant={row.state === 'complete' || row.state === 'cleaned' ? 'neutral' : 'warning'}>{row.state === 'complete' || row.state === 'cleaned' ? 'File cleanup confirmed' : row.state === 'accepted' ? 'Original acceptance confirmed' : 'Recovery pending'}</StatusBadge>
                    <p className="text-subtle">Recorded at {formatDateTime(row.recorded_at ?? null, 'Time not recorded')}</p>
                    <div className="flex flex-wrap gap-3">{view === 'staging' && <Button variant="outline" className="frontline-tap" disabled={busy || uncertain} onClick={() => void run('inspect', row.id)}>Check original upload acceptance</Button>}
                        {can.retry && view === 'staging' && 'accepted' in row && row.accepted && <Button variant="outline" className="frontline-tap" disabled={busy || uncertain} onClick={() => void run('bind', row.id)}>Reconnect accepted evidence</Button>}
                        {can.retry && row.can_retry && row.state !== 'cleaned' && <Button variant="outline" className="frontline-tap" disabled={busy || uncertain}
                            onClick={() => setPendingCleanup({ kind: view === 'staging' ? 'stage_cleanup' : 'removed_cleanup', id: row.id })}>{view === 'staging' ? 'Clean unaccepted staged bytes' : 'Retry physical file cleanup'}</Button>}</div>
                </li>)}</ul>)}
                {allowed && activeRows && <nav aria-label="Recovery pages" className="flex flex-wrap gap-3">{cursors[view] > 0 && <Button variant="outline" className="frontline-tap" disabled={busy} onClick={() => setCursors((old) => ({ ...old, [view]: 0 }))}>First recovery page</Button>}
                    {(view === 'staging' ? currentStages : currentCleanups)?.next_cursor && <Button variant="outline" className="frontline-tap" disabled={busy} onClick={() => setCursors((old) => ({ ...old, [view]: (view === 'staging' ? currentStages : currentCleanups)!.next_cursor! }))}>Next recovery page</Button>}</nav>}
            </div>
            <ConfirmDialog open={pendingCleanup !== null} onClose={() => setPendingCleanup(null)} processing={busy} variant="destructive"
                title="Delete this stored file?" confirmText="Delete the file" cancelText="Keep it"
                description={pendingCleanup?.kind === 'stage_cleanup'
                    ? 'This deletes the stored copy of an upload that was never saved to the exposure record. The record’s history stays. This can’t be undone.'
                    : 'This deletes the stored copy of a file already removed from the exposure record. The record’s history stays. This can’t be undone.'}
                onConfirm={() => { const next = pendingCleanup; setPendingCleanup(null); if (next) void run(next.kind, next.id); }} />
        </PageLayout>
    </AppLayout>;
}
