/* Medication › Safety & oversight › Emergency access — /emar/emergency-access
 * (Main, Q1). One page with three views: Running now · To review · History.
 * People who hold emergency access see Running now and History and can start
 * it; people who review it (audit.view) see To review and History, and
 * Running now read-only. Nobody else is told it exists: a deep link reads as
 * a page that isn’t available (P00 v5, no existence leak). The policy is
 * P11’s tab, linked, not redesigned. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertTriangle, ArrowUpRight, Bell, CheckCheck, ClipboardCheck, Clock3, Download, Eye, FileText, Flag, History, Home, KeyRound, LockKeyhole, RefreshCw, Settings2, ShieldAlert, TimerReset, XCircle, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';
import { HOUSES, PEOPLE, PERSONAS, REASONS, has, type Grant, type House, type Persona, type PersonaId } from '../data';
import { canExtendNow, didSummary, EndsCell, ENDING_SOON, GrantBadge, LiveStrip, WhoCell } from '../ea-ui';
import { useOpen } from '../host';
import {
    atLong,
    atText,
    allGrants,
    canEndOthers,
    canRequest,
    canReview,
    canSeeAccess,
    eaNow,
    endedOf,
    flagsOf,
    housesOf,
    houseOfGrant,
    isLive,
    isOverdue,
    minutesLeft,
    endOf,
    needsReview,
    reviewBlock,
    reviewDueOf,
    toMin,
    HOW_ENDED,
    type EaSettings,
    type Scenario,
} from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, StateLine, Wrap } from '../ui';
import { useCtx } from './hub';

type View = 'active' | 'review' | 'history';
interface V {
    s: ReturnType<typeof useStore>;
    r: ReturnType<typeof useStore>['route'];
    p: PersonaId;
    me: Persona;
    open: (spec: string) => void;
    ea: EaSettings;
    scn: Scenario;
    set: (patch: Record<string, string | undefined>) => void;
    flags: ReturnType<typeof flagsOf>;
    overdue: Grant[];
}
const reasonLabel = (g: Grant) => REASONS.find((r) => r.key === g.reason)!.label;

export function NotAvailablePage({ area }: { area: string }) {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: area }]}>
            <Card className="items-center gap-3 p-10 text-center">
                <LockKeyhole className="size-8 text-muted-foreground" aria-hidden="true" />
                <h1 className="text-section-title">We can’t show this page</h1>
                <p className="text-subtle">It may not exist, or it may not be available to you. Check the link, or go back.</p>
            </Card>
            <DesignNote>Someone without emergency access, and without the review permission, is never told the page exists (P00 v5 — the same as a record that doesn’t exist).</DesignNote>
        </Shell>
    );
}

export function AccessPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const ctx = useCtx(KeyRound);
    const scn = r.scenario;
    if (!canSeeAccess(p)) return <NotAvailablePage area="Safety & oversight" />;
    const ea = eaNow(s.rt, scn);
    const houseQ = r.q.get('house') as House | null;
    const houses = housesOf(p).filter((h) => !houseQ || h === houseQ);
    const grants = allGrants(s.rt, scn).filter((g) => houses.includes(houseOfGrant(g)));
    const live = grants.filter(isLive).sort((a, b) => toMin(a.start) - toMin(b.start));
    const mine = live.filter((g) => g.by === me.name);
    const toReview = grants.filter(needsReview).sort((a, b) => toMin(reviewDueOf(a, ea)!) - toMin(reviewDueOf(b, ea)!));
    const overdue = toReview.filter((g) => isOverdue(g, ea));
    const flags = flagsOf(s.rt, scn).filter((f) => !f.ack);
    const month = grants.filter((g) => g.start.day >= '2026-09-01');
    const RAIL: { key: View; label: string; icon: LucideIcon; count?: number }[] = [
        { key: 'active', label: 'Running now', icon: KeyRound, count: live.length || undefined },
        { key: 'review', label: 'To review', icon: ClipboardCheck, count: toReview.length || undefined },
        { key: 'history', label: 'History', icon: History },
    ];
    const view = (RAIL.some((x) => x.key === r.q.get('view')) ? r.q.get('view') : canRequest(p) ? 'active' : 'review') as View;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const set = (patch: Record<string, string | undefined>) => s.set({ ...patch, open: undefined });
    const viewLabel = RAIL.find((x) => x.key === view)!.label;

    /* ── the same menu for ⋯, right-click and the menu key ── */
    const menu = (g: Grant): MenuItem[] => {
        const block = reviewBlock(p, g);
        const own = g.by === me.name;
        return compactMenu([
            { label: 'Open the grant', icon: FileText, onClick: () => open(`grant:${g.id}`) },
            needsReview(g) && !block && { label: 'Review it', icon: ClipboardCheck, onClick: () => open(`review:${g.id}`) },
            !isLive(g) && !needsReview(g) && !block && { label: 'Correct the review', icon: CheckCheck, onClick: () => open(`correct:${g.id}`) },
            isLive(g) && own && canExtendNow(g) && { label: `Extend it`, icon: TimerReset, onClick: () => open(`extend:${g.id}`) },
            isLive(g) && own && { label: 'I’m done — end it now', icon: XCircle, onClick: () => open(`done:${g.id}`) },
            { label: `Open ${PEOPLE[g.pid].pref}’s MAR`, icon: Eye, onClick: () => s.go('/emar/mar', { person: g.pid, view: undefined, open: undefined, house: undefined }) },
            isLive(g) && !own && canEndOthers(p) && { separator: true },
            isLive(g) && !own && canEndOthers(p) && { label: 'End their access', icon: XCircle, onClick: () => open(`end:${g.id}`), danger: true },
        ]);
    };
    const rowOpen = (g: Grant) => open(needsReview(g) && !reviewBlock(p, g) ? `review:${g.id}` : `grant:${g.id}`);

    const meters = (
        <>
            <PageHeaderMeterBlock label="Running now" tone={live.some((g) => minutesLeft(g) <= ENDING_SOON) ? 'warning' : 'brand'} ariaLabel={`${live.length} running now`} onClick={() => set({ view: 'active' })}>
                <PageHeaderMeterBig>{dash ?? live.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : mine.length ? `Yours ends ${atText(endOf(mine[0]))}` : live.length ? `Ends ${atText(endOf(live[0]))}` : 'None'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="To review" tone={overdue.length ? 'critical' : 'brand'} ariaLabel={`${toReview.length} to review, ${overdue.length} overdue`} onClick={() => set({ view: 'review' })}>
                <PageHeaderMeterBig>{dash ?? toReview.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : overdue.length ? `${overdue.length} overdue` : toReview.length ? 'None overdue' : 'Nothing waiting'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Repeat use" tone={flags.length ? 'warning' : 'brand'} ariaLabel={`${flags.length} repeat-use flags`} onClick={() => set({ view: 'history' })}>
                <PageHeaderMeterBig>{dash ?? flags.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : 'Reported, never blocked'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="This month" ariaLabel={`${month.length} grants this month`} onClick={() => set({ view: 'history' })}>
                <PageHeaderMeterBig>{dash ?? month.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : houses.length > 1 ? 'Both houses' : HOUSES[houses[0]]}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
    const header = (
        <PageHeader
            icon={KeyRound}
            title="Emergency access"
            titleChip={<PageHeaderStatusChip variant="neutral">{houses.length > 1 ? '2 houses' : HOUSES[houses[0]]}</PageHeaderStatusChip>}
            subline={`${viewLabel} · for recording for someone you aren’t rostered for · NZ time`}
            actions={
                <>
                    {view === 'history' && has(p, 'audit.export') ? (
                        <PageHeaderGlassButton icon={Download} onClick={() => open('export:ea')}>
                            Export
                        </PageHeaderGlassButton>
                    ) : null}
                    <PageHeaderGlassButton icon={Settings2} onClick={() => s.go('/emar/settings', { view: 'alerts', sec: 'emergency', open: undefined, house: undefined })}>
                        The policy
                    </PageHeaderGlassButton>
                    {canRequest(p) ? (
                        <PageHeaderPrimaryButton icon={KeyRound} onClick={() => open('request')}>
                            Start emergency access
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={meters}
            filters={
                <>
                    {me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="House" value={houseQ ?? 'all'} allValue="all" onChange={(v) => set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                    {scn === 'stale' ? (
                        <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                            As at 8:40 am · couldn’t refresh — try again
                        </PageHeaderFilterButton>
                    ) : (
                        <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                            As at 9:12 am · NZDT
                        </PageHeaderFilterButton>
                    )}
                </>
            }
            rail={<PageHeaderRail<View> items={RAIL} value={view} onSelect={(k) => set({ view: k })} ariaLabel="Emergency access views" />}
        />
    );

    const v: V = { s, r, p, me, open, ea, scn, set, flags, overdue };
    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label={`Loading ${viewLabel.toLowerCase()}`}>
                <SkeletonTable rows={5} columns={5} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title={`We couldn’t load ${viewLabel.toLowerCase()}`} message="Nothing is shown rather than a list that might be wrong. Try again in a moment." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (view === 'active') body = <Running v={v} live={live} mine={mine} menu={menu} rowOpen={rowOpen} ctx={ctx} />;
    else if (view === 'review') body = <ToReview v={v} rows={toReview} menu={menu} rowOpen={rowOpen} ctx={ctx} />;
    else body = <HistoryView v={v} grants={grants} menu={menu} ctx={ctx} />;

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: hrefFor('/emar/emergency-access', {}, r) }, { title: 'Safety & oversight' }, { title: 'Emergency access', href: hrefFor('/emar/emergency-access', {}, r) }, { title: viewLabel }]}>
            {header}
            {scn === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                    We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am — a grant may have started or ended since.
                </Notice>
            ) : null}
            {body}
            <DesignNote title={view === 'review' ? 'Design note — review (Main, Q6, NF-12)' : view === 'history' ? 'Design note — history and repeat use (Main, Q7, Q8)' : 'Design note — running now (Main, Q1, Q5)'}>
                <p>
                    {view === 'review'
                        ? 'Today the only review dialog is on a page that reviewers can’t open (breakglass-gated), anyone can review their own grant, and a second review overwrites the first. Here every ended grant — ran out, ended early or ended by someone else — joins this list with a due time from the policy; overdue reviews also go to Follow-ups and the daily report; the reviewer is never the person who used it or the one who confirmed it; a review is never overwritten.'
                        : view === 'history'
                          ? 'Today grants, extensions, ends and reviews write nothing to the audit log, and the history export is built in the browser from 150 rows. Here each is an event in the medication audit trail (P09), and the history export uses P09’s export dialog, with a purpose, under the audit-trail export key. Repeat use is reported, never blocked.'
                          : 'Today a grant simply stops being honoured when its time is up: nothing ends it, the chart shows no warning, and a save fails with “You do not have a current assignment for this medication action.” Here the person using it sees when it ends, gets Extend once 10 minutes or less remain, and can end it early; the house’s reviewers are told when it starts.'}
                </p>
            </DesignNote>
        </Shell>
    );

}

/* ── views ── */
function Running({ v, live: rows, mine: yours, menu: m, rowOpen: ro, ctx: c }: { v: V; live: Grant[]; mine: Grant[]; menu: (g: Grant) => MenuItem[]; rowOpen: (g: Grant) => void; ctx: ReturnType<typeof useCtx> }) {
    const { s, p, open } = v;
    return (
        <>
            {yours.map((g) => (
                <LiveStrip key={g.id} g={g} onMar={() => s.go('/emar/mar', { person: g.pid, view: undefined, open: undefined, house: undefined })} onExtend={() => open(`extend:${g.id}`)} onDone={() => open(`done:${g.id}`)} />
            ))}
            <section className="flex flex-col gap-2.5" aria-label="Running now">
                <ListCaption title="Running now" caption={rows.length ? `${rows.length} · each covers one person` : 'None'} />
                {rows.length ? (
                    <EntityTable<Grant>
                        rows={rows}
                        rowKey={(g) => g.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="For"
                        identityWidth="1.3fr"
                        identity={(g) => ({ mark: <PersonDisc name={PEOPLE[g.pid].legal} size={30} />, name: PEOPLE[g.pid].legal, subline: `${g.id} · ${HOUSES[PEOPLE[g.pid].house]}` })}
                        columns={[
                            { key: 'by', label: 'Used by', width: '1.3fr', cell: (g) => <WhoCell name={g.by} /> },
                            { key: 'start', label: 'Started', width: '1fr', cell: (g) => <span className="flex flex-col py-1 text-[12.5px]"><span>{atText(g.start)}</span><span className="text-[11.5px] text-muted-foreground">{g.second ? `Confirmed by ${g.second.name}` : 'No second person'}</span></span> },
                            { key: 'ends', label: 'Ends', width: '0.9fr', cell: (g) => <EndsCell g={g} /> },
                            { key: 'why', label: 'Why', width: '2fr', cell: (g) => <span className="py-1 text-[12.5px]"><span className="block font-medium">{reasonLabel(g)}</span><Wrap>{g.why}</Wrap></span> },
                        ]}
                        actionsFor={m}
                        onOpen={ro}
                        onRowContextMenu={(e, g) => c.openAt(e, `${g.id} · ${PEOPLE[g.pid].pref}`, m(g))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={KeyRound} title="No one is using emergency access right now" description={canRequest(p) ? 'Start it from a person’s record when you must record for someone you aren’t rostered for.' : 'When someone starts it for a person at your houses, it shows here and you’re told.'} />
                    </Card>
                )}
                <p className="text-caption">A grant covers one person, for one member of staff — never a round or a whole house.</p>
                {c.node}
            </section>
        </>
    );
}

function ToReview({ v, rows, menu: m, rowOpen: ro, ctx: c }: { v: V; rows: Grant[]; menu: (g: Grant) => MenuItem[]; rowOpen: (g: Grant) => void; ctx: ReturnType<typeof useCtx> }) {
    const { s, p, ea, scn, overdue } = v;
    const yesterday = allGrants(s.rt, scn).filter((g) => g.start.day === '2026-09-27');
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="To review">
                <ListCaption title="To review" caption={rows.length ? `${rows.length} · due ${Number(ea.reviewDays) === 1 ? 'a day' : `${ea.reviewDays} days`} after each ends` : 'None'} />
                {rows.length ? (
                    <EntityTable<Grant>
                        rows={rows}
                        rowKey={(g) => g.id}
                        rowHeight="content"
                        minWidth={1040}
                        identityLabel="For"
                        identityWidth="1.2fr"
                        identity={(g) => ({ mark: <PersonDisc name={PEOPLE[g.pid].legal} size={30} />, name: PEOPLE[g.pid].legal, subline: `${g.id} · ${HOUSES[PEOPLE[g.pid].house]}` })}
                        columns={[
                            { key: 'by', label: 'Used by', width: '1.2fr', cell: (g) => <WhoCell name={g.by} /> },
                            { key: 'when', label: 'When', width: '1.3fr', cell: (g) => <span className="flex flex-col py-1 text-[12.5px]"><span>{atLong(g.start)}</span><Wrap><span className="text-[11.5px] text-muted-foreground">{HOW_ENDED(g)}</span></Wrap></span> },
                            { key: 'did', label: 'What was done', width: '1.2fr', cell: (g) => <span className="py-1 text-[12.5px]"><Wrap>{didSummary(g)}</Wrap></span> },
                            {
                                key: 'due',
                                label: 'Review due',
                                width: '1.2fr',
                                cell: (g) => {
                                    const block = reviewBlock(p, g);
                                    return (
                                        <span className="flex flex-col items-start gap-1 py-1">
                                            <StatusBadge variant={isOverdue(g, ea) ? 'critical' : 'neutral'} className="rounded-[8px]">
                                                {isOverdue(g, ea) ? `Overdue since ${atText(reviewDueOf(g, ea)!)}` : `Due ${atText(reviewDueOf(g, ea)!)}`}
                                            </StatusBadge>
                                            {block === 'own' || block === 'confirmed' ? <StateLine>{block === 'own' ? 'You used it — someone else reviews it' : 'You confirmed it — someone else reviews it'}</StateLine> : null}
                                        </span>
                                    );
                                },
                            },
                        ]}
                        actionsFor={m}
                        onOpen={ro}
                        onRowContextMenu={(e, g) => c.openAt(e, `${g.id} · ${PEOPLE[g.pid].pref}`, m(g))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={ClipboardCheck} title="Nothing to review" description="Every grant that has ended has been reviewed." />
                    </Card>
                )}
                <p className="text-caption">Overdue reviews also go to Safety & oversight › Follow-ups and the daily report.</p>
                {c.node}
            </section>
            <Card className="gap-3 p-4" aria-label="Today’s daily report">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <span className="flex items-start gap-3">
                        <Bell className="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <span>
                            <span className="block text-sm font-semibold">Today’s daily report — sent 8:00 am</span>
                            <span className="text-caption block">Covers Sunday 27 September, midnight to midnight, NZ time.</span>
                        </span>
                    </span>
                    <Button variant="outline" size="sm" onClick={() => s.go('/emar/settings', { view: 'alerts', sec: 'emergency', open: undefined })}>
                        Who gets it <ArrowUpRight className="size-4" />
                    </Button>
                </div>
                <p className="text-sm">
                    {scn === 'empty' || !yesterday.length ? 'Emergency access wasn’t used yesterday.' : `Emergency access was used ${yesterday.length === 1 ? 'once' : `${yesterday.length} times`} yesterday: ${yesterday.map((g) => `${g.by} for ${PEOPLE[g.pid].pref}, ${atText(g.start).replace(/^Sun 27 Sep, /, '')}–${atText(endedOf(g)?.at ?? endOf(g)).replace(/^Sun 27 Sep, /, '')}`).join('; ')}.`}{' '}
                    {rows.length ? `${rows.length} ${rows.length === 1 ? 'grant is' : 'grants are'} still to review${overdue.length ? ` — ${overdue.length} overdue` : ''}.` : 'Nothing is waiting for review.'}
                </p>
            </Card>
        </>
    );
}

function HistoryView({ v, grants: all, menu: m, ctx: c }: { v: V; grants: Grant[]; menu: (g: Grant) => MenuItem[]; ctx: ReturnType<typeof useCtx> }) {
    const { s, r, p, me, open, ea, scn, set, flags } = v;
    const show = r.q.get('show') ?? 'all';
    const flagged = new Set(flags.flatMap((f) => f.grants));
    const rows = all
        .filter((g) => (show === 'flagged' ? flagged.has(g.id) : show === 'waiting' ? needsReview(g) : show === 'not' ? g.reviews.length && g.reviews[g.reviews.length - 1].outcome === 'notJustified' : true))
        .sort((a, b) => toMin(b.start) - toMin(a.start));
    const allFlags = flagsOf(s.rt, scn);
    return (
        <>
            {allFlags.length ? (
                <section className="flex flex-col gap-2.5" aria-label="Repeat use">
                    <ListCaption title="Repeat use" caption="Reported to reviewers — it never blocks anyone" />
                    {allFlags.map((f) => (
                        <Card key={f.id} className="flex-row flex-wrap items-start justify-between gap-3 p-4">
                            <span className="flex min-w-0 items-start gap-3">
                                <Flag className="mt-0.5 size-4 shrink-0 text-status-warning" aria-hidden="true" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold">
                                        {f.who} — {f.grants.length} grants within 7 days
                                    </span>
                                    <span className="text-caption block">
                                        {f.grants.join(', ')} · flagged {atText(f.raised)}
                                        {f.back ? ' · back after new use' : ''}
                                    </span>
                                    {f.ack ? <StateLine>{`Acknowledged by ${f.ack.by}, ${atText(f.ack.at)} — “${f.ack.why.replace(/\.$/, '')}”. It comes back if ${f.who.split(' ')[0]} uses it again.`}</StateLine> : null}
                                </span>
                            </span>
                            <span className="flex flex-wrap items-center gap-2">
                                {f.ack ? <StatusBadge variant="neutral" className="rounded-[8px]">Acknowledged</StatusBadge> : <StatusBadge variant="warning" className="rounded-[8px]">To acknowledge</StatusBadge>}
                                <Button size="sm" variant="outline" onClick={() => set({ show: 'flagged' })}>
                                    Show these grants
                                </Button>
                                {!f.ack && canReview(p) && f.who !== me.name ? (
                                    <Button size="sm" onClick={() => open(`ack:${f.id}`)} data-return={`ack-${f.id}`}>
                                        Acknowledge
                                    </Button>
                                ) : null}
                            </span>
                        </Card>
                    ))}
                    {allFlags.some((f) => f.who === me.name && !f.ack) ? <p className="text-caption">It’s about your own use, so a reviewer acknowledges it.</p> : null}
                </section>
            ) : null}
            <section className="flex flex-col gap-2.5" aria-label="Every grant">
                <ListCaption
                    title="Every grant"
                    caption={`${rows.length} shown · newest first`}
                    right={
                        <span className="flex items-center gap-2">
                            {(['all', 'flagged', 'waiting', 'not'] as const).map((k) => (
                                <Button key={k} size="sm" variant={show === k ? 'secondary' : 'ghost'} aria-pressed={show === k} onClick={() => set({ show: k === 'all' ? undefined : k })}>
                                    {k === 'all' ? 'All' : k === 'flagged' ? 'Repeat use' : k === 'waiting' ? 'To review' : 'Not justified'}
                                </Button>
                            ))}
                        </span>
                    }
                />
                {rows.length ? (
                    <EntityTable<Grant>
                        rows={rows}
                        rowKey={(g) => g.id}
                        rowHeight="content"
                        minWidth={1060}
                        identityLabel="For"
                        identityWidth="1.2fr"
                        identity={(g) => ({ mark: <PersonDisc name={PEOPLE[g.pid].legal} size={30} />, name: PEOPLE[g.pid].legal, subline: `${g.id} · ${HOUSES[PEOPLE[g.pid].house]}` })}
                        columns={[
                            { key: 'by', label: 'Used by', width: '1.2fr', cell: (g) => <WhoCell name={g.by} /> },
                            { key: 'when', label: 'When', width: '1.3fr', cell: (g) => <span className="flex flex-col py-1 text-[12.5px]"><span>{atLong(g.start)}</span><Wrap><span className="text-[11.5px] text-muted-foreground">{isLive(g) ? `Running until ${atText(endOf(g))}` : HOW_ENDED(g)}</span></Wrap></span> },
                            { key: 'why', label: 'Why', width: '1.3fr', cell: (g) => <span className="py-1 text-[12.5px]"><Wrap>{reasonLabel(g)}</Wrap></span> },
                            {
                                key: 'rev',
                                label: 'Review',
                                width: '1.3fr',
                                cell: (g) => (
                                    <span className="flex flex-col items-start gap-1 py-1">
                                        <GrantBadge g={g} ea={ea} />
                                        {g.reviews.length ? <StateLine>{`${g.reviews[g.reviews.length - 1].by}, ${atText(g.reviews[g.reviews.length - 1].at)}`}</StateLine> : null}
                                        {flagged.has(g.id) ? <StateLine tone="warning" icon={ShieldAlert}>Part of a repeat-use flag</StateLine> : null}
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={m}
                        onOpen={(g) => open(`grant:${g.id}`)}
                        onRowContextMenu={(e, g) => c.openAt(e, `${g.id} · ${PEOPLE[g.pid].pref}`, m(g))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={History} title={show === 'all' ? 'Emergency access hasn’t been used yet' : 'No grants match'} description={show === 'all' ? 'Every grant shows here once it starts — who, for whom, why, what was done and its review.' : 'Choose “All” to see every grant.'} />
                    </Card>
                )}
                <p className="text-caption">Every start, extension, end and review is also an event in the medication audit trail.</p>
                {c.node}
            </section>
        </>
    );
}
