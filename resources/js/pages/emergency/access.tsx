import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmergencyAccessStrip } from '@/components/emar/emergency-access-strip';
import InputError from '@/components/input-error';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch } from '@/components/page/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { emergencyTimeLeft } from '@/lib/emergency-access';
import { Head, Link, router } from '@inertiajs/react';
import { Clock, History, MoreHorizontal, Plus, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { RequestAccessDialog } from './_request-dialog';
import { ReviewDialog } from './_review-dialog';
import type { Approver, ClientLite, EmergencyPolicy, Grant, OnCallContact } from './_types';

type View = 'running' | 'review' | 'history';
type Flag = { type: string; key: string; title: string; detail: string; can_acknowledge: boolean };
type Pagination = { current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
type Props = {
    query: string; results: ClientLite[]; approvers: Approver[]; activeAccesses: Grant[]; auditLog: Grant[]; reviewQueue: Grant[];
    can_start: boolean; can_review: boolean; policy: EmergencyPolicy; flaggedSignals: Flag[];
    stats: { active: number; awaiting_review: number; flagged: number; month: number };
    sites: { id: number; name: string }[]; active_site: { id: number; name: string } | null; request_client: ClientLite | null;
    on_call_contacts: Record<number, OnCallContact>;
    open_grant?: Grant | null;
    history_pagination?: Pagination; review_pagination?: Pagination;
};
type Command = { kind: 'extend' | 'end' | 'ack'; grant?: Grant; flag?: Flag };

export default function AccessPage(props: Props) {
    const breadcrumbs = useEmarBreadcrumbs();
    const [view, setView] = useState<View>(() => {
        const value = new URLSearchParams(window.location.search).get('view');
        return value === 'running' || value === 'review' || value === 'history' ? value : props.can_start ? 'running' : 'review';
    });
    const [search, setSearch] = useState('');
    const [requesting, setRequesting] = useState(!!props.request_client);
    const [selected, setSelected] = useState<Grant | null>(props.open_grant ?? null);
    const [command, setCommand] = useState<Command | null>(null);
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const id = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(id); }, []);
    useEffect(() => { if (props.request_client) setRequesting(true); }, [props.request_client]);
    useEffect(() => { if (props.open_grant) setSelected(props.open_grant); }, [props.open_grant]);
    const source = view === 'running' ? props.activeAccesses : view === 'review' ? props.reviewQueue ?? props.auditLog.filter((g) => g.status !== 'active' && !g.review_outcome) : props.auditLog;
    const rows = source.filter((g) => `${g.client_name} ${g.staff} ${g.reason} EA-${g.id}`.toLowerCase().includes(search.toLowerCase()));
    const pagination = view === 'history' ? props.history_pagination : view === 'review' ? props.review_pagination : null;
    const open = (grant: Grant) => setSelected(grant);
    const actions = (grant: Grant) => [
        { label: 'Open the grant', run: () => open(grant) },
        ...(grant.can_review ? [{ label: grant.review_outcome ? 'Correct the review' : 'Review the grant', run: () => open(grant) }] : []),
        ...(grant.can_extend && emergencyTimeLeft(grant.expires_at, now).warning ? [{ label: 'Extend', run: () => setCommand({ kind: 'extend', grant }) }] : []),
        ...(grant.can_revoke ? [{ label: grant.own ? 'I’m done — end it now' : 'End their access', run: () => setCommand({ kind: 'end', grant }) }] : []),
    ];
    const selectView = (next: View) => { setView(next); setSearch(''); const url = new URL(window.location.href); url.searchParams.set('view', next); window.history.replaceState({}, '', url); };
    const ownGrants = props.activeAccesses.filter((g) => g.own);
    const status = (g: Grant) => <StatusBadge variant={g.status === 'active' ? 'warning' : !g.review_outcome ? 'info' : g.review_outcome === 'justified' ? 'success' : 'critical'}>
        {g.status === 'active' && !emergencyTimeLeft(g.expires_at, now).ended ? `Running · ${emergencyTimeLeft(g.expires_at, now).label}` : g.review_outcome ? g.review_outcome === 'justified' ? 'Justified' : 'Not justified' : 'To review'}
    </StatusBadge>;
    return <AppLayout breadcrumbs={breadcrumbs}><Head title="Emergency access" />
        <div className="space-y-5">
            <PageHeader icon={ShieldAlert} title="Emergency access" subline="Temporary access to one person’s medication record · independently reviewed"
                actions={<><PageHeaderSearch value={search} onChange={setSearch} placeholder="Search grants shown" />{props.can_start && <PageHeaderPrimaryButton icon={Plus} onClick={() => setRequesting(true)}>Start emergency access</PageHeaderPrimaryButton>}</>}
                meters={<>
                    <PageHeaderMeterBlock label="Running now" tone="warning" onClick={() => selectView('running')}><PageHeaderMeterBig>{props.stats.active}</PageHeaderMeterBig><PageHeaderMeterCaption>At your houses</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="To review" onClick={() => selectView('review')}><PageHeaderMeterBig>{props.stats.awaiting_review}</PageHeaderMeterBig><PageHeaderMeterCaption>Ended grants</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Repeat use" tone={props.stats.flagged ? 'warning' : 'brand'} onClick={() => selectView('history')}><PageHeaderMeterBig>{props.stats.flagged}</PageHeaderMeterBig><PageHeaderMeterCaption>Reported, never blocked</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="This month" onClick={() => selectView('history')}><PageHeaderMeterBig>{props.stats.month}</PageHeaderMeterBig><PageHeaderMeterCaption>All uses this month</PageHeaderMeterCaption></PageHeaderMeterBlock>
                </>}
                filters={<>
                    <PageHeaderFilterSelect label="House" value={String(props.active_site?.id ?? 'all')} options={[{ value: 'all', label: 'All houses' }, ...props.sites.map((site) => ({ value: String(site.id), label: site.name }))]}
                        onChange={(value) => router.get('/emar/emergency-access', { site_id: value === 'all' ? undefined : value, view }, { preserveState: true })} />
                    <PageHeaderFilterButton onClick={() => router.visit('/emar/settings#alerts/emergency')}>Emergency access policy</PageHeaderFilterButton>
                </>}
                rail={<PageHeaderRail value={view} onSelect={selectView} items={[
                    { key: 'running', label: 'Running now', icon: Clock, count: props.stats.active },
                    ...(props.can_review ? [{ key: 'review' as const, label: 'To review', icon: ShieldAlert, count: props.stats.awaiting_review }] : []),
                    { key: 'history', label: 'History', icon: History },
                ]} />}
            />
            {view === 'running' && ownGrants.map((grant) => <EmergencyAccessStrip key={grant.id} grant={grant} onExtend={() => setCommand({ kind: 'extend', grant })} />)}
            {view === 'history' && props.flaggedSignals.map((flag) => <Alert key={flag.key} className="border-status-warning"><AlertTitle>{flag.title}</AlertTitle><AlertDescription className="flex flex-wrap items-center justify-between gap-3">{flag.detail}{flag.can_acknowledge && <Button variant="outline" onClick={() => setCommand({ kind: 'ack', flag })}>Acknowledge</Button>}</AlertDescription></Alert>)}
            {!rows.length ? <EmptyState icon={ShieldAlert} title={search ? 'No matching grants shown' : view === 'running' ? 'No emergency access is running' : view === 'review' ? 'No grants waiting for review here' : 'No emergency access recorded here'} description="Every use stays in History after it ends." /> : <>
                <div className="hidden md:block"><Table><TableHeader><TableRow><TableHead>Grant · person</TableHead><TableHead>Used by</TableHead><TableHead>When</TableHead><TableHead>State</TableHead><TableHead><span className="sr-only">Actions</span></TableHead></TableRow></TableHeader><TableBody>
                    {rows.map((grant) => <ContextMenu key={grant.id}><ContextMenuTrigger asChild><TableRow tabIndex={0} onClick={() => open(grant)} onKeyDown={(e) => { if (e.key === 'Enter' && e.target === e.currentTarget) open(grant); }} className="cursor-pointer focus-visible:ring-2 focus-visible:ring-ring">
                        <TableCell><span className="font-medium">EA-{grant.id} · {grant.client_name}</span><div className="text-caption">{grant.site_name}</div></TableCell>
                        <TableCell>{grant.staff}<div className="text-caption">{grant.reason_category}</div></TableCell>
                        <TableCell>{formatDateTime(grant.created_at)}<div className="text-caption">{grant.status === 'active' ? `Ends ${formatDateTime(grant.expires_at)}` : `Review due ${formatDateTime(grant.review_due_at)}`}</div></TableCell>
                        <TableCell>{status(grant)}{grant.review_denial && <div className="text-caption">{grant.review_denial}</div>}</TableCell>
                        <TableCell onClick={(e) => e.stopPropagation()}><DropdownMenu><DropdownMenuTrigger asChild><Button variant="ghost" size="icon" className="frontline-tap" aria-label={`Actions for EA-${grant.id}`}><MoreHorizontal className="size-4" /></Button></DropdownMenuTrigger><DropdownMenuContent>{actions(grant).map((action) => <DropdownMenuItem key={action.label} onSelect={action.run}>{action.label}</DropdownMenuItem>)}</DropdownMenuContent></DropdownMenu></TableCell>
                    </TableRow></ContextMenuTrigger><ContextMenuContent>{actions(grant).map((action) => <ContextMenuItem key={action.label} onSelect={action.run}>{action.label}</ContextMenuItem>)}</ContextMenuContent></ContextMenu>)}
                </TableBody></Table></div>
                <div className="grid gap-3 md:hidden">{rows.map((grant) => <Card key={grant.id}><CardContent className="space-y-3 p-4"><Button variant="link" className="frontline-tap h-auto whitespace-normal p-0" onClick={() => open(grant)}>EA-{grant.id} · {grant.client_name}</Button><p>{grant.staff} · {grant.site_name}</p>{status(grant)}<p className="text-caption">{formatDateTime(grant.created_at)} · {grant.reason}</p><div className="flex flex-wrap gap-2">{actions(grant).slice(1).map((action) => <Button key={action.label} variant="outline" className="frontline-tap" onClick={action.run}>{action.label}</Button>)}</div></CardContent></Card>)}</div>
            </>}
            {pagination && pagination.last_page > 1 && <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-caption">Page {pagination.current_page} of {pagination.last_page} · {pagination.total} grants</p><div className="flex gap-2">
                {pagination.prev_page_url && <Button asChild variant="outline"><Link href={pagination.prev_page_url} preserveState preserveScroll>Previous</Link></Button>}
                {pagination.next_page_url && <Button asChild variant="outline"><Link href={pagination.next_page_url} preserveState preserveScroll>Next</Link></Button>}
            </div></div>}
        </div>
        {requesting && <RequestAccessDialog results={props.results} query={props.query} approvers={props.approvers} policy={props.policy} prefillClient={props.request_client} onCallContacts={props.on_call_contacts} onClose={() => setRequesting(false)}
            onSearch={(q) => router.get('/emar/emergency-access', { q, site_id: props.active_site?.id, view }, { preserveState: true, preserveScroll: true })} />}
        {selected && <ReviewDialog record={selected} onClose={() => setSelected(null)} />}
        {command && <CommandDialog command={command} onClose={() => setCommand(null)} />}
    </AppLayout>;
}

