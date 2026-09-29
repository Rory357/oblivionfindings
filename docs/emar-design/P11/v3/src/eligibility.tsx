/* Safety & oversight › Staff eligibility — the register for leads (answer 5), renewals,
 * finite exemptions (NF-03) and witness competency beside PIN status. */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderMeterDonut,
    PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyError, EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import {
    AlarmClock, AlertOctagon, ArrowUpRight, ClipboardCheck, ClipboardList, Clock, Eye, Flag, Gauge, Home, KeyRound, LockKeyhole, Plus, RefreshCw, Repeat, Shield, ShieldCheck, UserCheck, Users, XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { AREAS, HOUSES, HOUSE_KEYS, NOW_LABEL, type HouseKey } from './data';
import {
    areaAbility, areaRes, canAssess, canExempt, effStatus, eligLine, firstName, givenAbility, has, leadCap, myHouses, onShiftWitnesses, pinOf, reminderDays,
    staffById, staffList, STATUS_META, useStore, validState, witnessAbility, type Exemption, type StaffNow,
} from './model';
import { ELIG_SECS, eligHref, settingsHref, useNav } from './nav';
import { pinBadge, canResetPin } from './settings';
import { Flash, Note, RowMenu, Section } from './ui';

export const SAFETY_VIEWS: { key: string; label: string; icon: typeof Gauge; pkg: string }[] = [
    { key: 'overview', label: 'Overview', icon: Gauge, pkg: 'P09' },
    { key: 'followups', label: 'Follow-ups', icon: Flag, pkg: 'P08a' },
    { key: 'overrides', label: 'Witness overrides', icon: Shield, pkg: 'P07a' },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon, pkg: 'P08b' },
    { key: 'handovers', label: 'Handovers', icon: Repeat, pkg: 'P08a' },
    { key: 'eligibility', label: 'Staff eligibility', icon: UserCheck, pkg: 'P11' },
    { key: 'emergency', label: 'Emergency access', icon: LockKeyhole, pkg: 'P10' },
];
export const StatusChip = ({ x }: { x: StaffNow }) => { const { m } = useStore(); const [v, l] = STATUS_META[effStatus(m, x)]; return <StatusBadge variant={v} size="sm">{l}</StatusBadge>; };

