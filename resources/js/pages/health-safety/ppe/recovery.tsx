/**
 * Equipment file recovery (approved H&S drawing pg-hsn-124 — Stephan 23 Oct 2026): one header with Check again and
 * the three queues as rail tabs; each queue is its explanation box (word for word) and one log of file attempts —
 * the ones that still need cleanup ringed amber with Retry file cleanup beside them, and the same actions on the
 * row's kebab and right-click.
 *
 * Each queue fetch and retry re-enters current source access; an empty page is never a purge certificate.
 */
import { PageHeader, PageHeaderGlassButton, PageHeaderPrimaryButton, PageHeaderRail, PageLayout } from '@/components/page';
import type { MenuItem } from '@/components/lists/entity-menu';
import { EntityContextMenu, useEntityContextMenu } from '@/components/lists/entity-menu';
import { Log, Note, QuietLine, type LogItem, type SgTone } from '@/components/signature/blocks';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { HardHat, History, Info, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { checkedRecoveryAttempt, checkedRecoveryPage, recoveryStateLabel, type RecoveryAttempt, type RecoveryKind, type RecoveryPage } from './_recovery-state';

const PATHS = { uploads: '/health-safety/ppe/evidence-staging', removals: '/health-safety/ppe/cleanup', relocations: '/health-safety/ppe/relocation-cleanup' };
const EXPLAINED: Record<RecoveryKind, string> = {
    uploads: 'These attempts did not confirm a saved evidence record. Cleanup checks the original source and protects accepted or referenced bytes.',
    relocations: 'The evidence record now references its observed private copy. Cleanup of the old public copy is separate and rechecks the current private file before removing it. The evidence record remains available.',
    removals: 'These files were removed from the active record. Physical cleanup is a separate confirmed step; original record history remains.',
};
/** What one clean-up deletes, said in the confirm. */
const CLEANUP_EFFECT: Record<RecoveryKind, string> = {
    uploads: 'This deletes the stored copy of an upload that was never saved to a record. A file still used by a record is kept.',
    removals: 'This deletes the stored copy of a file that was already removed from its record.',
    relocations: 'This deletes the old public copy. The record keeps its private copy.',
};
const LOG_TITLE: Record<RecoveryKind, string> = { uploads: 'Uploads', removals: 'Removed evidence files', relocations: 'Old public copies' };
/** Still to clean up reads amber; saved evidence green; finished cleanup quiet. */
const tone = (attempt: RecoveryAttempt): SgTone => (attempt.can_retry ? 'warning' : attempt.state === 'accepted' ? 'success' : ['complete', 'cleaned'].includes(attempt.state) ? 'none' : 'warning');

export function PpeRecoveryQueue({ kind, checkSignal = 0 }: { kind: RecoveryKind; /** Bumped by the header's Check again: re-reads the first page. */ checkSignal?: number }) {
    const context = useEntityContextMenu<RecoveryAttempt>();
    const [page, setPage] = useState<RecoveryPage | null>(null); const [cursor, setCursor] = useState(0);
    const [busy, setBusy] = useState(false); const [failure, setFailure] = useState<string | null>(null);
    const [confirmation, setConfirmation] = useState<string | null>(null);
    // Cleaning up deletes a stored file for good, so it asks first.
    const [confirmRetry, setConfirmRetry] = useState<number | null>(null);
    const request = useRef<AbortController | null>(null); const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); request.current = null; }; }, []);
    const load = async (afterId = 0) => {
        if (request.current) return;
        context.close();
        const controller = new AbortController(); request.current = controller; setBusy(true); setFailure(null);
        try {
            const { data } = await axios.get(PATHS[kind], { params: { after_id: afterId }, headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            const fresh = checkedRecoveryPage(data, kind, afterId); if (!fresh) throw new Error('Recovery state unavailable');
            setPage(fresh); setCursor(afterId); setConfirmation(null);
        } catch (error) {
            if (controller.signal.aborted) return;
            setPage(null); setFailure(axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0) ?
                'These file attempts are unavailable with current access.' : 'Current file attempts could not be checked. Retry the check.');
        } finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    // The latest load, kept in a ref (written after render) so the mount and Check again effects always run current code.
    const loadOnMount = useRef(load);
    useEffect(() => { loadOnMount.current = load; });
    useEffect(() => { void loadOnMount.current(); }, []);
    useEffect(() => { if (checkSignal > 0) void loadOnMount.current(); }, [checkSignal]);
    const retry = async (id: number) => {
        if (request.current || !page?.attempts.some((attempt) => attempt.id === id && attempt.can_retry)) return;
        context.close();
        const controller = new AbortController(); request.current = controller; setBusy(true); setFailure(null); setConfirmation(null);
        try {
            const { data } = await axios.post(PATHS[kind] + '/' + id + '/retry', {}, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            const result = checkedRecoveryAttempt(data?.[kind === 'uploads' ? 'staging' : kind === 'relocations' ? 'relocation_cleanup' : 'cleanup'], kind, id);
            if (!result) throw new Error('Retry state unavailable');
            setPage((before) => before ? { ...before, attempts: before.attempts.map((attempt) => attempt.id === id ? { ...attempt, ...result, recorded_at: result.recorded_at ?? attempt.recorded_at } : attempt) } : null);
            setConfirmation(recoveryStateLabel(kind, result.state));
        } catch (error) {
            if (controller.signal.aborted) return;
            // An interrupted retry may have finished. Refetch current state before any repeat.
            setPage(null); setFailure(axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0) ?
                'This file attempt is unavailable with current access.' : 'Cleanup could not be confirmed. Check current attempts before retrying.');
        } finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    const actions = (attempt: RecoveryAttempt): MenuItem[] => [
        { label: 'Check current attempts', icon: RefreshCw, onClick: () => void load(cursor), disabled: busy ? 'A current check is in progress.' : undefined },
        ...(attempt.can_retry ? [{ label: 'Retry file cleanup', icon: RefreshCw, onClick: () => setConfirmRetry(attempt.id), disabled: busy ? 'A current check is in progress.' : undefined }] : []),
    ];
    const items: LogItem[] = (page?.attempts ?? []).map((attempt) => ({
        key: attempt.id, tone: tone(attempt),
        time: attempt.recorded_at && toDateInput(attempt.recorded_at) ? formatDateOnly(toDateInput(attempt.recorded_at)) : 'Time not recorded',
        title: <><span>File attempt {attempt.id}</span> · <span>{recoveryStateLabel(kind, attempt.state)}</span></>,
        sub: [attempt.recorded_at ? formatTime(attempt.recorded_at) : null, attempt.command ? `Recorded action: ${attempt.command.replaceAll('_', ' ')}` : null].filter(Boolean).join(' · ') || undefined,
        action: attempt.can_retry ? { label: 'Retry file cleanup', onClick: () => setConfirmRetry(attempt.id) } : null,
        menu: actions(attempt), onContextMenu: (event) => context.open(event, attempt),
    }));
    // Without a confirmed page the check sits beside the status line; with one, the header's Check again and each row's menu re-read it.
    const check = <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => void load()}><RefreshCw aria-hidden="true" className="size-4" />{busy ? 'Checking…' : 'Check current attempts'}</Button>;
    return <section className="grid min-w-0 gap-5" aria-label={kind === 'uploads' ? 'Unaccepted upload attempts' : kind === 'relocations' ? 'Old public copy cleanup' : 'Removed-evidence file cleanup'}>
        <Note icon={Info}>{EXPLAINED[kind]} Each action rechecks current access.</Note>
        {failure && <div className="flex flex-wrap items-center gap-3"><p role="alert" className="text-subtle text-status-warning">{failure}</p>{check}</div>}
        {confirmation && <p role="status" className="text-subtle">{confirmation}</p>}
        {!page && !failure && <div className="flex flex-wrap items-center gap-3"><p role="status" className="text-subtle">Current file attempts have not been confirmed.</p>{check}</div>}
        {page && <>
            {page.attempts.length === 0
                ? <QuietLine title={LOG_TITLE[kind]} text="No currently accessible pending attempts on this page. This view does not confirm organisation-wide file cleanup." />
                : <Log title={LOG_TITLE[kind]} sub={`${page.attempts.length} on this loaded page${page.next_cursor !== null ? ' · more pages available' : ''}`} items={items} limit={items.length} />}
            {(cursor !== 0 || page.next_cursor !== null) && <nav aria-label="File attempt pages" className="flex flex-wrap items-center gap-3">
                <Button type="button" size="sm" variant="outline" disabled={busy || cursor === 0} onClick={() => void load()}>Back to first page</Button>
                <Button type="button" size="sm" variant="outline" disabled={busy || page.next_cursor === null} onClick={() => page.next_cursor !== null && void load(page.next_cursor)}>Next attempts</Button></nav>}
        </>}
        <ConfirmDialog open={confirmRetry !== null} onClose={() => { if (!busy) setConfirmRetry(null); }} processing={busy} variant="destructive"
            title="Delete this stored file?" confirmText="Delete the file" cancelText="Keep it"
            description={`${CLEANUP_EFFECT[kind]} The record’s history stays. This can’t be undone.`}
            onConfirm={() => { const id = confirmRetry; setConfirmRetry(null); if (id !== null) void retry(id); }} />
        {context.ctx && page?.attempts.some((attempt) => attempt.id === context.ctx?.record.id) && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} icon={History} title={`File attempt ${context.ctx.record.id}`} items={actions(context.ctx.record)} onClose={context.close} />}
    </section>;
}
const VIEWS: { key: RecoveryKind; label: string }[] = [{ key: 'uploads', label: 'Unfinished uploads' }, { key: 'removals', label: 'Removed evidence' }, { key: 'relocations', label: 'Old public copies' }];
export default function PpeRecovery() {
    const [section, setSection] = useState<RecoveryKind>('uploads');
    const [checks, setChecks] = useState<Record<RecoveryKind, number>>({ uploads: 0, removals: 0, relocations: 0 });
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Risks and prevention', href: '/health-safety/registers?view=prevention' },
        { title: 'PPE & equipment', href: '/health-safety/ppe' }, { title: 'File recovery', href: '/health-safety/ppe/recovery' }]}>
        <Head title="Equipment file recovery" />
        <PageLayout hero={<PageHeader title="Equipment file recovery" icon={History} subline="Files that didn’t finish uploading, and older evidence to move"
            actions={<><PageHeaderGlassButton icon={HardHat} asChild><Link href="/health-safety/ppe">Equipment register</Link></PageHeaderGlassButton>
                <PageHeaderPrimaryButton icon={RefreshCw} onClick={() => setChecks((current) => ({ ...current, [section]: current[section] + 1 }))}>Check again</PageHeaderPrimaryButton></>}
            rail={<PageHeaderRail items={VIEWS} value={section} onSelect={setSection} ariaLabel="File recovery views" />} />}>
            {VIEWS.map((view) => <div key={view.key} hidden={section !== view.key}><PpeRecoveryQueue kind={view.key} checkSignal={checks[view.key]} /></div>)}
        </PageLayout>
    </AppLayout>;
}
