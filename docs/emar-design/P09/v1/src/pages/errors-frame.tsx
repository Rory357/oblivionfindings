/* Safety & oversight › Medication errors — a FRAME of P08b v1.1’s approved
 * view (frozen on :4393), showing only P09’s addition to its close dialog
 * (Main, Q11): when Settings › Records & reporting turns SAC ratings on, the
 * person closing an error confirms its SAC, chosen for them from the
 * organisation’s mapping — except severe or permanent harm (1 or 2, their
 * choice) and near misses (none). Everything else is P08b’s, unchanged. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeader, PageHeaderRail, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertOctagon, CheckCheck, Flag, Gauge, LockKeyhole, Repeat, Scale, Settings2, Shield, ShieldCheck, UserCheck, type LucideIcon } from 'lucide-react';
import { type MouseEvent } from 'react';
import { HARM_LABEL, HOUSES, PEOPLE, PERSONAS, TYPE_LABEL, has, type MedError } from '../data';
import { useOpen } from '../host';
import { allErrors, housesOf } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, StateLine, Wrap } from '../ui';
import { useCtx } from './hub';

const VIEWS: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'overview', label: 'Overview', icon: Gauge },
    { key: 'followups', label: 'Follow-ups', icon: Flag },
    { key: 'overrides', label: 'Witness overrides', icon: Shield },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon },
    { key: 'handovers', label: 'Handovers', icon: Repeat },
    { key: 'eligibility', label: 'Staff eligibility', icon: UserCheck },
    { key: 'emergency', label: 'Emergency access', icon: LockKeyhole },
];
/** Who may close (P08b Q5/Q7): someone who manages errors and didn’t report it. */
export const canCloseErr = (p: keyof typeof PERSONAS, e: MedError) => has(p, 'errors.manage') && PERSONAS[p].name !== e.reportedBy;

export function ErrorsFrame() {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const ctxMenu = useCtx(AlertOctagon);
    const sacOn = s.rt.org.sac || s.route.q.get('sac') === 'on';
    const rows = allErrors(s.rt).filter((e) => housesOf(p).includes(PEOPLE[e.pid].house) && ((e.stage === 'actions' && e.openActions === 0 && e.told) || (e.stage === 'closed' && s.rt.errPatch[e.id])));
    const stop = (fn: () => void) => (ev: MouseEvent) => (ev.stopPropagation(), fn());
    const menu = (e: MedError) => compactMenu([e.stage !== 'closed' && canCloseErr(p, e) && { label: 'Close the error', icon: CheckCheck, onClick: () => open(`close:${e.id}`) }, { label: `Open ${e.id} (P08b)`, icon: AlertOctagon, onClick: () => s.toast('info', `Opens ${e.id} in P08b’s approved view — frozen on :4393.`) }]);
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: hrefFor('/emar/reports', {}, s.route) }, { title: 'Safety & oversight', href: hrefFor('/emar/errors', {}, s.route) }, { title: 'Medication errors' }]}>
            <PageHeader
                icon={ShieldCheck}
                title="Safety & oversight"
                titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
                subline="Medication errors · P08b’s view, with P09’s addition to closing"
                rail={<PageHeaderRail items={VIEWS} value="errors" onSelect={() => s.toast('info', 'P08b’s approved Safety & oversight frame — outside this preview.')} ariaLabel="Safety & oversight views" />}
            />
            <Notice
                tone="neutral"
                icon={Scale}
                title={sacOn ? 'SAC ratings are on — the person closing an error confirms its rating' : 'SAC ratings are off — closing works as P08b approved it'}
                actions={
                    <Button size="sm" variant="outline" onClick={() => s.go('/emar/settings', { view: 'rules', sec: 'records', open: undefined })}>
                        <Settings2 className="size-4" /> Settings › Records & reporting
                    </Button>
                }
            >
                {sacOn ? 'Chosen for them from the organisation’s mapping, except severe or permanent harm (SAC 1 or 2, their choice). Near misses get no SAC.' : 'Turn them on in Settings for adverse-event reporting.'}
            </Notice>
            <section className="flex flex-col gap-2.5" aria-label="Ready to close">
                <ListCaption title="Ready to close" caption={`${rows.length} shown · the reporter never closes`} />
                <EntityTable<MedError>
                    rows={rows}
                    rowKey={(e) => e.id}
                    rowHeight="content"
                    minWidth={940}
                    identityLabel="Person"
                    identityWidth="1.5fr"
                    identity={(e) => ({ mark: <PersonDisc name={PEOPLE[e.pid].legal} size={30} />, name: PEOPLE[e.pid].legal, subline: <Wrap>{e.id} · {HOUSES[PEOPLE[e.pid].house]}</Wrap> })}
                    columns={[
                        { key: 'what', label: 'What happened', width: '1.5fr', cell: (e) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span className="font-semibold">{TYPE_LABEL[e.type]}</span><StateLine>{e.occurred}</StateLine></span> },
                        { key: 'harm', label: 'Reach & harm', width: '1.3fr', cell: (e) => <StatusBadge variant={e.harm === 'severe' || e.harm === 'death' ? 'critical' : e.harm === 'moderate' || e.harm === 'minor' ? 'warning' : e.reached === 'no' ? 'info' : 'neutral'} className="rounded-[8px]">{e.reached === 'no' ? 'Near miss' : e.harm === 'none' ? 'Reached · no harm' : HARM_LABEL[e.harm]}</StatusBadge> },
                        { key: 'st', label: 'Where it’s at', width: '1.2fr', cell: (e) => (e.stage === 'closed' ? <span className="flex flex-col items-start gap-0.5"><StatusBadge variant="neutral" className="rounded-[8px]">Closed{e.closed?.sac ? ` · SAC ${e.closed.sac}` : ''}</StatusBadge><StateLine>{e.closed?.by}, {e.closed?.at}</StateLine></span> : <span className="flex flex-col items-start gap-0.5"><StatusBadge variant="info" className="rounded-[8px]">Ready to close</StatusBadge><StateLine>{e.owner}{e.incident ? ` · incident ${e.incident}` : ''}</StateLine></span>) },
                        { key: 'act', label: '', width: '110px', align: 'right', cell: (e) => (e.stage !== 'closed' && canCloseErr(p, e) ? <Button size="sm" variant="outline" data-return={`cl-${e.id}`} onClick={stop(() => open(`close:${e.id}`))}>Close it</Button> : null) },
                    ]}
                    actionsFor={menu}
                    onOpen={(e) => (e.stage !== 'closed' && canCloseErr(p, e) ? open(`close:${e.id}`) : s.toast('info', `Opens ${e.id} in P08b’s approved view — frozen on :4393.`))}
                    onRowContextMenu={(ev, e) => ctxMenu.openAt(ev, `${e.id} · ${PEOPLE[e.pid].pref}`, menu(e))}
                />
                {ctxMenu.node}
            </section>
            <DesignNote title="Design note — SAC at close, a P09 addition to P08b (Main, Q11)">
                <p>P08b’s build note 13 said reports map harm to the HQSC Severity Assessment Code. Main refined it: the organisation turns SAC ratings on in Settings, and the person closing each error confirms the rating, chosen for them from the organisation’s mapping — death → 1, moderate → 3, minor or no harm → 4 — with severe or permanent harm left for them to choose (1 or 2), because our harm question can’t tell those apart. Near misses get none. The errors report and its file show the confirmed rating.</p>
            </DesignNote>
        </Shell>
    );
}