export function SafetyPage() {
    const { m, set } = useStore();
    const { route, go, open } = useNav();
    const p = m.persona, d = m.demo.elig, sec = route.sec;
    const [q, setQ] = useState(''), [house, setHouse] = useState('all'), [status, setStatus] = useState(route.q.get('st') || 'all'), [role, setRole] = useState('all');
    const all = staffList(m);
    const cantN = all.filter((x) => givenAbility(m, x).v === 'no' && x.st !== 'restricted').length;
    const railItems = SAFETY_VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon, ...(v.key === 'eligibility' && cantN ? { count: cantN, alert: true } : {}) }));
    const isElig = route.view === 'eligibility';
    const meters = !isElig ? undefined : d === 'loading' || d === 'error'
        ? ['Can record alone', 'Due for renewal', 'Can’t record given', 'Restricted', 'Exemptions', 'Can witness'].map((l) => <PageHeaderMeterBlock key={l} label={l} ariaLabel={`${l}: ${d === 'loading' ? 'loading' : 'unavailable'}`} onClick={() => undefined}><PageHeaderMeterBig>—</PageHeaderMeterBig><PageHeaderMeterCaption>{d === 'loading' ? 'Loading…' : 'Unavailable'}</PageHeaderMeterCaption></PageHeaderMeterBlock>)
        : d === 'empty'
            ? <>
                <PageHeaderMeterBlock label="Can record alone" onClick={() => go(eligHref('register'))}><PageHeaderMeterBig>n/a</PageHeaderMeterBig><PageHeaderMeterCaption>Nobody assessed yet</PageHeaderMeterCaption></PageHeaderMeterBlock>
                {['Due for renewal', 'Can’t record given', 'Restricted', 'Exemptions', 'Can witness'].map((l) => <PageHeaderMeterBlock key={l} label={l} onClick={() => go(eligHref('register'))}><PageHeaderMeterBig>0</PageHeaderMeterBig><PageHeaderMeterCaption>Nobody assessed yet</PageHeaderMeterCaption></PageHeaderMeterBlock>)}
            </>
            : (() => {
                const alone = all.filter((x) => givenAbility(m, x).v === 'yes' && x.st !== 'restricted'), due = all.filter((x) => effStatus(m, x) === 'due').sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
                const restr = all.filter((x) => x.st === 'restricted'), exAct = m.exemptions.filter((e) => e.status === 'active' && myHouses(p).includes(e.house)), wit = all.filter((x) => witnessAbility(m, x).v === 'yes');
                const gap = HOUSE_KEYS.filter((h) => myHouses(p).includes(h) && !onShiftWitnesses(m, h).some((x) => witnessAbility(m, x).v === 'yes'));
                return (
                    <>
                        <PageHeaderMeterBlock label="Can record alone" ariaLabel={`View ${alone.length} staff who can record given doses alone`} onClick={() => { setStatus('alone'); go(eligHref('register')); }}><PageHeaderMeterDonut percent={(alone.length / all.length) * 100} caption={<>{alone.length} of {all.length}<br />current, not restricted</>} /></PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Due for renewal" tone={due.length ? 'warning' : 'brand'} ariaLabel={`View ${due.length} renewals due`} onClick={() => go(eligHref('renewals'))}><PageHeaderMeterBig>{due.length}</PageHeaderMeterBig><PageHeaderMeterCaption>{due.length ? `First: ${firstName(due[0])}, ${due[0].until?.replace(' 2026', '')}` : `Nothing within ${reminderDays(m)} days`}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Can’t record given" tone={cantN ? 'critical' : 'brand'} ariaLabel={`View ${cantN} staff who can’t record given doses`} onClick={() => { setStatus('cant'); go(eligHref('register')); }}><PageHeaderMeterBig>{cantN}</PageHeaderMeterBig><PageHeaderMeterCaption>{cantN ? 'Refused and withheld only' : 'Everyone can'}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Restricted" tone={restr.length ? 'warning' : 'brand'} ariaLabel={`View ${restr.length} restricted staff`} onClick={() => { setStatus('restricted'); go(eligHref('register')); }}><PageHeaderMeterBig>{restr.length}</PageHeaderMeterBig><PageHeaderMeterCaption>Rule: {{ block: 'Block', cosigner: 'co-signer', off: 'Off' }[m.saved.safety.restricted]}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Exemptions" ariaLabel={`View exemptions, ${exAct.length} active`} onClick={() => go(eligHref('exemptions'))}><PageHeaderMeterBig>{exAct.length} active</PageHeaderMeterBig><PageHeaderMeterCaption>Longest {m.saved.elig.longestEx} days{m.setBy.elig.longestEx ? '' : ' · not reviewed'}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Can witness" tone={gap.length ? 'warning' : 'brand'} ariaLabel={`View witnesses and PINs, ${wit.length} can witness`} onClick={() => go(eligHref('witness'))}><PageHeaderMeterBig>{wit.length} of {all.length}</PageHeaderMeterBig><PageHeaderMeterCaption>{gap.length ? `${gap.map((h) => HOUSES[h].replace(' House', '')).join(', ')}: nobody on shift now` : 'Covered on shift now'}</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    </>
                );
            })();
    const header = (
        <PageHeader className="overflow-clip!" icon={Shield} title="Safety & oversight" titleChip={<PageHeaderStatusChip variant="neutral">{myHouses(p).length} houses</PageHeaderStatusChip>}
            subline={`${myHouses(p).map((h) => HOUSES[h]).join(' and ')} · your approved houses · times in NZDT (Pacific/Auckland)`}
            actions={isElig ? <><PageHeaderSearch value={q} onChange={setQ} placeholder="Search staff" />{canAssess(p) ? <PageHeaderPrimaryButton icon={Plus} onClick={() => open({ kind: 'aw', arg: 'new' })}>New assessment</PageHeaderPrimaryButton> : null}</> : undefined}
            meters={meters}
            filters={isElig ? <>
                <PageHeaderFilterSelect icon={Home} label="All houses" value={house} onChange={setHouse} options={[{ value: 'all', label: 'All houses' }, ...myHouses(p).map((h) => ({ value: h, label: HOUSES[h] }))]} />
                {sec === 'register' ? <PageHeaderFilterSelect label="Any status" value={status} onChange={setStatus} options={[['all', 'Any status'], ['alone', 'Can record given doses alone'], ['due', 'Due for renewal'], ['cant', 'Can’t record given doses'], ['restricted', 'Restricted'], ['areas', 'Areas not passed']].map(([value, label]) => ({ value, label }))} /> : null}
                {sec === 'register' ? <PageHeaderFilterSelect icon={Users} label="Any role" value={role} onChange={setRole} options={[['all', 'Any role'], ['Support worker', 'Support workers'], ['House lead', 'House leads']].map(([value, label]) => ({ value, label }))} /> : null}
                <PageHeaderFilterButton icon={d === 'stale' ? AlarmClock : RefreshCw} onClick={() => set((x) => { if (x.demo.elig === 'stale') x.demo.elig = 'loaded'; })}>{d === 'stale' ? 'Not updated since 8:40 am' : `Updated ${NOW_LABEL.replace(' NZDT', '')}`}</PageHeaderFilterButton>
            </> : undefined}
            rail={<PageHeaderRail items={railItems} value={route.view} onSelect={(k) => go(`#/safety/${k}`)} ariaLabel="Safety & oversight views" />}
        />
    );
    if (!isElig) {
        const v = SAFETY_VIEWS.find((x) => x.key === route.view) ?? SAFETY_VIEWS[0];
        return <div className="space-y-5">{header}<EmptyState icon={v.icon} title={`${v.label} is designed in ${v.pkg}`} description="It isn’t part of this preview, which covers Settings and Staff eligibility (P11)." action={<Button variant="outline" size="sm" onClick={() => go(eligHref())}>Back to Staff eligibility</Button>} /></div>;
    }
    const tabs = ELIG_SECS.map(([key, label]) => {
        const n = key === 'renewals' ? all.filter((x) => (givenAbility(m, x).v === 'no' && x.st !== 'restricted') || effStatus(m, x) === 'due').length : key === 'exemptions' ? m.exemptions.filter((e) => e.status === 'active').length : 0;
        return { key, label, icon: { register: ClipboardList, renewals: Clock, exemptions: ShieldCheck, witness: KeyRound }[key]!, ...(n ? (key === 'renewals' ? { warningCount: n } : { count: n }) : {}) };
    });
    const f = { q, house, status, role };
    let body;
    if (d === 'loading') body = <div aria-busy="true" aria-label="Loading staff eligibility"><SkeletonTable rows={6} columns={5} /></div>;
    else if (d === 'error') body = <EmptyError title="Couldn’t load staff eligibility" description="Who can record and witness is still checked every time a dose is saved — this page just couldn’t show it. Don’t rely on memory: try again, or ask a lead." onRetry={() => set((x) => { x.demo.elig = 'loaded'; })} />;
    else if (d === 'empty') body = <EmptyState icon={UserCheck} title="Nobody has been assessed yet" description="Until someone is assessed, nobody at your houses can record doses as given. Refused, withheld and away can always be recorded." action={canAssess(p) ? <Button size="sm" onClick={() => open({ kind: 'aw', arg: 'new' })}><Plus />New assessment</Button> : undefined} />;
    else body = sec === 'renewals' ? <Renewals f={f} /> : sec === 'exemptions' ? <Exemptions f={f} /> : sec === 'witness' ? <Witnesses f={f} /> : <Register f={f} clear={() => { setQ(''); setHouse('all'); setStatus('all'); setRole('all'); }} />;
    return (
        <div className="space-y-5">
            {header}
            <Sections tabs={tabs} value={sec} onChange={(k) => go(eligHref(k))} />
            <Flash />
            {d === 'stale' ? <Note><b>Not updated since 8:40 am NZDT (32 min ago).</b> An assessment or PIN may have changed since. Refresh before you decide who can give or witness a dose. <Button variant="outline" size="sm" className="ml-2" onClick={() => set((x) => { x.demo.elig = 'loaded'; })}><RefreshCw />Refresh now</Button></Note> : null}
            {body}
        </div>
    );
}