function CommandDialog({ command, onClose }: { command: Command; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const ownEnd = command.kind === 'end' && command.grant?.own;
    const title = command.kind === 'extend' ? 'Extend emergency access' : command.kind === 'ack' ? 'Acknowledge repeat use' : ownEnd ? 'I’m done — end it now' : 'End their emergency access';
    function send() {
        setBusy(true);
        const options = { preserveScroll: true, onSuccess: onClose, onError: (e: Record<string, string>) => { setErrors(e); setConfirmed(false); }, onFinish: () => setBusy(false) };
        const grant = command.grant;
        if (command.kind === 'ack') router.post('/emar/break-glass-flags/dismiss', { type: 'repeat', key: command.flag!.key, reason }, options);
        else if (command.kind === 'extend') router.post(`/emar/clients/${grant!.client_id}/break-glass/${grant!.id}/extend`, { reason }, options);
        else router.delete(`/emar/clients/${grant!.client_id}/break-glass/${grant!.id}`, { ...options, data: ownEnd ? {} : { reason } });
    }
    return <>
        <Dialog open={!confirmed} onOpenChange={(v) => !v && onClose()}><DialogContent><DialogHeader><DialogTitle>{title}</DialogTitle><DialogDescription>{command.grant ? `EA-${command.grant.id} · ${command.grant.client_name}` : command.flag?.detail}</DialogDescription></DialogHeader>
            <div className="space-y-3">{!ownEnd && <><Label htmlFor="emergency-command-reason">{command.kind === 'ack' ? 'What you found' : command.kind === 'extend' ? 'Why you need longer' : 'Why you’re ending it'}</Label><Textarea id="emergency-command-reason" value={reason} onChange={(e) => setReason(e.target.value)} /></>}
                <p className="text-caption">{command.kind === 'end' ? 'It ends now and goes to reviewers. The person using it is told if someone else ends it.' : 'Your name, the time and the reason are recorded.'}</p>
                {Object.values(errors).map((error) => <InputError key={error} message={error} />)}
            </div><DialogFooter><Button variant="outline" onClick={onClose}>Cancel</Button><Button variant={command.kind === 'end' && !ownEnd ? 'destructive' : 'default'} disabled={busy || !ownEnd && reason.trim().length < (command.kind === 'extend' ? 5 : 10)} onClick={() => command.kind === 'end' ? setConfirmed(true) : send()}>{busy ? 'Saving…' : title}</Button></DialogFooter>
        </DialogContent></Dialog>
        <ConfirmDialog open={confirmed} onClose={() => setConfirmed(false)} onConfirm={send} processing={busy} variant={ownEnd ? 'default' : 'destructive'} title={title} description="The grant ends immediately. Unsaved dose entries need fresh authority before recording." confirmText="End it now" cancelText="Keep it running" />
    </>;
}
