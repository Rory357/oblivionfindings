import { PageHeader, PageHeaderGlassButton, PageHeaderRail, PageLayout } from '@/components/page';
import { EntityCard } from '@/components/lists/entity-card';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { History, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { checkedRecoveryAttempt, checkedRecoveryPage, recoveryStateLabel, type RecoveryAttempt, type RecoveryKind, type RecoveryPage } from './_recovery-state';

const PATHS = { uploads: '/health-safety/ppe/evidence-staging', removals: '/health-safety/ppe/cleanup', relocations: '/health-safety/ppe/relocation-cleanup' };
/** Each queue fetch and retry re-enters current source access; an empty page is never a purge certificate. */
export function PpeRecoveryQueue({ kind }: { kind: RecoveryKind }) {
    const context = useEntityContextMenu<RecoveryAttempt>();
    const [page, setPage] = useState<RecoveryPage | null>(null); const [cursor, setCursor] = useState(0);
    const [busy, setBusy] = useState(false); const [failure, setFailure] = useState<string | null>(null);
    const [confirmation, setConfirmation] = useState<string | null>(null);
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
    const loadOnMount = useRef(load); loadOnMount.current = load;
    useEffect(() => { void loadOnMount.current(); }, []);
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
        ...(attempt.can_retry ? [{ label: 'Retry file cleanup', icon: RefreshCw, onClick: () => void retry(attempt.id), disabled: busy ? 'A current check is in progress.' : undefined }] : []),
    ];
    return <section className="space-y-5" aria-label={kind === 'uploads' ? 'Unaccepted upload attempts' : kind === 'relocations' ? 'Old public copy cleanup' : 'Removed-evidence file cleanup'}>
        <h2 className="text-section-title">{kind === 'uploads' ? 'Your unaccepted upload attempts' : kind === 'relocations' ? 'Your old public copy cleanup' : 'Your removed-evidence file cleanup'}</h2>
        <p className="text-subtle">{kind === 'uploads' ? 'These attempts did not confirm a saved evidence record. Cleanup checks the original source and protects accepted or referenced bytes.' : kind === 'relocations' ? 'The evidence record now references its observed private copy. Cleanup of the old public copy is separate and rechecks the current private file before removing it. The evidence record remains available.' : 'These files were removed from the active record. Physical cleanup is a separate confirmed step; original record history remains.'}</p>
        <Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => void load()}><RefreshCw aria-hidden="true" className="size-4" />{busy ? 'Checking…' : 'Check current attempts'}</Button>
        {failure && <p role="alert" className="text-subtle text-status-warning">{failure}</p>}
        {confirmation && <p role="status" className="text-subtle">{confirmation}</p>}
        {!page && !failure && <p role="status" className="text-subtle">Current file attempts have not been confirmed.</p>}
        {page && <><ListCaption title="Current file attempts" caption={`${page.attempts.length} on this loaded page${page.next_cursor !== null ? ' · more pages available' : ''}`} />
            {page.attempts.length === 0 ? <p className="text-subtle">No currently accessible pending attempts on this page. This view does not confirm organisation-wide file cleanup.</p> :
            <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">{page.attempts.map((attempt) => <li key={attempt.id}>
                <EntityCard icon={History} meridian={['complete', 'cleaned'].includes(attempt.state) ? 'neutral' : 'warning'} name={`File attempt ${attempt.id}`}
                    subline={formatDateTimeLong(attempt.recorded_at, 'Time not recorded')} actions={actions(attempt)} onContextMenu={(event) => context.open(event, attempt)}
                    onOpen={() => void load(cursor)} openLabel="Check state"
                    chips={<StatusBadge variant={['complete', 'cleaned'].includes(attempt.state) ? 'neutral' : 'warning'}>{recoveryStateLabel(kind, attempt.state)}</StatusBadge>}
                    alerts={<>{attempt.command && <p className="text-subtle">Recorded action: {attempt.command.replaceAll('_', ' ')}</p>}
                        {attempt.can_retry && <Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={(event) => { event.stopPropagation(); void retry(attempt.id); }}>Retry file cleanup</Button>}</>}
                    footer={{ primary: kind === 'relocations' ? 'Old public copy cleanup' : kind === 'removals' ? 'Removed-evidence file cleanup' : 'Unaccepted upload cleanup', secondary: 'Each action rechecks current access' }} />
            </li>)}</ul>}
            <div className="flex flex-wrap items-center gap-3"><Button type="button" className="frontline-tap" variant="outline" disabled={busy || cursor === 0} onClick={() => void load()}>Back to first page</Button>
                <Button type="button" className="frontline-tap" variant="outline" disabled={busy || page.next_cursor === null} onClick={() => page.next_cursor !== null && void load(page.next_cursor)}>Next attempts</Button></div>
        </>}
        {context.ctx && page?.attempts.some((attempt) => attempt.id === context.ctx?.record.id) && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} icon={History} title={`File attempt ${context.ctx.record.id}`} items={actions(context.ctx.record)} onClose={context.close} />}
    </section>;
}
export default function PpeRecovery() {
    const [section, setSection] = useState<RecoveryKind>('uploads');
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'PPE & equipment', href: '/health-safety/ppe' }, { title: 'File recovery', href: '/health-safety/ppe/recovery' }]}>
        <Head title="Equipment file recovery" />
        <PageLayout hero={<PageHeader frontline title="Equipment file recovery" icon={History} subline="Your pending file cleanup and historical evidence transfers"
            actions={<PageHeaderGlassButton asChild><Link href="/health-safety/ppe">Equipment register</Link></PageHeaderGlassButton>}
            rail={<PageHeaderRail items={[{ key: 'uploads', label: 'Unaccepted uploads' }, { key: 'removals', label: 'Removed evidence' }, { key: 'relocations', label: 'Old public copies' }]} value={section} onSelect={setSection} ariaLabel="File recovery views" />} />}>
            <div hidden={section !== 'uploads'}><PpeRecoveryQueue kind="uploads" /></div><div hidden={section !== 'removals'}><PpeRecoveryQueue kind="removals" /></div>
            <div hidden={section !== 'relocations'}><PpeRecoveryQueue kind="relocations" /></div>
        </PageLayout>
    </AppLayout>;
}