type F = { q: string; house: string; status: string; role: string };
function useStaffActions() {
    const { m } = useStore();
    const { open } = useNav();
    const p = m.persona;
    return (x: StaffNow): MenuItem[] => compactMenu([
        x.st !== 'none' && { label: 'View assessment', icon: Eye, onClick: () => open({ kind: 'av', arg: x.id }) },
        canAssess(p) && x.st !== 'ack' && { label: x.st === 'none' ? 'Start first assessment' : x.st === 'failed' ? 'Start remedial assessment' : 'Renew or reassess', icon: ClipboardCheck, onClick: () => open({ kind: 'aw', arg: x.id, step: x.st === 'none' ? 'new' : x.st === 'failed' ? 'remedial' : 'renew' }) },
        canExempt(p) && !validState(x) && !x.exempt && { label: 'Grant an exemption', icon: ShieldCheck, onClick: () => open({ kind: 'xw', arg: x.id }) },
        { separator: true },
        pinOf(m, x.name) !== 'notset' && canResetPin(m, HOUSES[x.house]) && { label: 'Reset witness PIN', icon: RefreshCw, danger: true, onClick: () => open({ kind: 'pinreset', arg: x.name }) },
    ]);
}
const identity = (x: StaffNow) => ({ mark: <PersonDisc name={x.name} size={30} />, name: x.name, subline: `${x.role} · ${HOUSES[x.house]}` });
function Register({ f, clear }: { f: F; clear: () => void }) {
    const { m } = useStore();
    const { open } = useNav();
    const menu = useEntityContextMenu<StaffNow>();
    const actions = useStaffActions();
    const all = staffList(m);
    const rows = all.filter((x) => {
        const s = effStatus(m, x), g = givenAbility(m, x).v;
        if (f.house !== 'all' && x.house !== f.house) return false;
        if (f.role !== 'all' && x.role !== f.role) return false;
        if (f.q && !x.name.toLowerCase().includes(f.q.toLowerCase())) return false;
        return f.status === 'alone' ? g === 'yes' && x.st !== 'restricted' : f.status === 'due' ? s === 'due' : f.status === 'cant' ? g === 'no' && x.st !== 'restricted' : f.status === 'restricted' ? x.st === 'restricted' : f.status === 'areas' ? x.st !== 'none' && AREAS.some((a) => areaRes(x, a.k) === 'no') : true;
    });
    return (
        <Section id="reg" title="Competency register" caption={`${rows.length} of ${all.length} people who record doses at your houses`}>
            {rows.length ? (
                <EntityTable<StaffNow>
                    rows={rows} rowKey={(x) => x.id} identityLabel="Person" identityWidth="1.35fr" minWidth={1080} rowHeight="content" identity={identity}
                    columns={[
                        { key: 'comp', label: 'Competency', width: '1.35fr', cell: (x) => <div className="space-y-1 py-2"><StatusChip x={x} /><p className="text-caption">{eligLine(m, x)}</p></div> },
                        { key: 'can', label: 'What they can do', width: '1.3fr', cell: (x) => { const g = givenAbility(m, x), cd = areaAbility(m, x, 'cd'); return <div className="space-y-1 py-2 text-[12.5px]"><p>{g.v === 'no' ? (x.st === 'restricted' ? 'Can’t sign given doses — restricted' : 'Refused, withheld and away only') : g.v === 'part' ? (x.st === 'restricted' ? 'Given doses with a co-signer' : 'Given doses under an exemption') : 'Given doses'}</p>{g.v !== 'no' && cd.v === 'no' ? <p className="text-muted-foreground">Not controlled doses</p> : null}</div>; } },
                        { key: 'areas', label: 'Areas not passed', width: '1.4fr', cell: (x) => { if (x.st === 'none') return <span className="text-caption">No assessment</span>; const fail = AREAS.filter((a) => areaRes(x, a.k) === 'no'), unseen = AREAS.filter((a) => areaRes(x, a.k) === 'unseen'); return !fail.length && !unseen.length ? <span className="text-caption">All 12 passed</span> : <div className="flex flex-wrap gap-1 py-2">{fail.map((a) => <StatusBadge key={a.k} variant="critical" size="sm">{a.l}{a.core ? ' (core)' : ''}</StatusBadge>)}{unseen.map((a) => <StatusBadge key={a.k} variant="neutral" size="sm">{a.l} — not assessed</StatusBadge>)}</div>; } },
                        { key: 'wit', label: 'Witness', width: '1.2fr', cell: (x) => { const w = witnessAbility(m, x); return w.v === 'yes' ? <StatusBadge variant="success" size="sm">Can witness</StatusBadge> : <div className="space-y-1 py-2"><StatusBadge variant="neutral" size="sm">Not a witness</StatusBadge><p className="text-caption">{w.why!.join(' · ')}</p></div>; } },
                    ]}
                    actionsFor={actions} onOpen={(x) => (x.st === 'none' ? open({ kind: 'av', arg: x.id }) : open({ kind: 'av', arg: x.id }))} onRowContextMenu={menu.open}
                />
            ) : <EmptyState icon={Users} title="Nobody matches" description="Clear the filters or the search." action={<Button variant="outline" size="sm" onClick={clear}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={UserCheck} title={(x) => x.name} items={actions} />
            <Note>“Due for renewal” starts {reminderDays(m)} days before the end date. Refused, withheld and away can always be recorded, whatever the status.</Note>
        </Section>
    );
}
function Renewals({ f }: { f: F }) {
    const { m } = useStore();
    const { open } = useNav();
    const menu = useEntityContextMenu<StaffNow>();
    const actions = useStaffActions();
    const all = staffList(m).filter((x) => (f.house === 'all' || x.house === f.house) && (!f.q || x.name.toLowerCase().includes(f.q.toLowerCase())));
    const now = all.filter((x) => givenAbility(m, x).v === 'no' && x.st !== 'restricted'), due = all.filter((x) => effStatus(m, x) === 'due').sort((a, b) => (a.days ?? 0) - (b.days ?? 0)), later = all.filter((x) => x.st === 'current' && effStatus(m, x) !== 'due').sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
    const roster = (x: StaffNow) => (x.st === 'current' ? 'Rostering shows a warning the rosterer can override' : /Rostered/.test(x.shift) ? `${x.shift} — rostering blocks medication shifts until this is fixed` : 'Rostering blocks medication shifts until this is fixed');
    const table = (list: StaffNow[]) => (
        <EntityTable<StaffNow>
            rows={list} rowKey={(x) => x.id} identityLabel="Person" identityWidth="1.3fr" minWidth={900} rowHeight="content" identity={identity}
            columns={[
                { key: 'st', label: 'Status', width: '1.4fr', cell: (x) => <div className="space-y-1 py-2"><StatusChip x={x} /><p className="text-caption">{eligLine(m, x)}</p></div> },
                { key: 'roster', label: 'Rostering', width: '1.6fr', cell: (x) => <span className="py-2 text-[12.5px]">{roster(x)}</span> },
                { key: 'next', label: 'Next step', width: '1fr', cell: (x) => <span className="text-[12.5px]">{x.st === 'ack' ? `${firstName(x)} acknowledges from their own login` : x.st === 'none' ? 'First assessment' : x.st === 'failed' ? 'Remedial assessment' : 'Renewal'}</span> },
            ]}
            actionsFor={actions} onOpen={(x) => open(canAssess(m.persona) && x.st !== 'ack' ? { kind: 'aw', arg: x.id, step: x.st === 'none' ? 'new' : x.st === 'failed' ? 'remedial' : 'renew' } : { kind: 'av', arg: x.id })} onRowContextMenu={menu.open}
        />
    );
    return (
        <div className="space-y-5">
            <Section id="rn-now" title="Can’t record given doses now" caption={`${now.length} people · refused, withheld and away still recordable`}>{now.length ? table(now) : <EmptyState variant="inline" icon={UserCheck} title="Everyone at your houses can record given doses." />}</Section>
            <Section id="rn-due" title={`Due within ${reminderDays(m)} days`} caption={`${due.length} people · still current until the end date`}>{due.length ? table(due) : <EmptyState variant="inline" icon={Clock} title={`No renewals due within ${reminderDays(m)} days.`} />}</Section>
            <Section id="rn-later" title="Later" caption={`${later.length} people current beyond ${reminderDays(m)} days${later[0] ? ` · next: ${later[0].name}, ${later[0].until}` : ''}`}><span /></Section>
            <RowMenu ctx={menu.ctx} close={menu.close} icon={UserCheck} title={(x) => x.name} items={actions} />
        </div>
    );
}
function Exemptions({ f }: { f: F }) {
    const { m } = useStore();
    const { open, go } = useNav();
    const p = m.persona, menu = useEntityContextMenu<Exemption>();
    const list = m.exemptions.filter((e) => myHouses(p).includes(e.house) && (f.house === 'all' || e.house === f.house) && (!f.q || staffById(e.who)!.name.toLowerCase().includes(f.q.toLowerCase())));
    const actions = (e: Exemption): MenuItem[] => compactMenu([
        { label: 'View the person’s assessment', icon: Eye, onClick: () => open({ kind: 'av', arg: e.who }) },
        e.status === 'active' && canExempt(p) && { label: 'End early', icon: XCircle, danger: true, onClick: () => open({ kind: 'xend', arg: e.id }) },
    ]);
    const B: Record<Exemption['status'], [ 'info' | 'neutral', string]> = { active: ['info', 'Active'], ended: ['neutral', 'Ended'], revoked: ['neutral', 'Ended early'] };
    return (
        <Section id="xl" title="Exemptions" caption={`${list.length} shown · longest ${m.saved.elig.longestEx} days`} right={canExempt(p) ? <Button size="sm" onClick={() => open({ kind: 'xw', arg: 'new' })}><Plus />Grant an exemption</Button> : null}>
            <p className="text-subtle">One house, a reason and an end date within {m.saved.elig.longestEx} days ({m.setBy.elig.longestEx ? 'set' : 'default — not yet reviewed'}). The restricted and area rules still apply during an exemption. Never a witness. <Button variant="outline" size="sm" className="ml-2" onClick={() => go(settingsHref('staff', 'exemptions'))}>Longest exemption setting<ArrowUpRight /></Button></p>
            {list.length ? (
                <EntityTable<Exemption>
                    rows={list} rowKey={(e) => e.id} identityLabel="Person" identityWidth="1.2fr" minWidth={1000} rowHeight="content"
                    identity={(e) => { const x = staffById(e.who)!; return { mark: <PersonDisc name={x.name} size={30} />, name: x.name, subline: x.role }; }}
                    columns={[
                        { key: 'where', label: 'Where', width: '0.9fr', cell: (e) => <EntityChip icon={Home}>{HOUSES[e.house]}</EntityChip> },
                        { key: 'why', label: 'Why', width: '1.6fr', cell: (e) => <span className="py-2 text-[12.5px]">{e.reason}</span> },
                        { key: 'dates', label: 'From – until', width: '1.1fr', cell: (e) => <span className="text-[13px]">{e.from} – {e.until}</span> },
                        { key: 'by', label: 'Approved by', width: '1fr', cell: (e) => <div><div className="text-[13px]">{e.by}</div><div className="text-caption">{e.at}</div></div> },
                        { key: 'st', label: 'Status', width: '1fr', cell: (e) => <div className="space-y-1 py-2"><StatusBadge variant={B[e.status][0]} size="sm">{B[e.status][1]}</StatusBadge>{e.endNote ? <p className="text-caption">{e.endNote}</p> : null}</div> },
                    ]}
                    actionsFor={actions} onOpen={(e) => open({ kind: 'av', arg: e.who })} onRowContextMenu={menu.open} mutedFor={(e) => e.status !== 'active'}
                />
            ) : <EmptyState icon={ShieldCheck} title="No exemptions" description="Nobody at your houses is exempt. Exemptions show here with their end date, and end by themselves." action={canExempt(p) ? <Button size="sm" onClick={() => open({ kind: 'xw', arg: 'new' })}><Plus />Grant an exemption</Button> : undefined} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={ShieldCheck} title={(e) => `${staffById(e.who)!.name} — exemption`} items={actions} />
        </Section>
    );
}
function Witnesses({ f }: { f: F }) {
    const { m } = useStore();
    const { open, go } = useNav();
    const p = m.persona, menu = useEntityContextMenu<StaffNow>();
    const actions = useStaffActions();
    const next: Record<HouseKey, string> = { kowhai: 'Jordan Tipene from 3:00 pm', rimu: 'Sione Taufa from 3:00 pm' };
    const rows = staffList(m).filter((x) => (f.house === 'all' || x.house === f.house) && (!f.q || x.name.toLowerCase().includes(f.q.toLowerCase())));
    return (
        <div className="space-y-5">
            <div className="grid gap-5 lg:grid-cols-2">
                {HOUSE_KEYS.filter((h) => myHouses(p).includes(h)).map((h) => {
                    const on = onShiftWitnesses(m, h), ok = on.filter((x) => witnessAbility(m, x).v === 'yes');
                    return (
                        <Card key={h} className="gap-3 p-4">
                            <div className="flex items-center justify-between gap-3"><h3 className="text-sm font-semibold">{HOUSES[h]} · on shift now</h3>{ok.length ? <StatusBadge variant="success" size="sm">{ok.length} can witness</StatusBadge> : <StatusBadge variant="warning" size="sm">Nobody can witness</StatusBadge>}</div>
                            <ul className="divide-y divide-border rounded-lg border">
                                {on.map((x) => { const w = witnessAbility(m, x); return <li key={x.id} className="flex items-start justify-between gap-3 p-3 text-[13px]"><span><b>{x.name}</b><span className="text-caption block">{x.shift}</span></span><span className="text-caption max-w-[55%] text-right">{w.v === 'yes' ? 'Can witness' : w.why!.join(' · ')}</span></li>; })}
                            </ul>
                            <p className="text-caption">{ok.length ? '' : `Next witness-eligible: ${next[h]}. `}Checked against the roster and clock-ins at {NOW_LABEL}.{!ok.length && leadCap(p) ? ' Controlled doses due before then need a witness override.' : ''}</p>
                            {!ok.length && leadCap(p) ? <div><Button variant="outline" size="sm" onClick={() => go('#/safety/overrides')}>Open witness overrides<ArrowUpRight /></Button></div> : null}
                        </Card>
                    );
                })}
            </div>
            <Section id="wp" title="Witness competency and PIN" caption={`${rows.length} people`}>
                <p className="text-subtle">A witness needs all four: a current assessment (not an exemption, not restricted) with “can witness controlled drugs”, a witness PIN, and being on shift at the house.</p>
                <EntityTable<StaffNow>
                    rows={rows} rowKey={(x) => x.id} identityLabel="Person" identityWidth="1.3fr" minWidth={1000} rowHeight="content" identity={identity}
                    columns={[
                        { key: 'comp', label: 'Witness competency', width: '1.5fr', cell: (x) => <span className="py-2 text-[12.5px]">{x.st === 'none' ? 'Not assessed' : x.st === 'restricted' ? 'No — restricted' : !(x.st === 'current' || (x.st === 'ack' && x.prevValid)) ? `No — ${STATUS_META[effStatus(m, x)][1].toLowerCase()}` : areaRes(x, 'cd') !== 'yes' ? 'No — controlled drugs area not passed' : x.witness ? 'Yes — on for this assessment' : 'No — not on'}</span> },
                        { key: 'pin', label: 'Witness PIN', width: '1.2fr', cell: (x) => pinBadge(pinOf(m, x.name), m.pins.find((y) => y.name === x.name)?.changed) },
                        { key: 'shift', label: 'On shift now', width: '1.2fr', cell: (x) => <span className="text-[12.5px]">{x.shift.startsWith('Clocked') ? x.shift.split(' · ')[0] : <span className="text-muted-foreground">{x.shift}</span>}</span> },
                        { key: 'can', label: 'Can witness', width: '0.8fr', cell: (x) => (witnessAbility(m, x).v === 'yes' ? <StatusBadge variant="success" size="sm">Yes</StatusBadge> : <StatusBadge variant="neutral" size="sm">No</StatusBadge>) },
                    ]}
                    actionsFor={actions} onOpen={(x) => open({ kind: 'av', arg: x.id })} onRowContextMenu={menu.open}
                />
                <Note>Nobody can see or set another person’s PIN. House leads (for their houses) and clinical leads can reset one; the owner then sets a new PIN in their account settings.</Note>
            </Section>
            <RowMenu ctx={menu.ctx} close={menu.close} icon={KeyRound} title={(x) => x.name} items={actions} />
        </div>
    );
}
export { has };
