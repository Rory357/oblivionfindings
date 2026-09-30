/* A FRAME of P01’s Meds today, showing only P10’s parts (Main, Q9, Q11): the
 * downtime banner when the page is offline, out of date or couldn’t load —
 * with today’s paper pack when this device has it, and an honest line about
 * what can’t be saved offline (D7: doses are saved on the device and sent
 * later; witnessed doses and controlled entries need a connection) — and the
 * “paper records to enter” card after a downtime. The dose list is P01’s. */
import { PageHeader, PageHeaderFilterButton, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { AlertTriangle, CalendarClock, Clock3, CloudOff, FileText, PenLine, Pill, Printer, RefreshCw, WifiOff } from 'lucide-react';
import { HOUSES, ONCALL, PERSONAS } from '../data';
import { allDowntimes, frontlineShift, itemState, labelIso } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice } from '../ui';

export function TodayFrame() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const scn = r.scenario;
    const shift = frontlineShift(p);
    const house = me.houses[0];
    const oncall = ONCALL[house];
    const pack = s.rt.exports.find((x) => x.what.startsWith('Downtime pack') && x.house === house);
    const mineToEnter = allDowntimes(s.rt, scn)
        .filter((d) => d.house === house)
        .flatMap((d) => d.items.map((i) => ({ d, i })))
        .filter(({ i }) => itemState(s.rt, i.id) === 'toEnter');
    const yours = mineToEnter.filter(({ i }) => i.paper.by === me.name);
    const others = mineToEnter.filter(({ i }) => i.paper.by !== me.name);
    const packLine = pack ? null : `No paper pack on this device today — use the printed copy kept in the house${oncall && oncall.name !== me.name ? `, or call ${oncall.name} — ${oncall.phone}` : ''}.`;
    const packButton = pack ? (
        <Button size="sm" variant="outline" onClick={() => s.toast('info', `Opens today’s pack for ${HOUSES[house]} (${pack.id}, made ${pack.at.replace('Mon 28 Sep, ', '')}) from this device — nothing downloads in the preview.`)}>
            <Printer className="size-4" /> Open today’s paper pack
        </Button>
    ) : null;
    if (!shift)
        return (
            <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Meds today' }]}>
                <Card className="items-center gap-3 p-10 text-center">
                    <Clock3 className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h1 className="text-section-title">You’re not on shift today</h1>
                    <p className="text-subtle">Meds today lists your doses once you clock in.</p>
                </Card>
            </Shell>
        );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Meds today' }]}>
            <PageHeader
                icon={Pill}
                title="Meds today"
                titleChip={<PageHeaderStatusChip variant="neutral">{HOUSES[house]}</PageHeaderStatusChip>}
                subline={`Your shift · ${shift} · NZ time`}
                filters={
                    scn === 'stale' ? (
                        <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                            As at 8:40 am · couldn’t refresh — try again
                        </PageHeaderFilterButton>
                    ) : (
                        <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                            As at 9:12 am · NZDT
                        </PageHeaderFilterButton>
                    )
                }
            />
            {scn === 'offline' ? (
                <Notice tone="warning" icon={WifiOff} live="status" title="You’re offline" actions={packButton}>
                    Doses you record are kept on this device and sent when you’re back. Anything that needs a witness, and controlled-medicine entries, need a connection — if this lasts, record those on today’s paper pack. {packLine}
                </Notice>
            ) : scn === 'stale' ? (
                <Notice
                    tone="warning"
                    icon={AlertTriangle}
                    title="This may be out of date"
                    actions={
                        <>
                            <Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                                <RefreshCw className="size-4" /> Try again
                            </Button>
                            {packButton}
                        </>
                    }
                >
                    We couldn’t refresh at 9:12 am — it shows what was known at 8:40 am. If it doesn’t come back soon, record on today’s paper pack. {packLine}
                </Notice>
            ) : scn === 'unavailable' ? (
                <Notice tone="critical" icon={CloudOff} title="We couldn’t load your doses" actions={packButton}>
                    Record on today’s paper pack, and enter it when the system is back. {packLine}
                </Notice>
            ) : null}
            {mineToEnter.length ? (
                <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                    <span className="flex items-start gap-3">
                        <PenLine className="mt-0.5 size-4 text-status-warning" aria-hidden="true" />
                        <span>
                            <span className="block text-sm font-semibold">
                                {mineToEnter.length} paper {mineToEnter.length === 1 ? 'record' : 'records'} from the downtime on {labelIso(mineToEnter[0].d.start.day)} to enter
                            </span>
                            <span className="text-caption block">{[yours.length ? `${yours.length} ${yours.length === 1 ? 'is' : 'are'} yours` : '', others.length ? `${others.length} ${others.length === 1 ? 'is' : 'are'} ${[...new Set(others.map(({ i }) => i.paper.by))].join(' and ')}’s` : ''].filter(Boolean).join(' · ')}</span>
                        </span>
                    </span>
                    <Button size="sm" onClick={() => s.go('/emar/downtime', { dt: mineToEnter[0].d.id, open: undefined })}>
                        <FileText className="size-4" /> Enter them
                    </Button>
                </Card>
            ) : null}
            <Card className="gap-3 p-6">
                <span className="flex items-start gap-3">
                    <CalendarClock className="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                    <span>
                        <span className="block text-sm font-semibold">Your doses this morning</span>
                        <span className="text-caption block">P01’s approved Meds today — outside this preview.</span>
                    </span>
                </span>
                <p>
                    <a className="text-primary underline-offset-2 hover:underline" href={hrefFor('/emar/mar', { person: p === 'rimu' ? 'ben' : 'tama' }, r)}>
                        Open {p === 'rimu' ? 'Ben' : 'Tama'}’s MAR
                    </a>
                </p>
            </Card>
            <DesignNote title="Design note — downtime on Meds today (Main, Q9, Q11)">
                <p>Today’s offline banner says “We’ll send anything you save when you’re back”, but the witnessed and controlled dialogs refuse to save offline, and no page ever says the data is stale. There’s no print pack — the round sheet only lists doses already recorded. Here the banner says what can’t be saved offline and opens today’s pack from this device, if it has one; after a downtime, staff see the paper records waiting for them.</p>
            </DesignNote>
        </Shell>
    );
}
