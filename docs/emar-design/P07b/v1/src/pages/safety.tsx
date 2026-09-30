/* Safety & oversight › Witness overrides (Main, Q7) — inside P08a v1’s
 * approved Safety & oversight frame (PageHeader, the seven-view rail); P07b
 * designs only this view. Each override: request → decision → doses given →
 * the house lead’s witnessed count and sign-off (P07a Q4), with an overdue flag.
 * `controlled.override` is the key for granting overrides, and nothing else. */
import { compactMenu } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertOctagon, BookOpen, Clock3, Flag, Gauge, Home, Lock, LockKeyhole, Repeat, Shield, ShieldCheck, UserCheck, type LucideIcon } from 'lucide-react';
import { type MouseEvent, type ReactNode } from 'react';
import { HOUSES, PEOPLE, PERSONAS, type House, type Override } from '../data';
import { useOpen } from '../host';
import { allOverrides, canGrantOverride, cdView, medOf, overrideState } from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote, StateLine, Wrap } from '../ui';
import { useCtx } from './register';

type View = 'overview' | 'followups' | 'overrides' | 'errors' | 'handovers' | 'eligibility' | 'emergency';
const VIEWS: { key: View; label: string; icon: LucideIcon; pkg: string }[] = [
    { key: 'overview', label: 'Overview', icon: Gauge, pkg: 'P09' },
    { key: 'followups', label: 'Follow-ups', icon: Flag, pkg: 'P08a (approved)' },
    { key: 'overrides', label: 'Witness overrides', icon: Shield, pkg: 'P07b' },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon, pkg: 'P08b' },
    { key: 'handovers', label: 'Handovers', icon: Repeat, pkg: 'P08a (approved)' },
    { key: 'eligibility', label: 'Staff eligibility', icon: UserCheck, pkg: 'P11 (approved)' },
    { key: 'emergency', label: 'Emergency access', icon: LockKeyhole, pkg: 'P10' },
];
const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());

export function SafetyPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const ctx = useCtx();
    const view = (VIEWS.some((v) => v.key === r.q.get('view')) ? r.q.get('view') : 'overrides') as View;
    const houseQ = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseQ || h === houseQ);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const all = empty || !cdView(p) ? [] : allOverrides(s.rt).filter((o) => houses.includes(o.house));
    const st = r.q.get('st') ?? 'all';
    const list = all.filter((o) => st === 'all' || (st === 'attention' ? ['Sign-off overdue', 'Count and sign-off due', 'Waiting for a manager'].includes(overrideState(o).label) : st === 'declined' ? o.decision.state === 'declined' : overrideState(o).label === 'Signed off'));
    const overdue = all.filter((o) => overrideState(o).label === 'Sign-off overdue');
    const due = all.filter((o) => overrideState(o).label === 'Count and sign-off due');
    const approved = all.filter((o) => o.decision.state === 'approved');
    const declined = all.filter((o) => o.decision.state === 'declined');
    const go = (patch: Record<string, string | undefined>) => s.go('/emar/safety', { view: 'overrides', open: undefined, ...patch });
    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Safety & oversight"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseQ ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Medication safety for ${houses.map((h) => HOUSES[h]).join(' and ')} · times in NZDT`}
            meters={
                view === 'overrides' ? (
                    <>
                        <PageHeaderMeterBlock label="Sign-off overdue" tone={!dash && overdue.length ? 'critical' : 'brand'} ariaLabel={`View ${overdue.length} overrides whose sign-off is overdue`} onClick={() => go({ st: 'attention' })}>
                            <PageHeaderMeterBig>{dash ?? overdue.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : overdue.length ? 'Count and sign-off late' : 'None late'}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Sign-off due" tone={!dash && due.length ? 'warning' : 'brand'} ariaLabel={`View ${due.length} overrides with a sign-off due`} onClick={() => go({ st: 'attention' })}>
                            <PageHeaderMeterBig>{dash ?? due.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : due.length ? 'By next shift’s end' : 'None due'}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Granted" ariaLabel={`View ${approved.length} overrides granted`} onClick={() => go({ st: 'all' })}>
                            <PageHeaderMeterBig>{dash ?? approved.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : 'Last 7 days'}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Declined" ariaLabel={`View ${declined.length} overrides declined`} onClick={() => go({ st: 'declined' })}>
                            <PageHeaderMeterBig>{dash ?? declined.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : 'Last 7 days'}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ) : undefined
            }
            filters={
                view === 'overrides' ? (
                    <>
                        {me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="All houses" value={houseQ ?? 'all'} onChange={(v) => go({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                        <PageHeaderFilterSelect label="All overrides" value={st} allValue="all" onChange={(v) => go({ st: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All overrides' }, { value: 'attention', label: 'Needs a sign-off' }, { value: 'done', label: 'Signed off' }, { value: 'declined', label: 'Declined' }]} />
                        <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                            As at 9:12 am · Pacific/Auckland
                        </PageHeaderFilterButton>
                    </>
                ) : undefined
            }
            rail={<PageHeaderRail<View> items={VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon, ...(v.key === 'overrides' && overdue.length && !dash ? { count: overdue.length, alert: true } : {}) }))} value={view} onSelect={(k) => s.go('/emar/safety', { view: k })} ariaLabel="Safety & oversight views" />}
        />
    );
    const menu = (o: Override) => compactMenu([{ label: 'Open the override', icon: Shield, onClick: () => open(`override:${o.id}`) }, { label: 'Open the register for this medicine', icon: BookOpen, onClick: () => s.go('/emar/controlled', { open: `med:${o.medId}` }) }]);
    let body: ReactNode;
    const v = VIEWS.find((x) => x.key === view)!;
    if (view !== 'overrides')
        body = (
            <Card className="p-2">
                <EmptyState icon={v.icon} title={`${v.label} is designed in ${v.pkg}`} description="It isn’t part of this preview, which covers Witness overrides (P07b)." action={<Button variant="outline" onClick={() => s.go('/emar/safety', { view: 'overrides' })}>Go to Witness overrides</Button>} />
            </Card>
        );
    else if (!cdView(p))
        body = (
            <Card className="items-center gap-3 p-10 text-center">
                <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                <h2 className="text-section-title">You don’t have access to Witness overrides</h2>
                <p className="text-subtle">It needs controlled-medicine access. Ask your manager if you need it for your work.</p>
            </Card>
        );
    else if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading overrides">
                <SkeletonTable rows={4} columns={5} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load witness overrides" message="Some sign-offs may be overdue. Ask the house leads on shift, and try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else
        body = (
            <section className="flex flex-col gap-2.5" aria-label="Witness overrides">
                <ListCaption title="Witness overrides" caption={`${list.length} shown · newest first · ${canGrantOverride(p) ? 'you grant overrides (controlled.override)' : 'managers grant overrides; the house lead counts and signs off after'}`} />
                {list.length ? (
                    <EntityTable<Override>
                        rows={list}
                        rowKey={(o) => o.id}
                        rowHeight="content"
                        minWidth={940}
                        identityLabel="Override"
                        identityWidth="1.3fr"
                        identity={(o) => ({ icon: Shield, name: medOf(o.medId).med, subline: <Wrap>{o.id} · {PEOPLE[medOf(o.medId).pid].pref} · {HOUSES[o.house]}</Wrap> })}
                        columns={[
                            { key: 'req', label: 'Asked for', width: '1.5fr', cell: (o) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{o.requestedAt} · {o.requestedBy}</span><StateLine>“{o.why}”</StateLine></span> },
                            { key: 'dec', label: 'Decision', width: '1.2fr', cell: (o) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span className="font-semibold">{o.decision.state === 'approved' ? `Granted until ${o.decision.until}` : o.decision.state === 'declined' ? 'Declined' : 'Waiting'}</span><StateLine>{o.decision.by ? `${o.decision.by}, ${o.decision.at}` : ''}{o.decision.note ? ` — “${o.decision.note}”` : ''}</StateLine></span> },
                            { key: 'doses', label: 'Doses under it', width: '0.9fr', cell: (o) => <span className="text-[12.5px]">{o.doses.length ? o.doses.map((d) => `${d.at.split(', ')[1]} · ${d.by}`).join('; ') : 'None'}</span> },
                            { key: 'st', label: 'Sign-off', width: '1.4fr', cell: (o) => <span className="flex flex-col items-start gap-1 py-0.5"><StatusBadge variant={overrideState(o).variant} className="rounded-[8px]">{overrideState(o).label}</StatusBadge>{o.followUp ? <StateLine>{o.followUp.done ? `${o.followUp.done.by}, ${o.followUp.done.at} — witnessed by ${o.followUp.done.witness}` : `${o.followUp.owner} · due ${o.followUp.due}`}</StateLine> : null}</span> },
                            { key: 'act', label: '', width: '96px', align: 'right', cell: (o) => <Button size="sm" variant={overrideState(o).label === 'Sign-off overdue' ? 'default' : 'outline'} data-return={`ov-${o.id}`} onClick={stop(() => open(`override:${o.id}`))}>Open</Button> },
                        ]}
                        actionsFor={menu}
                        onOpen={(o) => open(`override:${o.id}`)}
                        onRowContextMenu={(e, o) => ctx.openAt(e, `${o.id} · ${medOf(o.medId).med}`, menu(o))}
                    />
                ) : (
                    <Card className="p-2">
                        {st === 'all' ? <EmptyState variant="compact" icon={Shield} title="No witness overrides" description="Overrides appear here when a manager is asked to let a controlled dose go ahead without a second person." /> : <EmptyState variant="compact" icon={Shield} title={st === 'attention' ? 'No overrides need a sign-off' : st === 'done' ? 'No signed-off overrides' : 'No declined overrides'} description="Choose All overrides to see the rest." />}
                    </Card>
                )}
                {ctx.node}
                <p className="text-caption">A manager is asked from the dose (P01) or Controlled checks (P07a). After a dose given under an override, the house lead does a witnessed count and signs it off by the end of the next shift.</p>
                <DesignNote title="Design note — Witness overrides (Main, Q7)">
                    <p>Today there’s no witness-override flow and no list for leads, and `controlled.override` (“Override controlled drug discrepancy blocks”) is checked nowhere — the block it describes doesn’t exist (AUDIT 5). Here `controlled.override` is only the key for granting witness overrides.</p>
                </DesignNote>
            </section>
        );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/safety' }, { title: 'Safety & oversight' }]}>
            {header}
            {body}
        </Shell>
    );
}
